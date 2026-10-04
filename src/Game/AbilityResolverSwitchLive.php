<?php
/**
 * Live-zone / Live Success ability cases — extracted from AbilityResolverSwitch.php.
 * (Not live_start_* — see AbilityResolverSwitchLiveStart.php.)
 */

function tryResolveAbilityEffectSwitchLive(
    array $state,
    string $pid,
    array $source,
    array $ab,
    array $ctx,
    string $type,
    array &$p,
    string $name
): array {
    switch ($type) {
        case 'live_ban_until_end':
            $state = initLiveModifiers($state);
            $state['live_modifiers'][$pid]['cannot_live'] = true;
            if (intval($ab['draw'] ?? 0) > 0) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw']));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn; cannot attempt a Live until this Live ends.");
            } else {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] cannot attempt a Live until this Live ends.");
            }
            break;

        case 'live_score_if_stage_wr_name_live':
            if (countStageGroupMembers($p, $ab['group'] ?? '')
                >= intval($ab['min_stage_members'] ?? 3)
                && wrHasLiveNameContains($p, $ab['wr_name_contains'] ?? '')) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (stage + Waiting Room Live name).');
            }
            break;

        case 'live_score_if_yell_group_count':
            $yellCards = $ctx['yell_cards'] ?? [];
            $cnt = count(array_filter(
                $yellCards,
                fn($c) => cardMatchesGroup($c, $ab['group'] ?? '', $ab['filter'] ?? 'member')
            ));
            if ($cnt >= intval($ab['min_count'] ?? 1)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    " ($cnt Yell cards matched).");
            }
            break;

        case 'live_success_energy_wait_if_winning':
            // Compare finalized round scores only. Pending Lives still in the
            // opponent's zone (not yet heart-checked) must not block this (issue #67).
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (!liveRoundScoreIsFinalized($state, $opp)) {
                $state['_deferred_energy_wait_if_winning'][] = [
                    'pid'         => $pid,
                    'group'       => $ab['group'] ?? '',
                    'source_name' => $name,
                ];
                break;
            }
            $state = applyLiveSuccessEnergyWaitIfWinning($state, $pid, $ab['group'] ?? '', $name);
            break;

        case 'live_success_energy_wait_if_excess':
            if (intval($ctx['excess_hearts'] ?? 0) < intval($ab['min_excess'] ?? 1)) break;
            $exColor = $ab['excess_color'] ?? '';
            if ($exColor !== '') {
                // Live Success passes leftover hearts as a flat color list (`excess_heart_colors`).
                $colors = $ctx['excess_heart_colors'] ?? [];
                if (!is_array($colors)) {
                    $colors = [];
                }
                $colorExcess = count(array_filter(
                    $colors,
                    static fn($c) => (string)$c === (string)$exColor
                ));
                // Legacy / mistaken key support
                if ($colorExcess < 1 && isset($ctx['excess_hearts_by_color'][$exColor])) {
                    $colorExcess = intval($ctx['excess_hearts_by_color'][$exColor]);
                }
                if ($colorExcess < intval($ab['min_excess'] ?? 1)) break;
            }
            if (!stageHasGroupMember($p, $ab['group'] ?? '')) break;
            if (putEnergyFromDeckInWait($p)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put 1 Energy from Energy deck into Wait (excess hearts).');
            }
            break;

        case 'live_score_per_stage_wait_member':
            $cnt = countStageWaitMembers($p);
            if ($cnt > 0) {
                $bonus = $cnt * intval($ab['amount'] ?? 1);
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $bonus);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] score +$bonus ($cnt Member(s) in Wait).");
            }
            break;

        case 'live_score_if_yell_has_hearts':
            // Deprecated: Love U my friends — use live_score_if_yell_has_all_blade.
            break;

        case 'live_score_if_yell_has_all_blade': {
            // Official: [Live Success] if Yell reveals ≥1 card with ALL blade → this Live's score +N once
            // (not +N per ALL-blade card). Official Q&A: ALL hearts do not satisfy this.
            $yellCards = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            if (!is_array($yellCards) || !yellCardsHaveAllBlade($yellCards)) {
                break;
            }
            $amt = intval($ab['amount'] ?? 1);
            if ($amt <= 0) {
                break;
            }
            if (bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $amt)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . $amt . ' (Yell has ALL blade).');
            }
            break;
        }

        case 'live_success_add_wr_if_distinct_subunit':
            if (countDistinctNamedSubunit($p, $ab['subunit'] ?? '') >= intval($ab['min_distinct'] ?? 2)) {
                $cfg = wrPickCfgFromAbility($ab);
                $cfg['subunit'] = $ab['subunit'] ?? '';
                $count = intval($ab['count'] ?? 1);
                $added = addFromWaitingRoomWithChoice($state, $pid, $source, $ab, $ctx, $cfg, $count);
                if ($added === null) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] choose a " . ($ab['subunit'] ?? '') . ' card from Waiting Room.');
                    break;
                }
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added $added " . ($ab['subunit'] ?? '') . ' card(s) from Waiting Room.');
                }
            }
            break;

        case 'live_score_if_wr_subunit_count':
            $cnt = countWrSubunitFilter($p, $ab['subunit'] ?? '', $ab['filter'] ?? 'live');
            if ($cnt >= intval($ab['min_count'] ?? 3)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (' . ($ab['subunit'] ?? '') . " Live in WR: $cnt).");
            }
            break;

        case 'live_success_energy_wait_if_fewer':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (countEnergyInZone($p) >= countEnergyInZone($state['players'][$opp])) {
                break;
            }
            if (putEnergyFromDeckInWait($p)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put 1 Energy from Energy deck into Wait (fewer Energy).');
            }
            break;

        case 'live_success_pick_energy_or_member':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $canBoth = !empty(array_filter(
                $p['success_lives'] ?? [],
                fn($c) => ($c['group'] ?? '') === ($ab['group'] ?? 'Nijigasaki')
            ));
            $state['pending_prompt'] = [
                'type'          => 'live_success_pick_energy_or_member',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'can_both'      => $canBoth,
                'prompt'        => $canBoth
                    ? 'Choose: Energy to Wait, Member from WR to hand, or both.'
                    : 'Choose: put 1 Energy into Wait, or add 1 Member from WR to hand.',
                'choices'       => $canBoth
                    ? ['energy', 'member', 'both', 'skip']
                    : ['energy', 'member', 'skip'],
                'choice_labels' => $canBoth
                    ? ['Energy → Wait', 'Member → hand', 'Both', 'Skip']
                    : ['Energy → Wait', 'Member → hand', 'Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Success choice.');
            break;

        case 'live_success_choose_draw_or_energy_wait':
            // Wien Margarete (PL!SP-pb2-010): draw 2 OR Energy from deck into Wait.
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $drawN = intval($ab['draw'] ?? 2);
            $state['pending_prompt'] = [
                'type'          => 'live_success_choose_draw_or_energy_wait',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'draw'          => $drawN,
                'prompt'        => "Choose one — draw $drawN cards; or put 1 Energy from your Energy deck into Wait.",
                'choices'       => ['draw', 'energy'],
                'choice_labels' => ["Draw $drawN", 'Energy → Wait'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Live Success choice.");
            break;

        case 'live_score_bonus':
            $state = applyModifierEffect($state, $pid, $ab);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] gains +' . intval($ab['amount'] ?? 1) . ' total Live Score until this Live ends.');
            break;

        case 'live_success_yell_live_deck_bottom':
            $yellPool = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            $lives = array_values(array_filter($yellPool, fn($c) => ($c['card_type'] ?? '') === 'ライブ'));
            if (empty($lives) || !empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'live_success_yell_live_deck_bottom',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $lives),
                'prompt'        => 'Put up to 1 Live card revealed for Yell on the bottom of your deck?',
                'choices'       => ['pick', 'skip'],
                'choice_labels' => ['Choose Live', 'Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Success (optional deck bottom).');
            break;

        case 'live_success_pick_yell_live':
            $yellAll = $ctx['yell_cards'] ?? $state['_last_yell_cards'] ?? [];
            if (!empty($ab['min_distinct_yell_members'])) {
                $group = $ab['group'] ?? '';
                if (countDistinctYellMembers($yellAll, $group)
                    < intval($ab['min_distinct_yell_members'])) {
                    break;
                }
            }
            $pool = array_values(array_filter(
                $yellAll,
                function ($c) use ($ab) {
                    if (($c['card_type'] ?? '') !== 'ライブ') return false;
                    $group = $ab['group'] ?? '';
                    return $group === '' || ($c['group'] ?? '') === $group;
                }
            ));
            if (empty($pool)) {
                break;
            }
            if (count($pool) === 1) {
                $picked = $pool[0];
                $p['hand'][] = $picked;
                $remaining = array_values(array_filter(
                    $p['_pending_yell_wr'] ?? $pool,
                    fn($c) => ($c['instance_id'] ?? '') !== ($picked['instance_id'] ?? '')
                ));
                $p['_pending_yell_wr'] = $remaining;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added ' . cardDisplayName($picked) . ' from Yell to hand.');
                break;
            }
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'live_success_pick_yell_live',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $pool),
                'prompt'        => 'Choose 1 Live card revealed by Yell to add to your hand.',
                'ability'       => $ab,
            ];
            break;

        case 'live_success_energy_wait_if_yell_live':
            $pool = $ctx['yell_cards'] ?? $state['_last_yell_cards'] ?? [];
            if (countYellLiveCards($pool) < 1) {
                break;
            }
            if (putEnergyFromDeckInWait($p)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put 1 Energy from Energy deck into Wait (Yell revealed Live).');
            }
            break;

        case 'live_success_pick_yell_card':
            if (!empty($ab['min_distinct_named_on_stage'])) {
                if (countDistinctNamedOnStage($p, $ab['names'] ?? [])
                    < intval($ab['min_distinct_named_on_stage'])) {
                    break;
                }
            }
            $yellPool = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            if (empty($yellPool)) break;
            $eligible = filterYellPoolForAbility($yellPool, $ab);
            if (empty($eligible)) break;
            if (count($eligible) === 1) {
                $picked = $eligible[0];
                $pickId = $picked['instance_id'] ?? '';
                $p['_pending_yell_wr'] = array_values(array_filter(
                    $p['_pending_yell_wr'] ?? $yellPool,
                    fn($c) => ($c['instance_id'] ?? '') !== $pickId
                ));
                $p['hand'][] = $picked;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added ' . cardDisplayName($picked) . ' from Yell to hand.');
                break;
            }
            $subunit = trim($ab['subunit'] ?? '');
            $maxMemberCost = isset($ab['max_member_cost']) ? intval($ab['max_member_cost'])
                : (isset($ab['max_cost']) ? intval($ab['max_cost']) : null);
            if ($subunit !== '') {
                $pickLabel = 'Choose 1 ' . $subunit . ' Member card revealed by Yell to add to your hand.';
            } elseif ($maxMemberCost !== null && ($ab['filter'] ?? 'member') === 'member') {
                $pickLabel = "Choose 1 Member card with cost $maxMemberCost or less revealed by Yell to add to your hand.";
            } else {
                $pickLabel = 'Choose 1 card revealed by Yell to add to your hand.';
            }
            $state['pending_prompt'] = [
                'type'        => 'pick_yell_member',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'prompt'      => $pickLabel,
                'candidates'  => array_map('cardPromptSummary', $eligible),
                'ability'     => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose a Yell card.');
            break;

        case 'live_cost_from_subunit_pick':
            if (!empty($state['pending_prompt'])) break;
            $subunit = $ab['subunit'] ?? 'DOLLCHESTRA';
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr || !cardMatchesSubunit($mbr, $subunit)) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'        => 'live_cost_from_subunit_pick',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_id'   => $source['instance_id'] ?? '',
                'source_name' => $name,
                'candidates'  => $candidates,
                'ability'     => $ab,
                'heart_color' => $ab['heart_color'] ?? 'blue',
                'prompt'      => 'Choose 1 ' . $subunit . ' Member on your Stage.',
            ];
            break;

    }
    return $state;
}

/** True when this player's Live Performance heart check has finished (or they did not attempt). */
function liveRoundScoreIsFinalized(array $state, string $pid): bool {
    if (!in_array($pid, $state['live_attempt'] ?? ['p1', 'p2'], true)) {
        return true;
    }
    return array_key_exists($pid, $state['live_round_success'] ?? []);
}

/** Score of Lives that succeeded this round (0 if failed / did not attempt). */
function resolvedSuccessfulLiveRoundScore(array $state, string $pid): int {
    if (!in_array($pid, $state['live_attempt'] ?? ['p1', 'p2'], true)) {
        return 0;
    }
    if (!($state['live_round_success'][$pid] ?? false)) {
        return 0;
    }
    $zone = $state['players'][$pid]['live_zone'] ?? [];
    if (empty($zone)) {
        return 0;
    }
    return sumLiveZoneCardScores($zone) + getLiveScoreBonus($state, $pid);
}

function applyLiveSuccessEnergyWaitIfWinning(
    array $state,
    string $pid,
    string $group,
    string $name
): array {
    if ($pid === '' || !($state['live_round_success'][$pid] ?? false)) {
        return $state;
    }
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    $myScore = resolvedSuccessfulLiveRoundScore($state, $pid);
    $oppScore = resolvedSuccessfulLiveRoundScore($state, $opp);
    if ($myScore <= $oppScore) {
        return $state;
    }
    $p = &$state['players'][$pid];
    if ($group !== '' && !stageHasGroupMember($p, $group)) {
        return $state;
    }
    if (putEnergyFromDeckInWait($p)) {
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $name . '] put 1 Energy from Energy deck into Wait.');
    }
    return $state;
}

/** Run Do! Do! Do!-style effects deferred until both players' Performance results are known. */
function flushDeferredLiveSuccessEnergyWaitIfWinning(array $state): array {
    $queue = $state['_deferred_energy_wait_if_winning'] ?? [];
    unset($state['_deferred_energy_wait_if_winning']);
    foreach ($queue as $item) {
        if (!is_array($item)) {
            continue;
        }
        $state = applyLiveSuccessEnergyWaitIfWinning(
            $state,
            (string)($item['pid'] ?? ''),
            (string)($item['group'] ?? ''),
            (string)($item['source_name'] ?? 'Live')
        );
    }
    return $state;
}
