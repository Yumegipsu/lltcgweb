<?php
/**
 * Love Live! Official Card Game — card ability resolution.
 *
 * Included by api.php. Card skills are stored in cards.json as structured "abilities"
 * (trigger + type + params). resolveAbilityEffect dispatches on type; set-specific
 * handlers live in *_effects.php modules. actionResolvePrompt (src/Game/PromptResolver.php)
 * completes interactive prompts; prompt enrichment and optional-discard resolution (src/Game/PromptEnrichment.php); actionActivateAbility (src/Game/ActivateAbility.php)
 * handles [Activated] costs on stage Members. Live Start phase (src/Game/LiveStartEffects.php)
 * runs mandatory/optional live_start abilities before Performance. Live Score breakdown
 * and HUD (src/Game/LiveScoreBonus.php) aggregates continuous score bonuses.
 *
 * Hooks tie into turn flow: on_enter, live_start, continuous, live_success, on_leave_stage,
 * yell reveal auto-effects, and Live modifier state (bonus hearts, blade, score).
 */

require_once __DIR__ . '/subunits.php';
require_once __DIR__ . '/src/Game/PromptLifecycle.php';
require_once __DIR__ . '/src/Game/ZoneMovement.php';
require_once __DIR__ . '/src/Game/LiveModifiers.php';
require_once __DIR__ . '/nijigasaki_effects.php';
require_once __DIR__ . '/hs_bp6_effects.php';
require_once __DIR__ . '/hs_pb1_effects.php';
require_once __DIR__ . '/hs_cl1_effects.php';
require_once __DIR__ . '/n_bp5_effects.php';
require_once __DIR__ . '/s_bp5_effects.php';
require_once __DIR__ . '/s_bp6_effects.php';
require_once __DIR__ . '/s_sd1_effects.php';
require_once __DIR__ . '/sp_bp2_effects.php';
require_once __DIR__ . '/sp_bp5_effects.php';
require_once __DIR__ . '/pl_muse_gap_effects.php';
require_once __DIR__ . '/pl_sp_sd2_effects.php';
require_once __DIR__ . '/batch99_effects.php';
require_once __DIR__ . '/src/Game/LiveScoreBonus.php';
require_once __DIR__ . '/src/Game/AbilityResolver.php';
require_once __DIR__ . '/src/Game/LiveStartEffects.php';
require_once __DIR__ . '/src/Game/PromptEnrichment.php';
require_once __DIR__ . '/src/Game/PromptResolver.php';
require_once __DIR__ . '/src/Game/ActivateAbility.php';

function cardHasAbilities(array $card): bool {
    return !empty($card['abilities']) && is_array($card['abilities']);
}

/** Lazy card catalog for hydrating runtime copies missing abilities / group / cost. */
function tcgCardsCatalogMap(): array {
    static $map = null;
    if ($map === null) {
        $map = [];
        $path = __DIR__ . '/cards.json';
        if (is_file($path)) {
            $data = json_decode(file_get_contents($path), true);
            foreach ($data['cards'] ?? [] as $c) {
                $no = $c['card_no'] ?? '';
                if ($no !== '') {
                    $map[$no] = $c;
                }
            }
        }
    }
    return $map;
}

function mergeCardCatalogFields(array &$card): void {
    $no = $card['card_no'] ?? '';
    if ($no === '') {
        return;
    }
    $base = tcgCardsCatalogMap()[$no] ?? null;
    if (!$base) {
        return;
    }
    // Oracle sync: skill text and structured abilities always follow cards.json so
    // in-progress games pick up fixes without forcing a new match.
    foreach (['abilities', 'text', 'text_jp', 'text_es'] as $oracleKey) {
        if (!empty($base[$oracleKey])) {
            $card[$oracleKey] = $base[$oracleKey];
        }
    }
    foreach (['group', 'cost', 'card_type', 'card_type_en', 'name', 'name_en'] as $key) {
        if (empty($card[$key]) && !empty($base[$key])) {
            $card[$key] = $base[$key];
        }
    }
    if (empty($card['blade_hearts']) && !empty($base['blade_hearts'])) {
        $card['blade_hearts'] = $base['blade_hearts'];
    }
    foreach (['special_heart', 'yell_draw_icon', 'yell_score_icon', 'required_hearts', 'score'] as $key) {
        if (!isset($card[$key]) && isset($base[$key]) && $base[$key] !== '' && $base[$key] !== []) {
            $card[$key] = $base[$key];
        }
    }
}

/** Hydrate yell-reveal copies so Live blade hearts / icons match the catalog. */
function mergeYellCardCatalogFields(array &$card): void {
    mergeCardCatalogFields($card);
}

/** Inactive Energy that would flip when a leaving Member's baton-to-WR skill resolves. */
function countActivatableEnergyInZone(array $p, int $max): int {
    $n = 0;
    foreach ($p['energy_zone'] ?? [] as $e) {
        if ($n >= $max) {
            break;
        }
        if (!($e['active'] ?? false)) {
            $n++;
        }
    }
    return $n;
}

/** Energy from activate_if_baton_to_wr on the Member being replaced (e.g. Kaho SD). */
function estimateBatonWrEnergyActivation(array $leaving, array $incoming, array $p): int {
    mergeCardCatalogFields($leaving);
    mergeCardCatalogFields($incoming);
    foreach (getAbilitiesByTrigger($leaving, 'on_leave_stage') as $ab) {
        if (($ab['type'] ?? '') !== 'activate_if_baton_to_wr') {
            continue;
        }
        if (!isMemberCard($incoming)) {
            continue;
        }
        if (($ab['group'] ?? '') !== '' && ($incoming['group'] ?? '') !== ($ab['group'] ?? '')) {
            continue;
        }
        if (intval($incoming['cost'] ?? 0) < intval($ab['min_baton_cost'] ?? 10)) {
            continue;
        }
        $want = intval($ab['count'] ?? 2);
        return min($want, countActivatableEnergyInZone($p, $want));
    }
    return 0;
}

function getAbilitiesByTrigger(array $card, string $trigger): array {
    if (!cardHasAbilities($card)) return [];
    $abs = array_values(array_filter($card['abilities'], function ($a) use ($trigger) {
        $t = $a['trigger'] ?? '';
        if ($t === $trigger) return true;
        if ($trigger === 'on_enter' && $t === 'on_enter_or_live_start') return true;
        if ($trigger === 'live_start' && $t === 'on_enter_or_live_start') return true;
        if ($trigger === 'on_enter' && $t === 'on_enter_or_auto') return true;
        if ($trigger === 'auto' && $t === 'on_enter_or_auto') return true;
        return false;
    }));
    if (isMemberCard($card)) {
        $abs = array_merge($abs, spBp2InheritedAbilitiesForTrigger($card, $trigger));
    }
    return $abs;
}

function stageHasMemberMinCost(array $p, int $minCost): bool {
    foreach ($p['stage'] as $m) {
        if ($m && intval($m['cost'] ?? 0) >= $minCost) return true;
    }
    return false;
}

function eitherStageHasMemberMinCost(array $state, int $minCost): bool {
    foreach (['p1', 'p2'] as $id) {
        if (stageHasMemberMinCost($state['players'][$id], $minCost)) {
            return true;
        }
    }
    return false;
}

function countYellLiveCards(array $yellCards): int {
    return count(array_filter($yellCards, 'isLiveTypeCard'));
}

function liveCardCannotSuccess(array $card): bool {
    foreach ($card['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') === 'continuous' && ($ab['type'] ?? '') === 'cannot_success_live') {
            return true;
        }
    }
    return false;
}

/** True when an attempted Live grants "Yell hearts count as any color" (card skill, not global). */
function liveCardsGrantYellHeartsWildcard(array $liveCards): bool {
    foreach ($liveCards as $lc) {
        foreach ($lc['abilities'] ?? [] as $ab) {
            if (($ab['type'] ?? '') === 'yell_hearts_wildcard') {
                return true;
            }
        }
        $text = (string)($lc['text'] ?? '') . (string)($lc['text_jp'] ?? '');
        if ($text !== '' && (
            preg_match('/revealed for Yell may be treated as any color/i', $text)
            || preg_match('/エールで出た.*任意の色/u', $text)
        )) {
            return true;
        }
    }
    return false;
}

function waitFirstGroupMember(array &$p, string $group, array $state): bool {
    foreach ($p['stage'] as &$mbr) {
        if ($mbr && ($mbr['group'] ?? '') === $group) {
            waitMember($mbr, $state);
            unset($mbr);
            return true;
        }
    }
    unset($mbr);
    return false;
}

function stageHasGroupMember(array $p, string $group): bool {
    foreach ($p['stage'] as $mbr) {
        if ($mbr && ($mbr['group'] ?? '') === $group) return true;
    }
    return false;
}

function playerHasCannotLiveIfSoloStage(array $state, string $pid): bool {
    $p = $state['players'][$pid] ?? [];
    if (countStageMembers($p) !== 1) {
        return false;
    }
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        foreach ($mbr['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'cannot_live_if_solo_stage') {
                return true;
            }
        }
    }
    return false;
}

function stageHasNamedMember(array $p, array $names): bool {
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
        foreach ($names as $n) {
            if ($label === $n || str_contains($label, $n)) {
                return true;
            }
        }
    }
    return false;
}

function stageHasAllNamePairs(array $p, array $pairs): bool {
    foreach ($pairs as $pair) {
        if (!stageHasNamedMember($p, $pair)) {
            return false;
        }
    }
    return true;
}

function memberMatchesNames(array $mbr, array $names): bool {
    $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
    foreach ($names as $n) {
        if ($label === $n || str_contains($label, $n)) {
            return true;
        }
    }
    return false;
}

function applyNamedMemberBladeBonus(array &$state, string $pid, array $grants): int {
    $applied = 0;
    foreach ($grants as $grant) {
        $names = $grant['names'] ?? [];
        $amount = intval($grant['amount'] ?? 1);
        $max = intval($grant['max'] ?? 1);
        $count = 0;
        foreach ($state['players'][$pid]['stage'] as &$mbr) {
            if (!$mbr || $count >= $max) continue;
            if (!memberMatchesNames($mbr, $names)) continue;
            $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $amount;
            $count++;
            $applied++;
        }
        unset($mbr);
    }
    return $applied;
}

function countDistinctGroupStageWr(array $p, string $group, string $filter = 'member'): int {
    $names = [];
    foreach (array_merge($p['stage'] ?? [], $p['waiting_room'] ?? []) as $c) {
        if (!$c) continue;
        if (!cardMatchesGroup($c, $group, $filter)) continue;
        $label = $c['name_en'] ?? $c['name'] ?? '';
        if ($label !== '') {
            $names[$label] = true;
        }
    }
    return count($names);
}

function countEnteredMovedSubunitThisTurn(array $p, string $subunit): int {
    $n = 0;
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        if (($mbr['subunit'] ?? '') !== $subunit) continue;
        if (!empty($mbr['entered_this_turn']) || !empty($mbr['moved_this_turn'])) {
            $n++;
        }
    }
    return $n;
}

function formationRotatePlayerStage(array &$stage): void {
    $center = $stage['center'] ?? null;
    $left = $stage['left'] ?? null;
    $right = $stage['right'] ?? null;
    $stage['center'] = $right;
    $stage['left'] = $center;
    $stage['right'] = $left;
    foreach ($stage as &$mbr) {
        if ($mbr) {
            $mbr['moved_this_turn'] = true;
        }
    }
    unset($mbr);
}

function allEnergyActive(array $p): bool {
    $zone = $p['energy_zone'] ?? [];
    if (empty($zone)) return false;
    foreach ($zone as $e) {
        if (!($e['active'] ?? false)) return false;
    }
    return true;
}

function putEnergyFromDeckInWait(array &$p, ?array &$state = null, ?string $pid = null): bool {
    if (empty($p['energy_deck'])) return false;
    $e = array_shift($p['energy_deck']);
    $e['active'] = false;
    $p['energy_zone'][] = $e;
    if ($state !== null && $pid !== null) {
        $state = resolveEnergyPlacedAbilities($state, $pid);
        $state = spBp5OnEnergyPlaced($state, $pid);
    }
    return true;
}

function countEnergyInZone(array $p): int {
    return count($p['energy_zone'] ?? []);
}

function countCombinedEnergy(array $state): int {
    return countEnergyInZone($state['players']['p1'] ?? [])
        + countEnergyInZone($state['players']['p2'] ?? []);
}

function countOppWaitMembers(array $state, string $opp): int {
    $n = 0;
    foreach ($state['players'][$opp]['stage'] ?? [] as $mbr) {
        if ($mbr && !($mbr['active'] ?? true)) {
            $n++;
        }
    }
    return $n;
}

function liveCardScoreForJudge(array $card): int {
    mergeCardCatalogFields($card);
    return intval($card['score'] ?? 0) + intval($card['live_score_bonus'] ?? 0);
}

function sumLiveZoneCardScores(array $zone): int {
    $sum = 0;
    foreach ($zone as $c) {
        if ($c) {
            $sum += liveCardScoreForJudge($c);
        }
    }
    return $sum;
}

function yellScoreIconsForPlayer(array $state, string $pid): int {
    $key = '_last_yell_score_icons_' . $pid;
    if (array_key_exists($key, $state)) {
        return intval($state[$key]);
    }
    return intval($state['_last_yell_score_icons'] ?? 0);
}

function stageSlotBlocksAdditionalEnterThisTurn(?array $occupant, array $state): bool {
    if (!$occupant || empty($occupant['blocks_slot_entries'])) {
        return false;
    }
    return intval($occupant['entered_turn'] ?? 0) === intval($state['turn'] ?? 1);
}

function getLiveTotalScore(array $state, string $pid): int {
    $p = $state['players'][$pid] ?? [];
    $zone = $p['live_zone'] ?? [];
    if (empty($zone)) {
        return 0;
    }
    return sumLiveZoneCardScores($zone) + getLiveScoreBonus($state, $pid);
}

function countDistinctWrLives(array $p, string $group): int {
    $names = [];
    foreach ($p['waiting_room'] ?? [] as $c) {
        if (($c['card_type'] ?? '') !== 'ライブ') {
            continue;
        }
        if ($group !== '' && ($c['group'] ?? '') !== $group) {
            continue;
        }
        $label = $c['name_en'] ?? $c['name'] ?? '';
        if ($label !== '') {
            $names[$label] = true;
        }
    }
    return count($names);
}

function countNamedSuccessLives(array $p, string $name): int {
    $n = 0;
    foreach ($p['success_lives'] ?? [] as $c) {
        $label = $c['name_en'] ?? $c['name'] ?? '';
        if ($label === $name || str_contains($label, $name)) {
            $n++;
        }
    }
    return $n;
}

function stageFullGroupMembersMinCost(array $p, string $group, int $minCost): bool {
    $slots = ['left', 'center', 'right'];
    $total = 0;
    foreach ($slots as $slot) {
        $mbr = $p['stage'][$slot] ?? null;
        if (!$mbr || ($mbr['group'] ?? '') !== $group) {
            return false;
        }
        $total += intval($mbr['cost'] ?? 0);
    }
    return $total >= $minCost;
}

function yellMembersHaveAllHeartColors(array $cards): bool {
    $found = [];
    foreach ($cards as $c) {
        if (($c['card_type'] ?? '') !== 'メンバー') {
            continue;
        }
        foreach ($c['hearts'] ?? [] as $hg) {
            $color = $hg['color'] ?? '';
            if ($color !== '') {
                $found[$color] = true;
            }
        }
    }
    foreach (['pink', 'red', 'yellow', 'green', 'purple', 'blue'] as $need) {
        if (empty($found[$need])) {
            return false;
        }
    }
    return true;
}

function resolveAutoOnWaitAbilities(array $state, string $pid, array $member): array {
    foreach ($member['abilities'] ?? [] as $idx => $ab) {
        if (($ab['trigger'] ?? '') !== 'auto') {
            continue;
        }
        if (($ab['type'] ?? '') !== 'on_self_wait_draw_discard') {
            continue;
        }
        if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $idx)) {
            continue;
        }
        $phase = $state['phase'] ?? '';
        if (!in_array($phase, ['main_first', 'main_second'], true)) {
            continue;
        }
        $p = &$state['players'][$pid];
        $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
        $name = $member['name_en'] ?? $member['name'] ?? 'Member';
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] drew $drawn (Active → Wait).");
        markAbilityUsed($member, $idx);
        foreach ($p['stage'] as $slot => $mbr) {
            if ($mbr && ($mbr['instance_id'] ?? '') === ($member['instance_id'] ?? '')) {
                $p['stage'][$slot] = $member;
                break;
            }
        }
        if (intval($ab['discard'] ?? 0) > 0 && !empty($p['hand'])) {
            return startEffectDiscardHandPrompt(
                $state,
                $pid,
                $name,
                intval($ab['discard'] ?? 1)
            );
        }
    }
    return $state;
}

function resolveAutoYellAbilities(array $state, string $pid, array $yellCards): array {
    if (empty($yellCards) || !empty($state['pending_prompt'])) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $hasLive = countYellLiveCards($yellCards) > 0;
    $myCount = count($yellCards);
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    $oppCount = count($state['players'][$opp]['_pending_yell_wr'] ?? $state['players'][$opp]['yell_cards'] ?? []);

    foreach ($p['stage'] as $slot => &$member) {
        if (!$member) continue;
        foreach ($member['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') continue;
            if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $idx)) continue;
            $type = $ab['type'] ?? '';
            $mName = $member['name_en'] ?? $member['name'] ?? 'Member';

            if ($type === 'auto_yell_live_heart' && $hasLive) {
                $color = $ab['heart_color'] ?? 'green';
                $count = intval($ab['heart_count'] ?? 1);
                $state = initLiveModifiers($state);
                for ($i = 0; $i < $count; $i++) {
                    $state['live_modifiers'][$pid]['bonus_hearts'][] = $color;
                }
                markAbilityUsed($member, $idx);
                $p['stage'][$slot] = $member;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$mName] gained $count " . ucfirst($color) . " heart(s) from Yell (Live revealed).");
            } elseif ($type === 'auto_yell_draw_if_hand_max' && $hasLive
                && count($p['hand']) <= intval($ab['max_hand'] ?? 7)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                markAbilityUsed($member, $idx);
                $p['stage'][$slot] = $member;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$mName] drew $drawn (Yell revealed Live, hand ≤" . intval($ab['max_hand'] ?? 7) . ').');
            } elseif ($type === 'auto_yell_draw_if_fewer_cards' && $myCount < $oppCount) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                markAbilityUsed($member, $idx);
                $p['stage'][$slot] = $member;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$mName] drew $drawn (fewer Yell cards than opponent).");
            } elseif ($type === 'auto_yell_no_live_retry' && !$hasLive && $myCount >= 1) {
                $maxBlade = intval($ab['max_blade_hearts'] ?? 99);
                $bladeCnt = 0;
                foreach ($yellCards as $yc) {
                    if (!empty($yc['blade_hearts'])) {
                        $bladeCnt++;
                    }
                }
                if ($bladeCnt > $maxBlade) continue;
                markAbilityUsed($member, $idx);
                $p['stage'][$slot] = $member;
                $state = queueYellRetryOffer($state, $pid, $slot, $idx, $ab, $mName);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$mName] optional Yell retry (no Live revealed).");
            } elseif ($type === 'auto_yell_no_blade_heart' && !yellCardsHaveBladeHeart($yellCards)) {
                addBonusHeartsToModifier($state, $pid, $ab['hearts'] ?? []);
                markAbilityUsed($member, $idx);
                $p['stage'][$slot] = $member;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$mName] gained bonus heart(s) from Yell (no Blade hearts revealed).");
            } elseif ($type === 'auto_yell_mill_extra_yell') {
                $state = hsResolveHasunosoraEffect($state, $pid, $member, $ab, [
                    'yell_cards'     => $yellCards,
                    'member_slot'    => $slot,
                    'ability_index'  => $idx,
                ]);
                if (!empty($state['pending_prompt'])) {
                    markAbilityUsed($member, $idx);
                    $p['stage'][$slot] = $member;
                    return $state;
                }
            } elseif ($type === 'auto_yell_wr_members_extra_yell') {
                $state = sBp6ResolveEffect($state, $pid, $member, $ab, ['yell_cards' => $yellCards]);
                if (!empty($state['pending_prompt'])) {
                    markAbilityUsed($member, $idx);
                    $p['stage'][$slot] = $member;
                    return $state;
                }
                markAbilityUsed($member, $idx);
                $p['stage'][$slot] = $member;
            } elseif ($type === 'auto_yell_distinct_blade_heart_milestones') {
                $state = nBp5ResolveEffect($state, $pid, $member, $ab, ['yell_cards' => $yellCards]);
                markAbilityUsed($member, $idx);
                $p['stage'][$slot] = $member;
            } elseif ($type === 'auto_yell_hearts_per_yell_live') {
                $state = sSd1ResolveAutoYell($state, $pid, $member, $slot, $idx, $ab, $yellCards);
            } elseif ($type === 'auto_yell_blade_if_group_count') {
                $cnt = count(array_filter(
                    $yellCards,
                    fn($c) => ($c['card_type'] ?? '') === 'メンバー'
                        && cardMatchesGroup($c, $ab['group'] ?? '', 'member')
                ));
                if ($cnt >= intval($ab['min_count'] ?? 3)) {
                    $state = applyModifierEffect($state, $pid, [
                        'type'   => 'blade_bonus',
                        'amount' => intval($ab['amount'] ?? 1),
                    ]);
                    markAbilityUsed($member, $idx);
                    $p['stage'][$slot] = $member;
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$mName] gained +" . intval($ab['amount'] ?? 1) . ' Blade until Live ends (Yell).');
                }
            } elseif ($type === 'auto_yell_hearts_if_group_member_count') {
                $resolved = batch99ResolveAutoYell($state, $pid, $yellCards, $ab, $member, $idx, $slot);
                if ($resolved !== null) {
                    $state = $resolved;
                }
            }
        }
    }
    unset($member);

    foreach ($p['live_zone'] ?? [] as $li => &$live) {
        if (!$live || !isLiveTypeCard($live)) {
            continue;
        }
        mergeCardCatalogFields($live);
        foreach ($live['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') {
                continue;
            }
            if (!empty($ab['once_per_turn']) && isAbilityUsed($live, $idx)) {
                continue;
            }
            $type = $ab['type'] ?? '';
            if ($type !== 'auto_yell_mill_extra_yell') {
                continue;
            }
            $state = hsResolveHasunosoraEffect($state, $pid, $live, $ab, [
                'yell_cards'      => $yellCards,
                'live_zone_index' => $li,
                'ability_index'   => $idx,
            ]);
            if (!empty($state['pending_prompt'])) {
                markAbilityUsed($live, $idx);
                $p['live_zone'][$li] = $live;
                return $state;
            }
        }
    }
    unset($live);

    return $state;
}

function allStackedEnergyIdsOnStage(array $p): array {
    $ids = [];
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) {
            continue;
        }
        foreach (memberStackedEnergyIds($mbr) as $id) {
            if ($id !== '') {
                $ids[] = $id;
            }
        }
    }
    return array_values(array_unique($ids));
}

/** Move legacy stacked_energy_ids (still in zone) onto member.stacked_energy. */
function normalizeLegacyStackedEnergyZoneRefs(array &$p, array &$member): void {
    if (!empty($member['stacked_energy'])) {
        unset($member['stacked_energy_ids']);
        return;
    }
    $ids = array_values(array_filter($member['stacked_energy_ids'] ?? []));
    if (empty($ids)) {
        return;
    }
    $idSet = array_flip($ids);
    $stack = [];
    $p['energy_zone'] = array_values(array_filter(
        $p['energy_zone'],
        function ($e) use (&$stack, $idSet) {
            $id = $e['instance_id'] ?? '';
            if ($id !== '' && isset($idSet[$id])) {
                $e['active'] = false;
                $stack[] = $e;
                return false;
            }
            return true;
        }
    ));
    if (!empty($stack)) {
        $member['stacked_energy'] = $stack;
    }
    unset($member['stacked_energy_ids']);
}

function memberStackedEnergyIds(array $member): array {
    $ids = [];
    foreach ($member['stacked_energy'] ?? [] as $e) {
        $id = $e['instance_id'] ?? '';
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    if (!empty($ids)) {
        return $ids;
    }
    return array_values(array_filter($member['stacked_energy_ids'] ?? []));
}

function countMemberStackedEnergy(array $p, array &$member): int {
    return count(getMemberStackedEnergyCards($p, $member));
}

function getMemberStackedEnergyCards(array $p, array &$member): array {
    normalizeLegacyStackedEnergyZoneRefs($p, $member);
    return $member['stacked_energy'] ?? [];
}

function attachStackedEnergyCardsToMember(array &$member, array $cards): void {
    $cards = array_values(array_filter($cards));
    if (empty($cards)) {
        return;
    }
    if (!isset($member['stacked_energy'])) {
        $member['stacked_energy'] = [];
    }
    foreach ($cards as $e) {
        $e['active'] = false;
        $member['stacked_energy'][] = $e;
    }
    unset($member['stacked_energy_ids']);
}

/** @deprecated Baton carry-over — prefer attachStackedEnergyCardsToMember with card objects. */
function attachPaidEnergyToMember(array &$member, array $paidIds): void {
    $paidIds = array_values(array_filter($paidIds));
    if (empty($paidIds)) {
        return;
    }
    foreach ($paidIds as $id) {
        $member['stacked_energy_ids'][] = $id;
    }
    $member['stacked_energy_ids'] = array_values(array_unique($member['stacked_energy_ids'] ?? []));
    unset($member['stacked_energy']);
}

function takeActiveEnergyFromZone(array &$p, int $count): array {
    if ($count <= 0) {
        return [];
    }
    $taken = [];
    $zone = &$p['energy_zone'];
    for ($i = 0; $i < count($zone) && count($taken) < $count; ) {
        if (!($zone[$i]['active'] ?? false)) {
            $i++;
            continue;
        }
        $card = $zone[$i];
        array_splice($zone, $i, 1);
        $card['active'] = false;
        $taken[] = $card;
    }
    return $taken;
}

function returnMemberStackedEnergyToDeck(array &$p, array &$member, int $count): int {
    if ($count <= 0) {
        return 0;
    }
    normalizeLegacyStackedEnergyZoneRefs($p, $member);
    $stack = $member['stacked_energy'] ?? [];
    if (empty($stack)) {
        return 0;
    }
    $take = min($count, count($stack));
    $returned = array_splice($stack, 0, $take);
    if (empty($stack)) {
        unset($member['stacked_energy']);
    } else {
        $member['stacked_energy'] = $stack;
    }
    unset($member['stacked_energy_ids']);
    if (!empty($returned)) {
        foreach ($returned as &$e) {
            $e['active'] = false;
        }
        unset($e);
        $p['energy_deck'] = array_merge($p['energy_deck'], $returned);
    }
    return $take;
}

function countGroupMembersOnStage(array $p, string $group, string $filter = 'member'): int {
    $n = 0;
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        if (!cardMatchesGroup($mbr, $group, $filter)) continue;
        $n++;
    }
    return $n;
}

function countStageWaitMembers(array $p): int {
    $n = 0;
    foreach ($p['stage'] as $mbr) {
        if ($mbr && !($mbr['active'] ?? true)) $n++;
    }
    return $n;
}

function cardHasPrintedHearts(array $card): bool {
    mergeYellCardCatalogFields($card);
    foreach ($card['hearts'] ?? [] as $hg) {
        if (intval($hg['count'] ?? 0) > 0) return true;
    }
    return false;
}

/** Hydrate yell-reveal rows before draw/score icon counts or heart checks. */
function hydrateYellCardsForPerformance(array &$yellCards): void {
    foreach ($yellCards as &$yc) {
        mergeYellCardCatalogFields($yc);
    }
    unset($yc);
}

/** Apply continuous live_score_if_yell_has_hearts directly on live zone cards. */
function applyLiveScoreIfYellHasHeartsInZone(array &$zone, array $yellCards): void {
    hydrateYellCardsForPerformance($yellCards);
    $yellHasPrintedHearts = false;
    foreach ($yellCards as $yc) {
        if (cardHasPrintedHearts($yc)) {
            $yellHasPrintedHearts = true;
            break;
        }
    }
    if (!$yellHasPrintedHearts) {
        return;
    }
    foreach ($zone as &$lc) {
        if (!$lc || !isLiveTypeCard($lc)) {
            continue;
        }
        mergeCardCatalogFields($lc);
        foreach ($lc['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') {
                continue;
            }
            if (($ab['type'] ?? '') !== 'live_score_if_yell_has_hearts') {
                continue;
            }
            $lc['score'] = intval($lc['score'] ?? 0) + intval($ab['amount'] ?? 1);
        }
    }
    unset($lc);
}

function stageMembersWithStackedEnergy(array $p): array {
    $out = [];
    foreach ($p['stage'] as $slot => $mbr) {
        if (!$mbr) continue;
        if (countMemberStackedEnergy($p, $mbr) <= 0) continue;
        $out[] = ['slot' => $slot, 'member' => $mbr];
    }
    return $out;
}

function addBonusHeartsToMember(array &$member, array $heartsSpec, int $mult = 1): void {
    if (!isset($member['bonus_hearts'])) $member['bonus_hearts'] = [];
    foreach ($heartsSpec as $hg) {
        $color = $hg['color'] ?? 'any';
        $cnt = intval($hg['count'] ?? 1) * $mult;
        for ($i = 0; $i < $cnt; $i++) {
            $member['bonus_hearts'][] = $color;
        }
    }
}

function detachStackedEnergyForBatonTransfer(array &$member, ?array &$p = null): array {
    if ($p !== null) {
        normalizeLegacyStackedEnergyZoneRefs($p, $member);
    }
    $cards = $member['stacked_energy'] ?? [];
    unset($member['stacked_energy_ids'], $member['stacked_energy']);
    return $cards;
}

function returnStackedEnergyOnLeave(array &$p, array &$member): int {
    normalizeLegacyStackedEnergyZoneRefs($p, $member);
    $stack = $member['stacked_energy'] ?? [];
    if (empty($stack)) {
        unset($member['stacked_energy'], $member['stacked_energy_ids']);
        return 0;
    }
    $returned = 0;
    foreach ($stack as $e) {
        $e['active'] = false;
        $p['energy_zone'][] = $e;
        $returned++;
    }
    unset($member['stacked_energy'], $member['stacked_energy_ids']);
    return $returned;
}

function revealDeckTopLiveScore(array &$state, string $pid, array $source, int $amount): array {
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';
    refreshMainDeckFromWaitingRoom($state, $pid);
    if (empty($p['main_deck'])) return $state;
    $top = array_shift($p['main_deck']);
    $isLive = ($top['card_type'] ?? '') === 'ライブ';
    $p['waiting_room'][] = $top;
    if ($isLive) {
        bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $amount);
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $name . "] revealed Live; score +$amount.");
    } else {
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $name . '] revealed top of deck (not a Live card).');
    }
    refreshMainDeckFromWaitingRoom($state, $pid);
    return $state;
}

function lookRevealFilter(array &$p, int $look, string $filter, int $pick, string $group = '', string $nameContains = ''): int {
    $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
    $picked = 0;
    $rest = [];
    foreach ($top as $c) {
        $label = $c['name_en'] ?? $c['name'] ?? '';
        $nameOk = $nameContains === '' || str_contains($label, $nameContains);
        if ($picked < $pick && $nameOk && cardMatchesGroup($c, $group, $filter)) {
            $p['hand'][] = $c;
            $picked++;
        } else {
            $rest[] = $c;
        }
    }
    if (!empty($rest)) {
        $p['waiting_room'] = array_merge($p['waiting_room'], $rest);
    }
    return $picked;
}

function lookRevealGroup(array &$p, int $look, string $group, string $filter, int $pick): int {
    $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
    $picked = 0;
    $rest = [];
    foreach ($top as $c) {
        if ($picked < $pick && cardMatchesGroup($c, $group, $filter)) {
            $p['hand'][] = $c;
            $picked++;
        } else {
            $rest[] = $c;
        }
    }
    if (!empty($rest)) {
        $p['waiting_room'] = array_merge($p['waiting_room'], $rest);
    }
    return $picked;
}

function cardMatchesLookPick(array $card, array $cfg): bool {
    $nameContains = $cfg['name_contains'] ?? '';
    $label = $card['name_en'] ?? $card['name'] ?? '';
    if ($nameContains !== '' && !str_contains($label, $nameContains)) {
        return false;
    }
    if (!empty($cfg['min_cost']) && intval($card['cost'] ?? 0) < intval($cfg['min_cost'])) {
        return false;
    }
    if (isset($cfg['max_cost']) && intval($card['cost'] ?? 0) > intval($cfg['max_cost'])) {
        return false;
    }
    $heartColors = $cfg['heart_colors'] ?? [];
    if (!empty($heartColors)) {
        $cardColors = [];
        foreach ($card['hearts'] ?? [] as $hg) {
            $c = $hg['color'] ?? '';
            if ($c !== '') {
                $cardColors[$c] = true;
            }
        }
        $hasHeart = false;
        foreach ($heartColors as $c) {
            if (isset($cardColors[$c])) {
                $hasHeart = true;
                break;
            }
        }
        if (!$hasHeart) {
            return false;
        }
    }
    if (($cfg['filter'] ?? '') === 'live' && !empty($cfg['min_required_hearts'])) {
        if (liveRequiredHeartCount($card) < intval($cfg['min_required_hearts'])) {
            return false;
        }
    }
    $subunit = $cfg['subunit'] ?? '';
    if ($subunit !== '' && !cardMatchesSubunit($card, $subunit)) {
        return false;
    }
    $names = $cfg['names'] ?? [];
    if (!empty($names) && !cardMatchesNames($card, $names)) {
        return false;
    }
    return cardMatchesGroup($card, $cfg['group'] ?? '', $cfg['filter'] ?? '');
}

function lookPickIsOptional(array $cfg): bool {
    if (array_key_exists('optional_pick', $cfg)) {
        return !empty($cfg['optional_pick']);
    }
    $filter = $cfg['filter'] ?? '';
    $group = $cfg['group'] ?? '';
    $subunit = $cfg['subunit'] ?? '';
    return $filter !== '' || $group !== '' || $subunit !== '';
}

/** Minimum main-deck cards needed for a discard-then effect to be worth doing (0 = no deck requirement). */
function optionalThenDeckLookMin(array $then): int {
    $type = $then['type'] ?? '';
    if (in_array($type, ['look_reveal_filter', 'look_reveal_group', 'look_reveal_named'], true)) {
        return max(1, intval($then['look'] ?? 1));
    }
    if ($type === 'look_reveal_live_score_plus') {
        return 1;
    }
    if ($type === 'mill_then_add_wr_group') {
        return max(1, intval($then['mill'] ?? 1));
    }
    // energy_wait_from_deck pulls from energy_deck — handled in optionalDiscardThenViable.
    return 0;
}

/** True when optional discard's follow-up can still look/mill at least one deck card. */
function optionalDiscardThenViable(array $p, array $then): bool {
    if (($then['type'] ?? '') === 'energy_wait_from_deck') {
        $need = max(1, intval($then['count'] ?? 1));
        return count($p['energy_deck'] ?? []) >= $need;
    }
    $need = optionalThenDeckLookMin($then);
    if ($need <= 0) {
        return true;
    }
    $deck = count($p['main_deck'] ?? []);
    if ($deck >= 1) {
        return true;
    }
    // Empty deck would immediately refresh from Waiting Room before the look.
    return count($p['waiting_room'] ?? []) >= 1;
}

function applyLookPickHand(array &$p, array $looked, array $pickIds): void {
    $pickSet = array_flip(array_values(array_filter($pickIds)));
    $rest = [];
    foreach ($looked as $c) {
        $id = $c['instance_id'] ?? '';
        if ($id !== '' && isset($pickSet[$id])) {
            $p['hand'][] = $c;
            unset($pickSet[$id]);
        } else {
            $rest[] = $c;
        }
    }
    if (!empty($rest)) {
        $p['waiting_room'] = array_merge($p['waiting_room'], $rest);
    }
}

function beginLookRevealPick(array $state, string $pid, string $name, array &$p, array $cfg): array {
    $look = intval($cfg['look'] ?? 5);
    $pickCount = max(1, intval($cfg['pick'] ?? 1));
    $optional = lookPickIsOptional($cfg);

    refreshMainDeckFromWaitingRoom($state, $pid);
    $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
    if (empty($top)) {
        return addLog($state, $state['players'][$pid]['name'] .
            " — [$name] looked at deck top; no cards.");
    }
    if (empty($p['main_deck'])) {
        refreshMainDeckFromWaitingRoom($state, $pid);
    }

    $eligible = array_values(array_filter($top, fn($c) => cardMatchesLookPick($c, $cfg)));

    if (count($top) === 1 && !$optional) {
        applyLookPickHand($p, $top, [$top[0]['instance_id'] ?? '']);
        return addLog($state, $state['players'][$pid]['name'] .
            " — [$name] looked at 1 card; added 1 to hand.");
    }

    if ($optional && empty($eligible)) {
        $p['waiting_room'] = array_merge($p['waiting_room'], $top);
        return addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $name . '] looked at ' . count($top) . ' card(s); none eligible.');
    }

    if (!$optional && empty($eligible)) {
        $p['waiting_room'] = array_merge($p['waiting_room'], $top);
        return addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $name . '] looked at ' . count($top) . ' card(s); none eligible.');
    }

    $eligibleIds = array_values(array_filter(array_map(
        fn($c) => $c['instance_id'] ?? '',
        $eligible
    )));
    $destMode = $cfg['destination'] ?? 'hand';
    if ($destMode === 'hand_or_stage') {
        $promptText = $optional
            ? 'Choose 1 Member to add to your hand or play to an empty Stage area (or skip).'
            : 'Choose 1 Member to add to your hand or play to an empty Stage area.';
    } else {
        $promptText = $optional
            ? ($pickCount === 1
                ? 'Choose 1 card to add to your hand (or skip).'
                : "Choose up to $pickCount card(s) to add to your hand (or skip).")
            : ($pickCount === 1
                ? 'Choose 1 card to add to your hand.'
                : "Choose $pickCount card(s) to add to your hand.");
    }

    $state['surveil_stash'] = $top;
    $state['pending_prompt'] = [
        'type'         => 'pick_looked_deck_hand',
        'owner'        => $pid,
        'responder'    => $pid,
        'source_name'  => $name,
        'candidates'   => array_map('cardPromptSummary', $top),
        'eligible_ids' => $eligibleIds,
        'pick_count'   => $pickCount,
        'optional'     => $optional,
        'prompt'       => $promptText,
        'ability'      => $cfg,
    ];
    return addLog($state, $state['players'][$pid]['name'] .
        ' — [' . $name . '] looked at ' . count($top) . ' card(s) (choose).');
}

function memberHeartCount(array $member): int {
    $n = 0;
    foreach ($member['hearts'] ?? [] as $hg) {
        $n += intval($hg['count'] ?? 1);
    }
    return $n;
}

function memberHeartColorCount(array $member, string $color): int {
    if ($color === '') {
        return memberHeartCount($member);
    }
    $n = 0;
    foreach ($member['hearts'] ?? [] as $hg) {
        if (($hg['color'] ?? '') === $color) {
            $n += intval($hg['count'] ?? 1);
        }
    }
    return $n;
}

function liveRequiredHeartColorCount(array $live, string $color): int {
    if ($color === '') {
        return liveRequiredHeartCount($live);
    }
    $n = 0;
    foreach ($live['required_hearts'] ?? $live['hearts'] ?? [] as $hg) {
        if (($hg['color'] ?? '') === $color) {
            $n += intval($hg['count'] ?? 1);
        }
    }
    return $n;
}

function memberContinuousHeartCount(array $member, array $state, string $pid): int {
    $n = count($member['bonus_hearts'] ?? []);
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') continue;
        if (($ab['type'] ?? '') === 'heart_if_opp_wait_min') {
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (countOppWaitMembers($state, $opp) >= intval($ab['min_count'] ?? 2)) {
                $n += intval($ab['count'] ?? 1);
            }
        }
        if (($ab['type'] ?? '') === 'heart_per_opp_wait') {
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $n += countOppWaitMembers($state, $opp) * intval($ab['amount'] ?? 1);
        }
    }
    return $n;
}

function getContinuousWildHearts(array $state, string $pid): int {
    $n = 0;
    foreach ($state['players'][$pid]['stage'] ?? [] as $member) {
        if (!$member) continue;
        foreach ($member['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') continue;
            if (($ab['type'] ?? '') === 'wild_heart_blade_if_distinct_costs') {
                if (countDistinctCostsOnStage($state['players'][$pid])
                    >= intval($ab['min_count'] ?? 3)) {
                    $n += intval($ab['hearts'][0]['count'] ?? 1);
                }
            }
        }
    }
    return $n;
}

/** Hearts on stage members grouped by color for UI summaries. */
/** Hearts revealed on Yell cards (sideways blade hearts only) as color counts. */
function aggregateYellHeartsByColor(array $yellCards): array {
    $flat = [];
    foreach ($yellCards as $c) {
        foreach ($c['blade_hearts'] ?? [] as $bh) {
            if (is_string($bh)) {
                if ($bh === 'draw') {
                    continue;
                }
                $flat[] = normalizeHeartColor($bh);
            } elseif (($bh['type'] ?? '') === 'draw') {
                continue;
            } else {
                $flat[] = normalizeHeartColor((string)($bh['color'] ?? $bh['type'] ?? 'any'));
            }
        }
    }
    $map = [];
    foreach ($flat as $color) {
        $map[$color] = ($map[$color] ?? 0) + 1;
    }
    ksort($map);
    $out = [];
    foreach ($map as $color => $count) {
        $out[] = ['color' => $color, 'count' => $count];
    }
    return $out;
}

function mergeHeartColorCounts(array $a, array $b): array {
    $map = [];
    foreach (array_merge($a, $b) as $hg) {
        $color = normalizeHeartColor((string)($hg['color'] ?? 'any'));
        $map[$color] = ($map[$color] ?? 0) + max(1, intval($hg['count'] ?? 1));
    }
    ksort($map);
    $out = [];
    foreach ($map as $color => $count) {
        $out[] = ['color' => $color, 'count' => $count];
    }
    return $out;
}

function aggregateStageHeartsByColor(?array $stage): array {
    $map = [];
    if (!$stage) {
        return [];
    }
    foreach ($stage as $member) {
        if (!$member) {
            continue;
        }
        foreach ($member['hearts'] ?? [] as $hg) {
            $color = normalizeHeartColor((string)($hg['color'] ?? 'any'));
            $map[$color] = ($map[$color] ?? 0) + max(1, intval($hg['count'] ?? 1));
        }
    }
    ksort($map);
    $out = [];
    foreach ($map as $color => $count) {
        $out[] = ['color' => $color, 'count' => $count];
    }
    return $out;
}

/** Total Yell (Blade) from active stage members — mirrors performance draw count. */
function computeYellBladeTotal(array $state, string $pid): int {
    if (empty($state['players'][$pid])) {
        return 0;
    }
    $total = 0;
    foreach ($state['players'][$pid]['stage'] ?? [] as $slot => $member) {
        if (!$member || !memberContributesBladeToYell($member)) {
            continue;
        }
        $total += getMemberBlade($member, $state, $pid, (string)$slot);
        if (!empty($member['hearts_as_blade_color'])) {
            $total += memberHeartCount($member);
        }
    }
    // Player-wide Live modifiers apply once to Yell total, not per stage member.
    $state = initLiveModifiers($state);
    $total += getBothStagesBladeBonus($state) + getStageBladeBonus($state, $pid);
    return $total;
}

function liveRequiredHeartCount(array $live): int {
    $n = 0;
    foreach ($live['required_hearts'] ?? $live['hearts'] ?? [] as $hg) {
        $n += intval($hg['count'] ?? 1);
    }
    return $n;
}

function sumGroupStageHearts(array $p, string $group, string $color = ''): int {
    $n = 0;
    foreach ($p['stage'] as $m) {
        if (!$m || ($m['group'] ?? '') !== $group) {
            continue;
        }
        $n += $color === '' ? memberHeartCount($m) : memberHeartColorCount($m, $color);
    }
    return $n;
}

function cardMeetsHeartThreshold(array $c, int $minMemberHearts, int $minLiveRequired, string $heartColor = ''): bool {
    if (($c['card_type'] ?? '') === 'メンバー') {
        return ($heartColor === ''
            ? memberHeartCount($c)
            : memberHeartColorCount($c, $heartColor)) >= $minMemberHearts;
    }
    if (($c['card_type'] ?? '') === 'ライブ') {
        return ($heartColor === ''
            ? liveRequiredHeartCount($c)
            : liveRequiredHeartColorCount($c, $heartColor)) >= $minLiveRequired;
    }
    return false;
}

function cardHasLiveStartAbility(array $card): bool {
    foreach ($card['abilities'] ?? [] as $ab) {
        $t = $ab['trigger'] ?? '';
        if ($t === 'live_start' || $t === 'on_enter_or_live_start') {
            return true;
        }
    }
    return false;
}

function listWrMemberEnterAbilities(array $member): array {
    $choices = [];
    foreach ($member['abilities'] ?? [] as $idx => $ab) {
        if (($ab['trigger'] ?? '') !== 'on_enter') {
            continue;
        }
        $choices[] = ['index' => $idx, 'ability' => $ab];
    }
    return $choices;
}

function countCombinedSuccessLives(array $state): int {
    return count($state['players']['p1']['success_lives'] ?? [])
        + count($state['players']['p2']['success_lives'] ?? []);
}

function stageHasGroupMemberMinHearts(array $p, string $group, int $minHearts): bool {
    foreach ($p['stage'] as $m) {
        if (!$m || ($m['group'] ?? '') !== $group) continue;
        if (memberHeartCount($m) >= $minHearts) return true;
    }
    return false;
}

function stageHasGroupMemberMinBlades(array $p, string $group, int $minBlades): bool {
    foreach ($p['stage'] as $m) {
        if (!$m || ($m['group'] ?? '') !== $group) continue;
        if (intval($m['blade'] ?? 0) >= $minBlades) return true;
    }
    return false;
}

function positionChangeOffCenter(array &$p, string $instanceId): bool {
    if (($p['stage']['center']['instance_id'] ?? '') !== $instanceId) return false;
    $member = $p['stage']['center'];
    foreach (['left', 'right'] as $toSlot) {
        $other = $p['stage'][$toSlot];
        $p['stage'][$toSlot] = $member;
        $p['stage']['center'] = $other;
        return true;
    }
    return false;
}

function waitOpponentActiveMembers(array &$state, string $oppId, int $count, ?string $effectSourcePid = null): int {
    $waited = 0;
    foreach ($state['players'][$oppId]['stage'] as $slot => &$mbr) {
        if (!$mbr) continue;
        if (memberIsInWait($mbr)) continue;
        $snap = memberSnapshot($mbr);
        waitMember($mbr, $state);
        if ($effectSourcePid) {
            $state = resolveAutomaticOpponentWaitEffects($state, $effectSourcePid, $snap);
        }
        $waited++;
        if ($waited >= $count) break;
    }
    unset($mbr);
    return $waited;
}

/** Yell score icons / yell-Live counts only apply during the current Performance round. */
function isLiveScoreYellContext(array $state): bool {
    if (function_exists('isInPerformancePhase') && isInPerformancePhase($state)) {
        return true;
    }
    if (in_array($state['phase'] ?? '', [
        'live_performance_first',
        'live_performance_second',
        'live_success_effects',
        'live_judge',
    ], true)) {
        return true;
    }
    // Main-phase carryover: judge bonuses still apply until the next Performance begins.
    if (in_array($state['phase'] ?? '', [
        'main_first', 'main_second', 'active_first', 'active_second',
    ], true)) {
        return !empty($state['_live_round_success_snapshot'])
            || !empty($state['_yell_reveal_snapshot'])
            || !empty($state['_yell_blade_snapshot']);
    }
    return false;
}

function bumpLiveCardScore(array &$state, string $pid, string $instanceId, int $amount): bool {
    foreach ($state['players'][$pid]['live_zone'] as &$lc) {
        if ($lc && ($lc['instance_id'] ?? '') === $instanceId) {
            $lc['score'] = intval($lc['score'] ?? 0) + $amount;
            unset($lc);
            return true;
        }
    }
    unset($lc);
    return false;
}

function reduceHeartRequirements(array $required, int $reduce): array {
    if ($reduce <= 0) return $required;
    $req = array_map(fn($h) => [
        'color' => $h['color'] ?? 'any',
        'count' => intval($h['count'] ?? 1),
    ], $required);
    while ($reduce > 0 && !empty($req)) {
        $i = count($req) - 1;
        $c = $req[$i]['count'];
        if ($c <= $reduce) {
            $reduce -= $c;
            array_pop($req);
        } else {
            $req[$i]['count'] = $c - $reduce;
            $reduce = 0;
        }
    }
    return array_values($req);
}

function reduceHeartRequirementsByColor(array $required, string $color, int $reduce): array {
    if ($reduce <= 0) return $required;
    $req = array_map(fn($h) => [
        'color' => $h['color'] ?? 'any',
        'count' => intval($h['count'] ?? 1),
    ], $required);
    foreach ($req as &$h) {
        if ($reduce <= 0) break;
        if (($h['color'] ?? '') !== $color) continue;
        $take = min($h['count'], $reduce);
        $h['count'] -= $take;
        $reduce -= $take;
    }
    unset($h);
    return array_values(array_filter($req, fn($h) => ($h['count'] ?? 0) > 0));
}

function increaseHeartRequirementsByColor(array $required, string $color, int $amount): array {
    if ($amount <= 0) {
        return $required;
    }
    $req = array_map(fn($h) => [
        'color' => $h['color'] ?? 'any',
        'count' => intval($h['count'] ?? 1),
    ], $required);
    foreach ($req as &$h) {
        if (($h['color'] ?? '') === $color) {
            $h['count'] += $amount;
            unset($h);
            return $req;
        }
    }
    unset($h);
    $req[] = ['color' => $color, 'count' => $amount];
    return $req;
}

function applyLiveHeartReductions(array $required, array $liveCard): array {
    $req = $required;
    $generic = intval($liveCard['hearts_reduction'] ?? 0);
    if ($generic > 0) {
        $req = reduceHeartRequirements($req, $generic);
    }
    foreach ($liveCard['hearts_color_reduction'] ?? [] as $color => $n) {
        $req = reduceHeartRequirementsByColor($req, $color, intval($n));
    }
    foreach ($liveCard['hearts_color_increase'] ?? [] as $color => $n) {
        $req = increaseHeartRequirementsByColor($req, (string)$color, intval($n));
    }
    return $req;
}

function bumpLiveCardColorReduction(array &$state, string $pid, string $instanceId, string $color, int $amount): void {
    foreach ($state['players'][$pid]['live_zone'] as &$lc) {
        if ($lc && ($lc['instance_id'] ?? '') === $instanceId) {
            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                $lc['hearts_color_reduction'] = [];
            }
            $lc['hearts_color_reduction'][$color] = intval($lc['hearts_color_reduction'][$color] ?? 0) + $amount;
            unset($lc);
            return;
        }
    }
    unset($lc);
}

function cardEffectiveSubunits(array $card): array {
    $subs = [];
    if (!empty($card['subunit'])) {
        $subs[] = $card['subunit'];
    }
    foreach ($card['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') continue;
        if (($ab['type'] ?? '') !== 'treat_as_subunits') continue;
        foreach ($ab['subunits'] ?? [] as $s) {
            if ($s !== '') $subs[] = $s;
        }
    }
    return array_values(array_unique($subs));
}

function cardMatchesSubunit(array $card, string $subunit): bool {
    if ($subunit === '') return true;
    foreach (cardEffectiveSubunits($card) as $s) {
        if (subunitNamesMatch($s, $subunit)) return true;
    }
    return false;
}

function countWrSubunitFilter(array $p, string $subunit, string $filter = 'live'): int {
    return count(array_filter(
        $p['waiting_room'] ?? [],
        fn($c) => cardMatchesSubunit($c, $subunit)
            && ($filter === '' || cardMatchesGroup($c, '', $filter))
    ));
}

function countDistinctNamedGroupOnStage(array $p, string $group, string $filter = 'member'): int {
    $names = [];
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        if (!cardMatchesGroup($mbr, $group, $filter)) continue;
        $n = $mbr['name_en'] ?? $mbr['name'] ?? '';
        if ($n !== '') $names[$n] = true;
    }
    return count($names);
}

function countDistinctCostsOnStage(array $p): int {
    $costs = [];
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        $costs[intval($mbr['cost'] ?? 0)] = true;
    }
    return count($costs);
}

function countDistinctNamesAndCostsOnStage(array $p): int {
    $keys = [];
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        $name = $mbr['name_en'] ?? $mbr['name'] ?? '';
        $keys[$name . '|' . intval($mbr['cost'] ?? 0)] = true;
    }
    return count($keys);
}

function countStageGroupMinCost(array $p, string $group, int $minCost): int {
    $n = 0;
    foreach ($p['stage'] as $mbr) {
        if (!$mbr || ($mbr['group'] ?? '') !== $group) continue;
        if (intval($mbr['cost'] ?? 0) >= $minCost) $n++;
    }
    return $n;
}

function countDistinctSubunitsOnStage(array $p, string $requireGroup = ''): int {
    $subs = [];
    $hasRequiredGroup = $requireGroup === '';
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        if ($requireGroup !== '' && ($mbr['group'] ?? '') === $requireGroup) {
            $hasRequiredGroup = true;
        }
        $su = $mbr['subunit'] ?? '';
        if ($su !== '') $subs[$su] = true;
    }
    if ($requireGroup !== '' && !$hasRequiredGroup) return 0;
    return count($subs);
}

function stageHasSubunitMinCost(array $p, string $subunit, int $minCost): bool {
    foreach ($p['stage'] as $mbr) {
        if (!$mbr || !cardMatchesSubunit($mbr, $subunit)) continue;
        if (intval($mbr['cost'] ?? 0) >= $minCost) return true;
    }
    return false;
}

function handHasSubunitCard(array $p, string $subunit): bool {
    foreach ($p['hand'] ?? [] as $c) {
        if (cardMatchesSubunit($c, $subunit)) return true;
    }
    return false;
}

function wrLiveNameContainsNeedle(string $wrName, string $needle): bool {
    return $needle !== '' && str_contains($wrName, $needle);
}

function addWrLiveNameSuperset(array &$p, string $needle): int {
    foreach ($p['waiting_room'] as $i => $c) {
        if (($c['card_type'] ?? '') !== 'ライブ') continue;
        $label = $c['name_en'] ?? $c['name'] ?? '';
        if (wrLiveNameContainsNeedle($label, $needle)) {
            $p['hand'][] = $c;
            array_splice($p['waiting_room'], $i, 1);
            return 1;
        }
    }
    return 0;
}

function getEffectiveMemberCost(array $member): int {
    if (isset($member['live_cost_override'])) {
        return intval($member['live_cost_override']);
    }
    return intval($member['cost'] ?? 0);
}

function countOtherLiveZoneGroup(array $p, string $group, string $excludeId = ''): int {
    $n = 0;
    foreach ($p['live_zone'] ?? [] as $lc) {
        if (!$lc) continue;
        if ($excludeId !== '' && ($lc['instance_id'] ?? '') === $excludeId) continue;
        if (($lc['group'] ?? '') === $group) $n++;
    }
    return $n;
}

function countBatonEnteredGroupThisTurn(array $p, string $group, int $turn): int {
    $n = 0;
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        if (($mbr['group'] ?? '') !== $group) continue;
        if (!($mbr['entered_via_baton'] ?? false)) continue;
        if (intval($mbr['entered_turn'] ?? 0) !== $turn) continue;
        $n++;
    }
    return $n;
}

function groupMemberEnteredThisTurn(array $p, string $group, int $turn): bool {
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        if (($mbr['group'] ?? '') !== $group) continue;
        if (intval($mbr['entered_turn'] ?? 0) === $turn) return true;
    }
    return false;
}

function stageMemberEnteredThisTurn(?array $member, array $state): bool {
    if (!$member) return false;
    return intval($member['entered_turn'] ?? 0) === intval($state['turn'] ?? 1);
}

function stageNamedSlotsMatch(array $p, array $slots): bool {
    foreach ($slots as $slot => $names) {
        $mbr = $p['stage'][$slot] ?? null;
        if (!$mbr || !cardMatchesNames($mbr, $names)) return false;
    }
    return true;
}

function addLiveFromWrToZone(array &$p, array $cfg): int {
    if (liveZoneCount($p['live_zone'] ?? []) >= 3) return 0;
    $picked = null;
    $rest = [];
    foreach ($p['waiting_room'] as $c) {
        if (!$picked && cardMatchesWrPick($c, $cfg)) {
            $picked = $c;
        } else {
            $rest[] = $c;
        }
    }
    if (!$picked) return 0;
    $slot = liveZoneFirstEmptySlot($p['live_zone'] ?? []);
    if ($slot < 0) return 0;
    $p['waiting_room'] = $rest;
    $picked['revealed'] = true;
    $picked['live_slot'] = $slot;
    $p['live_zone'][] = $picked;
    return 1;
}

function findActivatedAbilitySource(array &$p, string $instanceId): ?array {
    foreach ($p['stage'] as $slot => $m) {
        if ($m && ($m['instance_id'] ?? '') === $instanceId) {
            return ['card' => $m, 'slot' => $slot, 'zone' => 'stage'];
        }
    }
    foreach ($p['hand'] as $i => $c) {
        if (($c['instance_id'] ?? '') === $instanceId) {
            return ['card' => $c, 'hand_index' => $i, 'zone' => 'hand'];
        }
    }
    foreach ($p['waiting_room'] as $i => $c) {
        if (($c['instance_id'] ?? '') === $instanceId) {
            return ['card' => $c, 'wr_index' => $i, 'zone' => 'waiting_room'];
        }
    }
    return null;
}

function countLiveZoneGroup(array $liveZone, string $group): int {
    return count(array_filter(
        $liveZone,
        fn($c) => $c && isLiveTypeCard($c) && ($c['group'] ?? '') === $group
    ));
}

function countBothStagesMembers(array $state): int {
    $n = 0;
    foreach (['p1', 'p2'] as $id) {
        foreach ($state['players'][$id]['stage'] as $m) {
            if ($m) $n++;
        }
    }
    return $n;
}

function countStageHearts(array $p): int {
    $n = 0;
    foreach ($p['stage'] as $m) {
        if (!$m) continue;
        foreach ($m['hearts'] ?? [] as $hg) {
            $n += intval($hg['count'] ?? 1);
        }
        $n += count($m['bonus_hearts'] ?? []);
    }
    return $n;
}

function totalStageBlade(array $p): int {
    $n = 0;
    foreach ($p['stage'] as $m) {
        if ($m && ($m['active'] ?? true)) {
            $n += intval($m['blade'] ?? 0);
        }
    }
    return $n;
}

function applyMemberBladeBonus(array &$state, string $pid, array $effect): int {
    $amount = intval($effect['amount'] ?? 0);
    $max = intval($effect['max_members'] ?? 1);
    $group = $effect['group'] ?? '';
    $excludeId = $effect['exclude_source_id'] ?? '';
    $slotFilter = $effect['slot'] ?? '';
    $applied = 0;
    foreach ($state['players'][$pid]['stage'] as $slot => &$mbr) {
        if (!$mbr || $applied >= $max) continue;
        if ($group !== '' && ($mbr['group'] ?? '') !== $group) continue;
        if ($excludeId !== '' && ($mbr['instance_id'] ?? '') === $excludeId) continue;
        if ($slotFilter !== '' && $slot !== $slotFilter) continue;
        $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $amount;
        if (!empty($effect['hearts'])) {
            addBonusHeartsToMember($mbr, $effect['hearts'], 1);
        }
        $applied++;
    }
    unset($mbr);
    return $applied;
}

function applyCenterGroupBladeBonus(array &$state, string $pid, string $group, int $amount): bool {
    $mbr = $state['players'][$pid]['stage']['center'] ?? null;
    if (!$mbr || ($mbr['group'] ?? '') !== $group) return false;
    $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $amount;
    $state['players'][$pid]['stage']['center'] = $mbr;
    return true;
}

function applyOtherMemberHeartBonus(array &$state, string $pid, string $excludeId, string $color, int $max = 1): int {
    $applied = 0;
    foreach ($state['players'][$pid]['stage'] as &$mbr) {
        if (!$mbr || ($mbr['instance_id'] ?? '') === $excludeId) continue;
        if (!isset($mbr['bonus_hearts'])) $mbr['bonus_hearts'] = [];
        $mbr['bonus_hearts'][] = $color;
        $applied++;
        if ($applied >= $max) break;
    }
    unset($mbr);
    return $applied;
}

function stageAllMembersInGroup(array $p, string $group): bool {
    $found = false;
    foreach ($p['stage'] as $m) {
        if (!$m) continue;
        $found = true;
        if (($m['group'] ?? '') !== $group) return false;
    }
    return $found;
}

function stageAllMembersInSubunit(array $p, string $subunit): bool {
    $found = false;
    foreach ($p['stage'] as $m) {
        if (!$m) continue;
        $found = true;
        if (($m['subunit'] ?? '') !== $subunit) return false;
    }
    return $found;
}

function cardMatchesRevealFilter(array $card, array $cfg): bool {
    $filter = $cfg['filter'] ?? '';
    if ($filter === 'live') {
        return ($card['card_type'] ?? '') === 'ライブ';
    }
    if ($filter === 'member') {
        return ($card['card_type'] ?? '') === 'メンバー'
            && intval($card['cost'] ?? 0) >= intval($cfg['min_cost'] ?? 0);
    }
    return cardMatchesGroup($card, '', $filter);
}

function revealFromDeckUntil(array &$p, array $cfg, ?array &$state = null, ?string $pid = null): ?array {
    $revealed = [];
    $found = null;
    while (true) {
        if (empty($p['main_deck'])) {
            if ($state === null || $pid === null || refreshMainDeckFromWaitingRoom($state, $pid) <= 0) {
                break;
            }
        }
        if (empty($p['main_deck'])) {
            break;
        }
        $c = array_shift($p['main_deck']);
        $revealed[] = $c;
        if (cardMatchesRevealFilter($c, $cfg)) {
            $found = $c;
            break;
        }
    }
    foreach ($revealed as $c) {
        if ($found && ($c['instance_id'] ?? '') === ($found['instance_id'] ?? '')) {
            $p['hand'][] = $c;
        } else {
            $p['waiting_room'][] = $c;
        }
    }
    return $found;
}

function getSuccessLiveCenterBladeBonus(array $state, string $pid, array $member, string $slot): int {
    if ($slot !== 'center') return 0;
    $bonus = 0;
    foreach ($state['players'][$pid]['success_lives'] ?? [] as $sl) {
        foreach ($sl['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') continue;
            if (($ab['type'] ?? '') === 'center_group_blade_while_in_success') {
                if (($member['group'] ?? '') === ($ab['group'] ?? 'μ\'s')) {
                    $bonus += intval($ab['amount'] ?? 1);
                }
            }
        }
    }
    return $bonus;
}

function grantHeartToFirstGroupMember(array &$state, string $pid, string $group, string $color): bool {
    foreach ($state['players'][$pid]['stage'] as &$mbr) {
        if ($mbr && ($mbr['group'] ?? '') === $group) {
            if (!isset($mbr['bonus_hearts'])) $mbr['bonus_hearts'] = [];
            $mbr['bonus_hearts'][] = $color;
            unset($mbr);
            return true;
        }
    }
    unset($mbr);
    return false;
}

function sumStageMemberCost(array $p, ?array $state = null, ?string $pid = null): int {
    $n = 0;
    foreach ($p['stage'] as $m) {
        if (!$m) continue;
        if ($state !== null && $pid !== null) {
            $n += getEffectiveStageMemberCost($state, $pid, $m);
        } else {
            $n += intval($m['cost'] ?? 0);
        }
    }
    return $n;
}

function sumSuccessLiveScores(array $p, ?array $state = null, ?string $pid = null): int {
    $sum = 0;
    foreach ($p['success_lives'] ?? [] as $c) {
        $score = intval($c['score'] ?? 0);
        if ($state !== null && $pid !== null) {
            foreach ($c['abilities'] ?? [] as $ab) {
                if (($ab['trigger'] ?? '') !== 'continuous') continue;
                if (($ab['type'] ?? '') === 'success_self_score_bonus') {
                    $hasGroup = false;
                    foreach ($state['players'][$pid]['stage'] as $m) {
                        if ($m && ($m['group'] ?? '') === ($ab['group'] ?? 'μ\'s')) {
                            $hasGroup = true;
                            break;
                        }
                    }
                    if ($hasGroup) $score += intval($ab['amount'] ?? 0);
                }
            }
        }
        $sum += $score;
    }
    return $sum;
}

function liveZoneHasPlainLive(array $liveZone): bool {
    foreach ($liveZone as $c) {
        if (!$c || ($c['card_type'] ?? '') !== 'ライブ') continue;
        $hasLiveStart = false;
        $hasLiveSuccess = false;
        foreach ($c['abilities'] ?? [] as $ab) {
            $t = $ab['trigger'] ?? '';
            if ($t === 'live_start' || $t === 'on_enter_or_live_start') $hasLiveStart = true;
            if ($t === 'live_success') $hasLiveSuccess = true;
        }
        if (!$hasLiveStart && !$hasLiveSuccess) return true;
    }
    return false;
}

function appendContinuousHeartsFromSpec(array &$hearts, array $spec): void {
    foreach ($spec as $h) {
        for ($i = 0; $i < intval($h['count'] ?? 1); $i++) {
            $hearts[] = $h['color'] ?? 'any';
        }
    }
}

/** Per-member continuous hearts for Performance (UI animation + pool). */
function collectContinuousPerformanceHeartGrants(array $state, string $pid): array {
    $grants = [];
    foreach ($state['players'][$pid]['stage'] as $slot => $member) {
        if (!$member) {
            continue;
        }
        $memberHearts = [];
        foreach ($member['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') {
                continue;
            }
            if (($ab['type'] ?? '') === 'hearts_if_plain_live_in_zone') {
                if (liveZoneHasPlainLive($state['players'][$pid]['live_zone'])) {
                    appendContinuousHeartsFromSpec($memberHearts, $ab['hearts'] ?? []);
                }
            }
            if (($ab['type'] ?? '') === 'hearts_if_combined_energy') {
                if (countCombinedEnergy($state) >= intval($ab['min_total'] ?? 15)) {
                    appendContinuousHeartsFromSpec($memberHearts, $ab['hearts'] ?? []);
                }
            }
            if (($ab['type'] ?? '') === 'hearts_if_live_zone_six_colors') {
                $required = $ab['colors'] ?? ['pink', 'red', 'yellow', 'green', 'blue', 'purple'];
                $found = [];
                foreach ($state['players'][$pid]['live_zone'] ?? [] as $lc) {
                    if (!$lc || ($lc['card_type'] ?? '') !== 'ライブ') {
                        continue;
                    }
                    foreach ($lc['required_hearts'] ?? $lc['hearts'] ?? [] as $h) {
                        $found[$h['color'] ?? ''] = true;
                    }
                }
                $ok = true;
                foreach ($required as $col) {
                    if (empty($found[$col])) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    appendContinuousHeartsFromSpec($memberHearts, $ab['hearts'] ?? []);
                }
            }
            if (($ab['type'] ?? '') === 'hearts_if_center_highest_cost') {
                if (centerMemberHasHighestCost($state['players'][$pid], $member, $slot)) {
                    appendContinuousHeartsFromSpec($memberHearts, $ab['hearts'] ?? []);
                }
            }
            if (($ab['type'] ?? '') === 'hearts_if_min_energy') {
                if (countEnergyInZone($state['players'][$pid]) >= intval($ab['min_energy'] ?? 10)) {
                    appendContinuousHeartsFromSpec($memberHearts, $ab['hearts'] ?? []);
                }
            }
            if (($ab['type'] ?? '') === 'hearts_if_more_energy_than_opp') {
                $opp = ($pid === 'p1') ? 'p2' : 'p1';
                if (countEnergyInZone($state['players'][$pid])
                    > countEnergyInZone($state['players'][$opp])) {
                    appendContinuousHeartsFromSpec($memberHearts, $ab['hearts'] ?? []);
                }
            }
            if (($ab['type'] ?? '') === 'blade_if_either_stage_cost_min' && !empty($ab['hearts'])) {
                if (eitherStageHasMemberMinCost($state, intval($ab['min_cost'] ?? 13))) {
                    appendContinuousHeartsFromSpec($memberHearts, $ab['hearts']);
                }
            }
            if (($ab['type'] ?? '') === 'heart_per_opp_wait') {
                $opp = ($pid === 'p1') ? 'p2' : 'p1';
                $color = $ab['color'] ?? 'any';
                $n = countOppWaitMembers($state, $opp) * intval($ab['amount'] ?? 1);
                for ($i = 0; $i < $n; $i++) {
                    $memberHearts[] = $color;
                }
            }
            $memberHearts = plMuseGapApplyContinuousHearts($state, $pid, $member, $ab, $memberHearts);
            $memberHearts = spBp5ApplyContinuousHearts($state, $pid, $member, $slot, $memberHearts);
            $memberHearts = spBp2ApplyContinuousHearts($state, $pid, $member, $ab, $memberHearts);
            $memberHearts = batch99ApplyContinuousHearts($state, $pid, $member, $slot, $memberHearts);
            if (($ab['type'] ?? '') === 'blade_if_exact_stage_members' && !empty($ab['hearts'])) {
                if (countStageMembers($state['players'][$pid]) === intval($ab['count'] ?? 2)) {
                    appendContinuousHeartsFromSpec($memberHearts, $ab['hearts']);
                }
            }
            if (($ab['type'] ?? '') === 'blade_bonus_if_combined_stage_members' && !empty($ab['hearts'])) {
                if (plSpSd2CountCombinedStageMembers($state) >= intval($ab['min_count'] ?? 6)) {
                    appendContinuousHeartsFromSpec($memberHearts, $ab['hearts']);
                }
            }
            if (($ab['type'] ?? '') === 'blade_if_live_zone_group_live' && !empty($ab['hearts'])) {
                $grp = $ab['group'] ?? 'Nijigasaki';
                if (nijiCountLiveZoneCards($state['players'][$pid]) >= intval($ab['min_count'] ?? 3)
                    && nijiLiveZoneHasGroupLive($state['players'][$pid], $grp)) {
                    appendContinuousHeartsFromSpec($memberHearts, $ab['hearts']);
                }
            }
        }
        if (empty($memberHearts)) {
            continue;
        }
        $grants[] = [
            'instance_id' => $member['instance_id'] ?? '',
            'slot'        => (string)$slot,
            'who'         => stageMemberWhoLabel($member, (string)$slot),
            'hearts'      => $memberHearts,
        ];
    }
    return $grants;
}

function aggregateFlatHeartColors(array $hearts): array {
    $map = [];
    foreach ($hearts as $color) {
        $key = (string)($color ?? 'any');
        $map[$key] = ($map[$key] ?? 0) + 1;
    }
    ksort($map);
    $out = [];
    foreach ($map as $color => $count) {
        $out[] = ['color' => $color, 'count' => $count];
    }
    return $out;
}

function getContinuousPerformanceHearts(array $state, string $pid): array {
    $hearts = [];
    foreach (collectContinuousPerformanceHeartGrants($state, $pid) as $grant) {
        $hearts = array_merge($hearts, $grant['hearts']);
    }
    return $hearts;
}

// ─────────────────────────────────────────────
// [Live Success] abilities (after a successful Performance)
// ─────────────────────────────────────────────

function resolveLiveSuccessAbilities(
    array $state,
    string $pid,
    array $successCards,
    int $excessHearts,
    array $excessColors = [],
    array $yellCards = []
): array {
    foreach ($successCards as $lc) {
        foreach ($lc['granted_live_success_effects'] ?? [] as $eff) {
            if (($eff['type'] ?? '') !== 'draw_cards') {
                continue;
            }
            $drawn = drawCardsForPlayer($state, $pid, intval($eff['draw'] ?? 1));
            if ($drawn > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . ($lc['name_en'] ?? $lc['name'] ?? 'Live') .
                    "] drew $drawn (granted Live Success).");
            }
        }
        $abilities = getAbilitiesByTrigger($lc, 'live_success');
        if (empty($abilities)) continue;
        $state = logAbilityChain($state, $pid, $lc, 'live_success');
        foreach ($abilities as $ab) {
            $state = resolveAbilityEffect($state, $pid, $lc, $ab, [
                'excess_hearts'        => $excessHearts,
                'excess_heart_colors'  => $excessColors,
                'phase'                => 'live_success',
                'yell_cards'           => $yellCards,
            ]);
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
        }
    }
    foreach ($state['players'][$pid]['stage'] as $member) {
        if (!$member) {
            continue;
        }
        $abilities = spBp2MemberLiveSuccessAbilities($member);
        if (empty($abilities)) {
            continue;
        }
        $state = logAbilityChain($state, $pid, $member, 'live_success');
        foreach ($abilities as $ab) {
            $state = resolveAbilityEffect($state, $pid, $member, $ab, [
                'excess_hearts'        => $excessHearts,
                'excess_heart_colors'  => $excessColors,
                'phase'                => 'live_success',
                'yell_cards'           => $yellCards,
            ]);
            $state = nBp5NotifyMemberAbilityResolved($state, $pid, $member, 'live_success');
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
        }
    }
    $state = spBp2OnLiveSuccess($state, $pid);
    if (!empty($state['pending_prompt'])) {
        return $state;
    }
    return $state;
}

function flushPendingYellToWr(array $state, string $pid): array {
    $p = &$state['players'][$pid];
    $pending = $p['_pending_yell_wr'] ?? [];
    if (!empty($pending)) {
        $p['waiting_room'] = array_merge($p['waiting_room'], $pending);
        unset($p['_pending_yell_wr']);
    }
    return $state;
}

function finishLiveSuccessEffects(array $state): array {
    if (!empty($state['pending_prompt'])) {
        $state['phase'] = 'live_success_effects';
        return $state;
    }
    $pid = $state['_performance_continue'] ?? null;
    if ($pid && empty($GLOBALS['TUT_PERF_MANUAL_PHASES'])) {
        $state = flushPendingYellToWr($state, $pid);
        unset($state['_performance_continue']);
        if (!empty($state['_perf_yell_both_done'])) {
            $state['_perf_hearts_resolved'][$pid] = true;
            return finishYellRetryAndHearts($state);
        }
        $state = continuePerformancePhase($state, $pid);
    }
    return $state;
}

function cardMatchesNames(array $card, array $names): bool {
    $label = $card['name_en'] ?? $card['name'] ?? '';
    foreach ($names as $n) {
        if ($label === $n || str_contains($label, $n)) return true;
        if (str_contains($label, '&') || str_contains($label, '＆')) {
            foreach (preg_split('/[&＆]/u', $label) as $part) {
                if (trim($part) === $n) return true;
            }
        }
    }
    return false;
}

function getEffectiveHandCost(array $state, string $pid, array $card): int {
    $base = intval($card['cost'] ?? 0);
    if (!cardHasAbilities($card)) {
        $base = sBp5ApplyHandCostReduction($state, $pid, $card, $base);
        return spBp5ApplyHandCostReduction($state, $pid, $card, $base);
    }
    $p = $state['players'][$pid] ?? [];
    $base = hsApplyHandCostPerStageSubunit($card, $p);
    $hand = $state['players'][$pid]['hand'] ?? [];
    $others = count(array_filter($hand, fn($c) => ($c['instance_id'] ?? '') !== ($card['instance_id'] ?? '')));
    $p = $state['players'][$pid] ?? [];
    foreach ($card['abilities'] as $ab) {
        if (($ab['trigger'] ?? '') === 'continuous' && ($ab['type'] ?? '') === 'hand_cost_reduction') {
            $base = max(0, $base - $others * intval($ab['per_other_card'] ?? 1));
        }
        if (($ab['trigger'] ?? '') === 'continuous'
            && ($ab['type'] ?? '') === 'hand_cost_reduction_if_success_subunit') {
            if (successZoneHasSubunit($p, $ab['subunit'] ?? '')) {
                $base = max(0, $base - intval($ab['amount'] ?? 0));
            }
        }
        if (($ab['trigger'] ?? '') === 'continuous'
            && ($ab['type'] ?? '') === 'hand_cost_reduction_if_wait_group') {
            $group = $ab['group'] ?? 'Nijigasaki';
            foreach ($p['stage'] as $w) {
                if ($w && ($w['group'] ?? '') === $group && !($w['active'] ?? true)) {
                    $base = max(0, $base - intval($ab['amount'] ?? 2));
                    break;
                }
            }
        }
    }
    $base = plMuseGapApplyHandCostReduction($state, $pid, $card, $base);
    return spBp5ApplyHandCostReduction($state, $pid, $card, $base);
}

function memberBlocksBaton(array $member): bool {
    if (!cardHasAbilities($member)) return false;
    foreach ($member['abilities'] as $ab) {
        if (($ab['trigger'] ?? '') === 'continuous' && ($ab['type'] ?? '') === 'no_baton') {
            return true;
        }
    }
    return false;
}

function subunitNamesMatch(string $a, string $b): bool {
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    $norm = static fn(string $s): string => str_replace('！', '!', trim($s));
    return $norm($a) === $norm($b);
}

function memberBatonFromLowerCostSubunit(array $member, string $subunit): bool {
    $fromSub = $member['baton_from_subunit'] ?? '';
    if (!subunitNamesMatch($fromSub, $subunit)) return false;
    $fromCost = intval($member['baton_from_cost'] ?? -1);
    $selfCost = intval($member['cost'] ?? 0);
    return $fromCost >= 0 && $fromCost < $selfCost;
}

function cardNameKey(array $card): string {
    return $card['name_en'] ?? $card['name'] ?? '';
}

function stageMembersMatchingName(array $p, string $nameKey): array {
    $out = [];
    foreach ($p['stage'] as $slot => $mbr) {
        if (!$mbr || $nameKey === '') continue;
        if (cardNameKey($mbr) === $nameKey) {
            $out[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
        }
    }
    return $out;
}

function applyNamedMemberHeartsBlade(array &$state, string $pid, string $memberId, array $effect): bool {
    foreach ($state['players'][$pid]['stage'] as &$mbr) {
        if (!$mbr || ($mbr['instance_id'] ?? '') !== $memberId) continue;
        foreach ($effect['hearts'] ?? [] as $spec) {
            $color = is_array($spec) ? ($spec['color'] ?? 'green') : $spec;
            $cnt = is_array($spec) ? intval($spec['count'] ?? 1) : 1;
            if (!isset($mbr['bonus_hearts'])) $mbr['bonus_hearts'] = [];
            for ($i = 0; $i < $cnt; $i++) {
                $mbr['bonus_hearts'][] = $color;
            }
        }
        $blade = intval($effect['blade'] ?? 0);
        if ($blade > 0) {
            $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $blade;
        }
        unset($mbr);
        return true;
    }
    unset($mbr);
    return false;
}

function takeDiscardedHandCards(array &$p, array $ids): array {
    $taken = [];
    $p['hand'] = array_values(array_filter($p['hand'], function ($c) use ($ids, &$taken, &$p) {
        if (in_array($c['instance_id'] ?? '', $ids, true)) {
            $p['waiting_room'][] = $c;
            $taken[] = $c;
            return false;
        }
        return true;
    }));
    return $taken;
}

function stageMemberWhoLabel(array $member, string $slot = ''): string {
    $name = $member['name_en'] ?? $member['name'] ?? 'Member';
    if ($slot !== '') {
        return ucfirst($slot) . ' · ' . $name;
    }
    return $name;
}

// liveScoreBonusEntry … getLiveScoreBonus — see src/Game/LiveScoreBonus.php

function getEffectiveStageMemberCost(array $state, string $pid, array $member): int {
    $base = intval($member['cost'] ?? 0);
    $base += intval($member['live_cost_bonus'] ?? 0);
    $p = $state['players'][$pid] ?? [];
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') continue;
        if (($ab['type'] ?? '') === 'self_stage_cost_bonus') {
            if (sumSuccessLiveScores($p) >= intval($ab['min_success_score_sum'] ?? 0)) {
                $base += intval($ab['amount'] ?? 0);
            }
        }
        if (($ab['type'] ?? '') === 'self_stage_cost_per_success') {
            $base += count($p['success_lives'] ?? []) * intval($ab['amount'] ?? 1);
        }
        if (($ab['type'] ?? '') === 'cost_bonus_if_min_energy') {
            if (countEnergyInZone($p) >= intval($ab['min_energy'] ?? 10)) {
                $base += intval($ab['amount'] ?? 0);
            }
        }
        if (($ab['type'] ?? '') === 'cost_blade_per_stacked_max') {
            $stacked = min(
                count($member['stacked_members'] ?? []),
                intval($ab['max_stacked'] ?? 3)
            );
            $base += $stacked * intval($ab['cost_plus_per'] ?? 4);
        }
    }
    return spBp2ApplyContinuousMemberCost($member, $base);
}

function getMemberBlade(array $member, array $state, string $pid, string $slot = ''): int {
    $blade = isset($member['printed_blade_override'])
        ? intval($member['printed_blade_override'])
        : intval($member['blade'] ?? 0);
    $lm = $state['live_modifiers'][$pid] ?? [];
    $div = intval($lm['blade_per_hand_divisor'] ?? 0);
    if ($div > 0) {
        $handCount = count($state['players'][$pid]['hand'] ?? []);
        $blade += intdiv($handCount, $div) * intval($lm['blade_per_hand_amount'] ?? 1);
    }
    if (cardHasAbilities($member)) {
        foreach ($member['abilities'] as $ab) {
            if (($ab['trigger'] ?? '') === 'continuous' && ($ab['type'] ?? '') === 'blade_per_opp_wait') {
                $opp = ($pid === 'p1') ? 'p2' : 'p1';
                foreach ($state['players'][$opp]['stage'] as $mbr) {
                    if ($mbr && !($mbr['active'] ?? true)) {
                        $blade += intval($ab['amount'] ?? 1);
                    }
                }
            }
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'blade_bonus_per_success_lives') {
                $blade += count($state['players'][$pid]['success_lives'] ?? [])
                    * intval($ab['amount'] ?? 1);
            }
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'blade_if_higher_cost_on_stage') {
                if (stageHasHigherCostMember(
                    $state['players'][$pid],
                    $member,
                    $member['instance_id'] ?? ''
                )) {
                    $blade += intval($ab['amount'] ?? 0);
                }
            }
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'blade_per_other_subunit') {
                $blade += countOtherSubunitOnStage(
                    $state['players'][$pid],
                    $ab['subunit'] ?? '',
                    $member['instance_id'] ?? ''
                ) * intval($ab['amount'] ?? 1);
            }
            if (($ab['trigger'] ?? '') === 'continuous' && ($ab['type'] ?? '') === 'blade_if_success_score_higher') {
                $opp = ($pid === 'p1') ? 'p2' : 'p1';
                if (sumSuccessLiveScores($state['players'][$pid], $state, $pid) >
                    sumSuccessLiveScores($state['players'][$opp], $state, $opp)) {
                    $blade += intval($ab['amount'] ?? 2);
                }
            }
            if (plMuseGapIsEffectType($ab['type'] ?? '')) {
                $blade = plMuseGapApplyContinuousBlade($blade, $member, $state, $pid, $ab);
            }
            if (plSpSd2IsEffectType($ab['type'] ?? '')) {
                $blade = plSpSd2ApplyContinuousBlade($blade, $member, $state, $pid, $ab);
            }
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'blade_if_no_self_success_opp_has') {
                $opp = ($pid === 'p1') ? 'p2' : 'p1';
                if (empty($state['players'][$pid]['success_lives'])
                    && count($state['players'][$opp]['success_lives'] ?? [])
                        >= intval($ab['min_opp_success'] ?? 1)) {
                    $blade += intval($ab['amount'] ?? 3);
                }
            }
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'blade_if_exact_stage_members') {
                if (countStageMembers($state['players'][$pid]) === intval($ab['count'] ?? 2)) {
                    $blade += intval($ab['amount'] ?? 1);
                }
            }
            if (($ab['type'] ?? '') === 'blade_if_either_stage_cost_min' && empty($ab['hearts'])) {
                if (eitherStageHasMemberMinCost($state, intval($ab['min_cost'] ?? 13))) {
                    $blade += intval($ab['amount'] ?? 3);
                }
            }
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'blade_bonus_if_opp_more_energy') {
                $opp = ($pid === 'p1') ? 'p2' : 'p1';
                if (countEnergyInZone($state['players'][$opp])
                    > countEnergyInZone($state['players'][$pid])) {
                    $blade += intval($ab['amount'] ?? 3);
                }
            }
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'blade_bonus_if_combined_success_count') {
                if (countCombinedSuccessLives($state) >= intval($ab['min_count'] ?? 3)) {
                    $blade += intval($ab['amount'] ?? 3);
                }
            }
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'blade_if_stage_cost_lower_than_opp') {
                $opp = ($pid === 'p1') ? 'p2' : 'p1';
                if (sumStageMemberCost($state['players'][$pid], $state, $pid)
                    < sumStageMemberCost($state['players'][$opp], $state, $opp)) {
                    $blade += intval($ab['amount'] ?? 1);
                }
            }
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'blade_bonus_if_more_energy_than_opp'
                && empty($ab['hearts'])) {
                $opp = ($pid === 'p1') ? 'p2' : 'p1';
                if (countEnergyInZone($state['players'][$pid])
                    > countEnergyInZone($state['players'][$opp])) {
                    $blade += intval($ab['amount'] ?? 1);
                }
            }
            $blade += sBp6ApplyContinuousBlade($state, $pid, $member, $ab);
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'blade_if_moved_in_slot') {
                $needSlot = $ab['slot'] ?? '';
                if ($needSlot !== '' && $slot === $needSlot
                    && !empty($member['moved_this_turn'])) {
                    $blade += intval($ab['amount'] ?? 1);
                }
            }
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'blade_bonus_if_center') {
                if ($slot === 'center' || findMemberSlot($state['players'][$pid], $member['instance_id'] ?? '') === 'center') {
                    $blade += intval($ab['amount'] ?? 0);
                }
            }
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'continuous_blade_in_slot') {
                $needSlot = $ab['slot'] ?? '';
                if ($needSlot !== ''
                    && ($slot === $needSlot
                        || findMemberSlot($state['players'][$pid], $member['instance_id'] ?? '') === $needSlot)) {
                    $blade += intval($ab['amount'] ?? 0);
                }
            }
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'blade_bonus_if_named_on_stage') {
                if (stageHasNamedMember($state['players'][$pid], $ab['names'] ?? [])) {
                    $blade += intval($ab['amount'] ?? 0);
                }
            }
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'wild_heart_blade_if_distinct_costs') {
                if (countDistinctCostsOnStage($state['players'][$pid])
                    >= intval($ab['min_count'] ?? 3)) {
                    $blade += intval($ab['blade'] ?? 1);
                }
            }
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'blade_per_stage_excl_subunit') {
                foreach ($state['players'][$pid]['stage'] as $sm) {
                    if (!$sm) continue;
                    if (($sm['subunit'] ?? '') === ($ab['exclude_subunit'] ?? '')) continue;
                    if (intval($sm['cost'] ?? 0) >= intval($ab['min_cost'] ?? 4)) {
                        $blade += intval($ab['amount'] ?? 2);
                    }
                }
            }
            $blade = nijiApplyContinuousBlade($member, $ab, $state, $pid, $slot, $blade);
        }
    }
    if ($slot !== '') {
        $blade += getSuccessLiveCenterBladeBonus($state, $pid, $member, $slot);
    }
    $blade = hsApplySoloStageBlade($member, $state, $pid, $blade);
    $blade = hsPb1ApplyContinuousBlade($member, $state, $pid, $slot, $blade);
    $blade += intval($member['live_blade_bonus'] ?? 0);
    return $blade;
}

function abilityUsedKey(string $instanceId, int $idx): string {
    return $instanceId . ':' . $idx;
}

function isAbilityUsed(array $member, int $idx): bool {
    $used = $member['abilities_used'] ?? [];
    return !empty($used[abilityUsedKey($member['instance_id'] ?? '', $idx)]);
}

function markAbilityUsed(array &$member, int $idx): void {
    if (!isset($member['abilities_used'])) $member['abilities_used'] = [];
    $member['abilities_used'][abilityUsedKey($member['instance_id'] ?? '', $idx)] = true;
}

/** Reset per-turn Auto counters (e.g. max_uses_per_turn on stage Members). */
function clearMemberPerTurnAutoUses(array &$member): void {
    foreach (array_keys($member) as $key) {
        if (str_starts_with((string)$key, '_auto_uses_')) {
            unset($member[$key]);
        }
    }
}

function memberIsInWait(array $member): bool {
    return !empty($member['in_wait']);
}

/** Stood / not rested for unrelated reasons (Wait uses active=false but is handled separately). */
function memberIsActiveForGame(array $member): bool {
    return $member['active'] ?? true;
}

/** Wait only blocks blade contribution to Yell — not hearts, skills, or baton. */
function memberContributesBladeToYell(array $member): bool {
    if (memberIsInWait($member)) {
        return false;
    }
    return $member['active'] ?? true;
}

function clearMemberWait(array &$member): void {
    unset($member['in_wait'], $member['waited_turn'], $member['_was_active_before_wait']);
    $member['active'] = true;
}

function waitMember(array &$member, array $state): void {
    if (memberIsInWait($member)) {
        return;
    }
    $member['_was_active_before_wait'] = $member['active'] ?? true;
    $member['in_wait'] = true;
    $member['waited_turn'] = intval($state['turn'] ?? 1);
    $member['active'] = false;
}

function flushAutoOnWaitAbilities(array $state): array {
    $phase = $state['phase'] ?? '';
    if (!in_array($phase, ['main_first', 'main_second'], true)) {
        return $state;
    }
    foreach (['p1', 'p2'] as $pid) {
        $p = &$state['players'][$pid];
        foreach ($p['stage'] as $slot => &$mbr) {
            if (!$mbr || empty($mbr['_was_active_before_wait'])) {
                continue;
            }
            unset($mbr['_was_active_before_wait']);
            if ($mbr['active'] ?? true) {
                continue;
            }
            $state = resolveAutoOnWaitAbilities($state, $pid, $mbr);
            $p['stage'][$slot] = $mbr;
            if (!empty($state['pending_prompt'])) {
                unset($p);
                return $state;
            }
        }
        unset($mbr);
        unset($p);
    }
    return $state;
}

function memberSnapshot(array $member): array {
    $snap = $member;
    $snap['active'] = true;
    return $snap;
}

function abilitySlotAllowed(array $ab, array $ctx, array $p, array $source): bool {
    if (empty($ab['center_only'])) {
        return true;
    }
    $slot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
    return $slot === 'center';
}

function grantMemberLiveScoreBonus(array &$state, string $pid, string $memberInstanceId, int $amount): void {
    foreach ($state['players'][$pid]['stage'] as &$mbr) {
        if ($mbr && ($mbr['instance_id'] ?? '') === $memberInstanceId) {
            $mbr['live_score_bonus'] = intval($mbr['live_score_bonus'] ?? 0) + $amount;
            unset($mbr);
            return;
        }
    }
    unset($mbr);
}

function resolveAutomaticOpponentWaitEffects(array $state, string $sourcePid, array $waitedMember): array {
    if (!($waitedMember['active'] ?? true)) {
        return $state;
    }
    $cost = intval($waitedMember['cost'] ?? 0);
    $p = &$state['players'][$sourcePid];
    foreach ($p['stage'] as $slot => &$member) {
        if (!$member) continue;
        foreach ($member['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'automatic') continue;
            if (($ab['type'] ?? '') !== 'draw_on_opp_wait_by_effect') continue;
            if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $idx)) continue;
            if ($cost > intval($ab['max_opp_cost'] ?? 4)) continue;
            markAbilityUsed($member, $idx);
            $p['stage'][$slot] = $member;
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $state = addLog($state, $state['players'][$sourcePid]['name'] .
                ' — [' . ($member['name_en'] ?? $member['name']) . "] drew $drawn (opponent active Member put into Wait by your effect).");
            return $state;
        }
    }
    unset($member);
    return $state;
}

function waitOpponentMemberAtSlot(
    array &$state,
    string $oppId,
    string $slot,
    ?string $effectSourcePid = null
): bool {
    $mbr = &$state['players'][$oppId]['stage'][$slot];
    if (!$mbr || memberIsInWait($mbr)) {
        return false;
    }
    $snap = memberSnapshot($mbr);
    waitMember($mbr, $state);
    unset($mbr);
    if ($effectSourcePid) {
        $state = resolveAutomaticOpponentWaitEffects($state, $effectSourcePid, $snap);
    }
    return true;
}

function listSubunitStageMembers(array $p, string $subunit): array {
    $out = [];
    foreach ($p['stage'] as $slot => $mbr) {
        if ($mbr && ($mbr['subunit'] ?? '') === $subunit) {
            $out[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
        }
    }
    return $out;
}

function listGroupStageMembers(array $p, string $group): array {
    $out = [];
    foreach ($p['stage'] as $slot => $mbr) {
        if ($mbr && ($mbr['group'] ?? '') === $group) {
            $out[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
        }
    }
    return $out;
}

function listActiveStageMembers(array $p): array {
    $out = [];
    foreach ($p['stage'] as $slot => $mbr) {
        if ($mbr && ($mbr['active'] ?? true)) {
            $out[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
        }
    }
    return $out;
}

function countRequiredHearts(array $card): int {
    $hearts = $card['required_hearts'] ?? $card['hearts'] ?? [];
    $n = 0;
    foreach ($hearts as $h) {
        $n += intval($h['count'] ?? 0);
    }
    return $n;
}

function countRequiredHeartsOfColor(array $card, string $color): int {
    $n = 0;
    foreach ($card['required_hearts'] ?? $card['hearts'] ?? [] as $hg) {
        $c = $hg['color'] ?? '';
        if ($c === $color) {
            $n += intval($hg['count'] ?? 0);
        }
    }
    return $n;
}

function activateMembersForPlayer(array &$p, int $max): int {
    $n = 0;
    foreach ($p['stage'] as &$mbr) {
        if ($n >= $max) break;
        if (!$mbr || memberIsActiveForGame($mbr)) continue;
        if (memberIsInWait($mbr)) continue;
        $mbr['active'] = true;
        $n++;
    }
    unset($mbr);
    return $n;
}

function waitOpponentStageByCost(
    array &$state,
    string $oppId,
    int $maxCost,
    ?int $pickCount = null,
    ?string $effectSourcePid = null,
    bool $activeOnly = false
): int {
    $waited = 0;
    foreach ($state['players'][$oppId]['stage'] as &$mbr) {
        if (!$mbr) continue;
        if (memberIsInWait($mbr)) continue;
        if ($activeOnly && !memberIsActiveForGame($mbr)) continue;
        if (intval($mbr['cost'] ?? 0) > $maxCost) continue;
        if ($pickCount !== null && $waited >= $pickCount) break;
        $snap = memberSnapshot($mbr);
        waitMember($mbr, $state);
        if ($effectSourcePid) {
            $state = resolveAutomaticOpponentWaitEffects($state, $effectSourcePid, $snap);
        }
        $waited++;
    }
    unset($mbr);
    return $waited;
}

function memberBladeIconCount(array $member): int {
    $bh = $member['blade_hearts'] ?? [];
    if (!empty($bh)) {
        return count($bh);
    }
    return intval($member['blade'] ?? 0) > 0 ? 1 : 0;
}

function findMemberSlot(array $p, string $instanceId): string {
    foreach ($p['stage'] as $slot => $mbr) {
        if ($mbr && ($mbr['instance_id'] ?? '') === $instanceId) {
            return $slot;
        }
    }
    return '';
}

function isMemberCard(array $card): bool {
    $type = $card['card_type'] ?? '';
    $typeEn = $card['card_type_en'] ?? '';
    return $type === 'メンバー' || $typeEn === 'Member';
}

function isLiveTypeCard(array $card): bool {
    $type = $card['card_type'] ?? '';
    $typeEn = $card['card_type_en'] ?? '';
    return $type === 'ライブ' || $typeEn === 'Live';
}

function isLiveStorageEligible(array $card): bool {
    return isLiveTypeCard($card) || isMemberCard($card);
}

function memberInstanceOnStage(array $p, string $instanceId): bool {
    if ($instanceId === '') {
        return false;
    }
    return findMemberSlot($p, $instanceId) !== '';
}

function findLiveStartSourceCard(array $state, string $pid, string $instanceId): ?array {
    $p = $state['players'][$pid] ?? [];
    foreach ($p['stage'] as $member) {
        if ($member && ($member['instance_id'] ?? '') === $instanceId && isMemberCard($member)) {
            return $member;
        }
    }
    foreach ($p['live_zone'] ?? [] as $live) {
        if ($live && ($live['instance_id'] ?? '') === $instanceId && isLiveTypeCard($live)) {
            return $live;
        }
    }
    return null;
}

function countStageSubunitMembers(array $p, string $subunit): int {
    $n = 0;
    foreach ($p['stage'] as $mbr) {
        if ($mbr && ($mbr['subunit'] ?? '') === $subunit) {
            $n++;
        }
    }
    return $n;
}

function stageHasOtherSubunitMember(array $p, string $subunit, string $excludeId): bool {
    foreach ($p['stage'] as $mbr) {
        if (!$mbr || ($mbr['instance_id'] ?? '') === $excludeId) continue;
        if (($mbr['subunit'] ?? '') === $subunit) return true;
    }
    return false;
}

function stageHasWaitMember(array $state, string $pid): bool {
    foreach ($state['players'][$pid]['stage'] as $mbr) {
        if ($mbr && memberIsInWait($mbr)) return true;
    }
    return false;
}

function waitOpponentStageByMaxBlade(
    array &$state,
    string $oppId,
    int $maxBlade,
    ?int $pickCount = null,
    ?string $effectSourcePid = null,
    bool $activeOnly = false
): int {
    $waited = 0;
    foreach ($state['players'][$oppId]['stage'] as &$mbr) {
        if (!$mbr) continue;
        if (memberIsInWait($mbr)) continue;
        if ($activeOnly && !memberIsActiveForGame($mbr)) continue;
        if (memberBladeIconCount($mbr) > $maxBlade) continue;
        if ($pickCount !== null && $waited >= $pickCount) break;
        $snap = memberSnapshot($mbr);
        waitMember($mbr, $state);
        if ($effectSourcePid) {
            $state = resolveAutomaticOpponentWaitEffects($state, $effectSourcePid, $snap);
        }
        $waited++;
    }
    unset($mbr);
    return $waited;
}

function putWrCardOnDeckTop(array &$p, string $instanceId): bool {
    foreach ($p['waiting_room'] as $i => $c) {
        if (($c['instance_id'] ?? '') === $instanceId) {
            array_splice($p['waiting_room'], $i, 1);
            array_unshift($p['main_deck'], $c);
            return true;
        }
    }
    return false;
}

function putHandLiveOnDeckBottom(array &$p, string $instanceId): ?array {
    foreach ($p['hand'] as $i => $c) {
        if (($c['instance_id'] ?? '') === $instanceId && ($c['card_type'] ?? '') === 'ライブ') {
            $card = $c;
            array_splice($p['hand'], $i, 1);
            $p['main_deck'][] = $card;
            return $card;
        }
    }
    return null;
}

function putWrLiveOnDeckBottom(array &$p, string $instanceId): ?array {
    foreach ($p['waiting_room'] as $i => $c) {
        if (($c['instance_id'] ?? '') === $instanceId && ($c['card_type'] ?? '') === 'ライブ') {
            $card = $c;
            array_splice($p['waiting_room'], $i, 1);
            $p['main_deck'][] = $card;
            return $card;
        }
    }
    return null;
}

function putWrMemberOnDeckTop(array &$p, string $instanceId): ?array {
    foreach ($p['waiting_room'] as $i => $c) {
        if (($c['instance_id'] ?? '') === $instanceId && ($c['card_type'] ?? '') === 'メンバー') {
            $card = $c;
            array_splice($p['waiting_room'], $i, 1);
            array_unshift($p['main_deck'], $card);
            return $card;
        }
    }
    return null;
}

function listStageMemberChoices(array $p, string $group = '', string $excludeId = ''): array {
    $out = [];
    foreach ($p['stage'] as $slot => $mbr) {
        if (!$mbr) continue;
        if ($excludeId !== '' && ($mbr['instance_id'] ?? '') === $excludeId) continue;
        if ($group !== '' && ($mbr['group'] ?? '') !== $group) continue;
        $out[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
    }
    return $out;
}

function listOppStageMembersByMaxCost(array $state, string $oppId, int $maxCost, bool $activeOnly = false): array {
    $out = [];
    foreach ($state['players'][$oppId]['stage'] as $slot => $mbr) {
        if (!$mbr) continue;
        if ($activeOnly && memberIsInWait($mbr)) continue;
        if (intval($mbr['cost'] ?? 0) > $maxCost) continue;
        $out[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
    }
    return $out;
}

function takeWrMemberExactCost(array &$p, string $group, int $exactCost): ?array {
    foreach ($p['waiting_room'] as $i => $c) {
        if (($c['card_type'] ?? '') !== 'メンバー') continue;
        if ($group !== '' && ($c['group'] ?? '') !== $group) continue;
        if (intval($c['cost'] ?? 0) !== $exactCost) continue;
        $member = $c;
        array_splice($p['waiting_room'], $i, 1);
        return $member;
    }
    return null;
}

function waitSwapHasValidTarget(array $p, string $group, int $bonus, string $excludeSlot): bool {
    foreach ($p['stage'] as $s => $mbr) {
        if (!$mbr || $s === $excludeSlot) continue;
        if ($group !== '' && ($mbr['group'] ?? '') !== $group) continue;
        $targetCost = intval($mbr['cost'] ?? 0) + $bonus;
        foreach ($p['waiting_room'] as $c) {
            if (($c['card_type'] ?? '') === 'メンバー'
                && ($c['group'] ?? '') === $group
                && intval($c['cost'] ?? 0) === $targetCost) {
                return true;
            }
        }
    }
    return false;
}

function countDistinctNamedSubunit(array $p, string $subunit): int {
    $names = [];
    foreach ($p['stage'] as $mbr) {
        if (!$mbr || ($mbr['subunit'] ?? '') !== $subunit) continue;
        $n = $mbr['name_en'] ?? $mbr['name'] ?? '';
        $names[$n] = true;
    }
    return count($names);
}

function stageFullDistinctGroupMembers(array $p, string $group, string $filter = 'member'): bool {
    $names = [];
    foreach (['left', 'center', 'right'] as $slot) {
        $mbr = $p['stage'][$slot] ?? null;
        if (!$mbr) return false;
        if (!cardMatchesGroup($mbr, $group, $filter)) return false;
        $n = $mbr['name_en'] ?? $mbr['name'] ?? '';
        if ($n === '' || isset($names[$n])) return false;
        $names[$n] = true;
    }
    return count($names) === 3;
}

function stageHasOtherMember(array $p, string $excludeId): bool {
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        if (($mbr['instance_id'] ?? '') === $excludeId) continue;
        return true;
    }
    return false;
}

function stageIsFull(array $p): bool {
    foreach (['left', 'center', 'right'] as $slot) {
        if (empty($p['stage'][$slot])) return false;
    }
    return true;
}

function stageHasHigherCostMember(array $p, array $self, string $excludeId = ''): bool {
    $selfCost = intval($self['cost'] ?? 0);
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        if ($excludeId !== '' && ($mbr['instance_id'] ?? '') === $excludeId) continue;
        if (intval($mbr['cost'] ?? 0) > $selfCost) return true;
    }
    return false;
}

function centerMemberHasHighestCost(array $p, array $member, string $slot): bool {
    if ($slot !== 'center') return false;
    $cost = intval($member['cost'] ?? 0);
    foreach ($p['stage'] as $s => $mbr) {
        if (!$mbr || $s === $slot) continue;
        if (intval($mbr['cost'] ?? 0) > $cost) return false;
    }
    return true;
}

function memberLiveStartAbilitiesNegated(array $member): bool {
    return !empty($member['live_start_negated']);
}

function yellCardsHaveBladeHeart(array $yellCards): bool {
    foreach ($yellCards as $yc) {
        if (!empty($yc['blade_hearts'])) return true;
    }
    return false;
}

function countDistinctNamedOnStage(array $p, array $names): int {
    $found = [];
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
        foreach ($names as $n) {
            if ($label === $n || str_contains($label, $n)) {
                $found[$n] = true;
            }
        }
    }
    return count($found);
}

function resolveAutoAreaMoveAbilities(array $state, string $pid, string $memberInstanceId, string $fromSlot = ''): array {
    $p = &$state['players'][$pid];
    if ($fromSlot === '') {
        $fromSlot = findMemberSlot($p, $memberInstanceId) ?? '';
    }
    foreach ($p['stage'] as $slot => &$member) {
        if (!$member || ($member['instance_id'] ?? '') !== $memberInstanceId) continue;
        $member['moved_this_turn'] = true;
        spBp2ApplyMovedByGroupEffect($member, $state);
        foreach ($member['abilities'] ?? [] as $idx => $ab) {
            $trigger = $ab['trigger'] ?? '';
            $type = $ab['type'] ?? '';
            if ($trigger === 'on_enter_or_auto' && $type === 'wait_opp_max_original_hearts') {
                $opp = ($pid === 'p1') ? 'p2' : 'p1';
                $waited = waitOpponentStageByOriginalHearts(
                    $state,
                    $opp,
                    intval($ab['max_original_hearts'] ?? 3),
                    intval($ab['pick_count'] ?? 1) ?: null,
                    $pid
                );
                if ($waited > 0) {
                    $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$mName] put $waited opponent Member(s) into Wait (area move).");
                }
                continue;
            }
            if ($trigger !== 'auto') continue;
            if ($type === 'auto_area_move_energy_wait') {
                if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $idx)) continue;
                if (putEnergyFromDeckInWait($p, $state, $pid)) {
                    markAbilityUsed($member, $idx);
                    $p['stage'][$slot] = $member;
                    $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$mName] put 1 Energy into Wait (area move).");
                }
                continue;
            }
            if ($type === 'auto_area_move_wr_live') {
                if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $idx)) continue;
                $added = addFromWaitingRoomFiltered(
                    $p,
                    $ab['group'] ?? '',
                    'live',
                    1,
                    null,
                    ['max_live_score' => intval($ab['max_live_score'] ?? 3)]
                );
                if ($added > 0) {
                    if (!empty($ab['once_per_turn'])) markAbilityUsed($member, $idx);
                    $p['stage'][$slot] = $member;
                    $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$mName] added 1 Live card from Waiting Room (area move).");
                }
            }
            if ($type === 'blade_if_entered_or_moved') {
                if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $idx)) continue;
                $state = applyModifierEffect($state, $pid, [
                    'type'   => 'blade_bonus',
                    'amount' => intval($ab['amount'] ?? 1),
                ]);
                if (!empty($ab['once_per_turn'])) markAbilityUsed($member, $idx);
                $p['stage'][$slot] = $member;
                $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$mName] gained +" . intval($ab['amount'] ?? 1) . ' Blade (moved).');
                continue;
            }
            $state = sBp5ResolveAutoAreaMove($state, $pid, $member, $slot, $idx, $ab);
            $state = spBp5OnAutoAreaMove($state, $pid, $member, $idx, $ab);
            if ($type === 'auto_area_move_draw') {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$mName] drew $drawn (area move).");
            }
        }
        $toSlot = findMemberSlot($p, $memberInstanceId) ?? $slot;
        $fromSlotEffective = $fromSlot !== '' ? $fromSlot : ($member['moved_from_slot'] ?? $toSlot);
        unset($member['moved_from_slot']);
        $p['stage'][$toSlot] = $member;
        $state = spBp2OnMemberAreaMove($state, $pid, $memberInstanceId, $fromSlotEffective, $toSlot);
        break;
    }
    unset($member);
    spBp2ClearEffectAreaMove($state);
    return $state;
}

function resolveEnergyPlacedAbilities(array $state, string $pid): array {
    foreach ($state['players'][$pid]['stage'] as $member) {
        if (!$member) continue;
        foreach ($member['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') continue;
            if (($ab['type'] ?? '') !== 'hearts_on_energy_placed') continue;
            addBonusHeartsToModifier($state, $pid, $ab['hearts'] ?? []);
            $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$mName] gained bonus hearts (Energy placed).");
        }
    }
    return $state;
}

function putWrGroupMemberToEmptyStage(array &$p, string $group, int $maxCost, int $turn = 1): ?array {
    foreach (['left', 'center', 'right'] as $slot) {
        if (!empty($p['stage'][$slot])) continue;
        foreach ($p['waiting_room'] as $i => $c) {
            if (!cardMatchesWrPick($c, ['group' => $group, 'filter' => 'member', 'max_cost' => $maxCost])) {
                continue;
            }
            $member = $c;
            array_splice($p['waiting_room'], $i, 1);
            $member['active'] = true;
            $member['entered_turn'] = $turn;
            $p['stage'][$slot] = $member;
            return ['member' => $member, 'slot' => $slot];
        }
    }
    return null;
}

function countDistinctYellMembers(array $yellCards, string $group = ''): int {
    $names = [];
    foreach ($yellCards as $c) {
        if (($c['card_type'] ?? '') !== 'メンバー') continue;
        if ($group !== '' && ($c['group'] ?? '') !== $group) continue;
        $label = $c['name_en'] ?? $c['name'] ?? '';
        if ($label !== '') $names[$label] = true;
    }
    return count($names);
}

function memberBladeHeartCount(array $member): int {
    return count($member['blade_hearts'] ?? []);
}

function applyOnEnterSideEffect(
    array $state,
    string $pid,
    array &$p,
    string $name,
    array $ab,
    string $slot
): array {
    $slots = $ab['slots'] ?? [];
    if (!empty($slots) && !in_array($slot, $slots, true)) {
        return $state;
    }
    $effect = $ab['effect'] ?? [];
    if (empty($effect)) return $state;
    return resolveAbilityEffect($state, $pid, ['name_en' => $name, 'name' => $name], $effect, [
        'slot'  => $slot,
        'phase' => 'on_enter',
    ]);
}

function countOtherSubunitOnStage(array $p, string $subunit, string $excludeId = ''): int {
    $n = 0;
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        if ($excludeId !== '' && ($mbr['instance_id'] ?? '') === $excludeId) continue;
        if (($mbr['subunit'] ?? '') === $subunit) $n++;
    }
    return $n;
}

const CARD_SPECIAL_HEART_DRAW = 'icon_draw.png';
const CARD_SPECIAL_HEART_SCORE = 'icon_score.png';

/** Draw icon on a Yell-revealed card (blade heart and/or printed yell draw icon). */
function cardYellDrawIconCount(array $card): int {
    $n = 0;
    foreach ($card['blade_hearts'] ?? [] as $bh) {
        if (is_string($bh) && $bh === 'draw') {
            $n++;
            continue;
        }
        if (is_array($bh) && ($bh['type'] ?? '') === 'draw') {
            $n += max(1, intval($bh['count'] ?? 1));
        }
    }
    if (!empty($card['yell_draw_icon']) || ($card['special_heart'] ?? '') === CARD_SPECIAL_HEART_DRAW) {
        $n++;
    }
    return $n;
}

/** Score icon on a Yell-revealed card (blade heart and/or printed yell score icon). */
function cardYellScoreIconCount(array $card): int {
    $n = 0;
    foreach ($card['blade_hearts'] ?? [] as $bh) {
        if (is_string($bh) && $bh === 'score') {
            $n++;
            continue;
        }
        if (is_array($bh) && ($bh['type'] ?? '') === 'score') {
            $n += max(1, intval($bh['count'] ?? 1));
        }
    }
    if (!empty($card['yell_score_icon']) || ($card['special_heart'] ?? '') === CARD_SPECIAL_HEART_SCORE) {
        $n++;
    }
    return $n;
}

function countYellScoreIcons(array $yellCards): int {
    $n = 0;
    foreach ($yellCards as $yc) {
        $n += cardYellScoreIconCount($yc);
    }
    return $n;
}

function countYellDrawIcons(array $yellCards): int {
    $n = 0;
    foreach ($yellCards as $yc) {
        $n += cardYellDrawIconCount($yc);
    }
    return $n;
}

function successZoneHasSubunit(array $p, string $subunit): bool {
    foreach ($p['success_lives'] ?? [] as $c) {
        if (($c['subunit'] ?? '') === $subunit) return true;
    }
    return false;
}

function lookRevealSubunit(array &$p, int $look, string $subunit): int {
    $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
    $picked = 0;
    foreach ($top as $c) {
        if ($picked < 1 && ($c['subunit'] ?? '') === $subunit) {
            $p['hand'][] = $c;
            $picked++;
        } else {
            $p['waiting_room'][] = $c;
        }
    }
    return $picked;
}

function activateSubunitMembers(array &$p, string $subunit, int $max): int {
    $n = 0;
    foreach ($p['stage'] as &$mbr) {
        if ($n >= $max) break;
        if (!$mbr || ($mbr['subunit'] ?? '') !== $subunit) continue;
        if ($mbr['active'] ?? true) continue;
        $mbr['active'] = true;
        $n++;
    }
    unset($mbr);
    return $n;
}

function activateMembersByEffect(array &$state, array &$p, int $max): int {
    if (!empty($state['block_effect_member_activate'])) {
        return 0;
    }
    return activateMembersForPlayer($p, $max);
}

function waitOpponentStageByOriginalHearts(
    array &$state,
    string $oppId,
    int $maxHearts,
    ?int $pickCount = null,
    ?string $effectSourcePid = null,
    bool $activeOnly = false
): int {
    $waited = 0;
    foreach ($state['players'][$oppId]['stage'] as &$mbr) {
        if (!$mbr) continue;
        if (memberIsInWait($mbr)) continue;
        if ($activeOnly && !memberIsActiveForGame($mbr)) continue;
        if (memberHeartCount($mbr) > $maxHearts) continue;
        if ($pickCount !== null && $waited >= $pickCount) break;
        $snap = memberSnapshot($mbr);
        waitMember($mbr, $state);
        if ($effectSourcePid) {
            $state = resolveAutomaticOpponentWaitEffects($state, $effectSourcePid, $snap);
        }
        $waited++;
    }
    unset($mbr);
    return $waited;
}

function waitOpponentStageByOriginalBlades(
    array &$state,
    string $oppId,
    int $maxBlades,
    ?int $pickCount = null,
    ?string $effectSourcePid = null,
    bool $activeOnly = false
): int {
    $waited = 0;
    foreach ($state['players'][$oppId]['stage'] as &$mbr) {
        if (!$mbr) continue;
        if (memberIsInWait($mbr)) continue;
        if ($activeOnly && !memberIsActiveForGame($mbr)) continue;
        if (intval($mbr['blade'] ?? 0) > $maxBlades) continue;
        if ($pickCount !== null && $waited >= $pickCount) break;
        $snap = memberSnapshot($mbr);
        waitMember($mbr, $state);
        if ($effectSourcePid) {
            $state = resolveAutomaticOpponentWaitEffects($state, $effectSourcePid, $snap);
        }
        $waited++;
    }
    unset($mbr);
    return $waited;
}

function countStageMembers(array $p): int {
    return count(array_filter($p['stage'] ?? [], fn($m) => (bool)$m));
}

function cardMatchesGroup(array $card, string $group, string $filter = ''): bool {
    if ($group !== '' && ($card['group'] ?? '') !== $group) return false;
    if ($filter === 'member') return isMemberCard($card);
    if ($filter === 'live') return isLiveTypeCard($card);
    return true;
}

function groupPromptLabel(string $group): string {
    return match ($group) {
        'Sunshine'   => 'Aqours',
        'Superstar'  => 'Liella!',
        'Hasunosora' => 'Hasunosora',
        'Nijigasaki' => 'Nijigasaki',
        default      => $group !== '' ? $group : 'matching',
    };
}

function countOptionalNamedDiscardMatches(array $p, array $ab, string $sourceId): int {
    $names = $ab['names'] ?? [];
    $n = 0;
    foreach ($p['hand'] ?? [] as $c) {
        if (cardMatchesNames($c, $names)
            || (($ab['include_self'] ?? false) && ($c['instance_id'] ?? '') === $sourceId)) {
            $n++;
        }
    }
    return $n;
}

function cardMatchesWrPick(array $card, array $cfg): bool {
    $subunit = $cfg['subunit'] ?? '';
    if ($subunit !== '' && !cardMatchesSubunit($card, $subunit)) return false;
    $group = $cfg['group'] ?? '';
    $filter = $cfg['filter'] ?? '';
    if ($filter === '') {
        return $subunit !== '' || cardMatchesGroup($card, $group, '');
    }
    if (!cardMatchesGroup($card, $group, $filter)) return false;
    if (($filter === 'member' || ($card['card_type'] ?? '') === 'メンバー')
        && isset($cfg['max_cost'])) {
        return intval($card['cost'] ?? 0) <= intval($cfg['max_cost']);
    }
    if (($filter === 'member' || ($card['card_type'] ?? '') === 'メンバー')
        && isset($cfg['min_cost'])) {
        return intval($card['cost'] ?? 0) >= intval($cfg['min_cost']);
    }
    if (($filter === 'live' || ($card['card_type'] ?? '') === 'ライブ')
        && isset($cfg['max_live_score'])) {
        return intval($card['score'] ?? 0) <= intval($cfg['max_live_score']);
    }
    if (($filter === 'live' || ($card['card_type'] ?? '') === 'ライブ')
        && isset($cfg['min_live_score'])) {
        return intval($card['score'] ?? 0) >= intval($cfg['min_live_score']);
    }
    if (isset($cfg['min_required_hearts'])
        && ($filter === 'live' || ($card['card_type'] ?? '') === 'ライブ')) {
        $min = intval($cfg['min_required_hearts']);
        $color = (string)($cfg['min_required_heart_color'] ?? '');
        if ($color !== '') {
            return countRequiredHeartsOfColor($card, $color) >= $min;
        }
        return countRequiredHearts($card) >= $min;
    }
    if (isset($cfg['min_score']) && ($filter === 'live' || ($card['card_type'] ?? '') === 'ライブ')) {
        return intval($card['score'] ?? 0) >= intval($cfg['min_score']);
    }
    return true;
}

function filterYellPoolForAbility(array $pool, array $ab): array {
    return array_values(array_filter($pool, fn($c) => cardMatchesYellPick($c, $ab)));
}

function cardMatchesYellPick(array $card, array $cfg): bool {
    $subunit = $cfg['subunit'] ?? '';
    if ($subunit !== '' && !cardMatchesSubunit($card, $subunit)) return false;
    $filter = $cfg['filter'] ?? 'member';
    if ($filter === 'any') {
        return true;
    }
    if ($filter === 'member_or_live') {
        if (($card['card_type'] ?? '') === 'メンバー') {
            return intval($card['cost'] ?? 0) <= intval($cfg['max_member_cost'] ?? 99);
        }
        if (($card['card_type'] ?? '') === 'ライブ') {
            return intval($card['score'] ?? 0) <= intval($cfg['max_live_score'] ?? 99);
        }
        return false;
    }
    if (!cardMatchesGroup($card, $cfg['group'] ?? '', $filter)) return false;
    if (($filter === 'member' || ($card['card_type'] ?? '') === 'メンバー')
        && isset($cfg['max_cost'])) {
        return intval($card['cost'] ?? 0) <= intval($cfg['max_cost']);
    }
    if (($filter === 'member' || ($card['card_type'] ?? '') === 'メンバー')
        && isset($cfg['min_cost'])) {
        return intval($card['cost'] ?? 0) >= intval($cfg['min_cost']);
    }
    return true;
}

function memberHasHeartColor(array $card, string $color): bool {
    if (($card['card_type'] ?? '') !== 'メンバー') return false;
    foreach ($card['hearts'] ?? [] as $h) {
        $c = $h['color'] ?? '';
        if ($c === $color || $c === 'any') return true;
    }
    return false;
}

function memberHasAnyHeart(array $card): bool {
    if (($card['card_type'] ?? '') !== 'メンバー') return false;
    foreach ($card['hearts'] ?? [] as $h) {
        if (($h['color'] ?? '') !== '') return true;
    }
    return false;
}

function countStageGroupMembers(array $p, string $group): int {
    $n = 0;
    foreach ($p['stage'] ?? [] as $mbr) {
        if ($mbr && ($mbr['group'] ?? '') === $group) {
            $n++;
        }
    }
    return $n;
}

function wrHasLiveNameContains(array $p, string $needle): bool {
    foreach ($p['waiting_room'] ?? [] as $c) {
        if (($c['card_type'] ?? '') !== 'ライブ') continue;
        $label = $c['name_en'] ?? $c['name'] ?? '';
        if ($needle !== '' && str_contains($label, $needle)) {
            return true;
        }
    }
    return false;
}

function cardNameMatchesList(array $card, array $names): bool {
    $label = $card['name_en'] ?? $card['name'] ?? '';
    foreach ($names as $n) {
        if ($n !== '' && ($label === $n || str_contains($label, $n))) {
            return true;
        }
    }
    return false;
}

function countPrintedMemberHearts(array $member): int {
    $n = 0;
    foreach ($member['hearts'] ?? [] as $h) {
        $n += intval($h['count'] ?? 1);
    }
    return $n;
}

function countTotalMemberHearts(array $member): int {
    return countPrintedMemberHearts($member) + count($member['bonus_hearts'] ?? []);
}

function stageHasMemberWithExtraHearts(array $p): bool {
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        if (countTotalMemberHearts($mbr) > countPrintedMemberHearts($mbr)) return true;
    }
    return false;
}

function validateSameGroupDiscard(array $p, array $ids, int $need): bool {
    if (count($ids) !== $need) return false;
    $groups = [];
    foreach ($p['hand'] as $c) {
        if (!in_array($c['instance_id'] ?? '', $ids, true)) continue;
        $g = $c['group'] ?? '';
        if ($g === '') return false;
        $groups[$g] = ($groups[$g] ?? 0) + 1;
    }
    if (array_sum($groups) !== $need) return false;
    foreach ($groups as $count) {
        if ($count === $need) return true;
    }
    return false;
}

function countWrGroup(array $p, string $group): int {
    return count(array_filter(
        $p['waiting_room'] ?? [],
        fn($c) => ($c['group'] ?? '') === $group
    ));
}

function putWrMemberToEmptyStageWait(array &$p, int $maxCost): ?array {
    $emptySlot = null;
    foreach (['left', 'center', 'right'] as $slot) {
        if (empty($p['stage'][$slot])) {
            $emptySlot = $slot;
            break;
        }
    }
    if ($emptySlot === null) return null;
    foreach ($p['waiting_room'] as $i => $c) {
        if (($c['card_type'] ?? '') !== 'メンバー') continue;
        if (intval($c['cost'] ?? 0) > $maxCost) continue;
        $member = $c;
        array_splice($p['waiting_room'], $i, 1);
        $member['active'] = false;
        $p['stage'][$emptySlot] = $member;
        return ['member' => $member, 'slot' => $emptySlot];
    }
    return null;
}

function activateSubunitFromWait(array &$p, string $subunit): int {
    $n = 0;
    foreach ($p['stage'] as &$mbr) {
        if (!$mbr || ($mbr['subunit'] ?? '') !== $subunit) continue;
        if (!memberIsInWait($mbr)) continue;
        clearMemberWait($mbr);
        $n++;
    }
    unset($mbr);
    return $n;
}

function addFromWaitingRoomFiltered(array &$p, string $group, string $filter, int $count, ?int $maxCost = null, array $extra = []): int {
    $picked = [];
    $rest = [];
    $cfg = array_merge(['group' => $group, 'filter' => $filter], $extra);
    if ($maxCost !== null) {
        $cfg['max_cost'] = $maxCost;
    }
    foreach ($p['waiting_room'] as $c) {
        if (count($picked) < $count && cardMatchesWrPick($c, $cfg)) {
            $picked[] = $c;
        } else {
            $rest[] = $c;
        }
    }
    if (empty($picked)) return 0;
    $p['waiting_room'] = $rest;
    $p['hand'] = array_merge($p['hand'], $picked);
    return count($picked);
}

function hydrateWrCardForPick(array &$card): void {
    mergeCardCatalogFields($card);
}

function hasWrLiveWithMinHearts(array $p, int $minHearts, string $color = ''): bool {
    foreach ($p['waiting_room'] ?? [] as $c) {
        if (($c['card_type'] ?? '') !== 'ライブ') {
            continue;
        }
        if ($color !== '') {
            if (countRequiredHeartsOfColor($c, $color) >= $minHearts) {
                return true;
            }
        } elseif (countRequiredHearts($c) >= $minHearts) {
            return true;
        }
    }
    return false;
}

function activatedAbilityRequiresStageSlot(array $ab): bool {
    $type = $ab['type'] ?? '';
    if (!empty($ab['center_only'])) {
        return true;
    }
    static $types = [
        'leave_stage_add_from_wr',
        'leave_play_named_from_hand_stack_energy',
        'wait_self_only',
        'wait_self_activate_energy',
        'wait_self_discard_add_wr_live',
        'wait_self_add_wr',
        'wait_self_choose_heart',
        'wait_self_discard_draw',
        'wait_self_draw_discard',
        'wait_self_draw_discard_activate',
        'wait_self_discard_reveal_until',
        'wait_self_energy_wait',
        'wait_pick_member_grant_live_score',
        'activated_swap_area_member',
        'pay_leave_stage_play_wr_member',
        'reveal_live_opp_discard_or_blade',
        'shuffle_named_from_waiting',
        'pay_energy_opp_pick_hand_reveal',
        'activated_pay_discard_add_wr_live',
        'hand_discard_for_stage_blade',
        'wait_self_only',
    ];
    if (in_array($type, $types, true)) {
        return true;
    }
    foreach (['wait_self_', 'leave_stage', 'leave_play', 'activated_swap', 'pay_leave_stage'] as $prefix) {
        if (str_starts_with($type, $prefix)) {
            return true;
        }
    }
    return false;
}

function listWrMemberActivatableAbilities(array $p, array $member): array {
    $choices = [];
    foreach ($member['abilities'] ?? [] as $idx => $ab) {
        if (($ab['trigger'] ?? '') !== 'activated') {
            continue;
        }
        if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $idx)) {
            continue;
        }
        if (empty($ab['from_wr_only']) && activatedAbilityRequiresStageSlot($ab)) {
            continue;
        }
        if (activatedAbilityWrBlockReason($p, $ab) !== null) {
            continue;
        }
        $choices[] = ['index' => $idx, 'ability' => $ab];
    }
    return $choices;
}

function activatedAbilityWrBlockReason(array $p, array $ab): ?string {
    $type = $ab['type'] ?? '';
    switch ($type) {
        case 'shuffle_named_from_waiting':
            $names = $ab['names'] ?? [];
            foreach ($p['waiting_room'] ?? [] as $c) {
                if (($c['card_type'] ?? '') === 'メンバー' && cardMatchesNames($c, $names)) {
                    return null;
                }
            }
            return 'no matching Members in Waiting Room.';

        case 'discard_hand_add_live_from_wr':
            if (!empty($ab['group'])) {
                $need = max(1, intval($ab['count'] ?? 1));
                $cfg = ['group' => $ab['group'] ?? '', 'filter' => $ab['filter'] ?? 'live'];
                if (wrPickMatchCount($p, $cfg, $need) >= $need) {
                    return null;
                }
                return 'no matching Live card in Waiting Room.';
            }
            if (hasWrLiveWithMinHearts(
                $p,
                intval($ab['min_required_hearts'] ?? 3),
                (string)($ab['min_required_heart_color'] ?? '')
            )) {
                return null;
            }
            return 'no matching Live card in Waiting Room.';

        case 'discard_add_from_wr':
            $need = max(1, intval($ab['count'] ?? 1));
            $cfg = [
                'group'  => $ab['group'] ?? '',
                'filter' => $ab['filter'] ?? 'member',
            ];
            if (wrPickMatchCount($p, $cfg, $need) >= $need) {
                return null;
            }
            $filter = $cfg['filter'] ?? 'member';
            if ($filter === 'live') {
                return 'no matching Live card in Waiting Room.';
            }
            return 'no matching card in Waiting Room.';

        case 'discard_cost_add_live_subunit':
        case 'wait_self_discard_add_wr_live':
        case 'activated_pay_discard_add_wr_live':
            $need = max(1, intval($ab['count'] ?? 1));
            $cfg = [
                'group'  => $ab['group'] ?? ($type === 'activated_pay_discard_add_wr_live' ? 'Nijigasaki' : ''),
                'filter' => $ab['filter'] ?? 'live',
            ];
            if (wrPickMatchCount($p, $cfg, $need) >= $need) {
                return null;
            }
            return 'no matching Live card in Waiting Room.';

        case 'activated_discard_add_wr_scored_live':
            $cfg = [
                'group'     => $ab['group'] ?? 'Sunshine',
                'filter'    => 'live',
                'min_score' => intval($ab['min_score'] ?? 1),
            ];
            if (wrPickMatchCount($p, $cfg, 1) >= 1) {
                return null;
            }
            return 'no scored Live card in Waiting Room.';

        case 'wait_self_add_wr':
            $need = max(1, intval($ab['count'] ?? 1));
            $cfg = ['group' => $ab['group'] ?? '', 'filter' => $ab['filter'] ?? ''];
            if (($cfg['filter'] ?? '') === '' && ($cfg['group'] ?? '') === '') {
                if (count($p['waiting_room'] ?? []) >= $need) {
                    return null;
                }
            } elseif (wrPickMatchCount($p, $cfg, $need) >= $need) {
                return null;
            }
            return 'no matching card in Waiting Room.';

        case 'leave_stage_add_from_wr':
            $cfg = wrPickCfgForLeaveStageAbility($ab);
            if (wrPickMatchCount($p, $cfg, 1) >= 1) {
                return null;
            }
            return 'no matching card in Waiting Room.';

        case 'pay_energy_add_from_wr':
            $need = max(1, intval($ab['count'] ?? 1));
            $cfg = ['group' => $ab['group'] ?? '', 'filter' => $ab['filter'] ?? 'member'];
            if (isset($ab['max_cost'])) {
                $cfg['max_cost'] = intval($ab['max_cost']);
            }
            if (isset($ab['max_live_score'])) {
                $cfg['max_live_score'] = intval($ab['max_live_score']);
            }
            if (wrPickMatchCount($p, $cfg, $need) >= $need) {
                return null;
            }
            return 'no matching card in Waiting Room.';

        case 'pay_energy_add_live_zone_from_wr':
            if (liveZoneCount($p['live_zone'] ?? []) >= 3) {
                return 'Live storage is full.';
            }
            $cfg = ['group' => $ab['group'] ?? '', 'filter' => $ab['filter'] ?? 'live'];
            if (isset($ab['max_live_score'])) {
                $cfg['max_live_score'] = intval($ab['max_live_score']);
            }
            if (wrPickMatchCount($p, $cfg, 1) >= 1) {
                return null;
            }
            return 'no matching Live card in Waiting Room.';

        case 'pay_energy_play_wr_empty':
            foreach (['left', 'center', 'right'] as $targetSlot) {
                if (!empty($p['stage'][$targetSlot])) {
                    continue;
                }
                foreach ($p['waiting_room'] ?? [] as $c) {
                    if (cardMatchesWrPick($c, [
                        'filter'   => 'member',
                        'max_cost' => intval($ab['max_cost'] ?? 2),
                        'group'    => $ab['group'] ?? '',
                        'subunit'  => $ab['subunit'] ?? '',
                    ])) {
                        return null;
                    }
                }
            }
            return 'no matching Member in Waiting Room.';

        case 'pay_leave_stage_play_wr_member':
        case 'activated_leave_play_wr_same_slot':
            $cfg = [
                'filter'  => 'member',
                'group'   => $ab['group'] ?? '',
                'subunit' => $ab['subunit'] ?? '',
            ];
            if (isset($ab['max_cost'])) {
                $cfg['max_cost'] = intval($ab['max_cost']);
            }
            if (wrPickMatchCount($p, $cfg, 1) >= 1) {
                return null;
            }
            return 'no matching Member in Waiting Room.';

        default:
            return null;
    }
}

function fizzleActivatedAbilityNoWr(array $state, string $pid, array $member, string $detail): array {
    $name = $member['name_en'] ?? $member['name'] ?? 'Member';
    $state = addLog($state, $state['players'][$pid]['name'] .
        ' — [' . $name . '] ' . $detail);
    $state['seq']++;
    return $state;
}

function takeWrMemberToStageSlot(array &$p, array $cfg, string $slot): ?array {
    foreach ($p['waiting_room'] as $i => $c) {
        if (!cardMatchesWrPick($c, array_merge($cfg, ['filter' => 'member']))) continue;
        $member = $c;
        array_splice($p['waiting_room'], $i, 1);
        $member['active'] = false;
        $p['stage'][$slot] = $member;
        return $member;
    }
    return null;
}

function autoDiscardFromHand(array &$p, int $count): int {
    $n = 0;
    while ($n < $count && !empty($p['hand'])) {
        $p['waiting_room'][] = array_shift($p['hand']);
        $n++;
    }
    return $n;
}

function cardDisplayName(array $card): string {
    return $card['name_en'] ?? $card['name'] ?? 'a card';
}

function cardLogArticle(array $card): string {
    $type = $card['card_type'] ?? '';
    if ($type === 'ライブ') {
        return 'a Live card';
    }
    if ($type === 'メンバー') {
        return 'a Member card';
    }
    if ($type === 'エネルギー') {
        return 'an Energy card';
    }
    return 'a card';
}

function effectLogDrewDetail(array $card): array {
    return [
        'drew ' . cardDisplayName($card) . '.',
        'drew ' . cardLogArticle($card) . '.',
    ];
}

function effectLogPutWrDetail(array $card): array {
    return [
        'put ' . cardDisplayName($card) . ' into the Waiting Room.',
        'put ' . cardLogArticle($card) . ' into the Waiting Room.',
    ];
}

function redactEffectDetailForOpponent(string $detail): string {
    $rules = [
        '/\bdrew [^.]+\./' => 'drew a card.',
        '/\bput [^.]+ into the Waiting Room\./' => 'put a card into the Waiting Room.',
        '/\brevealed [^.]+ from deck top\./' => 'revealed a card from deck top.',
        '/\brevealed [^.]+ from deck and added it to hand\./' => 'revealed a card from deck and added it to hand.',
        '/\bopponent revealed [^.]+ from hand\./' => 'opponent revealed a card from hand.',
        '/\badded [^.]+ from Waiting Room to hand\./' => 'added a card from Waiting Room to hand.',
        '/\badded [^.]+ on top of deck\./' => 'added a card on top of deck.',
        '/\bdiscarded [^.]+\./' => 'discarded a card.',
    ];
    foreach ($rules as $pattern => $replacement) {
        $detail = preg_replace($pattern, $replacement, $detail);
    }
    return $detail;
}

function logEffectDraw(array $state, string $pid, string $sourceName, array $card, array $anim = []): array {
    [$detail, $detailPublic] = effectLogDrewDetail($card);
    return logEffectStep($state, $pid, $sourceName, $detail, $anim, $detailPublic);
}

function logEffectPutWr(array $state, string $pid, string $sourceName, array $card, array $anim = []): array {
    [$detail, $detailPublic] = effectLogPutWrDetail($card);
    return logEffectStep($state, $pid, $sourceName, $detail, $anim, $detailPublic);
}

function addEffectLog(
    array $state,
    string $pid,
    string $sourceName,
    string $detailPrivate,
    ?string $detailPublic = null,
    array $anim = []
): array {
    $prefix = $state['players'][$pid]['name'] . ' — [' . $sourceName . '] ';
    if ($detailPublic === null) {
        $detailPublic = redactEffectDetailForOpponent($detailPrivate);
    }
    $opts = [];
    if ($detailPublic !== $detailPrivate) {
        $opts = ['owner' => $pid, 'msg_public' => $prefix . $detailPublic];
    }
    return addLog($state, $prefix . $detailPrivate, 'effect', $anim, $opts);
}

function playerChoiceLabelText(array $choice): string {
    $label = trim($choice['label'] ?? '');
    $effect = $choice['effect'] ?? [];
    $type = $effect['type'] ?? '';
    if ($type === 'draw_and_discard') {
        $draw = intval($effect['draw'] ?? 1);
        $discard = intval($effect['discard'] ?? 1);
        return 'Draw ' . $draw . ' card' . ($draw === 1 ? '' : 's') .
            ' and put ' . $discard . ' card' . ($discard === 1 ? '' : 's') .
            ' from your hand into the Waiting Room.';
    }
    if ($type === 'wait_opponent_stage_max_cost') {
        $max = intval($effect['max_cost'] ?? 2);
        $pick = isset($effect['pick_count']) ? intval($effect['pick_count']) : null;
        if ($pick === 1) {
            return 'Put 1 opponent Stage Member with cost ' . $max . ' or less into Wait.';
        }
        return 'Put all opponent Stage Members with cost ' . $max . ' or less into Wait.';
    }
    if ($type === 'look_top_optional_wr') {
        $who = ($effect['target'] ?? '') === 'opponent' ? "opponent's" : 'your';
        return 'Look at the top card of ' . $who .
            ' deck. You may put it into the Waiting Room.';
    }
    if ($type === 'look_deck_top_arrange') {
        $look = intval($effect['look'] ?? 2);
        $who = ($effect['target'] ?? '') === 'opponent' ? "opponent's" : 'your';
        return 'Look at the top ' . $look . ' card' . ($look === 1 ? '' : 's') . ' of ' . $who .
            ' deck. Put any number on top in any order; send the rest to the Waiting Room.';
    }
    if ($type === 'activate_one_member') {
        return 'Activate 1 Member on your Stage.';
    }
    if ($type === 'activate_energy') {
        $count = intval($effect['count'] ?? 1);
        return 'Activate ' . $count . ' Energy.';
    }
    return $label !== '' ? $label : 'Choose this option';
}

function buildPlayerChoicePromptFields(array $ab): array {
    $labels = [];
    foreach ($ab['choices'] ?? [] as $choice) {
        $labels[] = playerChoiceLabelText(is_array($choice) ? $choice : []);
    }
    $prompt = trim($ab['prompt'] ?? '');
    if ($prompt === '' || $prompt === 'Choose an effect:') {
        $prompt = 'Choose one:';
    }
    return ['prompt' => $prompt, 'choice_labels' => $labels];
}

function buildOpponentTextAnswerPromptFields(array $ab): array {
    return [
        'prompt'        => trim($ab['prompt'] ?? 'What do you like?'),
        'outcome_hints' => opponentTextAnswerOutcomeHints($ab),
    ];
}

function opponentTextAnswerOutcomeHints(array $ab): array {
    $promptKey = trim($ab['prompt'] ?? '');
    if ($promptKey === 'What do you like?') {
        return [
            'Chocolate mint, strawberry flavor, or cookies & cream → each player puts 1 card from hand into the Waiting Room.',
            '"You" → each player draws 1 card.',
            'Anything else → all Stage Members gain +1 Blade until this Live ends.',
        ];
    }
    $hints = [];
    foreach ($ab['choices'] ?? [] as $key => $choice) {
        $label = playerChoiceLabelText(is_array($choice) ? $choice : []);
        if ($key === 'other') {
            $hints[] = 'Anything else → ' . $label;
        } else {
            $hints[] = $label;
        }
    }
    return $hints;
}

function normalizeAnswerText(string $raw): string {
    $s = mb_strtolower(trim($raw), 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}\s&]/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

function classifyAiScreamAnswer(string $raw): string {
    $s = normalizeAnswerText($raw);
    if ($s === '' && trim($raw) === '') {
        return 'other';
    }

    if (preg_match('/^(あなた|きみ|君|アナタ)/u', trim($raw))) {
        return 'you';
    }
    if (preg_match('/^(you|u|yu|youu|yuo|yoo|anata|kimi)$/u', $s)) {
        return 'you';
    }
    if (mb_strlen($s) <= 16 && preg_match('/\byou\b/u', $s)) {
        return 'you';
    }

    if (preg_match('/チョコミント|ストロベリー|クッキー/u', $raw)) {
        return 'choco_mint';
    }

    $flavorNeedles = [
        'chocominto', 'chocomint', 'choco mint', 'chocolate mint', 'mint choco', 'mint chocolate',
        'chocmint', 'choc mint', 'cho co mint', 'chocolatemint',
        'strawberry', 'straw berry', 'strawberry flavor', 'strawberry flavour',
        'ichigo', 'cookies and cream', 'cookie and cream', 'cookies n cream', 'cookies cream',
        'cookie cream', 'cookies & cream', 'oreo',
    ];
    foreach ($flavorNeedles as $needle) {
        if ($needle !== '' && mb_strpos($s, $needle) !== false) {
            return 'choco_mint';
        }
    }

    return 'other';
}

function classifyOpponentTextAnswer(array $ab, string $raw): string {
    $prompt = trim($ab['prompt'] ?? '');
    if ($prompt === 'What do you like?') {
        return classifyAiScreamAnswer($raw);
    }
    $batch99 = batch99ClassifyTextAnswer($ab, $raw);
    if ($batch99 !== null) {
        return $batch99;
    }
    return 'other';
}

function opponentTextAnswerOutcomeLabel(array $choiceEntry): string {
    $effect = is_array($choiceEntry) ? ($choiceEntry['effect'] ?? []) : [];
    $type = $effect['type'] ?? '';
    if ($type === 'both_discard_hand') {
        return 'each player puts 1 card from hand into the Waiting Room';
    }
    if ($type === 'both_draw') {
        return 'each player draws 1 card';
    }
    if ($type === 'both_stages_blade_bonus') {
        $amt = intval($effect['amount'] ?? 1);
        return 'all Stage Members gain +' . $amt . ' Blade until this Live ends';
    }
    return playerChoiceLabelText(is_array($choiceEntry) ? $choiceEntry : []);
}

function logOpponentMembersWaitedOutcome(
    array $state,
    string $ownerPid,
    string $srcName,
    int $waited,
    int $maxCost
): array {
    $prefix = $state['players'][$ownerPid]['name'] . ' — [' . $srcName . '] ';
    if ($waited > 0) {
        return addLog($state, $prefix . "put $waited opponent Stage Member" .
            ($waited === 1 ? '' : 's') . ' into Wait (cost ≤' . $maxCost . ').');
    }
    return addLog($state, $prefix .
        'no opponent Stage Members matched (cost ≤' . $maxCost . '); none put into Wait.');
}

function applyDrawThenDiscard(
    array $state,
    string $pid,
    array &$p,
    string $name,
    int $draw,
    int $discard,
    array $extra = []
): array {
    $drawnCards = drawCardInstances($p, $draw);
    foreach ($drawnCards as $c) {
        $state = logEffectDraw($state, $pid, $name, $c,
            [animSpec($c['instance_id'], 'main_deck', 'hand', $pid)]);
    }
    if ($draw > 0 && empty($drawnCards)) {
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] could not draw (deck empty).");
    }
    if ($discard > 0 && !empty($p['hand'])) {
        return startEffectDiscardHandPrompt($state, $pid, $name, $discard, '', $extra);
    }
    if ($discard > 0 && empty($p['hand'])) {
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] drew " . count($drawnCards) . ' but had no cards in hand to discard.');
    }
    return $state;
}

function applyChoiceEffect(array $state, string $owner, array &$ownerP, array $effect, array $prompt): array {
    $srcName = $prompt['source_name'] ?? 'Member';
    $type = $effect['type'] ?? '';

    if ($type === 'both_discard_hand') {
        $discarded = ['p1' => 0, 'p2' => 0];
        $emptyHand = [];
        foreach (['p1', 'p2'] as $id) {
            $pl = &$state['players'][$id];
            if (!empty($pl['hand'])) {
                $c = array_shift($pl['hand']);
                $pl['waiting_room'][] = $c;
                $discarded[$id]++;
                $state = logEffectPutWr($state, $id, 'Live Start', $c,
                    [animSpec($c['instance_id'], 'hand', 'waiting_room', $id)]);
            } else {
                $emptyHand[] = $state['players'][$id]['name'];
            }
        }
        unset($pl);
        $total = $discarded['p1'] + $discarded['p2'];
        $srcTag = $srcName !== 'Member' ? "[$srcName] " : '';
        if ($total > 0) {
            $state = addLog($state, $srcTag . 'Both players put ' . $total . ' card' .
                ($total === 1 ? '' : 's') . ' from hand into the Waiting Room (' .
                $state['players']['p1']['name'] . ': ' . $discarded['p1'] . ', ' .
                $state['players']['p2']['name'] . ': ' . $discarded['p2'] . ').');
        } else {
            $state = addLog($state, $srcTag . 'Neither player had cards in hand to put into the Waiting Room.');
        }
        if (!empty($emptyHand)) {
            $names = implode(' and ', $emptyHand);
            $state = addLog($state, $srcTag . $names .
                (count($emptyHand) === 1 ? ' had no card in hand to discard.' : ' had no cards in hand to discard.'));
        }
        return $state;
    }
    if ($type === 'both_draw') {
        $drawn = ['p1' => 0, 'p2' => 0];
        foreach (['p1', 'p2'] as $id) {
            $drawnCards = drawCardInstances($state['players'][$id], intval($effect['count'] ?? 1));
            $drawn[$id] = count($drawnCards);
            foreach ($drawnCards as $c) {
                $state = logEffectDraw($state, $id, 'Live Start', $c,
                    [animSpec($c['instance_id'], 'main_deck', 'hand', $id)]);
            }
        }
        $total = $drawn['p1'] + $drawn['p2'];
        $srcTag = $srcName !== 'Member' ? "[$srcName] " : '';
        if ($total > 0) {
            $state = addLog($state, $srcTag . 'Both players drew (' .
                $state['players']['p1']['name'] . ': ' . $drawn['p1'] . ', ' .
                $state['players']['p2']['name'] . ': ' . $drawn['p2'] . ').');
        } else {
            $state = addLog($state, $srcTag . 'Neither player could draw (deck empty).');
        }
        return $state;
    }
    if ($type === 'both_stages_blade_bonus') {
        $state = applyModifierEffect($state, $owner, $effect);
        return addLog($state, 'Both players\' Stage Members gain +' .
            intval($effect['amount'] ?? 1) . ' Blade until this Live ends.');
    }
    if ($type === 'draw_and_discard') {
        return applyDrawThenDiscard(
            $state,
            $owner,
            $ownerP,
            $srcName,
            intval($effect['draw'] ?? 1),
            intval($effect['discard'] ?? 1)
        );
    }
    if ($type === 'wait_opponent_stage_max_cost') {
        return beginWaitOpponentStagePick(
            $state,
            $owner,
            $srcName,
            $effect,
            $prompt['source_id'] ?? '',
            ($state['phase'] ?? '') === 'live_start_effects' || !empty($prompt['live_start'])
        );
    }
    if ($type === 'look_top_optional_wr') {
        $target = ($effect['target'] ?? '') === 'opponent'
            ? (($owner === 'p1') ? 'p2' : 'p1')
            : $owner;
        $pl = &$state['players'][$target];
        if (empty($pl['main_deck'])) {
            return addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] looked at deck top (empty).');
        }
        $top = $pl['main_deck'][0];
        $label = cardDisplayName($top);
        $state['pending_prompt'] = [
            'type'          => 'look_top_optional_wr',
            'owner'         => $owner,
            'responder'     => $owner,
            'target'        => $target,
            'source_name'   => $srcName,
            'top_card'      => cardPromptSummary($top),
            'prompt'        => "Looked at $label on top of " .
                ($target === $owner ? 'your' : "opponent's") .
                ' deck. Put it into the Waiting Room?',
            'choices'       => ['yes', 'no'],
            'choice_labels' => ['Yes — Put in WR', 'No — Leave on top'],
        ];
        $state = addLog($state, $state['players'][$owner]['name'] .
            " — [$srcName] looked at deck top ($label).");
        return $state;
    }
    if ($type === 'look_deck_top_arrange') {
        $target = ($effect['target'] ?? '') === 'opponent'
            ? (($owner === 'p1') ? 'p2' : 'p1')
            : $owner;
        $pl = &$state['players'][$target];
        $look = intval($effect['look'] ?? 2);
        $top = array_splice($pl['main_deck'], 0, min($look, count($pl['main_deck'])));
        if (empty($top)) {
            return addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] looked at deck top (empty).');
        }
        return startSurveilArrangePrompt($state, $owner, $srcName, $top, [
            'target' => $target,
        ]);
    }
    if ($type !== '') {
        $sourceId = $prompt['source_id'] ?? '';
        $source = ($sourceId !== '')
            ? (findLiveStartSourceCard($state, $owner, $sourceId)
                ?? findSourceCard($state, $owner, $sourceId))
            : null;
        if (!$source) {
            $source = ['name_en' => $srcName, 'name' => $srcName];
            if ($sourceId !== '') {
                $source['instance_id'] = $sourceId;
            }
        }
        $phase = ($state['phase'] ?? '') === 'live_start_effects' ? 'live_start' : 'choice';
        return resolveAbilityEffect($state, $owner, $source, $effect, ['phase' => $phase]);
    }
    return $state;
}

function animSpec(string $iid, string $from, string $to, string $pid, array $extra = []): array {
    return array_merge([
        'iid'  => $iid,
        'from' => $from,
        'to'   => $to,
        'pid'  => $pid,
    ], $extra);
}

function liveZoneSlotOf(array $card, int $fallbackIndex = 0): int {
    if (isset($card['live_slot']) && $card['live_slot'] >= 0 && $card['live_slot'] <= 2) {
        return intval($card['live_slot']);
    }
    return max(0, min(2, $fallbackIndex));
}

function countLiveCardsInZone(array $zone): int {
    return count(array_filter($zone, fn($c) => $c && isLiveTypeCard($c)));
}

function liveZoneCount(array $zone): int {
    $n = 0;
    foreach ($zone as $c) {
        if (!empty($c['instance_id'])) {
            $n++;
        }
    }
    return $n;
}

function liveZoneFirstEmptySlot(array $zone): int {
    $used = [];
    foreach ($zone as $i => $c) {
        if (empty($c['instance_id'])) {
            continue;
        }
        $used[liveZoneSlotOf($c, $i)] = true;
    }
    for ($s = 0; $s < 3; $s++) {
        if (empty($used[$s])) {
            return $s;
        }
    }
    return -1;
}

function liveZoneDiscardAnims(array $zone, string $pid): array {
    $anims = [];
    foreach ($zone as $li => $c) {
        if (empty($c['instance_id'])) {
            continue;
        }
        $anims[] = animSpec($c['instance_id'], 'live', 'waiting_room', $pid, [
            'from_index' => liveZoneSlotOf($c, $li),
        ]);
    }
    return $anims;
}

function logEffectStep(
    array $state,
    string $pid,
    string $sourceName,
    string $detail,
    array $anim = [],
    ?string $detailPublic = null
): array {
    $prefix = $state['players'][$pid]['name'] . ' — [' . $sourceName . '] ';
    if ($detailPublic === null) {
        $detailPublic = redactEffectDetailForOpponent($detail);
    }
    $opts = [];
    if ($detailPublic !== $detail) {
        $opts = ['owner' => $pid, 'msg_public' => $prefix . $detailPublic];
    }
    return addLog($state, $prefix . $detail, 'effect', $anim, $opts);
}

function resolveOnLeaveStageAbilities(array $state, string $pid, array &$member, array $ctx = []): array {
    $p = &$state['players'][$pid];
    mergeCardCatalogFields($member);
    $returned = returnStackedEnergyOnLeave($p, $member);
    if ($returned > 0) {
        $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — $returned Energy under [$mName] returned to Energy Zone (inactive).");
    }
    $abilities = getAbilitiesByTrigger($member, 'on_leave_stage');
    if (empty($abilities)) {
        return $state;
    }
    $state = logAbilityChain($state, $pid, $member, 'on_leave_stage');
    foreach ($abilities as $ab) {
        $state = resolveAbilityEffect($state, $pid, $member, $ab, array_merge(['phase' => 'on_leave_stage'], $ctx));
        if (!empty($state['pending_prompt'])) {
            break;
        }
    }
    return $state;
}

// applyModifierEffect — see src/Game/LiveModifiers.php

// resumeLiveStartEffectPhase — see src/Game/LiveStartEffects.php

function beginWaitOpponentStagePick(
    array $state,
    string $owner,
    string $srcName,
    array $effect,
    string $sourceId = '',
    bool $liveStart = false
): array {
    $opp = ($owner === 'p1') ? 'p2' : 'p1';
    $maxCost = intval($effect['max_cost'] ?? 9);
    $pickCount = isset($effect['pick_count']) ? intval($effect['pick_count']) : null;
    $activeOnly = !empty($effect['active_only']);
    $members = listOppStageMembersByMaxCost($state, $opp, $maxCost, $activeOnly);
    if (empty($members)) {
        return logOpponentMembersWaitedOutcome($state, $owner, $srcName, 0, $maxCost);
    }
    if ($pickCount === null || $pickCount > 1) {
        $waited = waitOpponentStageByCost($state, $opp, $maxCost, $pickCount, $owner);
        return logOpponentMembersWaitedOutcome($state, $owner, $srcName, $waited, $maxCost);
    }
    if (count($members) === 1) {
        waitOpponentMemberAtSlot($state, $opp, $members[0]['slot'], $owner);
        return logOpponentMembersWaitedOutcome($state, $owner, $srcName, 1, $maxCost);
    }
    $state['pending_prompt'] = [
        'type'          => 'wait_opponent_stage_pick',
        'step'          => 'pick_opp_wait',
        'owner'         => $owner,
        'responder'     => $opp,
        'opp'           => $opp,
        'source_id'     => $sourceId,
        'source_name'   => $srcName,
        'live_start'    => $liveStart,
        'prompt'        => 'Choose 1 active Member on your Stage to put into Wait.',
        'candidates'    => $members,
        'max_cost'      => $maxCost,
        'ability'       => $effect,
    ];
    $state['seq']++;
    return $state;
}

function abilityTriggerLabel(string $trigger): string {
    return match ($trigger) {
        'on_enter'               => 'On Enter',
        'on_enter_or_live_start' => 'Live Start',
        'live_start'             => 'Live Start',
        'live_success'           => 'Live Success',
        'on_leave_stage'         => 'Leave Stage',
        'activated'              => 'Activated',
        'continuous'             => 'Always',
        default                  => ucfirst(str_replace('_', ' ', $trigger)),
    };
}

function logAbilityChain(array $state, string $pid, array $source, string $trigger): array {
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';
    return addLog(
        $state,
        $state['players'][$pid]['name'] . ' — [' . $name . '] ' . abilityTriggerLabel($trigger) . ':',
        'effect'
    );
}

// markMemberDualEnterLiveStartFired, shouldSkipDualEnterLiveStartAtLiveStart — see src/Game/LiveStartEffects.php

function resolveOnEnterAbilities(array $state, string $pid, array $member, string $slot = ''): array {
    $abilities = getAbilitiesByTrigger($member, 'on_enter');
    if (!empty($abilities)) {
        $state = logAbilityChain($state, $pid, $member, 'on_enter');
        foreach ($abilities as $ab) {
            if (($ab['trigger'] ?? '') === 'on_enter_or_live_start') {
                $state = markMemberDualEnterLiveStartFired($state, $pid, $member['instance_id'] ?? '');
            }
            $state = resolveAbilityEffect($state, $pid, $member, $ab, [
                'slot'  => $slot,
                'phase' => 'on_enter',
            ]);
            if (!empty($state['pending_prompt'])) {
                break;
            }
        }
    }
    // Auto-on-other-enter must run for every Stage enter, even when the entering Member
    // has no On Enter abilities (e.g. SD Kaho with only Automatic/on-leave text).
    $state = hsResolveAutoOnOtherMemberEnter($state, $pid, $member);
    $state = hsPb1ExtendAutoOnOtherMemberEnter($state, $pid, $member);
    return $state;
}

// resolveLiveStartAbilities, isQueuedOptionalLiveStart — see src/Game/LiveStartEffects.php

function findSourceCard(array $state, string $pid, string $instanceId): ?array {
    $p = $state['players'][$pid];
    foreach ($p['stage'] as $m) {
        if ($m && ($m['instance_id'] ?? '') === $instanceId) return $m;
    }
    foreach ($p['live_zone'] as $c) {
        if ($c && ($c['instance_id'] ?? '') === $instanceId) return $c;
    }
    return null;
}

function yellPromptCandidateIds(array $prompt): array {
    return array_values(array_filter(array_map(
        fn($c) => $c['instance_id'] ?? '',
        $prompt['candidates'] ?? []
    )));
}

/** Remove one card from _pending_yell_wr; validates against prompt candidates when set. */
function takeFromPendingYellPool(
    array &$ownerP,
    string $cardId,
    array $prompt,
    ?array $state = null,
    ?string $owner = null
): ?array {
    $eligibleIds = yellPromptCandidateIds($prompt);
    if ($cardId === '' && count($eligibleIds) === 1) {
        $cardId = $eligibleIds[0];
    }
    if ($cardId === '') {
        return null;
    }
    if (!empty($eligibleIds) && !in_array($cardId, $eligibleIds, true)) {
        return null;
    }

    $searchPools = [];
    if (!empty($ownerP['_pending_yell_wr'])) {
        $searchPools[] = $ownerP['_pending_yell_wr'];
    }
    if ($state !== null && $owner !== null) {
        if (!empty($state['_last_yell_cards'])) {
            $searchPools[] = $state['_last_yell_cards'];
        }
        if (!empty($state['yell_reveal'][$owner])) {
            $searchPools[] = $state['yell_reveal'][$owner];
        }
        if (!empty($ownerP['yell_cards'])) {
            $searchPools[] = $ownerP['yell_cards'];
        }
    }

    $picked = null;
    foreach ($searchPools as $pool) {
        foreach ($pool as $c) {
            if (($c['instance_id'] ?? '') === $cardId) {
                $picked = $c;
                break 2;
            }
        }
    }
    if (!$picked) {
        foreach ($ownerP['waiting_room'] ?? [] as $c) {
            if (($c['instance_id'] ?? '') === $cardId) {
                $picked = $c;
                break;
            }
        }
    }
    if (!$picked) {
        foreach ($prompt['candidates'] ?? [] as $c) {
            if (($c['instance_id'] ?? '') === $cardId) {
                $picked = $c;
                mergeYellCardCatalogFields($picked);
                break;
            }
        }
    }
    if (!$picked) {
        return null;
    }

    $rest = [];
    foreach ($ownerP['_pending_yell_wr'] ?? [] as $c) {
        if (($c['instance_id'] ?? '') !== $cardId) {
            $rest[] = $c;
        }
    }
    $ownerP['_pending_yell_wr'] = $rest;
    return $picked;
}

function cardPromptSummary(array $c): array {
    $summary = [
        'instance_id' => $c['instance_id'] ?? '',
        'name_en'     => $c['name_en'] ?? $c['name'] ?? 'Card',
        'card_no'     => $c['card_no'] ?? '',
        'image'       => $c['image'] ?? '',
        'card_type'   => $c['card_type'] ?? '',
        'card_type_en'=> $c['card_type_en'] ?? '',
    ];
    if (array_key_exists('cost', $c)) {
        $summary['cost'] = $c['cost'];
    }
    if (array_key_exists('group', $c)) {
        $summary['group'] = $c['group'];
    }
    if (array_key_exists('blade', $c)) {
        $summary['blade'] = $c['blade'];
    }
    if (array_key_exists('score', $c)) {
        $summary['score'] = $c['score'];
    }
    if (!empty($c['subunit'])) {
        $summary['subunit'] = $c['subunit'];
    }
    // CPU / client WR filters need subunit on summaries (e.g. Sayaka DOLLCHESTRA pick).
    if (function_exists('cardEffectiveSubunits')) {
        $subs = cardEffectiveSubunits($c);
        if (!empty($subs)) {
            $summary['subunits'] = array_values($subs);
        }
    } elseif (!empty($c['subunits']) && is_array($c['subunits'])) {
        $summary['subunits'] = array_values($c['subunits']);
    }
    return $summary;
}

// collectOptionalLiveStartAbilities, liveStartOptionalPromptText, buildOptionalLiveStartPrompt — see src/Game/LiveStartEffects.php

// buildInternalOptionalDiscardConfirmPrompt, enrichSelfActivationPrompt, enrichAbilityContextPrompt — see src/Game/PromptEnrichment.php


function applySurveilArrangement(array &$p, array $lookedCards, array $topIds, array $wrIds): void {
    $byId = [];
    foreach ($lookedCards as $c) {
        $byId[$c['instance_id'] ?? ''] = $c;
    }
    $top = [];
    foreach ($topIds as $id) {
        if (isset($byId[$id])) {
            $top[] = $byId[$id];
            unset($byId[$id]);
        }
    }
    foreach ($wrIds as $id) {
        if (isset($byId[$id])) {
            $p['waiting_room'][] = $byId[$id];
            unset($byId[$id]);
        }
    }
    foreach ($byId as $c) {
        $p['waiting_room'][] = $c;
    }
    if (!empty($top)) {
        $p['main_deck'] = array_merge($top, $p['main_deck']);
    }
}

// finishLiveStartEffects — see src/Game/LiveStartEffects.php

// resolveAbilityEffect — see src/Game/AbilityResolver.php

// actionActivateAbility — see src/Game/ActivateAbility.php


function persistActivatedMemberAfterUse(array &$p, array $member, ?string $slot, string $zone, ?int $wrIndex): void {
    if ($zone === 'waiting_room' && $wrIndex !== null && isset($p['waiting_room'][$wrIndex])) {
        $p['waiting_room'][$wrIndex] = $member;
        return;
    }
    if ($zone === 'stage' && $slot !== null && $slot !== '') {
        $p['stage'][$slot] = $member;
    }
}

function beginWaitSubunitOppActiveChain(array $state, string $owner, array $prompt): array {
    $ability = $prompt['ability'] ?? [];
    $sourceId = $prompt['source_id'] ?? '';
    $ownerP = &$state['players'][$owner];
    if (!empty($ability['center_only']) && findMemberSlot($ownerP, $sourceId) !== 'center') {
        unset($state['pending_prompt']);
        return addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped (must be in Center).');
    }
    $subunit = $ability['subunit'] ?? '';
    $members = listSubunitStageMembers($ownerP, $subunit);
    if (empty($members)) {
        unset($state['pending_prompt']);
        return addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] no $subunit Members on Stage.");
    }
    $state['pending_prompt'] = [
        'type'          => 'wait_subunit_member_pick',
        'owner'         => $owner,
        'responder'     => $owner,
        'source_id'     => $sourceId,
        'source_name'   => $prompt['source_name'] ?? 'Member',
        'subunit'       => $subunit,
        'max_members'   => intval($ability['max_members'] ?? 1),
        'min_members'   => 1,
        'stage_members' => $members,
        'prompt'        => 'Choose 1 ' . $subunit . ' Member to put into Wait.',
        'ability'       => $ability,
    ];
    return $state;
}

// isOptionalPromptType, isMandatorySkillPrompt, buildTimeoutPromptResolution, actionAntiSoftlockSkipPrompt — see src/Game/PromptEnrichment.php


// resolveOptionalDiscardPromptChoice — see src/Game/PromptEnrichment.php


// actionResolvePrompt — see src/Game/PromptResolver.php


// actionLiveStartChoice, beginLiveStartEffectPhase — see src/Game/LiveStartEffects.php


