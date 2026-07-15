<?php
/**
 * Live Score bonus breakdown — extracted from effects.php.
 */

function liveScoreBonusEntry(string $who, int $amount, string $text): array {
    return ['who' => $who, 'text' => $text, 'amount' => $amount];
}

function pushLiveScoreBonusEntry(array &$entries, int &$bonus, string $who, int $amount, string $text): void {
    if ($amount <= 0) return;
    $bonus += $amount;
    $entries[] = liveScoreBonusEntry($who, $amount, $text);
}

function continuousEffectDescription(array $ab, ?int $stackedEnergyCount = null): string {
    $type = $ab['type'] ?? '';
    $amount = intval($ab['amount'] ?? 1);
    if ($type === 'live_score_if_stacked_energy') {
        $minEnergy = intval($ab['min_energy'] ?? 2);
        if ($stackedEnergyCount !== null) {
            return "+{$amount} Live Score ({$stackedEnergyCount} Energy under this Member)";
        }
        return "+{$amount} Live Score ({$minEnergy}+ Energy under this Member)";
    }
    $labels = [
        'live_score_bonus'                    => "+{$amount} Live Score",
        'yell_hearts_wildcard'                => 'Yell hearts count as any color',
        'yell_heart_score_bonus'              => "+{$amount} Score per Yell heart (all your Lives this Performance)",
        'live_score_if_yell_has_hearts'       => "+{$amount} Score when Yell has printed hearts",
        'success_score_per_yell_score'        => "+{$amount} Live Score per Yell Score icon",
        'live_score_if_wr_group_count'        => "+{$amount} Live Score (Waiting Room group count)",
        'live_score_if_stage_wr_name_live'    => "+{$amount} Live Score (stage + WR Live name)",
        'live_score_if_full_stage_distinct_group' => "+{$amount} Live Score (full stage group)",
        'live_score_if_opp_success_total'     => "+{$amount} Live Score (opponent Success total)",
        'live_score_bonus_if_min_energy'      => "+{$amount} Live Score (Energy threshold)",
        'live_score_bonus_if_min_entered'     => "+{$amount} Live Score (Members entered this turn)",
        'grant_live_success_yell_live_score_if_full_stage' => 'Bonus Live Score from Yell Lives (full stage)',
        'draw_per_yell_draw'                  => 'Draw 1 per Yell draw icon revealed',
        'draw_per_yell_card'                  => 'Draw 1 per card revealed by Yell',
        'draw_per_yell_heart'                 => 'Draw 1 per heart revealed by Yell',
        'hand_cost_reduction'                 => 'Hand cost reduction',
        'cannot_success_live'               => 'Cannot be placed in Success Live',
    ];
    if (isset($labels[$type])) {
        return $labels[$type];
    }
    return ucwords(str_replace('_', ' ', $type));
}

/**
 * @param bool $omitUnrevealedLive When true, skip face-down Live storage (and their
 *   continuous text). Used for opponent stage-board UI so Active effects cannot
 *   spoil whether a Live was set during LIVE Phase.
 */
function getLiveScoreBonusBreakdown(array $state, string $pid, bool $omitUnrevealedLive = false): array {
    $state = initLiveModifiers($state);
    $entries = [];
    $bonus = 0;
    $modBonus = intval($state['live_modifiers'][$pid]['score_bonus'] ?? 0);
    pushLiveScoreBonusEntry($entries, $bonus, 'This turn', $modBonus, "+{$modBonus} Live Score modifier");
    $p = $state['players'][$pid] ?? [];
    foreach ($p['stage'] ?? [] as $slot => &$member) {
        if (!$member) continue;
        mergeCardCatalogFields($member);
        $who = stageMemberWhoLabel($member, is_string($slot) ? $slot : '');
        pushLiveScoreBonusEntry(
            $entries, $bonus, $who,
            intval($member['live_score_bonus'] ?? 0),
            '+' . intval($member['live_score_bonus'] ?? 0) . ' Live Score'
        );
        foreach ($member['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') continue;
            $amt = 0;
            $stackedForDesc = (($ab['type'] ?? '') === 'live_score_if_stacked_energy')
                ? countMemberStackedEnergy($p, $member) : null;
            $text = continuousEffectDescription($ab, $stackedForDesc);
            if (($ab['type'] ?? '') === 'live_score_bonus') {
                if (empty($ab['center_only']) || $slot === 'center') {
                    $amt = intval($ab['amount'] ?? 1);
                }
            }
            if (($ab['type'] ?? '') === 'live_score_if_stacked_energy') {
                if (countMemberStackedEnergy($p, $member) >= intval($ab['min_energy'] ?? 2)) {
                    $amt = intval($ab['amount'] ?? 1);
                }
            }
            if (($ab['type'] ?? '') === 'live_score_bonus_if_min_entered') {
                if (intval($p['members_entered_this_turn'] ?? 0) >= intval($ab['min_entered'] ?? 2)) {
                    $amt = intval($ab['amount'] ?? 1);
                }
            }
            if (($ab['type'] ?? '') === 'live_score_if_wr_group_count') {
                if (countWrGroup($p, $ab['group'] ?? '') >= intval($ab['min_count'] ?? 25)) {
                    $amt = intval($ab['amount'] ?? 1);
                }
            }
            if (($ab['type'] ?? '') === 'live_score_if_stage_wr_name_live') {
                if (countStageGroupMembers($p, $ab['group'] ?? '')
                    >= intval($ab['min_stage_members'] ?? 3)
                    && wrHasLiveNameContains($p, $ab['wr_name_contains'] ?? '')) {
                    $amt = intval($ab['amount'] ?? 1);
                }
            }
            if (($ab['type'] ?? '') === 'live_score_if_full_stage_distinct_group') {
                if (stageFullDistinctGroupMembers(
                    $p,
                    $ab['group'] ?? '',
                    $ab['filter'] ?? 'member'
                )) {
                    $amt = intval($ab['amount'] ?? 1);
                }
            }
            if (($ab['type'] ?? '') === 'grant_live_success_yell_live_score_if_full_stage') {
                if (isLiveScoreYellContext($state)
                    && stageFullDistinctGroupMembers(
                    $p,
                    $ab['group'] ?? 'Sunshine',
                    $ab['filter'] ?? 'member'
                )) {
                    $liveCnt = yellLiveCountForPlayer($state, $pid);
                    if ($liveCnt >= intval($ab['tier2_min'] ?? 3)) {
                        $amt = intval($ab['tier2_amount'] ?? 2);
                    } elseif ($liveCnt >= intval($ab['tier1_min'] ?? 1)) {
                        $amt = intval($ab['tier1_amount'] ?? 1);
                    }
                }
            }
            if (($ab['type'] ?? '') === 'live_score_if_opp_success_total') {
                $opp = ($pid === 'p1') ? 'p2' : 'p1';
                if (sumSuccessLiveScores($state['players'][$opp], $state, $opp)
                    >= intval($ab['min_score'] ?? 6)) {
                    $amt = intval($ab['amount'] ?? 1);
                }
            }
            if (($ab['type'] ?? '') === 'live_score_bonus_if_min_energy') {
                if (countEnergyInZone($p) >= intval($ab['min_energy'] ?? 12)) {
                    $amt = intval($ab['amount'] ?? 1);
                }
            }
            $packAmt = nBp5ApplyContinuousLiveScore($state, $pid, $member, $ab)
                + sBp5ApplyContinuousLiveScore($state, $pid, $member, $ab)
                + sBp6ApplyContinuousLiveScore($state, $pid, $member, $ab)
                + spBp5ApplyContinuousLiveScore($state, $pid, $member, $ab)
                + plMuseGapApplyContinuousLiveScore($state, $pid, $member, $ab, is_string($slot) ? $slot : '');
            if ($packAmt > 0) {
                pushLiveScoreBonusEntry($entries, $bonus, $who, $packAmt, "+{$packAmt} Live Score");
            }
            pushLiveScoreBonusEntry($entries, $bonus, $who, $amt, $text);
        }
    }
    unset($member);
    if (isLiveScoreYellContext($state)) {
        $yellScoreIcons = yellScoreIconsForPlayer($state, $pid);
        if ($yellScoreIcons > 0) {
            pushLiveScoreBonusEntry(
                $entries,
                $bonus,
                'Yell',
                $yellScoreIcons,
                "+{$yellScoreIcons} Live Score per Yell Score icon"
            );
        }
    }
    foreach ($p['live_zone'] ?? [] as $live) {
        if (!$live) {
            continue;
        }
        if ($omitUnrevealedLive && empty($live['revealed'])) {
            continue;
        }
        mergeCardCatalogFields($live);
        $who = $live['name_en'] ?? $live['name'] ?? 'Live';
        foreach ($live['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') {
                continue;
            }
            $amt = 0;
            $text = continuousEffectDescription($ab);
            if (($ab['type'] ?? '') === 'success_score_per_yell_score' && isLiveScoreYellContext($state)) {
                $icons = yellScoreIconsForPlayer($state, $pid);
                if ($icons > 0) {
                    $amt = intval($ab['amount'] ?? 1) * $icons;
                    $text = "+{$amt} Live Score per Yell Score icon";
                }
            }
            pushLiveScoreBonusEntry($entries, $bonus, $who, $amt, $text);
        }
    }
    return ['total' => $bonus, 'entries' => $entries];
}

/** Continuous skills/effects currently applying (score bonuses + Live storage passives). */
function collectActiveContinuousEffects(array $state, string $pid, bool $omitUnrevealedLive = false): array {
    $effects = getLiveScoreBonusBreakdown($state, $pid, $omitUnrevealedLive)['entries'];
    $p = $state['players'][$pid] ?? [];
    $livePassiveTypes = [
        'draw_per_yell_heart',
        'draw_per_yell_draw', 'draw_per_yell_card', 'live_score_if_yell_has_hearts',
        'success_score_per_yell_score',
    ];
    foreach ($p['live_zone'] ?? [] as $live) {
        if (!$live) continue;
        if ($omitUnrevealedLive && empty($live['revealed'])) continue;
        $who = $live['name_en'] ?? $live['name'] ?? 'Live';
        foreach ($live['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') continue;
            $type = $ab['type'] ?? '';
            if (!in_array($type, $livePassiveTypes, true)) continue;
            $effects[] = liveScoreBonusEntry($who, 0, continuousEffectDescription($ab));
        }
    }
    foreach ($p['stage'] ?? [] as $slot => $member) {
        if (!$member) continue;
        $who = stageMemberWhoLabel($member, is_string($slot) ? $slot : '');
        foreach ($member['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') continue;
            $type = $ab['type'] ?? '';
            if (str_starts_with($type, 'live_score') || str_starts_with($type, 'grant_live_success')) {
                continue;
            }
            if (in_array($type, ['hand_cost_reduction', 'no_baton', 'cannot_success_live'], true)) {
                $effects[] = liveScoreBonusEntry($who, 0, continuousEffectDescription($ab));
            }
        }
    }
    return $effects;
}

function getLiveScoreBonus(array $state, string $pid): int {
    return getLiveScoreBonusBreakdown($state, $pid)['total'];
}
