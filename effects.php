<?php
/**
 * Love Live! Official Card Game — card ability resolution.
 *
 * Included by api.php. Card skills are stored in cards.json as structured "abilities"
 * (trigger + type + params). resolveAbilityEffect dispatches on type; set-specific
 * handlers live in *_effects.php modules. actionResolvePrompt completes interactive
 * prompts; actionActivateAbility handles [Activated] costs on stage Members.
 *
 * Hooks tie into turn flow: on_enter, live_start, continuous, live_success, on_leave_stage,
 * yell reveal auto-effects, and Live modifier state (bonus hearts, blade, score).
 */

require_once __DIR__ . '/subunits.php';
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
    foreach (['abilities', 'text', 'text_jp'] as $oracleKey) {
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

// ─────────────────────────────────────────────
// Live modifiers (until this Live ends)
// ─────────────────────────────────────────────

function initLiveModifiers(array $state): array {
    if (!isset($state['live_modifiers'])) {
        $state['live_modifiers'] = ['p1' => liveModifierDefaults(), 'p2' => liveModifierDefaults()];
    }
    return $state;
}

function liveModifierDefaults(): array {
    return [
        'score_bonus'              => 0,
        'blade_bonus'              => 0,
        'both_stages_blade'        => 0,
        'bonus_hearts'             => [],
        'blade_per_live_zone'      => 0,
        'yell_reveal_reduction'    => 0,
        'blade_per_hand_divisor'   => 0,
        'blade_per_hand_amount'    => 0,
    ];
}

function addBonusHeartsToModifier(array &$state, string $pid, array $heartsSpec): void {
    $state = initLiveModifiers($state);
    foreach ($heartsSpec as $h) {
        $color = is_array($h) ? ($h['color'] ?? '') : (string)$h;
        $count = is_array($h) ? intval($h['count'] ?? 1) : 1;
        for ($i = 0; $i < $count; $i++) {
            $state['live_modifiers'][$pid]['bonus_hearts'][] = $color;
        }
    }
}

function getBonusHeartsFlat(array $state, string $pid): array {
    $state = initLiveModifiers($state);
    return $state['live_modifiers'][$pid]['bonus_hearts'] ?? [];
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

function waitFirstGroupMember(array &$p, string $group): bool {
    foreach ($p['stage'] as &$mbr) {
        if ($mbr && ($mbr['group'] ?? '') === $group) {
            waitMember($mbr);
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

function getLiveTotalScore(array $state, string $pid): int {
    $p = $state['players'][$pid] ?? [];
    $zone = $p['live_zone'] ?? [];
    if (empty($zone)) {
        return 0;
    }
    return array_sum(array_column($zone, 'score')) + getLiveScoreBonus($state, $pid);
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
                $state = hsResolveHasunosoraEffect($state, $pid, $member, $ab, ['yell_cards' => $yellCards]);
                if (!empty($state['pending_prompt'])) {
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
    foreach ($card['hearts'] ?? [] as $hg) {
        if (intval($hg['count'] ?? 0) > 0) return true;
    }
    return false;
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
    return cardMatchesGroup($card, $cfg['group'] ?? '', $cfg['filter'] ?? '');
}

function lookPickIsOptional(array $cfg): bool {
    if (array_key_exists('optional_pick', $cfg)) {
        return !empty($cfg['optional_pick']);
    }
    $filter = $cfg['filter'] ?? '';
    $group = $cfg['group'] ?? '';
    return $filter !== '' || $group !== '';
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
    if ($type === 'energy_wait_from_deck') {
        return max(1, intval($then['count'] ?? 1));
    }
    return 0;
}

/** True when optional discard's follow-up can still look/mill at least one deck card. */
function optionalDiscardThenViable(array $p, array $then): bool {
    $need = optionalThenDeckLookMin($then);
    if ($need <= 0) {
        return true;
    }
    return count($p['main_deck'] ?? []) >= 1;
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

    $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
    if (empty($top)) {
        return addLog($state, $state['players'][$pid]['name'] .
            " — [$name] looked at deck top; no cards.");
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
    $promptText = $optional
        ? ($pickCount === 1
            ? 'Choose 1 card to add to your hand (or skip).'
            : "Choose up to $pickCount card(s) to add to your hand (or skip).")
        : ($pickCount === 1
            ? 'Choose 1 card to add to your hand.'
            : "Choose $pickCount card(s) to add to your hand.");

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
        if (!$member || !($member['active'] ?? true)) {
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
        if (!($mbr['active'] ?? true)) continue;
        $snap = memberSnapshot($mbr);
        waitMember($mbr);
        if ($effectSourcePid) {
            $state = resolveAutomaticOpponentWaitEffects($state, $effectSourcePid, $snap);
        }
        $waited++;
        if ($waited >= $count) break;
    }
    unset($mbr);
    return $waited;
}

function clearLiveModifiers(array $state): array {
    $state['live_modifiers'] = ['p1' => liveModifierDefaults(), 'p2' => liveModifierDefaults()];
    foreach (['p1', 'p2'] as $pid) {
        $p = &$state['players'][$pid];
        $wrIds = array_column($p['waiting_room'] ?? [], 'instance_id');
        foreach ($p['stage'] as $slot => &$mbr) {
            if (!$mbr) continue;
            $iid = $mbr['instance_id'] ?? '';
            if ($iid !== '' && in_array($iid, $wrIds, true)) {
                $p['stage'][$slot] = null;
                continue;
            }
            unset($mbr['bonus_hearts'], $mbr['live_blade_bonus'], $mbr['live_score_bonus']);
            unset($mbr['on_enter_or_live_start_fired']);
        }
        unset($mbr, $p);
    }
    unset($state['pending_prompt']);
    unset(
        $state['_last_yell_score_icons'],
        $state['_last_yell_live_count'],
        $state['_last_yell_cards']
    );
    foreach (['p1', 'p2'] as $pid) {
        unset($state['_last_yell_live_count_' . $pid]);
    }
    return $state;
}

/** Yell score icons / yell-Live counts only apply during the current Performance round. */
function isLiveScoreYellContext(array $state): bool {
    if (function_exists('isInPerformancePhase')) {
        return isInPerformancePhase($state);
    }
    return in_array($state['phase'] ?? '', [
        'live_performance_first',
        'live_performance_second',
        'live_success_effects',
        'live_judge',
    ], true);
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
    return count(array_filter($liveZone, fn($c) => $c && ($c['group'] ?? '') === $group));
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
        if (!$member || !($member['active'] ?? true)) {
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

function finishPromptEffects(array $state): array {
    $phase = $state['phase'] ?? '';
    if ($phase === 'live_success_effects') {
        return finishLiveSuccessEffects($state);
    }
    if ($phase === 'live_start_effects') {
        return finishLiveStartEffects($state);
    }
    return $state;
}

/** After placing a Member from a prompt: keep chained On Enter prompts. */
function returnAfterPlacedMemberEnter(array $state, bool $finishLiveStart = false): array {
    if (!empty($state['pending_prompt'])) {
        $state['seq']++;
        return $state;
    }
    unset($state['pending_prompt']);
    $state['seq']++;
    return $finishLiveStart ? finishLiveStartEffects($state) : finishPromptEffects($state);
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

function getStageBladeBonus(array $state, string $pid): int {
    $state = initLiveModifiers($state);
    $bonus = intval($state['live_modifiers'][$pid]['blade_bonus'] ?? 0);
    $perLive = intval($state['live_modifiers'][$pid]['blade_per_live_zone'] ?? 0);
    if ($perLive > 0) {
        $bonus += count($state['players'][$pid]['live_zone'] ?? []) * $perLive;
    }
    return $bonus;
}

function getBothStagesBladeBonus(array $state): int {
    $state = initLiveModifiers($state);
    $a = intval($state['live_modifiers']['p1']['both_stages_blade'] ?? 0);
    $b = intval($state['live_modifiers']['p2']['both_stages_blade'] ?? 0);
    return max($a, $b);
}

function stageMemberWhoLabel(array $member, string $slot = ''): string {
    $name = $member['name_en'] ?? $member['name'] ?? 'Member';
    if ($slot !== '') {
        return ucfirst($slot) . ' · ' . $name;
    }
    return $name;
}

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

function getLiveScoreBonusBreakdown(array $state, string $pid): array {
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
                    $liveCnt = intval($state['_last_yell_live_count'] ?? 0);
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
        $yellScoreIcons = intval($state['_last_yell_score_icons'] ?? 0);
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
    return ['total' => $bonus, 'entries' => $entries];
}

/** Continuous skills/effects currently applying (score bonuses + Live storage passives). */
function collectActiveContinuousEffects(array $state, string $pid): array {
    $effects = getLiveScoreBonusBreakdown($state, $pid)['entries'];
    $p = $state['players'][$pid] ?? [];
    $livePassiveTypes = [
        'draw_per_yell_heart',
        'draw_per_yell_draw', 'draw_per_yell_card', 'live_score_if_yell_has_hearts',
        'success_score_per_yell_score',
    ];
    foreach ($p['live_zone'] ?? [] as $live) {
        if (!$live) continue;
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

function waitMember(array &$member): void {
    $member['_was_active_before_wait'] = $member['active'] ?? true;
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
    if (!$mbr || !($mbr['active'] ?? true)) {
        return false;
    }
    $snap = memberSnapshot($mbr);
    waitMember($mbr);
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
        if (!$mbr || ($mbr['active'] ?? true)) continue;
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
        if ($activeOnly && !($mbr['active'] ?? true)) continue;
        if (intval($mbr['cost'] ?? 0) > $maxCost) continue;
        if ($pickCount !== null && $waited >= $pickCount) break;
        $snap = memberSnapshot($mbr);
        waitMember($mbr);
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
        if ($mbr && !($mbr['active'] ?? true)) return true;
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
        if ($activeOnly && !($mbr['active'] ?? true)) continue;
        if (memberBladeIconCount($mbr) > $maxBlade) continue;
        if ($pickCount !== null && $waited >= $pickCount) break;
        $snap = memberSnapshot($mbr);
        waitMember($mbr);
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

function listOppStageMembersByMaxCost(array $state, string $oppId, int $maxCost): array {
    $out = [];
    foreach ($state['players'][$oppId]['stage'] as $slot => $mbr) {
        if (!$mbr) continue;
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
        if ($activeOnly && !($mbr['active'] ?? true)) continue;
        if (memberHeartCount($mbr) > $maxHearts) continue;
        if ($pickCount !== null && $waited >= $pickCount) break;
        $snap = memberSnapshot($mbr);
        waitMember($mbr);
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
        if ($activeOnly && !($mbr['active'] ?? true)) continue;
        if (intval($mbr['blade'] ?? 0) > $maxBlades) continue;
        if ($pickCount !== null && $waited >= $pickCount) break;
        $snap = memberSnapshot($mbr);
        waitMember($mbr);
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
        if ($mbr['active'] ?? true) continue;
        $mbr['active'] = true;
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

function wrPickMatchCount(array $p, array $cfg, int $need = 1): int {
    $count = 0;
    foreach ($p['waiting_room'] ?? [] as &$c) {
        hydrateWrCardForPick($c);
        if (cardMatchesWrPick($c, $cfg)) {
            $count++;
            if ($count >= $need) {
                unset($c);
                return $count;
            }
        }
    }
    unset($c);
    return $count;
}

function wrCandidatesMatching(array $p, array $cfg): array {
    $out = [];
    foreach ($p['waiting_room'] ?? [] as &$c) {
        hydrateWrCardForPick($c);
        if (cardMatchesWrPick($c, $cfg)) {
            $out[] = $c;
        }
    }
    unset($c);
    return $out;
}

function wrPickCfgFromAbility(array $ab): array {
    $cfg = ['group' => $ab['group'] ?? '', 'filter' => $ab['filter'] ?? 'member'];
    if (isset($ab['max_cost'])) {
        $cfg['max_cost'] = intval($ab['max_cost']);
    }
    if (isset($ab['max_live_score'])) {
        $cfg['max_live_score'] = intval($ab['max_live_score']);
    }
    if (isset($ab['min_required_hearts'])) {
        $cfg['min_required_hearts'] = intval($ab['min_required_hearts']);
    }
    if (!empty($ab['min_required_heart_color'])) {
        $cfg['min_required_heart_color'] = (string)$ab['min_required_heart_color'];
    }
    return $cfg;
}

/** leave_stage_add_from_wr defaults to Live when filter omitted; other WR picks default to Member. */
function wrPickCfgForLeaveStageAbility(array $ab): array {
    $cfg = wrPickCfgFromAbility($ab);
    if (($ab['type'] ?? '') === 'leave_stage_add_from_wr' && !isset($ab['filter'])) {
        $cfg['filter'] = 'live';
    }
    return $cfg;
}

function hydrateWrCardForPick(array &$card): void {
    mergeCardCatalogFields($card);
}

/**
 * Player chooses which Waiting Room card to add to hand (never auto-first-match).
 * Sets pending_prompt pick_wr_to_hand or pick_wr_leave_stage_add.
 */
function startPickWrToHandPrompt(
    array &$state,
    string $pid,
    array &$member,
    string $slot,
    int $abilityIdx,
    array $ab,
    array $cfg,
    bool $leaveStage = false
): void {
    $p = &$state['players'][$pid];
    $candidates = wrCandidatesMatching($p, $cfg);
    if (empty($candidates)) {
        throw new Exception('No matching card in Waiting Room');
    }
    $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
    $filter = $cfg['filter'] ?? 'member';
    if (!empty($ab['once_per_turn'])) {
        markAbilityUsed($member, $abilityIdx);
    }
    $p['stage'][$slot] = $member;
    $promptType = $leaveStage ? 'pick_wr_leave_stage_add' : 'pick_wr_to_hand';
    $state['pending_prompt'] = [
        'type'          => $promptType,
        'owner'         => $pid,
        'responder'     => $pid,
        'source_id'     => $member['instance_id'] ?? '',
        'source_slot'   => $slot,
        'source_name'   => $mName,
        'ability_index' => $abilityIdx,
        'prompt'        => 'Choose 1 ' . wrPickFilterLabel($filter) .
            ' card from your Waiting Room to add to your hand.',
        'candidates'    => array_map('cardPromptSummary', $candidates),
        'ability'       => $ab,
        'wr_pick_cfg'   => $cfg,
    ];
}

function wrPickFilterLabel(string $filter): string {
    if ($filter === 'live') {
        return 'Live';
    }
    if ($filter === 'member') {
        return 'Member';
    }
    return 'card';
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

function startEffectDiscardHandPrompt(
    array $state,
    string $pid,
    string $name,
    int $count,
    string $msg = '',
    array $extra = []
): array {
    if ($count < 1) {
        return $state;
    }
    $prompt = array_merge([
        'type'        => 'effect_discard_hand',
        'owner'       => $pid,
        'responder'   => $pid,
        'source_name' => $name,
        'count'       => $count,
        'prompt'      => $msg !== '' ? $msg : (
            $count === 1
                ? 'Choose a card to send to the Waiting Room.'
                : "Choose $count cards to send to the Waiting Room."
        ),
        'pick_mode'   => 'hand_discard',
    ], $extra);
    if (!empty($prompt['ability'])) {
        $prompt = enrichAbilityContextPrompt($state, $prompt);
    }
    $state['pending_prompt'] = $prompt;
    $state = logEffectStep($state, $pid, $name,
        'choose ' . $count . ' card(s) to put into the Waiting Room.');
    $state['seq']++;
    return $state;
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

function drawCardInstances(array &$p, int $count): array {
    $drawn = [];
    for ($i = 0; $i < $count; $i++) {
        if (empty($p['main_deck'])) {
            break;
        }
        $c = array_shift($p['main_deck']);
        $p['hand'][] = $c;
        $drawn[] = $c;
    }
    return $drawn;
}

function discardHandCardsByIds(array &$p, array $ids): array {
    $moved = [];
    $idSet = array_flip($ids);
    $p['hand'] = array_values(array_filter($p['hand'], function ($c) use ($idSet, &$moved, &$p) {
        $iid = $c['instance_id'] ?? '';
        if (isset($idSet[$iid])) {
            $p['waiting_room'][] = $c;
            $moved[] = $c;
            return false;
        }
        return true;
    }));
    return $moved;
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

function discardFromHandByIds(array &$p, array $ids, ?array &$notifyState = null, ?string $notifyPid = null): int {
    $count = 0;
    $p['hand'] = array_values(array_filter($p['hand'], function ($c) use ($ids, &$count, &$p) {
        if (in_array($c['instance_id'] ?? '', $ids, true)) {
            $p['waiting_room'][] = $c;
            $count++;
            return false;
        }
        return true;
    }));
    if ($count > 0 && $notifyState !== null && $notifyPid !== null) {
        hsPb1NotifyHandDiscard($notifyState, $notifyPid);
    }
    return $count;
}

/** Shuffle all Waiting Room cards into main deck when the deck is empty (deck refresh). */
function refreshMainDeckFromWaitingRoom(array &$state, string $pid): int {
    $p = &$state['players'][$pid];
    if (!empty($p['main_deck'])) {
        return 0;
    }
    $wr = $p['waiting_room'] ?? [];
    if (empty($wr)) {
        return 0;
    }
    $count = count($wr);
    $p['main_deck'] = $wr;
    $p['waiting_room'] = [];
    shuffle($p['main_deck']);
    $p['_deck_refreshed_turn'] = intval($state['turn'] ?? 0);
    $name = $p['name'] ?? 'Player';
    $state = addLog(
        $state,
        "$name — Deck refresh: shuffled $count card(s) from Waiting Room into a new deck.",
        'action'
    );
    return $count;
}

/** Draw from main deck to hand, refreshing from Waiting Room when the deck runs out. */
function drawCardsForPlayer(array &$state, string $pid, int $count): int {
    $p = &$state['players'][$pid];
    $drawn = 0;
    for ($i = 0; $i < $count; $i++) {
        if (empty($p['main_deck'])) {
            if (refreshMainDeckFromWaitingRoom($state, $pid) <= 0) {
                break;
            }
        }
        if (empty($p['main_deck'])) {
            break;
        }
        $p['hand'][] = array_shift($p['main_deck']);
        $drawn++;
    }
    return $drawn;
}

/** Draw to hand with per-card effect log + deck→hand animation (for draw-then-pick prompts). */
function drawCardsForPlayerWithEffectLog(
    array &$state,
    string $pid,
    string $sourceName,
    int $count
): array {
    $p = &$state['players'][$pid];
    $drawnCards = [];
    for ($i = 0; $i < $count; $i++) {
        if (empty($p['main_deck'])) {
            if (refreshMainDeckFromWaitingRoom($state, $pid) <= 0) {
                break;
            }
        }
        if (empty($p['main_deck'])) {
            break;
        }
        $c = array_shift($p['main_deck']);
        $p['hand'][] = $c;
        $drawnCards[] = $c;
        $state = logEffectDraw($state, $pid, $sourceName, $c,
            [animSpec($c['instance_id'], 'main_deck', 'hand', $pid)]);
    }
    return $drawnCards;
}

/** Draw from main deck (not to hand), refreshing from Waiting Room when empty. */
function drawMainDeckCards(array &$state, string $pid, int $count): array {
    $p = &$state['players'][$pid];
    $drawn = [];
    for ($i = 0; $i < $count; $i++) {
        if (empty($p['main_deck'])) {
            if (refreshMainDeckFromWaitingRoom($state, $pid) <= 0) {
                break;
            }
        }
        if (empty($p['main_deck'])) {
            break;
        }
        $drawn[] = array_shift($p['main_deck']);
    }
    return $drawn;
}

/** Take cards from the top of main deck (mills, reveals), refreshing when empty. */
function takeFromMainDeckTop(array &$state, string $pid, int $count): array {
    if ($count <= 0) {
        return [];
    }
    $p = &$state['players'][$pid];
    $taken = [];
    while (count($taken) < $count) {
        if (empty($p['main_deck'])) {
            if (refreshMainDeckFromWaitingRoom($state, $pid) <= 0) {
                break;
            }
        }
        $need = $count - count($taken);
        $batch = array_splice($p['main_deck'], 0, min($need, count($p['main_deck'])));
        if (empty($batch)) {
            break;
        }
        $taken = array_merge($taken, $batch);
    }
    return $taken;
}

function activateEnergyForPlayer(array &$p, int $max): int {
    $n = 0;
    foreach ($p['energy_zone'] as &$e) {
        if ($n >= $max) break;
        if (!($e['active'] ?? false)) {
            $e['active'] = true;
            $n++;
        }
    }
    unset($e);
    return $n;
}

function countActiveEnergyInZone(array $p): int {
    return count(array_filter($p['energy_zone'] ?? [], fn($e) => $e['active'] ?? false));
}

function affordableEnergyForBatonPlay(array $p, ?array $occupant, ?array $incoming = null): int {
    $active = countActiveEnergyInZone($p);
    if ($occupant && $incoming) {
        $active += estimateBatonWrEnergyActivation($occupant, $incoming, $p);
    }
    return $active;
}

function computeMemberPlayCostWithBaton(array $state, string $pid, array $card, ?array $occupant): int {
    $cost = getEffectiveHandCost($state, $pid, $card);
    if ($occupant) {
        $cost = max(0, $cost - getEffectiveStageMemberCost($state, $pid, $occupant));
    }
    return $cost;
}

function payEnergyCost(array &$p, int $cost, array $preferIds = []): bool {
    return count(payEnergyCostIds($p, $cost, $preferIds)) >= $cost;
}

function payEnergyCostIds(array &$p, int $cost, array $preferIds = []): array {
    if ($cost <= 0) {
        return [];
    }
    $paidIds = [];
    $preferIds = array_values(array_filter($preferIds));
    if (!empty($preferIds)) {
        foreach ($p['energy_zone'] as &$e) {
            if (count($paidIds) >= $cost) {
                break;
            }
            $id = $e['instance_id'] ?? '';
            if ($id !== '' && in_array($id, $preferIds, true) && ($e['active'] ?? false)) {
                $e['active'] = false;
                $paidIds[] = $id;
            }
        }
        unset($e);
    }
    if (count($paidIds) < $cost) {
        foreach ($p['energy_zone'] as &$e) {
            if (count($paidIds) >= $cost) {
                break;
            }
            $id = $e['instance_id'] ?? '';
            if ($id !== '' && ($e['active'] ?? false)) {
                $e['active'] = false;
                $paidIds[] = $id;
            }
        }
        unset($e);
    }
    if (count($paidIds) < $cost) {
        return [];
    }
    return $paidIds;
}

function applyModifierEffect(array $state, string $pid, array $effect): array {
    $state = initLiveModifiers($state);
    $type = $effect['type'] ?? '';
    switch ($type) {
        case 'live_score_bonus':
            $state['live_modifiers'][$pid]['score_bonus'] += intval($effect['amount'] ?? 0);
            break;
        case 'blade_bonus':
            $state['live_modifiers'][$pid]['blade_bonus'] += intval($effect['amount'] ?? 0);
            break;
        case 'blade_bonus_per_discarded':
            $state['live_modifiers'][$pid]['blade_bonus'] +=
                intval($effect['amount'] ?? 1) * intval($effect['discarded'] ?? 0);
            break;
        case 'blade_bonus_per_success':
            $succ = count($state['players'][$pid]['success_lives'] ?? []);
            $state['live_modifiers'][$pid]['blade_bonus'] +=
                intval($effect['amount'] ?? 1) * $succ;
            break;
        case 'blade_bonus_per_live_zone':
            $state['live_modifiers'][$pid]['blade_per_live_zone'] +=
                intval($effect['amount'] ?? 1);
            break;
        case 'member_blade_bonus':
            applyMemberBladeBonus($state, $pid, $effect);
            break;
        case 'both_stages_blade_bonus':
            foreach (['p1', 'p2'] as $id) {
                $state['live_modifiers'][$id]['both_stages_blade'] += intval($effect['amount'] ?? 0);
            }
            break;
        case 'hearts_and_blade_bonus':
            if (!empty($effect['hearts'])) {
                addBonusHeartsToModifier($state, $pid, $effect['hearts']);
            }
            if (!empty($effect['blade'])) {
                $state['live_modifiers'][$pid]['blade_bonus'] += intval($effect['blade']);
            }
            break;
        case 'grant_bonus_hearts':
            if (!empty($effect['hearts'])) {
                addBonusHeartsToModifier($state, $pid, $effect['hearts']);
            }
            break;
        case 'blade_bonus_per_paid':
            $state['live_modifiers'][$pid]['blade_bonus'] +=
                intval($effect['amount'] ?? 1) * intval($effect['paid'] ?? 0);
            break;
    }
    return $state;
}

function isLiveModifierEffectType(string $type): bool {
    return in_array($type, [
        'live_score_bonus',
        'blade_bonus',
        'blade_bonus_per_discarded',
        'blade_bonus_per_success',
        'blade_bonus_per_live_zone',
        'member_blade_bonus',
        'both_stages_blade_bonus',
        'hearts_and_blade_bonus',
        'grant_bonus_hearts',
        'blade_bonus_per_paid',
    ], true);
}

function finishAfterBranchChoicePrompt(array $state, array $prompt): array {
    if (($state['phase'] ?? '') === 'live_start_effects' || !empty($prompt['live_start'])) {
        return resumeLiveStartEffectPhase($state);
    }
    return finishPromptEffects($state);
}

/** After a Live Start prompt resolves, finish remaining players' mandatory Live Start abilities. */
function resumeLiveStartEffectPhase(array $state): array {
    if (($state['phase'] ?? '') !== 'live_start_effects') {
        return finishPromptEffects($state);
    }
    $fromPid = $state['_live_start_resume_from'] ?? null;
    unset($state['_live_start_resume_from']);
    if ($fromPid) {
        $attempting = $state['live_attempt'] ?? [];
        $resume = false;
        foreach ($attempting as $pid) {
            if (!$resume) {
                if ($pid === $fromPid) {
                    $resume = true;
                }
                continue;
            }
            $state = resolveLiveStartAbilities($state, $pid);
            if (!empty($state['pending_prompt'])) {
                $state['_live_start_resume_from'] = $pid;
                return $state;
            }
        }
    }
    return finishLiveStartEffects($state);
}

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
    $members = listOppStageMembersByMaxCost($state, $opp, $maxCost);
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
        'responder'     => $owner,
        'opp'           => $opp,
        'source_id'     => $sourceId,
        'source_name'   => $srcName,
        'live_start'    => $liveStart,
        'prompt'        => 'Choose 1 opponent Stage Member (cost ≤' . $maxCost . ') to put into Wait.',
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

function markMemberDualEnterLiveStartFired(array $state, string $pid, string $instanceId): array {
    if ($instanceId === '') {
        return $state;
    }
    $slot = findMemberSlot($state['players'][$pid] ?? [], $instanceId);
    if ($slot === '' || empty($state['players'][$pid]['stage'][$slot])) {
        return $state;
    }
    $state['players'][$pid]['stage'][$slot]['on_enter_or_live_start_fired'] = true;
    return $state;
}

function shouldSkipDualEnterLiveStartAtLiveStart(array $member, array $ab): bool {
    return ($ab['trigger'] ?? '') === 'on_enter_or_live_start'
        && !empty($member['on_enter_or_live_start_fired']);
}

function resolveOnEnterAbilities(array $state, string $pid, array $member, string $slot = ''): array {
    $abilities = getAbilitiesByTrigger($member, 'on_enter');
    if (empty($abilities)) {
        return $state;
    }
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
    $state = hsResolveAutoOnOtherMemberEnter($state, $pid, $member);
    $state = hsPb1ExtendAutoOnOtherMemberEnter($state, $pid, $member);
    return $state;
}

// ─────────────────────────────────────────────
// [Live Start] abilities (before Yell / Performance)
// ─────────────────────────────────────────────

function resolveLiveStartAbilities(array $state, string $pid): array {
    $attempting = $state['live_attempt'] ?? ['p1', 'p2'];
    if (!in_array($pid, $attempting, true)) {
        return $state;
    }
    $state = sBp5ApplyOppLivePenalties($state, $pid);
    $p = $state['players'][$pid];

    foreach ($p['stage'] as $member) {
        if (!$member || !isMemberCard($member)) continue;
        if (!memberInstanceOnStage($p, $member['instance_id'] ?? '')) continue;
        if (memberLiveStartAbilitiesNegated($member)) continue;
        $abilities = array_values(array_filter(
            getAbilitiesByTrigger($member, 'live_start'),
            fn($ab) => !isQueuedOptionalLiveStart($ab)
                && !shouldSkipDualEnterLiveStartAtLiveStart($member, $ab)
        ));
        if (empty($abilities)) continue;
        $state = logAbilityChain($state, $pid, $member, 'live_start');
        foreach ($abilities as $ab) {
            $state = resolveAbilityEffect($state, $pid, $member, $ab, ['phase' => 'live_start']);
            $state = nBp5NotifyMemberAbilityResolved($state, $pid, $member, 'live_start');
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
        }
    }

    foreach ($p['live_zone'] as $live) {
        if (!$live || !isLiveTypeCard($live)) continue;
        $abilities = array_values(array_filter(
            getAbilitiesByTrigger($live, 'live_start'),
            fn($ab) => !isQueuedOptionalLiveStart($ab)
        ));
        if (empty($abilities)) continue;
        $state = logAbilityChain($state, $pid, $live, 'live_start');
        foreach ($abilities as $ab) {
            $state = resolveAbilityEffect($state, $pid, $live, $ab, ['phase' => 'live_start']);
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
        }
    }

    return $state;
}

function isQueuedOptionalLiveStart(array $ab): bool {
    return in_array($ab['type'] ?? '', [
        'optional_discard_hand',
        'optional_discard_surveil',
        'optional_discard_add_from_wr',
        'optional_pay_energy',
        'optional_discard_named',
        'optional_discard_same_group',
        'optional_discard_prompt',
        'optional_wait_self_center_blade',
        'optional_position_change_all_muse',
        'optional_formation_change_group',
        'optional_pay_energy_up_to',
        'optional_wait_subunit_opp_pick_active',
        'optional_return_member_energy',
        'optional_discard_blade_draw_if_live',
        'optional_discard_blade_per_card',
        'optional_discard_blade_named_extra',
        'optional_wr_member_deck_top_blade',
        'live_start_pay_or_discard',
        'optional_discard_subunit_draw_buff_cost',
        'live_start_cost_plus_stage_cost_blade_hearts',
        'optional_shuffle_wr_members_deck_bottom_named_blade',
        'live_start_discard_heart_non_aqours_entered',
        'optional_wr_members_deck_bottom_milestones',
        'optional_discard_activate_wait_hearts',
        'optional_discard_activate_wait_blade',
    ], true);
}

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
function takeFromPendingYellPool(array &$ownerP, string $cardId, array $prompt): ?array {
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
    $yellPool = $ownerP['_pending_yell_wr'] ?? [];
    $picked = null;
    $rest = [];
    foreach ($yellPool as $c) {
        if (($c['instance_id'] ?? '') === $cardId) {
            $picked = $c;
        } else {
            $rest[] = $c;
        }
    }
    if ($picked) {
        $ownerP['_pending_yell_wr'] = $rest;
    }
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
    return $summary;
}

function collectOptionalLiveStartAbilities(array $state): array {
    $queue = [];
    $attempting = $state['live_attempt'] ?? ['p1', 'p2'];
    foreach (['p1', 'p2'] as $pid) {
        if (!in_array($pid, $attempting, true)) continue;
        $p = $state['players'][$pid];
        $sources = [];
        foreach ($p['stage'] ?? [] as $member) {
            if ($member && isMemberCard($member) && memberInstanceOnStage($p, $member['instance_id'] ?? '')) {
                $sources[] = $member;
            }
        }
        foreach ($p['live_zone'] ?? [] as $live) {
            if ($live && isLiveTypeCard($live)) {
                $sources[] = $live;
            }
        }
        foreach ($sources as $card) {
            if (isMemberCard($card) && memberLiveStartAbilitiesNegated($card)) {
                continue;
            }
            foreach ($card['abilities'] ?? [] as $idx => $ab) {
                $trigger = $ab['trigger'] ?? '';
                if ($trigger !== 'live_start' && $trigger !== 'on_enter_or_live_start') continue;
                if (isMemberCard($card) && shouldSkipDualEnterLiveStartAtLiveStart($card, $ab)) continue;
                if (!isQueuedOptionalLiveStart($ab)) continue;
                if (!empty($ab['requires_success_lives']) && empty($p['success_lives'])) continue;
                if (!empty($ab['requires_other_stage_member'])
                    && !stageHasOtherMember($p, $card['instance_id'] ?? '')) {
                    continue;
                }
                if (!empty($ab['requires_full_stage']) && !stageIsFull($p)) continue;
                if (($ab['type'] ?? '') === 'optional_return_member_energy'
                    && empty(stageMembersWithStackedEnergy($p))) {
                    continue;
                }
                $queue[] = [
                    'owner'         => $pid,
                    'source_id'     => $card['instance_id'] ?? '',
                    'source_name'   => $card['name_en'] ?? $card['name'] ?? 'Card',
                    'ability_index' => $idx,
                    'ability'       => $ab,
                ];
            }
        }
    }
    return $queue;
}

function liveStartOptionalPromptText(array $ab): string {
    $type = $ab['type'] ?? '';
    if ($type === 'optional_discard_hand') {
        return 'Put ' . intval($ab['discard'] ?? 1) . ' card(s) from your hand into the Waiting Room for this Live Start effect?';
    }
    if ($type === 'optional_discard_surveil') {
        return 'Put ' . intval($ab['discard'] ?? 2) . ' card(s) from your hand into the Waiting Room, then look at and arrange the top ' .
            intval($ab['look'] ?? 3) . ' cards of your deck?';
    }
    if ($type === 'optional_discard_add_from_wr') {
        return 'Put ' . intval($ab['discard'] ?? 1) . ' card(s) from your hand into the Waiting Room to add a μ\'s Live from your Waiting Room?';
    }
    if ($type === 'optional_pay_energy') {
        return 'Pay ' . intval($ab['cost'] ?? 0) . ' Energy for this Live Start effect?';
    }
    if ($type === 'optional_discard_named') {
        if (!empty($ab['exact_total'])) {
            $n = intval($ab['exact_total']);
            return "Put $n matching card(s) from your hand into the Waiting Room for this Live Start effect?";
        }
        return 'You may put any number of matching cards from your hand into the Waiting Room for this Live Start effect?';
    }
    if ($type === 'optional_discard_same_group') {
        $n = intval($ab['discard'] ?? 2);
        return "Put $n cards with the same unit name from your hand into the Waiting Room for this Live Start effect?";
    }
    if ($type === 'optional_wait_subunit_opp_pick_active') {
        $sub = $ab['subunit'] ?? 'Member';
        return "Put 1 $sub Member into Wait: your opponent puts 1 active Member into Wait?";
    }
    if ($type === 'optional_return_member_energy') {
        return 'Return Energy stacked under a Stage Member to your Energy deck for bonus hearts?';
    }
    if ($type === 'optional_discard_blade_named_extra') {
        $named = $ab['named'] ?? 'that Member';
        return 'Put 1 card from your hand into the Waiting Room: gain +'
            . intval($ab['amount'] ?? 1) . ' Blade until Live ends'
            . ($named !== '' ? " (+{$ab['extra_amount']} more if $named)" : '') . '?';
    }
    return $ab['prompt'] ?? 'Use optional Live Start effect?';
}

function buildOptionalLiveStartPrompt(array $state, array $item): array {
    $ab = $item['ability'];
    $owner = $item['owner'];
    $ownerP = $state['players'][$owner] ?? [];
    $discardCount = intval($ab['max_discard'] ?? 0) ?: intval($ab['discard'] ?? 0);
    $maxDiscard = intval($ab['max_discard'] ?? 0);
    if (($ab['type'] ?? '') === 'optional_discard_blade_named_extra') {
        $discardCount = 1;
    }
    if (($ab['type'] ?? '') === 'optional_discard_named' && empty($ab['exact_total'])) {
        $matchCount = countOptionalNamedDiscardMatches($ownerP, $ab, $item['source_id'] ?? '');
        $maxDiscard = $matchCount;
        $discardCount = $matchCount;
    }
    $prompt = [
        'type'          => 'optional_live_start',
        'owner'         => $owner,
        'responder'     => $owner,
        'source_id'     => $item['source_id'],
        'source_name'   => $item['source_name'],
        'ability_index' => $item['ability_index'],
        'prompt'        => liveStartOptionalPromptText($ab),
        'choices'       => ['yes', 'no'],
        'choice_labels' => ['Yes', 'No — Skip'],
        'ability'       => $ab,
        'discard_count' => $discardCount,
        'max_discard'   => $maxDiscard,
        'needs_pay'     => ($ab['type'] ?? '') === 'optional_pay_energy',
        'pay_cost'      => intval($ab['cost'] ?? 0),
    ];
    return enrichSelfActivationPrompt($state, $prompt);
}

/** Internal yes/no shell for optional_discard_prompt resolution (never omit choices). */
function buildInternalOptionalDiscardConfirmPrompt(
    array $state,
    string $owner,
    array $source,
    array $ab,
    string $sourceName,
    bool $liveStart
): array {
    return enrichSelfActivationPrompt($state, [
        'type'          => 'optional_discard_prompt',
        'owner'         => $owner,
        'responder'     => $owner,
        'source_id'     => $source['instance_id'] ?? '',
        'source_name'   => $sourceName,
        'prompt'        => $ab['prompt'] ?? ($liveStart ? 'Use optional Live Start effect?' : 'Use optional effect?'),
        'choices'       => ['yes', 'no'],
        'choice_labels' => ['Yes', 'No — Skip'],
        'ability'       => $ab,
        'live_start'    => $liveStart,
    ]);
}

function extractAbilityLineFromCardText(string $text, string $triggerLabel): ?string {
    foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (stripos($line, '[' . $triggerLabel . ']') !== false) {
            return $line;
        }
    }
    return null;
}

function describeThenEffect(array $then): string {
    if (empty($then)) {
        return '';
    }
    $type = $then['type'] ?? '';
    if ($type === 'blade_bonus') {
        return 'gain +' . intval($then['amount'] ?? 1) . ' Blade until this Live ends';
    }
    if ($type === 'blade_bonus_per_live_zone') {
        return 'gain +' . intval($then['amount'] ?? 1) . ' Blade for each Live card in your Live zone until this Live ends';
    }
    if ($type === 'live_score_bonus') {
        return 'gain +' . intval($then['amount'] ?? 1) . ' Live Score';
    }
    if ($type === 'draw') {
        return 'draw ' . intval($then['count'] ?? 1) . ' card(s)';
    }
    if ($type === 'look_reveal_filter' || $type === 'look_reveal_named') {
        $look = intval($then['look'] ?? 3);
        $pick = intval($then['pick'] ?? 1);
        return "look at the top $look cards of your deck and add $pick to your hand";
    }
    if ($type === 'activate_energy') {
        $max = intval($then['max'] ?? $then['count'] ?? 1);
        return 'activate up to ' . $max . ' Energy';
    }
    if ($type === 'add_from_waiting_room') {
        $filter = $then['filter'] ?? 'member';
        $count = intval($then['count'] ?? 1);
        return "add $count $filter card(s) from your Waiting Room to your hand";
    }
    return '';
}

function synthesizeAbilityEffectText(array $ab): string {
    $trigger = abilityTriggerLabel($ab['trigger'] ?? '');
    $type = $ab['type'] ?? '';
    $bracket = "[$trigger]";

    if ($type === 'optional_pay_energy') {
        $cost = intval($ab['cost'] ?? 0);
        $thenDesc = describeThenEffect($ab['then'] ?? []);
        return $thenDesc !== ''
            ? "$bracket You may pay $cost Energy: $thenDesc."
            : "$bracket You may pay $cost Energy for this effect.";
    }
    if ($type === 'optional_pay_energy_on_enter' || $type === 'optional_pay_energy_if_baton') {
        $cost = intval($ab['cost'] ?? 0);
        $thenDesc = describeThenEffect($ab['then'] ?? []);
        return $thenDesc !== ''
            ? "$bracket You may pay $cost Energy: $thenDesc."
            : "$bracket You may pay $cost Energy for this On Enter effect.";
    }
    if ($type === 'optional_discard_hand') {
        $n = intval($ab['discard'] ?? 1);
        $thenDesc = describeThenEffect($ab['then'] ?? []);
        return $thenDesc !== ''
            ? "$bracket Put $n card(s) from your hand into the Waiting Room: $thenDesc."
            : "$bracket Put $n card(s) from your hand into the Waiting Room.";
    }
    if (!empty($ab['prompt']) && is_string($ab['prompt'])) {
        return $bracket . ' ' . trim($ab['prompt']);
    }
    return $bracket . ' Optional effect — see card text.';
}

function abilityEffectTextFromSource(?array $source, array $ab, ?int $abilityIndex = null): string {
    if (!$source) {
        return synthesizeAbilityEffectText($ab);
    }
    $text = trim($source['text'] ?? '');
    if ($text === '') {
        return synthesizeAbilityEffectText($ab);
    }
    $trigger = $ab['trigger'] ?? 'live_start';
    $labels = [abilityTriggerLabel($trigger)];
    if ($trigger === 'on_enter_or_live_start') {
        $labels = ['Live Start', 'On Enter'];
    }
    foreach ($labels as $label) {
        $line = extractAbilityLineFromCardText($text, $label);
        if ($line !== null) {
            return $line;
        }
    }
    if ($abilityIndex !== null && !empty($source['abilities'][$abilityIndex]['prompt'])) {
        return '[' . abilityTriggerLabel($trigger) . '] ' . trim($source['abilities'][$abilityIndex]['prompt']);
    }
    return synthesizeAbilityEffectText($ab);
}

function isSelfActivationPromptType(string $type): bool {
    static $types = [
        'optional_live_start',
        'optional_pay_energy_on_enter',
        'optional_pay_energy_if_baton',
        'optional_pay_energy_live_success',
        'optional_pay_energy_up_to',
        'optional_discard_hand',
        'optional_discard_surveil',
        'optional_discard_add_from_wr',
        'optional_discard_named',
        'optional_discard_same_group',
        'optional_discard_prompt',
        'optional_discard_blade_draw_if_live',
        'optional_wait_self_wait_opp',
        'optional_wait_self_add_wr',
        'optional_wait_self',
        'optional_wait_self_center_blade',
        'optional_wr_member_reenter',
        'optional_pay_play_hand_member',
        'optional_negate_member_live_start_add_wr',
        'optional_reveal_live_deck_bottom_surveil',
        'optional_wr_member_deck_top_blade',
        'optional_success_live_swap',
        'optional_success_wr_live_swap',
        'optional_return_member_energy',
        'optional_wait_subunit_opp_pick_active',
        'optional_position_change_all_muse',
        'optional_formation_change_group',
        'live_start_pay_or_discard',
    ];
    return in_array($type, $types, true);
}

function enrichSelfActivationPrompt(array $state, array $prompt): array {
    return enrichAbilityContextPrompt($state, $prompt, true);
}

function enrichAbilityContextPrompt(array $state, array $prompt, bool $selfActivationOnly = false): array {
    $type = $prompt['type'] ?? '';
    $owner = $prompt['owner'] ?? '';
    $responder = $prompt['responder'] ?? '';
    if ($owner === '' || $responder !== $owner) {
        return $prompt;
    }
    if ($selfActivationOnly && !isSelfActivationPromptType($type)) {
        return $prompt;
    }
    if (!empty($prompt['effect_text'])) {
        return $prompt;
    }
    $ab = $prompt['ability'] ?? [];
    if (empty($ab)) {
        return $prompt;
    }
    $sourceId = $prompt['source_id'] ?? '';
    $source = $sourceId !== '' ? findSourceCard($state, $owner, $sourceId) : null;
    if (!$source && $sourceId !== '') {
        $source = findLiveStartSourceCard($state, $owner, $sourceId);
    }
    if (!$source && $sourceId !== '') {
        $p = $state['players'][$owner] ?? [];
        foreach (array_merge(
            $p['hand'] ?? [],
            $p['waiting_room'] ?? [],
            array_values(array_filter($p['stage'] ?? [])),
            $p['live_zone'] ?? []
        ) as $c) {
            if (($c['instance_id'] ?? '') === $sourceId) {
                $source = $c;
                break;
            }
        }
    }
    $prompt['effect_text'] = abilityEffectTextFromSource(
        $source,
        $ab,
        isset($prompt['ability_index']) ? intval($prompt['ability_index']) : null
    );
    $prompt['trigger_label'] = abilityTriggerLabel($ab['trigger'] ?? '');
    return $prompt;
}

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

function surveilArrangePromptText(int $count): string {
    $n = max(1, $count);
    if ($n === 1) {
        return 'Look at the top card of your deck. You may put it on top of your deck or put it into the Waiting Room.';
    }
    return "Look at the top {$n} cards of your deck. You may put any number of them on top of your deck in any order and put the rest into the Waiting Room.";
}

function startSurveilArrangePrompt(array $state, string $pid, string $name, array $looked, ?array $chain = null, ?string $sourceId = null): array {
    $state['surveil_stash'] = $looked;
    if ($chain !== null) {
        $state['_surveil_chain'] = $chain;
    }
    $state['pending_prompt'] = [
        'type'          => 'surveil_arrange',
        'owner'         => $pid,
        'responder'     => $pid,
        'source_id'     => $sourceId ?? ($chain['source_id'] ?? ''),
        'source_name'   => $name,
        'prompt'        => surveilArrangePromptText(count($looked)),
        'looked_cards'  => array_map('cardPromptSummary', $looked),
    ];
    return $state;
}

function finishLiveStartEffects(array $state, bool $advancePerformance = true): array {
    if (!empty($state['pending_prompt'])) {
        $state['phase'] = 'live_start_effects';
        return $state;
    }
    if (!array_key_exists('live_start_optional_queue', $state)) {
        $state['live_start_optional_queue'] = collectOptionalLiveStartAbilities($state);
    }
    $queue = $state['live_start_optional_queue'] ?? [];
    while (!empty($queue)) {
        $item = array_shift($queue);
        $ownerP = $state['players'][$item['owner']] ?? null;
        $srcId = $item['source_id'] ?? '';
        $source = $ownerP ? findLiveStartSourceCard($state, $item['owner'], $srcId) : null;
        if (!$source) {
            continue;
        }
        $state['live_start_optional_queue'] = $queue;
        $state['pending_prompt'] = buildOptionalLiveStartPrompt($state, $item);
        $state['phase'] = 'live_start_effects';
        $state = addLog($state, $state['players'][$item['owner']]['name'] .
            ' — [' . $item['source_name'] . '] optional Live Start (choose).');
        return $state;
    }
    unset($state['live_start_optional_queue']);
    if (($state['phase'] ?? '') === 'live_start_effects' && $advancePerformance && empty($GLOBALS['TUT_PERF_MANUAL_PHASES'])) {
        $state['phase'] = 'live_performance_first';
        $state = addLog($state, '=== Live Show ===');
        $first = $state['first_player'];
        $attempting = $state['live_attempt'] ?? ['p1', 'p2'];
        if (in_array($first, $attempting, true)) {
            $state = resolvePerformancePhase($state, $first);
        } else {
            $state = continuePerformancePhase($state, $first);
        }
    }
    return $state;
}

// ─────────────────────────────────────────────
// resolveAbilityEffect — core ability type switch
// ─────────────────────────────────────────────

function resolveAbilityEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (isMemberCard($source) && spBp2StageMemberAbilitiesSuppressed($state, $pid)) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'add_from_waiting_room':
            $candidates = array_values(array_filter($p['waiting_room'], function ($c) use ($ab) {
                if (($ab['filter'] ?? '') === 'member') {
                    return ($c['card_type'] ?? '') === 'メンバー';
                }
                return true;
            }));
            $take = min(intval($ab['count'] ?? 1), count($candidates));
            if ($take > 0) {
                $picked = array_slice($candidates, 0, $take);
                $pickedIds = array_column($picked, 'instance_id');
                $p['waiting_room'] = array_values(array_filter(
                    $p['waiting_room'],
                    fn($c) => !in_array($c['instance_id'] ?? '', $pickedIds, true)
                ));
                $p['hand'] = array_merge($p['hand'], $picked);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $take Member(s) from Waiting Room to hand.");
            }
            break;

        case 'look_reveal_named':
            $look = intval($ab['look'] ?? 5);
            $names = $ab['names'] ?? [];
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            $revealed = null;
            $rest = [];
            foreach ($top as $c) {
                if (!$revealed && ($c['card_type'] ?? '') === 'メンバー' && cardMatchesNames($c, $names)) {
                    $revealed = $c;
                    $p['hand'][] = $c;
                } else {
                    $rest[] = $c;
                }
            }
            $p['waiting_room'] = array_merge($p['waiting_room'], $rest);
            if ($revealed) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] revealed ' . ($revealed['name_en'] ?? $revealed['name']) . ' from deck top.');
                $then = $ab['then'] ?? [];
                if (($then['type'] ?? '') === 'wait_opponent_by_revealed') {
                    $opp = ($pid === 'p1') ? 'p2' : 'p1';
                    $maxCost = intval($revealed['cost'] ?? 0);
                    $maxBlade = intval($then['max_blade'] ?? 3);
                    $waited = 0;
                    foreach ($state['players'][$opp]['stage'] as &$mbr) {
                        if (!$mbr) continue;
                        if (intval($mbr['cost'] ?? 0) <= $maxCost && intval($mbr['blade'] ?? 0) <= $maxBlade) {
                            waitMember($mbr);
                            $waited++;
                        }
                    }
                    unset($mbr);
                    if ($waited > 0) {
                        $state = addLog($state, $state['players'][$opp]['name'] .
                            " — $waited opponent Member(s) put into Wait.");
                    }
                }
            } else {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at deck top; no matching Member to add.");
            }
            break;

        case 'optional_discard_named':
            $ids = $ctx['discard_ids'] ?? [];
            if (empty($ids)) break;
            $names = $ab['names'] ?? [];
            $valid = [];
            foreach ($p['hand'] as $c) {
                if (!in_array($c['instance_id'] ?? '', $ids, true)) continue;
                if (cardMatchesNames($c, $names) ||
                    (($ab['include_self'] ?? false) && ($c['instance_id'] ?? '') === ($source['instance_id'] ?? ''))) {
                    $valid[] = $c['instance_id'];
                }
            }
            if (!empty($ab['exact_total']) && count($valid) !== intval($ab['exact_total'])) {
                throw new Exception('Must discard exactly ' . $ab['exact_total'] . ' matching cards');
            }
            $discardedCards = [];
            foreach ($p['hand'] as $c) {
                if (in_array($c['instance_id'] ?? '', $valid, true)) {
                    $discardedCards[] = $c;
                }
            }
            $discarded = discardFromHandByIds($p, $valid);
            if ($discarded > 0 && !empty($ab['then'])) {
                $then = $ab['then'];
                if (($then['type'] ?? '') === 'blade_bonus_per_discarded') {
                    $then['discarded'] = $discarded;
                }
                if (($then['type'] ?? '') === 'hearts_from_discarded_colors') {
                    $state = batch99ResolveEffect($state, $pid, $source, $then, [
                        'discarded_cards' => $discardedCards,
                    ]);
                } else {
                    $state = applyModifierEffect($state, $pid, $then);
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] discarded $discarded card(s) for Live Start effect.");
            }
            break;

        case 'optional_discard_same_group':
            $ids = $ctx['discard_ids'] ?? [];
            if (empty($ids)) break;
            $need = intval($ab['discard'] ?? 2);
            if (!validateSameGroupDiscard($p, $ids, $need)) {
                throw new Exception("Must discard exactly $need cards sharing the same unit name");
            }
            $discarded = discardFromHandByIds($p, $ids);
            if ($discarded > 0 && !empty($ab['then'])) {
                $state = applyModifierEffect($state, $pid, $ab['then']);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] discarded $discarded same-unit card(s) for Live Start effect.");
            }
            break;

        case 'optional_pay_energy':
            if (($ctx['phase'] ?? '') === 'on_enter' && empty($ctx['pay']) && empty($ctx['confirm'])) {
                if (!empty($state['pending_prompt'])) break;
                $state['pending_prompt'] = [
                    'type'          => 'optional_pay_energy_on_enter',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_id'     => $source['instance_id'] ?? '',
                    'source_name'   => $name,
                    'prompt'        => 'Pay ' . intval($ab['cost'] ?? 0) . ' Energy for this On Enter effect?',
                    'choices'       => ['yes', 'no'],
                    'choice_labels' => ['Yes — Pay', 'No — Skip'],
                    'ability'       => $ab,
                    'pay_cost'      => intval($ab['cost'] ?? 0),
                ];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] optional On Enter (pay Energy).');
                break;
            }
            if (!empty($ab['requires_full_stage']) && !stageIsFull($p)) break;
            if (!empty($ab['requires_subunit_cost_min'])) {
                $req = $ab['requires_subunit_cost_min'];
                if (!stageHasSubunitMinCost(
                    $p,
                    $req['subunit'] ?? '',
                    intval($req['min_cost'] ?? 9)
                )) {
                    break;
                }
            }
            if (empty($ctx['pay']) && empty($ctx['confirm'])) break;
            $cost = intval($ab['cost'] ?? 0);
            if (!payEnergyCost($p, $cost)) {
                throw new Exception("Need $cost Energy for optional Live Start effect");
            }
            if (!empty($ab['then'])) {
                $then = $ab['then'];
                if (($then['type'] ?? '') === 'member_blade_bonus' && !empty($then['other_only'])) {
                    $then['exclude_source_id'] = $source['instance_id'] ?? '';
                }
                if (($then['type'] ?? '') === 'choose_heart_modifier') {
                    $state = resolveAbilityEffect($state, $pid, $source, $then, $ctx);
                } elseif (($then['type'] ?? '') === 'member_blade_bonus') {
                    $n = applyMemberBladeBonus($state, $pid, $then);
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] paid $cost Energy; $n Member(s) gained +" .
                        intval($then['amount'] ?? 0) . ' Blade.');
                } elseif (($then['type'] ?? '') === 'shuffle_wr_members_deck_top') {
                    $need = intval($then['count'] ?? 2);
                    $candidates = array_values(array_filter(
                        $p['waiting_room'],
                        fn($c) => ($c['card_type'] ?? '') === 'メンバー'
                    ));
                    if (count($candidates) >= $need) {
                        if (count($candidates) > $need) {
                            $state['pending_prompt'] = [
                                'type'          => 'pick_wr_members_deck_top',
                                'owner'         => $pid,
                                'responder'     => $pid,
                                'source_name'   => $name,
                                'prompt'        => "Choose $need Member card(s) from your Waiting Room to put on top of your deck (in order).",
                                'candidates'    => array_map('cardPromptSummary', $candidates),
                                'pick_count'    => $need,
                                'ability'       => $then,
                            ];
                            $state['seq']++;
                            return $state;
                        }
                        $picked = array_slice($candidates, 0, $need);
                        $pickIds = array_map(fn($c) => $c['instance_id'] ?? '', $picked);
                        $rest = array_values(array_filter(
                            $p['waiting_room'],
                            fn($c) => !in_array($c['instance_id'] ?? '', $pickIds, true)
                        ));
                        $p['waiting_room'] = $rest;
                        $p['main_deck'] = array_merge(array_reverse($picked), $p['main_deck']);
                        $state = addLog($state, $state['players'][$pid]['name'] .
                            " — [$name] put $need Member card(s) from Waiting Room on deck top.");
                    } else {
                        $state = addLog($state, $state['players'][$pid]['name'] .
                            " — [$name] paid $cost Energy but not enough Members in Waiting Room.");
                    }
                } elseif (in_array($then['type'] ?? '', [
                    'score_if_distinct_subunits_on_stage',
                    'live_start_edel_choice',
                ], true)) {
                    $state = resolveAbilityEffect($state, $pid, $source, $then, $ctx);
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] paid $cost Energy for Live Start effect.");
                } elseif (isLiveModifierEffectType($then['type'] ?? '')) {
                    $state = applyModifierEffect($state, $pid, $then);
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] paid $cost Energy for Live Start effect.");
                } elseif (($then['type'] ?? '') !== '') {
                    $state = resolveAbilityEffect($state, $pid, $source, $then, $ctx);
                    if (empty($state['pending_prompt'])) {
                        $state = addLog($state, $state['players'][$pid]['name'] .
                            " — [$name] paid $cost Energy for Live Start effect.");
                    }
                }
            }
            break;

        case 'opponent_text_answer':
            if (!empty($state['pending_prompt'])) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $textFields = buildOpponentTextAnswerPromptFields($ab);
            $state['pending_prompt'] = [
                'type'          => 'opponent_text_answer',
                'owner'         => $pid,
                'responder'     => $opp,
                'source_name'   => $name,
                'prompt'        => $textFields['prompt'],
                'outcome_hints' => $textFields['outcome_hints'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] asks opponent: "' . $textFields['prompt'] . '"');
            break;

        case 'opponent_choice':
            if (!empty($state['pending_prompt'])) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $choiceFields = buildPlayerChoicePromptFields($ab);
            $isLiveStart = ($ctx['phase'] ?? '') === 'live_start'
                || ($state['phase'] ?? '') === 'live_start_effects';
            $state['pending_prompt'] = [
                'type'          => 'opponent_choice',
                'owner'         => $pid,
                'responder'     => $opp,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'live_start'    => $isLiveStart,
                'prompt'        => $choiceFields['prompt'],
                'choices'       => array_keys($ab['choices'] ?? []),
                'choice_labels' => $choiceFields['choice_labels'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] opponent must choose an effect.');
            break;

        case 'player_choice':
            if (!empty($state['pending_prompt'])) break;
            $choiceFields = buildPlayerChoicePromptFields($ab);
            $isLiveStart = ($ctx['phase'] ?? '') === 'live_start'
                || ($state['phase'] ?? '') === 'live_start_effects';
            $state['pending_prompt'] = enrichAbilityContextPrompt($state, [
                'type'          => 'player_choice',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'live_start'    => $isLiveStart,
                'prompt'        => $choiceFields['prompt'],
                'choices'       => array_keys($ab['choices'] ?? []),
                'choice_labels' => $choiceFields['choice_labels'],
                'ability'       => $ab,
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose one effect.');
            break;

        case 'optional_wait_self_wait_opp':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_self',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Put this Member into Wait to affect an opponent Member?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Wait self', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional Wait effect (choose).');
            break;

        case 'optional_wait_self_add_wr':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_self_add_wr',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Put this Member into Wait to add a μ\'s Member from Waiting Room?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter effect (choose).');
            break;

        case 'optional_wait_self_energy_subunit':
            if (!empty($state['pending_prompt'])) break;
            $subunit = $ab['subunit'] ?? '';
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_self_energy_subunit',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => "Put this Member into Wait to activate 1 Energy per $subunit Member on your Stage?",
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Wait self', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter effect (choose).');
            break;

        case 'optional_wait_members_draw':
            if (!abilitySlotAllowed($ab, $ctx, $p, $source)) break;
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_members_draw',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Put up to ' . intval($ab['max_members'] ?? 3) .
                    ' Members into Wait to draw 1 card for each?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — choose Members', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter effect (choose).');
            break;

        case 'success_scored_live_score_bonus':
            if (!abilitySlotAllowed($ab, $ctx, $p, $source)) {
                break;
            }
            $slot = findMemberSlot($p, $source['instance_id'] ?? '');
            if (!empty($ab['center_only']) && $slot !== 'center') {
                break;
            }
            $targetScores = $ab['scores'] ?? null;
            if ($targetScores !== null) {
                $hasOne = false;
                $hasFive = false;
                foreach ($p['success_lives'] ?? [] as $c) {
                    $sc = intval($c['score'] ?? 0);
                    if ($sc === 1) $hasOne = true;
                    if ($sc === 5) $hasFive = true;
                }
                if ($hasOne && $hasFive) {
                    $amount = intval($ab['amount_two'] ?? 2);
                } elseif ($hasOne || $hasFive) {
                    $amount = intval($ab['amount_one'] ?? 1);
                } else {
                    break;
                }
            } else {
                $group = $ab['group'] ?? 'μ\'s';
                $scored = count(array_filter(
                    $p['success_lives'] ?? [],
                    fn($c) => ($c['group'] ?? '') === $group && intval($c['score'] ?? 0) > 0
                ));
                if ($scored >= 2) {
                    $amount = intval($ab['amount_two'] ?? 2);
                } elseif ($scored >= 1) {
                    $amount = intval($ab['amount_one'] ?? 1);
                } else {
                    break;
                }
            }
            $state = applyModifierEffect($state, $pid, [
                'type'   => 'live_score_bonus',
                'amount' => $amount,
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Success Live score bonus +$amount until this Live ends.");
            break;

        case 'draw_if_success_lives':
            $succ = $p['success_lives'] ?? [];
            if (!empty($ab['group'])) {
                $succ = array_values(array_filter(
                    $succ,
                    fn($c) => ($c['group'] ?? '') === ($ab['group'] ?? '')
                ));
            }
            if (!empty($succ)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Success Live area not empty).");
            }
            break;

        case 'draw_if_bonus_hearts_on_stage':
            if (stageHasMemberWithExtraHearts($p)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Member with bonus hearts on Stage).");
            }
            break;

        case 'wr_live_deck_top_draw_if_opp_wait':
            $group = $ab['group'] ?? 'μ\'s';
            $candidates = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => cardMatchesGroup($c, $group, 'live')
            ));
            if (count($candidates) > 1) {
                $state['pending_prompt'] = [
                    'type'          => 'pick_wr_live_deck_top',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_name'   => $name,
                    'prompt'        => 'Choose 1 μ\'s Live card from your Waiting Room to put on top of your deck.',
                    'candidates'    => array_map('cardPromptSummary', $candidates),
                    'ability'       => $ab,
                ];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] choose a Live card from Waiting Room.');
                break;
            }
            if (count($candidates) === 1) {
                putWrCardOnDeckTop($p, $candidates[0]['instance_id'] ?? '');
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put ' .
                    ($candidates[0]['name_en'] ?? $candidates[0]['name']) . ' on top of deck.');
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (stageHasWaitMember($state, $opp)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (opponent has a Member in Wait).");
            }
            break;

        case 'wait_opponent_max_blade':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $waited = waitOpponentStageByMaxBlade(
                $state,
                $opp,
                intval($ab['max_blade'] ?? 1),
                isset($ab['pick_count']) ? intval($ab['pick_count']) : null,
                $pid
            );
            if ($waited > 0) {
                $state = addLog($state, $state['players'][$opp]['name'] .
                    " — $waited Member(s) put into Wait ([$name]).");
            }
            break;

        case 'block_effect_member_activate_turn':
            $state['block_effect_member_activate'] = true;
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Members cannot become Active by effects this turn.");
            break;

        case 'wait_opp_if_distinct_subunit':
            if (countDistinctNamedSubunit($p, $ab['subunit'] ?? '') >= intval($ab['min_distinct'] ?? 2)) {
                $opp = ($pid === 'p1') ? 'p2' : 'p1';
                $waited = waitOpponentStageByCost(
                    $state,
                    $opp,
                    intval($ab['max_cost'] ?? 4),
                    isset($ab['pick_count']) ? intval($ab['pick_count']) : null,
                    $pid
                );
                if ($waited > 0) {
                    $state = addLog($state, $state['players'][$opp]['name'] .
                        " — $waited Member(s) put into Wait ([$name]).");
                }
            }
            break;

        case 'activate_subunit_members':
            $activated = activateSubunitMembers($p, $ab['subunit'] ?? '', intval($ab['max'] ?? 1));
            if ($activated > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated $activated " . ($ab['subunit'] ?? '') . ' Member(s).');
            }
            break;

        case 'optional_wait_subunit_opp_pick_active':
            if (!abilitySlotAllowed($ab, $ctx, $p, $source)) break;
            if (!empty($state['pending_prompt'])) break;
            $subunit = $ab['subunit'] ?? '';
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_subunit_opp_active',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'source_slot'   => $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? ''),
                'prompt'        => 'Put 1 ' . $subunit . ' Member into Wait: your opponent puts 1 active Member into Wait?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional effect (choose).');
            break;

        case 'optional_discard_look_reveal_subunit':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_look_reveal_subunit',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Put 1 card from hand into the Waiting Room to look at the top 4 cards of your deck?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose).');
            break;

        case 'optional_wait_self_draw_discard_unless_baton':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_self_draw_discard_unless_baton',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Put this Member into Wait to draw 1 card?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Wait self', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter effect (choose).');
            break;

        case 'if_baton_lower_cost':
            if (memberBatonFromLowerCostSubunit($source, $ab['baton_subunit'] ?? '')) {
                $then = $ab['then'] ?? [];
                if (!empty($then)) {
                    $thenType = $then['type'] ?? '';
                    if (in_array($thenType, [
                        'blade_bonus', 'hearts_and_blade_bonus', 'live_score_bonus',
                    ], true)) {
                        $state = applyModifierEffect($state, $pid, $then);
                        if ($thenType === 'blade_bonus') {
                            $state = addLog($state, $state['players'][$pid]['name'] .
                                ' — [' . $name . '] gained +' . intval($then['amount'] ?? 0) .
                                ' Blade until Live ends (Baton Touch).');
                        } else {
                            $state = addLog($state, $state['players'][$pid]['name'] .
                                ' — [' . $name . '] Baton Touch effect resolved.');
                        }
                    } else {
                        $state = resolveAbilityEffect($state, $pid, $source, $then, $ctx);
                    }
                }
            }
            break;

        case 'optional_pay_energy_if_baton':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_pay_energy_if_baton',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => $ab['prompt'] ?? (
                    'Pay ' . intval($ab['cost'] ?? 1) . ' Energy for this On Enter effect?'
                ),
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Pay Energy', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose).');
            break;

        case 'draw_if_wr_min':
            if (count($p['waiting_room'] ?? []) >= intval($ab['min_wr'] ?? 10)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Waiting Room has " . intval($ab['min_wr'] ?? 10) . "+ cards).");
            }
            break;

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

        case 'deck_surveil':
            $look = intval($ab['look'] ?? 2);
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            if (count($top) <= 1) {
                if (count($top) === 1) {
                    $p['main_deck'] = array_merge($top, $p['main_deck']);
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at deck top.");
            } else {
                $state = startSurveilArrangePrompt($state, $pid, $name, $top, null, $source['instance_id'] ?? '');
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at top " . count($top) . ' — arrange them.');
            }
            break;

        case 'activate_one_member':
            $activated = activateMembersByEffect($state, $p, 1);
            if ($activated > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated $activated Member(s).");
            }
            break;

        case 'optional_discard_prompt':
            if (!empty($state['pending_prompt'])) break;
            if (!empty($ab['requires_yell_members'])) {
                $yellCards = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
                $hasMember = !empty(array_filter(
                    $yellCards,
                    fn($c) => cardMatchesGroup(
                        $c,
                        $ab['yell_group'] ?? 'μ\'s',
                        $ab['yell_filter'] ?? 'member'
                    )
                ));
                if (!$hasMember) break;
            }
            if (!empty($ab['requires_yell_any'])) {
                $yellCards = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
                $hasPick = !empty(array_filter(
                    $yellCards,
                    fn($c) => cardMatchesYellPick($c, $ab['then'] ?? [])
                ));
                if (!$hasPick) break;
            }
            if (!empty($ab['requires_other_stage_member'])) {
                if (!stageHasOtherMember($p, $source['instance_id'] ?? '')) break;
            }
            if (!empty($ab['requires_other_stage_cost'])) {
                $need = intval($ab['requires_other_stage_cost']);
                $has = false;
                foreach ($p['stage'] as $mbr) {
                    if (!$mbr || ($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) continue;
                    if (intval($mbr['cost'] ?? 0) === $need) { $has = true; break; }
                }
                if (!$has) break;
            }
            if (!empty($ab['requires_subunit_in_hand'])) {
                if (!handHasSubunitCard($p, $ab['requires_subunit_in_hand'])) break;
            }
            $then = $ab['then'] ?? [];
            if (!optionalDiscardThenViable($p, $then)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] optional On Enter skipped (no cards left in deck).');
                break;
            }
            $energyCost = intval($ab['energy_cost'] ?? 0);
            if ($energyCost > 0 && !payEnergyCost($p, $energyCost)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] could not pay $energyCost Energy; effect skipped.");
                break;
            }
            if ($energyCost > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] paid $energyCost Energy.");
            }
            if (!empty($ctx['confirm']) || !empty($ctx['discard_ids'])) {
                return resolveOptionalDiscardPromptChoice($state, $pid, [
                    'ability'     => $ab,
                    'source_name' => $name,
                    'source_id'   => $source['instance_id'] ?? '',
                    'live_start'  => ($ctx['phase'] ?? '') === 'live_start',
                ], 'yes', ['discard_ids' => $ctx['discard_ids'] ?? []], true);
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_prompt',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => $ab['prompt'] ?? 'Use optional effect?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
                'live_start'    => ($ctx['phase'] ?? '') === 'live_start',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose).');
            break;

        case 'draw_per_stage_discard':
            $n = countStageMembers($p);
            $drawnCards = drawCardInstances($p, $n);
            foreach ($drawnCards as $c) {
                $state = logEffectDraw($state, $pid, $name, $c,
                    [animSpec($c['instance_id'], 'main_deck', 'hand', $pid)]);
            }
            $discardNeed = intval($ab['discard'] ?? 1);
            if ($discardNeed > 0 && !empty($p['hand'])) {
                return startEffectDiscardHandPrompt($state, $pid, $name, $discardNeed);
            }
            break;

        case 'reveal_hand_look_live_if_no_live':
            if (!empty($ab['requires_other_stage_member'])
                && !stageHasOtherMember($p, $source['instance_id'] ?? '')) {
                break;
            }
            $handSummary = implode(', ', array_map(
                fn($c) => cardDisplayName($c),
                $p['hand'] ?? []
            ));
            $hasLive = !empty(array_filter(
                $p['hand'] ?? [],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] revealed hand ($handSummary).");
            if (!$hasLive) {
                $picked = lookRevealGroup(
                    $p,
                    intval($ab['look'] ?? 5),
                    $ab['group'] ?? 'Nijigasaki',
                    $ab['filter'] ?? 'live',
                    intval($ab['pick'] ?? 1)
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at deck top; added $picked Live card(s) to hand.");
            }
            break;

        case 'look_reveal_filter':
            if (isset($ab['min_energy'])
                && countEnergyInZone($p) < intval($ab['min_energy'])) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] effect skipped (need ' . intval($ab['min_energy']) . '+ Energy).');
                break;
            }
            $state = beginLookRevealPick($state, $pid, $name, $p, $ab);
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
            break;

        case 'draw_and_discard':
            return applyDrawThenDiscard(
                $state,
                $pid,
                $p,
                $name,
                intval($ab['draw'] ?? 1),
                intval($ab['discard'] ?? 1),
                [
                    'ability'   => $ab,
                    'source_id' => $source['instance_id'] ?? '',
                ]
            );

        case 'activate_energy_if_success':
            if (sumSuccessLiveScores($p) >= intval($ab['min_success_score_sum'] ?? 6)) {
                $activated = activateEnergyForPlayer($p, intval($ab['count'] ?? 2));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated $activated Energy (Success Live score threshold met).");
            }
            break;

        case 'add_from_wr_max_cost':
            $added = addFromWaitingRoomFiltered(
                $p,
                $ab['group'] ?? '',
                $ab['filter'] ?? 'member',
                intval($ab['count'] ?? 1),
                intval($ab['max_cost'] ?? 2)
            );
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $added Member(s) from Waiting Room.");
            } else {
                $maxCost = intval($ab['max_cost'] ?? 2);
                $group = $ab['group'] ?? '';
                $groupLabel = $group !== '' ? $group . ' ' : '';
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no matching {$groupLabel}Member (cost ≤$maxCost) in Waiting Room.");
            }
            break;

        case 'position_change_off_center':
            $group = $ab['group'] ?? 'μ\'s';
            $minBlades = intval($ab['min_original_blades'] ?? 0);
            $hasMin = $minBlades > 0
                ? stageHasGroupMemberMinBlades($p, $group, $minBlades)
                : stageHasGroupMemberMinHearts($p, $group, intval($ab['min_hearts'] ?? 5));
            if (!$hasMin) {
                if (positionChangeOffCenter($p, $source['instance_id'] ?? '')) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] position-changed off Center.");
                }
            }
            break;

        case 'look_reveal_group':
            if (array_key_exists('min_success_score_sum', $ab)
                && sumSuccessLiveScores($p) < intval($ab['min_success_score_sum'])) {
                break;
            }
            $state = beginLookRevealPick($state, $pid, $name, $p, $ab);
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
            break;

        case 'grant_live_score_if_success':
            $succCount = count($p['success_lives'] ?? []);
            $scoreSum = sumSuccessLiveScores($p);
            if ($succCount >= intval($ab['min_success_count'] ?? 1)
                && $scoreSum <= intval($ab['max_success_score_sum'] ?? 1)) {
                $state = applyModifierEffect($state, $pid, [
                    'type'   => 'live_score_bonus',
                    'amount' => intval($ab['amount'] ?? 1),
                ]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] Live total score +' . intval($ab['amount'] ?? 1) . ' until Live ends.');
            }
            break;

        case 'wait_opponent_active':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $waited = waitOpponentActiveMembers($state, $opp, intval($ab['count'] ?? 1), $pid);
            if ($waited > 0) {
                $state = addLog($state, $state['players'][$opp]['name'] .
                    " — $waited active Member(s) put into Wait ([$name]).");
            }
            break;

        case 'activate_all_members':
            $activated = activateMembersByEffect($state, $p, 99);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] activated $activated Member(s).");
            break;

        case 'activate_members':
            $activated = activateMembersByEffect($state, $p, intval($ab['max'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] activated $activated Member(s).");
            break;

        case 'optional_discard_add_from_wr':
            if (!empty($ab['requires_success_lives']) && empty($p['success_lives'])) break;
            if (empty($ctx['discard_ids']) && empty($ctx['confirm'])) break;
            $ids = $ctx['discard_ids'] ?? [];
            if (!empty($ids)) {
                discardFromHandByIds($p, $ids);
            } elseif (!empty($ctx['confirm'])) {
                autoDiscardFromHand($p, intval($ab['discard'] ?? 1));
            }
            $added = addFromWaitingRoomFiltered(
                $p,
                $ab['group'] ?? '',
                $ab['filter'] ?? '',
                intval($ab['count'] ?? 1)
            );
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $added card(s) from Waiting Room.");
            }
            break;

        case 'optional_discard_hand':
            if (empty($ctx['discard_ids']) && empty($ctx['confirm'])) break;
            $need = intval($ab['discard'] ?? 1);
            if (!empty($ctx['discard_ids'])) {
                discardFromHandByIds($p, $ctx['discard_ids']);
            } else {
                autoDiscardFromHand($p, $need);
            }
            if (!empty($ab['then'])) {
                $then = $ab['then'];
                if (($then['type'] ?? '') === 'blade_bonus_per_success') {
                    $then['amount'] = intval($then['amount'] ?? 1);
                    $state = applyModifierEffect($state, $pid, $then);
                } elseif (($then['type'] ?? '') === 'member_blade_bonus') {
                    $n = applyMemberBladeBonus($state, $pid, $then);
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] discarded $need; $n Member(s) gained +" . intval($then['amount'] ?? 0) . ' Blade.');
                } else {
                    $state = resolveAbilityEffect($state, $pid, $source, $then, $ctx);
                    if (($then['type'] ?? '') !== 'choose_heart_modifier') {
                        $state = addLog($state, $state['players'][$pid]['name'] .
                            " — [$name] used Live Start optional effect.");
                    }
                }
            }
            break;

        case 'optional_discard_surveil':
            if (empty($ctx['discard_ids']) && empty($ctx['confirm'])) break;
            $need = intval($ab['discard'] ?? 2);
            if (!empty($ctx['discard_ids'])) {
                discardFromHandByIds($p, $ctx['discard_ids']);
            } else {
                autoDiscardFromHand($p, $need);
            }
            $look = intval($ab['look'] ?? 3);
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            if (count($top) <= 1) {
                if (count($top) === 1) {
                    $p['hand'][] = $top[0];
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] surveilled top of deck.");
            } else {
                $state = startSurveilArrangePrompt($state, $pid, $name, $top, null, $source['instance_id'] ?? '');
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at top " . count($top) . ' — arrange them.');
            }
            break;

        case 'draw_if_stage_cost_min':
            if (stageHasMemberMinCost($p, intval($ab['min_cost'] ?? 13))) {
                $drawnCards = drawCardInstances($p, intval($ab['draw'] ?? 1));
                foreach ($drawnCards as $c) {
                    $state = logEffectDraw($state, $pid, $name, $c,
                        [animSpec($c['instance_id'], 'main_deck', 'hand', $pid)]);
                }
                $drawn = count($drawnCards);
                if ($drawn > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] drew $drawn (Stage has cost " . intval($ab['min_cost'] ?? 13) . "+ Member).");
                }
            }
            break;

        case 'choose_heart_per_success':
            if (!empty($state['pending_prompt'])) break;
            $choices = $ab['heart_choices'] ?? ['yellow', 'pink', 'purple'];
            $labels = array_map(fn($c) => ucfirst($c) . ' ♡', $choices);
            $state['pending_prompt'] = [
                'type'          => 'choose_heart_per_success',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Choose a heart color to gain for each Success Live card until this Live ends.',
                'choices'       => $choices,
                'choice_labels' => $labels,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start: choose a heart color.');
            break;

        case 'optional_wait_mus_member_hearts':
            if (!empty($state['pending_prompt'])) break;
            if (!empty($ctx['skip'])) break;
            if (empty($ctx['confirm'])) {
                $state['pending_prompt'] = [
                    'type'          => 'optional_wait_mus_hearts',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_name'   => $name,
                    'prompt'        => 'Put 1 μ\'s Member on your Stage into Wait to gain bonus hearts until this Live ends?',
                    'choices'       => ['yes', 'no'],
                    'choice_labels' => ['Yes', 'No — Skip'],
                    'ability'       => $ab,
                ];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] optional Live Start effect (choose).');
                break;
            }
            if (waitFirstGroupMember($p, $ab['group'] ?? 'μ\'s')) {
                addBonusHeartsToModifier($state, $pid, $ab['hearts'] ?? []);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] Waited a μ\'s Member for bonus hearts.');
            }
            break;

        case 'optional_wait_self_surveil':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_self_surveil',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Put this Member into Wait to look at the top ' . intval($ab['look'] ?? 2) . ' cards of your deck?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter effect (choose).');
            break;

        case 'score_if_live_zone_group':
            $cnt = countLiveZoneGroup($p['live_zone'], $ab['group'] ?? 'μ\'s');
            if ($cnt >= intval($ab['min_count'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . " ($cnt μ's cards in Live).");
            }
            break;

        case 'reveal_per_both_stage_member':
            $members = countBothStagesMembers($state);
            $top = array_splice($p['main_deck'], 0, min($members, count($p['main_deck'])));
            $liveCount = 0;
            foreach ($top as $c) {
                if (($c['card_type'] ?? '') === 'ライブ') $liveCount++;
            }
            if ($liveCount > 0) {
                $bonus = $liveCount * intval($ab['score_per_live'] ?? 1);
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $bonus);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] revealed $liveCount Live card(s); score +$bonus.");
            }
            if (!empty($top)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $top);
            }
            break;

        case 'reduce_required_hearts_if_blade':
            if (totalStageBlade($p) >= intval($ab['min_blade'] ?? 10)) {
                $reduce = intval($ab['reduce'] ?? 2);
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $reduce;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $reduce;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$reduce Gray heart(s)" : "$reduce heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced (Stage Blade 10+).");
            }
            break;

        case 'score_if_success_lives':
            if (count($p['success_lives'] ?? []) >= intval($ab['min_success'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (2+ Success Lives).');
            }
            break;

        case 'choose_heart_one_mus_member':
            if (!empty($ab['requires_success_lives']) && empty($p['success_lives'])) break;
            if (!empty($state['pending_prompt'])) break;
            $choices = $ab['heart_choices'] ?? ['yellow', 'pink', 'purple'];
            $state['pending_prompt'] = [
                'type'          => 'choose_heart_mus_member',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Choose a heart for 1 μ\'s Member on your Stage to gain until this Live ends.',
                'choices'       => $choices,
                'choice_labels' => array_map(fn($c) => ucfirst($c) . ' ♡', $choices),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start: choose a heart for a μ\'s Member.');
            break;

        case 'score_if_no_excess_hearts':
            if (intval($ctx['excess_hearts'] ?? -1) === 0) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] Live success with no excess hearts; score +' . intval($ab['amount'] ?? 1) . '.');
            }
            break;

        case 'draw_if_excess_heart':
            $colors = $ctx['excess_heart_colors'] ?? [];
            $need = $ab['color'] ?? 'yellow';
            $has = count(array_filter($colors, fn($c) => $c === $need));
            if ($has >= 1) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (excess $need heart(s)).");
            }
            break;

        case 'draw_if_success_score':
            if (sumSuccessLiveScores($p, $state, $pid) >= intval($ab['min_success_score_sum'] ?? 3)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Success Live score threshold met).");
            }
            break;

        case 'other_member_blade_if_plain_live':
            if (liveZoneHasPlainLive($p['live_zone'])) {
                $n = applyMemberBladeBonus($state, $pid, [
                    'amount'             => intval($ab['amount'] ?? 2),
                    'max_members'        => intval($ab['max_members'] ?? 1),
                    'exclude_source_id'  => $source['instance_id'] ?? '',
                ]);
                if ($n > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] $n other Member(s) gained +" . intval($ab['amount'] ?? 2) . ' Blade until Live ends.');
                }
            }
            break;

        case 'member_blade_bonus':
            $n = applyMemberBladeBonus($state, $pid, $ab);
            if ($n > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] $n Member(s) gained +" . intval($ab['amount'] ?? 0) . ' Blade until Live ends.');
            }
            break;

        case 'optional_wait_self_center_blade':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_self_center_blade',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Put this Member into Wait: Center μ\'s Member gains +' .
                    intval($ab['amount'] ?? 1) . ' Blade until this Live ends?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Wait self', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional Live Start (choose).');
            break;

        case 'optional_position_change_all_muse':
            if (!stageAllMembersInGroup($p, 'μ\'s')) break;
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_position_change_all_muse',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Position-change 1 Member on your Stage?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional position change (choose).');
            break;

        case 'reduce_hearts_if_success_score':
            $scoreSum = sumSuccessLiveScores($p, $state, $pid);
            if ($scoreSum >= intval($ab['min_score_6'] ?? 6)) {
                $reduce = intval($ab['reduce'] ?? 1);
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $reduce;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $reduce;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$reduce Gray heart(s)" : "$reduce heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced.");
                if ($scoreSum >= intval($ab['min_score_9'] ?? 9)) {
                    bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['bonus_score'] ?? 1));
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] score +' . intval($ab['bonus_score'] ?? 1) . ' (Success score 9+).');
                }
            }
            break;

        case 'score_if_center_blade':
            $center = $p['stage']['center'] ?? null;
            if ($center && ($center['group'] ?? '') === ($ab['group'] ?? 'μ\'s')) {
                $blade = getMemberBlade($center, $state, $pid, 'center');
                if ($blade >= intval($ab['min_blade'] ?? 9)) {
                    bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 2));
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] score +' . intval($ab['amount'] ?? 2) . ' (Center Blade ' . $blade . '+).');
                }
            }
            break;

        case 'score_if_stage_hearts_more':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $mine = countStageHearts($p);
            $theirs = countStageHearts($state['players'][$opp]);
            if ($mine > $theirs) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Live success ($mine vs $theirs stage hearts); score +" . intval($ab['amount'] ?? 1) . '.');
            }
            break;

        case 'draw_if_stage_cost_less_than_opp':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (sumStageMemberCost($p, $state, $pid) < sumStageMemberCost($state['players'][$opp], $state, $opp)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Stage cost lower than opponent's).");
            }
            break;

        case 'add_from_wr_if_success_count':
            if (count($p['success_lives'] ?? []) >= intval($ab['min_success_count'] ?? 2)) {
                $added = addFromWaitingRoomFiltered(
                    $p,
                    $ab['group'] ?? '',
                    $ab['filter'] ?? 'live',
                    intval($ab['count'] ?? 1)
                );
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added $added Live card(s) from Waiting Room (2+ Success Lives).");
                }
            }
            break;

        case 'mill_deck_to_wr':
            $n = intval($ab['count'] ?? 5);
            $milled = takeFromMainDeckTop($state, $pid, $n);
            if (!empty($milled)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
                $state = spBp5NotifyCardsToWr($state, $pid, $milled);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put " . count($milled) . ' card(s) from deck top into Waiting Room.');
            }
            break;

        case 'mill_then_heart_if_all_members':
            $n = intval($ab['count'] ?? 3);
            $milled = array_splice($p['main_deck'], 0, min($n, count($p['main_deck'])));
            $color = $ab['heart_color'] ?? 'green';
            $allMatch = !empty($milled);
            foreach ($milled as $c) {
                if (($c['card_type'] ?? '') !== 'メンバー' || !memberHasHeartColor($c, $color)) {
                    $allMatch = false;
                    break;
                }
            }
            if (!empty($milled)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put " . count($milled) . ' card(s) from deck top into Waiting Room.');
            }
            if ($allMatch && count($milled) >= $n) {
                $cnt = intval($ab['heart_count'] ?? 1);
                addBonusHeartsToModifier($state, $pid, [['color' => $color, 'count' => $cnt]]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] gained $cnt $color heart(s) until this Live ends (all milled Members matched).");
            }
            break;

        case 'mill_then_blade_if_all_member_hearts':
            $n = intval($ab['count'] ?? 3);
            $milled = array_splice($p['main_deck'], 0, min($n, count($p['main_deck'])));
            $allMatch = !empty($milled);
            $reqColor = $ab['require_heart_color'] ?? '';
            foreach ($milled as $c) {
                if (($c['card_type'] ?? '') !== 'メンバー') {
                    $allMatch = false;
                    break;
                }
                if ($reqColor !== '') {
                    if (!memberHasHeartColor($c, $reqColor)) {
                        $allMatch = false;
                        break;
                    }
                } elseif (!memberHasAnyHeart($c)) {
                    $allMatch = false;
                    break;
                }
            }
            if (!empty($milled)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put " . count($milled) . ' card(s) from deck top into Waiting Room.');
            }
            if ($allMatch && count($milled) >= $n) {
                if (!empty($ab['hearts'])) {
                    addBonusHeartsToModifier($state, $pid, $ab['hearts']);
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] gained bonus heart(s) (all milled Members matched).');
                } else {
                    $state = applyModifierEffect($state, $pid, [
                        'type'   => 'blade_bonus',
                        'amount' => intval($ab['amount'] ?? 3),
                    ]);
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] gained +' . intval($ab['amount'] ?? 3) . ' Blade (all milled Members had hearts).');
                }
            }
            break;

        case 'activate_if_baton_to_wr':
            $incoming = $ctx['baton_incoming'] ?? null;
            if ($incoming) {
                mergeCardCatalogFields($incoming);
                if (!isMemberCard($incoming)) {
                    break;
                }
                $fromCost = intval($incoming['cost'] ?? 0);
                $fromGroup = $incoming['group'] ?? '';
            } else {
                if (empty($source['entered_via_baton'])) {
                    break;
                }
                $fromCost = intval($source['baton_from_cost'] ?? -1);
                $fromGroup = $source['baton_from_group'] ?? '';
            }
            if ($fromCost < intval($ab['min_baton_cost'] ?? 10)) {
                break;
            }
            if (($ab['group'] ?? '') !== '' && $fromGroup !== ($ab['group'] ?? '')) {
                break;
            }
            $want = intval($ab['count'] ?? 2);
            $activated = activateEnergyForPlayer($p, $want);
            $msg = $state['players'][$pid]['name'] .
                " — [$name] activated $activated Energy (Baton Touch to Waiting Room).";
            if ($activated < $want) {
                $msg .= ' (' . ($want - $activated) . ' already active.)';
            }
            $state = addLog($state, $msg);
            break;

        case 'if_baton_wr_add_live_not_self':
            if (empty($source['entered_via_baton'])) break;
            $batonId = $source['baton_wr_member_id'] ?? '';
            if ($batonId === '') break;
            $batonCard = null;
            foreach ($p['waiting_room'] as $c) {
                if (($c['instance_id'] ?? '') === $batonId) {
                    $batonCard = $c;
                    break;
                }
            }
            if ($batonCard && cardNameMatchesList($batonCard, $ab['exclude_names'] ?? [])) {
                break;
            }
            if (!$batonCard || !cardMatchesGroup($batonCard, $ab['group'] ?? '', 'member')) break;
            $added = addFromWaitingRoomFiltered(
                $p,
                $ab['group'] ?? '',
                'live',
                1
            );
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added 1 Live card from Waiting Room (Baton Touch).");
            }
            break;

        case 'on_enter_if_named_activate_add_wr':
            if (!stageHasNamedMember($p, $ab['names'] ?? [])) break;
            $activated = activateEnergyForPlayer($p, intval($ab['activate'] ?? 1));
            $added = addFromWaitingRoomFiltered(
                $p,
                $ab['group'] ?? '',
                $ab['filter'] ?? 'live',
                intval($ab['count'] ?? 1)
            );
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] activated $activated Energy; added $added card(s) from Waiting Room.");
            break;

        case 'draw_discard_if_group_on_stage':
            if (!stageHasGroupMember($p, $ab['group'] ?? '')) break;
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            if (intval($ab['discard'] ?? 0) > 0 && !empty($p['hand'])) {
                autoDiscardFromHand($p, intval($ab['discard'] ?? 1));
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn and discarded " . intval($ab['discard'] ?? 0) . '.');
            break;

        case 'draw_until_hand':
            $target = intval($ab['target'] ?? 5);
            $drawn = 0;
            while (count($p['hand']) < $target && !empty($p['main_deck'])) {
                $p['hand'][] = array_shift($p['main_deck']);
                $drawn++;
            }
            if ($drawn > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (hand size " . count($p['hand']) . ").");
            }
            break;

        case 'choose_heart_other_member':
            if (!empty($state['pending_prompt'])) break;
            $choices = $ab['heart_choices'] ?? ['yellow', 'pink', 'purple', 'green', 'blue', 'red'];
            $state['pending_prompt'] = [
                'type'          => 'choose_heart_other_member',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Choose a heart color for another Member on your Stage.',
                'choices'       => $choices,
                'choice_labels' => array_map(fn($c) => ucfirst($c) . ' ♡', $choices),
                'ability'       => $ab,
            ];
            break;

        case 'blade_per_discarded_pick_member':
            if (!empty($state['pending_prompt'])) break;
            $discarded = intval($ctx['discarded_count'] ?? 0);
            if ($discarded <= 0) break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                if (($ab['group'] ?? '') !== '' && ($mbr['group'] ?? '') !== ($ab['group'] ?? '')) continue;
                $candidates[] = cardPromptSummary($mbr) + ['slot' => $slot];
            }
            if (empty($candidates)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no matching Member on Stage for +Blade (discarded $discarded).");
                break;
            }
            if (count($candidates) === 1) {
                $bonus = intval($ab['amount'] ?? 3) * $discarded;
                $slot = $candidates[0]['slot'] ?? '';
                if ($slot !== '' && !empty($p['stage'][$slot])) {
                    $p['stage'][$slot]['live_blade_bonus'] =
                        intval($p['stage'][$slot]['live_blade_bonus'] ?? 0) + $bonus;
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] chosen Member gains +$bonus Blade.");
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'blade_per_discarded_pick_member',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Choose 1 Member on your Stage to gain Blade.',
                'candidates'    => $candidates,
                'discarded'     => $discarded,
                'ability'       => $ab,
            ];
            break;

        case 'mill_then_draw_if_all_members':
            $n = intval($ab['count'] ?? 3);
            $milled = array_splice($p['main_deck'], 0, min($n, count($p['main_deck'])));
            $allMembers = !empty($milled);
            foreach ($milled as $c) {
                if (($c['card_type'] ?? '') !== 'メンバー') {
                    $allMembers = false;
                    break;
                }
            }
            if (!empty($milled)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put " . count($milled) . ' card(s) from deck top into Waiting Room.');
            }
            if ($allMembers && count($milled) >= $n) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (all milled cards were Members).");
            }
            break;

        case 'optional_stage_reposition':
            if (countStageMembers($p) < 2) break;
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_stage_reposition',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'You may move Members on your Stage to any areas (confirm to keep current layout)?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Keep layout', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional Stage reposition (choose).');
            break;

        case 'pick_yell_member':
            if (($ctx['phase'] ?? '') !== 'live_success') break;
            $yellPool = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            $candidates = array_values(array_filter(
                $yellPool,
                fn($c) => cardMatchesYellPick($c, $ab)
            ));
            if (empty($candidates)) break;
            if (count($candidates) === 1) {
                $picked = $candidates[0];
                $ownerP['hand'][] = $picked;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added ' . cardDisplayName($picked) . ' from Yell to hand.');
                break;
            }
            $state['pending_prompt'] = [
                'type'        => 'pick_yell_member',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'prompt'      => 'Choose 1 card revealed by Yell to add to your hand.',
                'candidates'  => array_map('cardPromptSummary', $candidates),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose a Yell card.');
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
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $myZone = $p['live_zone'] ?? [];
            $oppZone = $state['players'][$opp]['live_zone'] ?? [];
            $myScore = empty($myZone) ? 0
                : array_sum(array_column($myZone, 'score')) + getLiveScoreBonus($state, $pid);
            $oppScore = empty($oppZone) ? 0
                : array_sum(array_column($oppZone, 'score')) + getLiveScoreBonus($state, $opp);
            if ($myScore <= $oppScore) break;
            if (($ab['group'] ?? '') !== '' && !stageHasGroupMember($p, $ab['group'])) break;
            if (putEnergyFromDeckInWait($p)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put 1 Energy from Energy deck into Wait.');
            }
            break;

        case 'live_success_energy_wait_if_excess':
            if (intval($ctx['excess_hearts'] ?? 0) < intval($ab['min_excess'] ?? 1)) break;
            $exColor = $ab['excess_color'] ?? '';
            if ($exColor !== '') {
                $colorExcess = intval($ctx['excess_hearts_by_color'][$exColor] ?? 0);
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
            break;

        case 'surveil_per_group_member_reveal_live':
            if (!empty($state['pending_prompt'])) break;
            $look = countGroupMembersOnStage($p, $ab['group'] ?? '', $ab['filter'] ?? 'member');
            if ($look <= 0) {
                $state = revealDeckTopLiveScore($state, $pid, $source, intval($ab['score_amount'] ?? 1));
                break;
            }
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            if (empty($top)) break;
            if (count($top) === 1) {
                $p['main_deck'] = array_merge($top, $p['main_deck']);
                $state = revealDeckTopLiveScore($state, $pid, $source, intval($ab['score_amount'] ?? 1));
                break;
            }
            $maxTop = intval($ab['max_top'] ?? 1);
            $chain = [
                'type'        => 'reveal_top_live_score',
                'source_id'   => $source['instance_id'] ?? '',
                'score_amount'=> intval($ab['score_amount'] ?? 1),
                'max_top'     => $maxTop,
            ];
            $state = startSurveilArrangePrompt($state, $pid, $name, $top, $chain);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] looked at $look card(s); arrange deck top.");
            break;

        case 'optional_return_member_energy':
            break;

        case 'mill_deck_draw_if_live':
            $n = intval($ab['count'] ?? 5);
            $milled = array_splice($p['main_deck'], 0, min($n, count($p['main_deck'])));
            $hasLive = false;
            foreach ($milled as $c) {
                if (($c['card_type'] ?? '') === 'ライブ') $hasLive = true;
            }
            if (!empty($milled)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
            }
            if ($hasLive) {
                $drawn = drawCardsForPlayer($state, $pid, 1);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] milled $n; drew $drawn (Live found).");
            } else {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] milled $n; no Live card found.");
            }
            break;

        case 'activate_energy':
            $activated = activateEnergyForPlayer($p, intval($ab['count'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] activated $activated Energy.");
            break;

        case 'discard_add_from_wr':
            return startEffectDiscardHandPrompt(
                $state,
                $pid,
                $name,
                intval($ab['discard'] ?? 1),
                '',
                ['then' => [
                    'type'   => 'add_from_wr',
                    'group'  => $ab['group'] ?? '',
                    'filter' => $ab['filter'] ?? 'member',
                    'count'  => intval($ab['count'] ?? 1),
                ]]
            );

        case 'add_from_wr':
            $extra = [];
            if (isset($ab['min_score'])) {
                $extra['min_score'] = intval($ab['min_score']);
            }
            if (isset($ab['min_live_score'])) {
                $extra['min_live_score'] = intval($ab['min_live_score']);
            }
            $added = addFromWaitingRoomFiltered(
                $p,
                $ab['group'] ?? '',
                $ab['filter'] ?? '',
                intval($ab['count'] ?? 1),
                isset($ab['max_cost']) ? intval($ab['max_cost']) : null,
                $extra
            );
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $added card(s) from Waiting Room.");
            }
            break;

        case 'live_start_surveil':
            $look = intval($ab['look'] ?? 3);
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            if (count($top) <= 1) {
                if (count($top) === 1) {
                    $p['hand'][] = $top[0];
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at deck top.");
            } else {
                $state = startSurveilArrangePrompt($state, $pid, $name, $top, null, $source['instance_id'] ?? '');
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at top " . count($top) . ' — arrange them.');
            }
            break;

        case 'reduce_hearts_per_success_count':
            $n = count($p['success_lives'] ?? []) * intval($ab['per_success'] ?? 1);
            if ($n > 0) {
                $color = $ab['color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $n;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $n;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$n Gray heart(s)" : "$n heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required hearts reduced by $label (Success Live area).");
            }
            break;

        case 'choose_heart_modifier':
            if (!empty($state['pending_prompt'])) break;
            $choices = $ab['heart_choices'] ?? ['yellow', 'pink', 'purple'];
            $state['pending_prompt'] = [
                'type'          => 'choose_heart_modifier',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Choose a heart color to gain until this Live ends.',
                'choices'       => $choices,
                'choice_labels' => array_map(fn($c) => ucfirst($c) . ' ♡', $choices),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose a heart color.');
            break;

        case 'optional_success_wr_live_swap':
            if (!empty($state['pending_prompt'])) break;
            $group = $ab['group'] ?? 'Nijigasaki';
            $filter = $ab['filter'] ?? 'live';
            $succ = array_values(array_filter(
                $p['success_lives'] ?? [],
                fn($c) => cardMatchesGroup($c, $group, $filter)
            ));
            if (empty($succ)) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_success_wr_live_swap',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'step'          => 'confirm',
                'group'         => $group,
                'filter'        => $filter,
                'prompt'        => 'Put 1 ' . $group . ' Live from your Success Live area into the Waiting Room. If you do, put 1 ' .
                    $group . ' Live from your Waiting Room into your Success Live area?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional Success / WR Live swap (choose).');
            break;

        case 'optional_success_live_swap':
            if (!empty($state['pending_prompt'])) break;
            $hasLiveHand = !empty(array_filter(
                $p['hand'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if (empty($p['success_lives']) || !$hasLiveHand) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_success_live_swap',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'step'          => 'confirm',
                'prompt'        => 'Reveal 1 Live card from your hand: add 1 Success Live card to your hand, then put the revealed card into your Success Live area?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose).');
            break;

        case 'both_wr_member_to_empty_stage':
            $maxCost = intval($ab['max_cost'] ?? 2);
            foreach (['p1', 'p2'] as $id) {
                $placed = putWrMemberToEmptyStageWait($state['players'][$id], $maxCost);
                if ($placed) {
                    $m = $placed['member'];
                    $state = addLog($state, $state['players'][$id]['name'] .
                        ' — [' . $name . '] put ' . ($m['name_en'] ?? $m['name']) .
                        ' from Waiting Room onto Stage in Wait.');
                }
            }
            break;

        case 'activate_subunit_from_wait_score':
            $subunit = $ab['subunit'] ?? '';
            $activated = activateSubunitFromWait($p, $subunit);
            if ($activated > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated $activated $subunit Member(s) from Wait.");
            }
            if ($activated >= intval($ab['min_activated'] ?? 3)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    " ($activated Members activated from Wait).");
            }
            break;

        case 'score_if_subunit_only_no_success':
            if (empty($p['success_lives'])
                && stageAllMembersInSubunit($p, $ab['subunit'] ?? '')) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (lily white only, no Success Lives).');
            }
            break;

        case 'reduce_hearts_if_opp_wait':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (stageHasWaitMember($state, $opp)) {
                $reduce = intval($ab['reduce'] ?? 1);
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $reduce;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $reduce;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$reduce Gray heart(s)" : "$reduce heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced (opponent has Wait).");
            }
            break;

        case 'live_success_add_wr_if_distinct_subunit':
            if (countDistinctNamedSubunit($p, $ab['subunit'] ?? '') >= intval($ab['min_distinct'] ?? 2)) {
                $added = addFromWaitingRoomFiltered(
                    $p,
                    $ab['group'] ?? '',
                    $ab['filter'] ?? 'member',
                    intval($ab['count'] ?? 1),
                    null,
                    ['subunit' => $ab['subunit'] ?? '']
                );
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added $added " . ($ab['subunit'] ?? '') . " card(s) from Waiting Room.");
                }
            }
            break;

        case 'score_if_distinct_subunit':
            if (countDistinctNamedSubunit($p, $ab['subunit'] ?? '') >= intval($ab['min_distinct'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (2+ distinct ' . ($ab['subunit'] ?? '') . ' Members).');
            }
            break;

        case 'score_if_stage_blade':
            if (totalStageBlade($p) >= intval($ab['min_blade'] ?? 10)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (Stage Blade ' . totalStageBlade($p) . '+).');
            }
            break;

        case 'yell_hearts_wildcard':
        case 'yell_heart_score_bonus':
        case 'draw_per_yell_heart':
            break;

        case 'waive_one_required_heart_color':
            if (!stageHasGroupMember($p, $ab['requires_stage_group'] ?? '')) break;
            if (!empty($state['pending_prompt'])) break;
            $choices = $ab['colors'] ?? ['pink', 'green', 'blue'];
            $state['pending_prompt'] = [
                'type'          => 'waive_required_heart_color',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Choose 1 required heart color you do not need for this Live.',
                'choices'       => $choices,
                'choice_labels' => array_map(fn($c) => ucfirst($c) . ' ♡ waived', $choices),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose a heart color to waive.');
            break;

        case 'choose_required_heart_pair_gray':
            if (!stageHasGroupMember($p, $ab['requires_stage_group'] ?? '')) break;
            if (!empty($state['pending_prompt'])) break;
            $choices = $ab['colors'] ?? ['pink', 'green', 'blue'];
            $state['pending_prompt'] = [
                'type'          => 'choose_required_heart_pair_gray',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Choose pink, green, or blue — required hearts become 2 of that color and 1 Gray Heart.',
                'choices'       => $choices,
                'choice_labels' => array_map(
                    fn($c) => ucfirst($c) . ' ♡, ' . ucfirst($c) . ' ♡, Gray ♡',
                    $choices
                ),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose required heart pattern.');
            break;

        case 'score_per_distinct_group_stage':
            $cnt = countDistinctNamedGroupOnStage(
                $p,
                $ab['group'] ?? '',
                $ab['filter'] ?? 'member'
            );
            if ($cnt > 0) {
                $bonus = $cnt * intval($ab['amount'] ?? 1);
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $bonus);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] score +$bonus ($cnt distinct " . ($ab['group'] ?? '') . ' Members).');
            }
            break;

        case 'reduce_hearts_if_baton_group':
            $turn = intval($state['turn'] ?? 1);
            $cnt = countBatonEnteredGroupThisTurn($p, $ab['group'] ?? '', $turn);
            if ($cnt >= intval($ab['min_baton'] ?? 2)) {
                bumpLiveCardColorReduction(
                    $state,
                    $pid,
                    $source['instance_id'] ?? '',
                    $ab['color'] ?? 'any',
                    intval($ab['reduce'] ?? 1)
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] required ' . ($ab['color'] ?? 'any') .
                    ' hearts reduced by ' . intval($ab['reduce'] ?? 1) .
                    " ($cnt Baton-entered Members).");
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

        case 'reduce_hearts_if_named_cost_pair':
            $baseNames = $ab['base_names'] ?? [];
            $higherNames = $ab['higher_names'] ?? [];
            $baseCost = null;
            $ok = false;
            foreach ($p['stage'] as $mbr) {
                if (!$mbr || !cardMatchesNames($mbr, $baseNames)) continue;
                $baseCost = intval($mbr['cost'] ?? 0);
                break;
            }
            if ($baseCost !== null) {
                foreach ($p['stage'] as $mbr) {
                    if (!$mbr || !cardMatchesNames($mbr, $higherNames)) continue;
                    if (intval($mbr['cost'] ?? 0) > $baseCost) {
                        $ok = true;
                        break;
                    }
                }
            }
            if ($ok) {
                bumpLiveCardColorReduction(
                    $state,
                    $pid,
                    $source['instance_id'] ?? '',
                    $ab['color'] ?? 'any',
                    intval($ab['reduce'] ?? 1)
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] required ' . ($ab['color'] ?? 'any') .
                    ' hearts reduced by ' . intval($ab['reduce'] ?? 1) . '.');
            }
            break;

        case 'score_if_named_stage_slots':
            if (stageNamedSlotsMatch($p, $ab['slots'] ?? [])) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (named Members in position).');
            }
            break;

        case 'activate_energy_if_other_group':
            $group = $ab['group'] ?? '';
            $hasOther = false;
            foreach ($p['stage'] as $mbr) {
                if (!$mbr) continue;
                if (($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) continue;
                if (($mbr['group'] ?? '') === $group) {
                    $hasOther = true;
                    break;
                }
            }
            if ($hasOther) {
                $activated = activateEnergyForPlayer($p, intval($ab['count'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated $activated Energy.");
            }
            break;

        case 'treat_as_subunits':
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

        case 'draw_if_live_score_higher_than_opp':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (getLiveTotalScore($state, $pid) > getLiveTotalScore($state, $opp)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Live score higher).");
            }
            break;

        case 'draw_cards':
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['count'] ?? $ab['draw'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn.");
            break;

        case 'draw_per_energy':
            $per = max(1, intval($ab['per'] ?? 6));
            $n = intdiv(countEnergyInZone($p), $per);
            if ($n > 0) {
                $drawn = drawCardsForPlayer($state, $pid, $n);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (1 per $per Energy).");
            }
            break;

        case 'wait_opponent_stage_max_cost':
            $state = beginWaitOpponentStagePick(
                $state,
                $pid,
                $name,
                $ab,
                $source['instance_id'] ?? '',
                ($ctx['phase'] ?? '') === 'live_start'
                    || ($state['phase'] ?? '') === 'live_start_effects'
            );
            break;

        case 'pick_wr_members_deck_top_by_opp_wait':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $need = countOppWaitMembers($state, $opp);
            if ($need <= 0) {
                break;
            }
            $candidates = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => cardMatchesGroup($c, $ab['group'] ?? '', $ab['filter'] ?? 'member')
            ));
            if (empty($candidates)) {
                break;
            }
            $pick = min($need, count($candidates));
            if (count($candidates) > $pick) {
                $state['pending_prompt'] = [
                    'type'          => 'pick_wr_members_deck_top',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_name'   => $name,
                    'prompt'        => "Choose up to $pick Nijigasaki Member(s) from Waiting Room for deck top.",
                    'candidates'    => array_map('cardPromptSummary', $candidates),
                    'pick_count'    => $pick,
                    'ability'       => $ab,
                ];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] choose up to $pick Member(s) from Waiting Room.");
                break;
            }
            $picked = array_slice($candidates, 0, $pick);
            $pickIds = array_map(fn($c) => $c['instance_id'] ?? '', $picked);
            $p['waiting_room'] = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => !in_array($c['instance_id'] ?? '', $pickIds, true)
            ));
            $p['main_deck'] = array_merge(array_reverse($picked), $p['main_deck']);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] put $pick Member(s) from Waiting Room on deck top.");
            break;

        case 'optional_pay_play_hand_member':
        case 'optional_play_hand_member':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $cost = intval($ab['cost'] ?? 0);
            $group = $ab['group'] ?? 'Nijigasaki';
            $maxCost = intval($ab['max_cost'] ?? 4);
            $promptPay = $cost > 0
                ? 'Pay ' . $cost . ' Energy: '
                : '';
            $state['pending_prompt'] = [
                'type'          => 'optional_pay_play_hand_member',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => $promptPay .
                    'put 1 ' . $group . ' Member (cost ≤' . $maxCost .
                    ') from your hand onto your Stage?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => [
                    ($cost > 0 ? 'Yes — Pay & Play' : 'Yes — Play'),
                    'No — Skip',
                ],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose).');
            break;

        case 'both_add_wr_live_to_hand':
            foreach (['p1', 'p2'] as $id) {
                $pl = &$state['players'][$id];
                $added = addFromWaitingRoomFiltered($pl, '', 'live', 1);
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$id]['name'] .
                        " — [$name] added 1 Live card from Waiting Room to hand.");
                }
                unset($pl);
            }
            break;

        case 'both_energy_wait_from_deck':
            foreach (['p1', 'p2'] as $id) {
                $pl = &$state['players'][$id];
                if (putEnergyFromDeckInWait($pl)) {
                    $state = addLog($state, $state['players'][$id]['name'] .
                        " — [$name] put 1 Energy into Wait.");
                }
                unset($pl);
            }
            break;

        case 'draw_if_stage_cost_less_surveil':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (sumStageMemberCost($p, $state, $pid)
                >= sumStageMemberCost($state['players'][$opp], $state, $opp)) {
                break;
            }
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 2));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (Stage cost lower).");
            $topN = intval($ab['deck_top'] ?? 1);
            if ($topN > 0 && count($p['hand']) >= $topN) {
                return startEffectDiscardHandPrompt(
                    $state,
                    $pid,
                    $name,
                    $topN,
                    "Choose $topN card(s) to put on top of your deck (left = top).",
                    ['pick_mode' => 'deck_top']
                );
            }
            break;

        case 'live_start_match_success_heart':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $lives = array_values(array_filter(
                $p['live_zone'] ?? [],
                fn($c) => $c && cardMatchesGroup($c, $ab['group'] ?? '', 'live')
            ));
            if (empty($lives)) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'pick_live_match_success_heart',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $lives),
                'ability'       => $ab,
                'prompt'        => 'Choose 1 Nijigasaki Live card in your Live.',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose a Live card.');
            break;

        case 'mill_then_add_wr_live_distinct':
            $n = intval($ab['count'] ?? 5);
            $milled = array_splice($p['main_deck'], 0, min($n, count($p['main_deck'])));
            if (!empty($milled)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put ' . count($milled) . ' card(s) into Waiting Room.');
            }
            if (countDistinctWrLives($p, $ab['group'] ?? '') >= intval($ab['min_distinct'] ?? 3)) {
                $added = addFromWaitingRoomFiltered(
                    $p,
                    $ab['group'] ?? '',
                    'live',
                    1
                );
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added $added Live card(s) from Waiting Room.");
                }
            }
            break;

        case 'optional_wr_to_deck_top':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            if (empty($p['waiting_room'])) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_wr_to_deck_top',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $p['waiting_room']),
                'prompt'        => 'Put 1 card from your Waiting Room on top of your deck?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Choose card', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose).');
            break;

        case 'optional_wait_group_member_draw_discard':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_group_member_draw_discard',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'group'         => $ab['group'] ?? 'Nijigasaki',
                'prompt'        => 'Put 1 ' . ($ab['group'] ?? 'Nijigasaki') .
                    ' Member into Wait: draw 1 card and discard 1?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose).');
            break;

        case 'yell_blades_to_blue':
            $state = initLiveModifiers($state);
            $state['live_modifiers'][$pid]['yell_blades_to_blue'] = true;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Yell Blade hearts become Blue until Live ends.');
            break;

        case 'yell_all_heart_types_score_bonus':
            $yellCards = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            if (yellMembersHaveAllHeartColors($yellCards)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (all heart colors in Yell).');
            }
            break;

        case 'score_per_named_success_live':
            $cnt = countNamedSuccessLives($p, $ab['name'] ?? '');
            if ($cnt > 0) {
                $bonus = $cnt * intval($ab['score_per'] ?? 2);
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $bonus);
                $inc = $cnt * intval($ab['hearts_increase'] ?? 3);
                $incColor = $ab['hearts_increase_color'] ?? 'any';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($incColor === 'gray') {
                            $lc['hearts_increase_gray'] = intval($lc['hearts_increase_gray'] ?? 0) + $inc;
                        } else {
                            $lc['hearts_increase'] = intval($lc['hearts_increase'] ?? 0) + $inc;
                        }
                        break;
                    }
                }
                unset($lc);
                $incLabel = $incColor === 'gray' ? "$inc Gray Hearts" : "$inc hearts";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] score +$bonus; required $incLabel (EMOTION in Success).");
            }
            break;

        case 'score_if_wr_distinct_live_count':
            $distinct = countDistinctWrLives($p, $ab['group'] ?? '');
            $amt = 0;
            if ($distinct >= intval($ab['min_6'] ?? 6)) {
                $amt = intval($ab['amount_6'] ?? 2);
            } elseif ($distinct >= intval($ab['min_4'] ?? 4)) {
                $amt = intval($ab['amount_4'] ?? 1);
            }
            if ($amt > 0) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $amt);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] score +$amt ($distinct distinct WR Lives).");
            }
            break;

        case 'turn_one_live_score_member_blade':
            if (intval($state['turn'] ?? 1) !== 1) {
                break;
            }
            bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['score'] ?? 1));
            $n = applyMemberBladeBonus($state, $pid, [
                'group'        => $ab['group'] ?? 'Nijigasaki',
                'amount'       => intval($ab['blade'] ?? 1),
                'max_members'  => intval($ab['max_members'] ?? 1),
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] score +1; $n Member(s) gained +" . intval($ab['blade'] ?? 1) . ' Blade (turn 1).');
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

        case 'draw_surveil_if_full_stage_cost':
            if (!stageFullGroupMembersMinCost(
                $p,
                $ab['group'] ?? 'Nijigasaki',
                intval($ab['min_cost'] ?? 20)
            )) {
                break;
            }
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 3));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (full Stage, cost 20+).");
            $topN = intval($ab['deck_top'] ?? 3);
            if ($topN > 0 && count($p['hand']) >= $topN) {
                return startEffectDiscardHandPrompt(
                    $state,
                    $pid,
                    $name,
                    $topN,
                    "Choose $topN card(s) to put on top of your deck (in order).",
                    ['pick_mode' => 'deck_top']
                );
            }
            break;

        case 'blade_bonus':
            $state = applyModifierEffect($state, $pid, $ab);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] gains +' . intval($ab['amount'] ?? 1) . ' Blade until this Live ends.');
            break;

        case 'live_score_bonus':
            $state = applyModifierEffect($state, $pid, $ab);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] gains +' . intval($ab['amount'] ?? 1) . ' total Live Score until this Live ends.');
            break;

        case 'score_if_deck_refreshed':
            if (intval($p['_deck_refreshed_turn'] ?? -1) === intval($state['turn'] ?? 0)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 2));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 2) . ' (deck refreshed this turn).');
            }
            break;

        case 'member_blade_bonus_if_success_count':
            if (count($p['success_lives'] ?? []) >= intval($ab['min_success_count'] ?? 2)) {
                $n = applyMemberBladeBonus($state, $pid, $ab);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] $n Member(s) gained +" . intval($ab['amount'] ?? 2) . ' Blade until Live ends.');
            }
            break;

        case 'member_blade_bonus_if_other_live_zone':
            $exclude = $ab['exclude_live_name'] ?? '';
            $hasOther = false;
            foreach ($p['live_zone'] ?? [] as $lc) {
                if (!$lc || ($lc['card_type'] ?? '') !== 'ライブ') continue;
                $ln = $lc['name_en'] ?? $lc['name'] ?? '';
                if ($exclude !== '' && str_contains($ln, $exclude)) continue;
                if (($lc['group'] ?? '') === ($ab['group'] ?? 'Sunshine')) {
                    $hasOther = true;
                    break;
                }
            }
            if ($hasOther) {
                $n = applyMemberBladeBonus($state, $pid, $ab);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] $n Member(s) gained +" . intval($ab['amount'] ?? 1) . ' Blade until Live ends.');
            }
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

        case 'add_self_to_hand_if_winning_yell':
            if (empty($ctx['yell_cards'])) break;
            $inYell = false;
            foreach ($ctx['yell_cards'] as $yc) {
                if (($yc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $inYell = true;
                    break;
                }
            }
            if (!$inYell) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $myScore = array_sum(array_column($p['live_zone'] ?? [], 'score')) + getLiveScoreBonus($state, $pid);
            $oppScore = array_sum(array_column($state['players'][$opp]['live_zone'] ?? [], 'score'))
                + getLiveScoreBonus($state, $opp);
            if ($myScore > $oppScore) {
                $p['hand'][] = $source;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added itself to hand (winning Live score, revealed by Yell).');
            }
            break;

        case 'optional_discard_blade_per_card':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_blade_per_card',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'max_discard'   => intval($ab['max_discard'] ?? 2),
                'prompt'        => 'Put up to ' . intval($ab['max_discard'] ?? 2) .
                    ' cards from your hand into the Waiting Room: gain +' .
                    intval($ab['blade_per'] ?? 1) . ' Blade per card discarded?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Discard', 'No — Skip'],
                'ability'       => $ab,
            ];
            break;

        case 'optional_wr_live_deck_bottom':
            if (!empty($state['pending_prompt'])) break;
            $cands = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if (empty($cands)) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_wr_live_deck_bottom',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $cands),
                'prompt'        => 'Put up to 1 Live card from your Waiting Room on the bottom of your deck?',
                'choices'       => ['pick', 'skip'],
                'choice_labels' => ['Choose Live', 'Skip'],
                'ability'       => $ab,
            ];
            break;

        case 'optional_reveal_live_deck_bottom_surveil':
            if (!empty($state['pending_prompt'])) break;
            $hasLive = !empty(array_filter(
                $p['hand'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if (!$hasLive || empty($p['main_deck'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_reveal_live_deck_bottom_surveil',
                'step'          => 'confirm',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Reveal 1 Live from your hand and put it on the bottom of your deck, then look at the top ' .
                    intval($ab['look'] ?? 2) . ' card(s) of your deck?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose).');
            break;

        case 'optional_wr_member_deck_top_blade':
            if (!empty($state['pending_prompt'])) break;
            $wrMembers = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
            ));
            if (empty($wrMembers) || empty(array_filter($p['stage']))) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_wr_member_deck_top_blade',
                'step'          => 'confirm',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Put 1 Member from your Waiting Room on top of your deck: 1 Stage Member gains +' .
                    intval($ab['blade_amount'] ?? 1) . ' Blade until this Live ends?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose).');
            break;

        case 'player_choice_wr_live_deck_bottom_draw':
            if (!empty($state['pending_prompt'])) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $selfLives = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            $oppLives = array_values(array_filter(
                $state['players'][$opp]['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if (empty($selfLives) && empty($oppLives)) break;
            $choices = [];
            $labels = [];
            if (!empty($selfLives)) {
                $choices[] = 'self';
                $labels[] = 'Yourself';
            }
            if (!empty($oppLives)) {
                $choices[] = 'opponent';
                $labels[] = 'Opponent';
            }
            $state['pending_prompt'] = [
                'type'          => 'player_choice_wr_live_deck_bottom_draw',
                'step'          => 'pick_player',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Choose yourself or your opponent: put 1 Live from that player\'s Waiting Room on the bottom of their deck (then draw ' .
                    intval($ab['draw'] ?? 1) . ').',
                'choices'       => $choices,
                'choice_labels' => $labels,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start: choose a player.');
            break;

        case 'live_start_center_cost_choice':
            if (!empty($state['pending_prompt'])) break;
            $group = $ab['group'] ?? 'Sunshine';
            $center = $p['stage']['center'] ?? null;
            if (!$center || ($center['group'] ?? '') !== $group) break;
            if (getEffectiveStageMemberCost($state, $pid, $center) < intval($ab['min_center_cost'] ?? 9)) {
                break;
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $hasBlade = !empty(listStageMemberChoices($p));
            $hasWait = !empty(listOppStageMembersByMaxCost(
                $state,
                $opp,
                intval($ab['wait_opp_max_cost'] ?? 4)
            ));
            if (!$hasBlade && !$hasWait) break;
            $choices = [];
            $labels = [];
            if ($hasBlade) {
                $choices[] = 'blade';
                $labels[] = 'Until this Live ends, 1 Member on your Stage gains +' .
                    intval($ab['blade_amount'] ?? 2) . ' Blade.';
            }
            if ($hasWait) {
                $choices[] = 'wait_opp';
                $labels[] = 'Put 1 opponent Stage Member with cost ' .
                    intval($ab['wait_opp_max_cost'] ?? 4) . ' or less into Wait.';
            }
            $state['pending_prompt'] = [
                'type'          => 'live_start_center_cost_choice',
                'step'          => 'pick_mode',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Choose one:',
                'choices'       => $choices,
                'choice_labels' => $labels,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start: choose an effect.');
            break;

        case 'play_wr_members_combined_cost':
            if (!empty($state['pending_prompt'])) break;
            $cands = array_values(array_filter($p['waiting_room'], function ($c) use ($ab) {
                return ($c['card_type'] ?? '') === 'メンバー'
                    && cardMatchesGroup($c, $ab['group'] ?? '', 'member');
            }));
            if (empty($cands)) break;
            $state['pending_prompt'] = [
                'type'               => 'play_wr_members_combined_cost',
                'owner'              => $pid,
                'responder'          => $pid,
                'source_name'        => $name,
                'max_combined_cost'  => intval($ab['max_combined_cost'] ?? 4),
                'max_count'          => intval($ab['count'] ?? 2),
                'prompt'             => 'Choose Member(s) from Waiting Room (combined cost ≤' .
                    intval($ab['max_combined_cost'] ?? 4) . ') to put on Stage in Wait.',
                'ability'            => $ab,
            ];
            break;

        case 'score_if_stage_member_hearts':
            if (!empty($state['pending_prompt'])) break;
            $group = $ab['group'] ?? 'Sunshine';
            $checkBlades = !empty($ab['min_blades']);
            $threshold = $checkBlades
                ? intval($ab['min_blades'])
                : intval($ab['min_hearts'] ?? 6);
            $eligible = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr || ($mbr['group'] ?? '') !== $group) continue;
                $ok = $checkBlades
                    ? (intval($mbr['blade'] ?? 0) + intval($mbr['live_blade_bonus'] ?? 0) >= $threshold)
                    : (memberHeartCount($mbr) >= $threshold);
                if ($ok) {
                    $eligible[] = ['slot' => $slot, 'summary' => cardPromptSummary($mbr)];
                }
            }
            if (empty($eligible)) break;
            $state['pending_prompt'] = [
                'type'        => 'score_if_stage_member_hearts',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_id'   => $source['instance_id'] ?? '',
                'source_name' => $name,
                'candidates'  => $eligible,
                'amount'      => intval($ab['amount'] ?? 1),
                'prompt'      => 'Choose 1 Aqours Member with ' . $threshold . '+' .
                    ($checkBlades ? ' Blades' : ' hearts') . ': this card\'s score +' .
                    intval($ab['amount'] ?? 1) . '?',
            ];
            break;

        case 'set_live_score_if_yell_or_excess':
            $noBlade = true;
            foreach ($state['_last_yell_cards'] ?? [] as $yc) {
                if (!empty($yc['blade_hearts'])) {
                    $noBlade = false;
                    break;
                }
            }
            $excessOk = intval($ctx['excess_hearts'] ?? 0) >= intval($ab['min_excess_hearts'] ?? 2);
            if ($noBlade || $excessOk) {
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        $lc['score'] = intval($ab['score'] ?? 4);
                        break;
                    }
                }
                unset($lc);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score set to ' . intval($ab['score'] ?? 4) . '.');
            }
            break;

        case 'add_wr_live_if_opp_hand_ahead':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $diff = count($state['players'][$opp]['hand'] ?? []) - count($p['hand'] ?? []);
            if ($diff >= intval($ab['min_hand_diff'] ?? 2)) {
                $added = addFromWaitingRoomFiltered($p, '', 'live', intval($ab['count'] ?? 1));
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added $added Live card(s) from Waiting Room (opponent hand +$diff).");
                }
            }
            break;

        case 'opp_may_discard_or_modifier':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $state['pending_prompt'] = [
                'type'          => 'opp_may_discard_or_modifier',
                'owner'         => $pid,
                'responder'     => $opp,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Put 1 Live card from your hand into the Waiting Room? (If not, opponent gains +1 total Live Score.)',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Discard Live', 'No — Opponent gains Live Score'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] On Enter: opponent may discard a Live card.");
            break;

        case 'member_hearts_as_blade':
            foreach ($p['stage'] as $slot => &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $mbr['hearts_as_blade_color'] = $ab['color'] ?? 'blue';
                    $p['stage'][$slot] = $mbr;
                    break;
                }
            }
            unset($mbr);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] printed hearts treated as ' .
                ucfirst($ab['color'] ?? 'blue') . ' Blade hearts until this Live ends.');
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

        case 'look_reveal_heart_threshold':
            $look = intval($ab['look'] ?? 4);
            $minM = intval($ab['min_member_hearts'] ?? 2);
            $minL = intval($ab['min_live_required'] ?? 2);
            $heartColor = (string)($ab['heart_color'] ?? '');
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            $eligible = array_values(array_filter(
                $top,
                fn($c) => cardMeetsHeartThreshold($c, $minM, $minL, $heartColor)
            ));
            if (empty($eligible)) {
                if (!empty($top)) {
                    $p['waiting_room'] = array_merge($p['waiting_room'], $top);
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at $look card(s); none eligible.");
                break;
            }
            if (count($eligible) === 1) {
                $pickId = $eligible[0]['instance_id'] ?? '';
                $rest = [];
                foreach ($top as $c) {
                    if (($c['instance_id'] ?? '') === $pickId) {
                        $p['hand'][] = $c;
                    } else {
                        $rest[] = $c;
                    }
                }
                if (!empty($rest)) {
                    $p['waiting_room'] = array_merge($p['waiting_room'], $rest);
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added 1 card from surveil to hand.');
                break;
            }
            $state['surveil_stash'] = $top;
            $state['pending_prompt'] = [
                'type'          => 'pick_surveil_heart_threshold',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $eligible),
                'prompt'        => 'Choose 1 eligible card to add to hand (or skip).',
                'choices'       => array_merge(['skip'], array_map(fn($c) => $c['instance_id'] ?? '', $eligible)),
                'choice_labels' => array_merge(
                    ['Skip — put all in Waiting Room'],
                    array_map(fn($c) => cardDisplayName($c), $eligible)
                ),
                'ability'       => $ab,
            ];
            break;

        case 'negate_self_live_success_if_group_hearts':
            $heartColor = (string)($ab['heart_color'] ?? '');
            if (sumGroupStageHearts($p, $ab['group'] ?? 'Sunshine', $heartColor)
                >= intval($ab['min_hearts'] ?? 6)) {
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        $lc['live_success_negated'] = true;
                        break;
                    }
                }
                unset($lc);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] Live Success ability negated (Aqours stage hearts).');
            }
            break;

        case 'opp_energy_wait_from_deck':
            if (!empty($ab['skip_if_negated'])) {
                foreach ($p['live_zone'] as $lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')
                        && !empty($lc['live_success_negated'])) {
                        break 2;
                    }
                }
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (putEnergyFromDeckInWait($state['players'][$opp])) {
                $state = addLog($state, $state['players'][$opp]['name'] .
                    ' — [' . $name . '] put 1 Energy from Energy deck into Wait.');
            }
            break;

        case 'score_if_group_stage_hearts':
            $heartColor = (string)($ab['heart_color'] ?? '');
            if (sumGroupStageHearts($p, $ab['group'] ?? 'Sunshine', $heartColor)
                >= intval($ab['min_hearts'] ?? 10)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 2));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 2) . ' (Aqours stage hearts).');
            }
            break;

        case 'score_if_group_stage_hearts_opp_no_excess':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $heartColor = (string)($ab['heart_color'] ?? '');
            if (sumGroupStageHearts($p, $ab['group'] ?? 'Sunshine', $heartColor)
                    >= intval($ab['min_hearts'] ?? 4)
                && !empty($state['_live_success_no_excess'][$opp])) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 2));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 2) .
                    ' (Aqours hearts + opponent no excess).');
            }
            break;

        case 'block_success_live_on_tie':
            $state = initLiveModifiers($state);
            $state['live_modifiers']['both']['block_success_live_on_tie'] = true;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] if Live scores tie, neither player adds Success Lives this turn.');
            break;

        case 'optional_negate_member_live_start_add_wr':
            if (!empty($state['pending_prompt'])) break;
            $candidates = [];
            foreach ($p['stage'] as $s => $mbr) {
                if (!$mbr || ($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) continue;
                if (!cardMatchesGroup($mbr, $ab['group'] ?? '', $ab['filter'] ?? 'member')) continue;
                $candidates[] = ['slot' => $s, 'summary' => cardPromptSummary($mbr)];
            }
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_negate_member_live_start_add_wr',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'candidates'    => $candidates,
                'prompt'        => 'Negate [Live Start] abilities of 1 Liella! Member until this Live ends and add 1 Liella! card from your Waiting Room to hand?',
                'choices'       => array_merge(['skip'], array_map(fn($c) => $c['summary']['instance_id'] ?? '', $candidates)),
                'choice_labels' => array_merge(
                    ['Skip'],
                    array_map(fn($c) => $c['summary']['name_en'] ?? 'Member', $candidates)
                ),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose Member).');
            break;

        case 'if_baton_wr_group_to_hand':
            if (empty($source['entered_via_baton'])) break;
            $batonId = $source['baton_wr_member_id'] ?? '';
            if ($batonId === '') break;
            $picked = null;
            $rest = [];
            foreach ($p['waiting_room'] as $c) {
                if (!$picked && ($c['instance_id'] ?? '') === $batonId
                    && cardMatchesGroup($c, $ab['group'] ?? '', $ab['filter'] ?? 'member')) {
                    $picked = $c;
                } else {
                    $rest[] = $c;
                }
            }
            if ($picked) {
                $p['waiting_room'] = $rest;
                $p['hand'][] = $picked;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added ' . cardDisplayName($picked) . ' from Baton Touch to hand.');
            }
            break;

        case 'blade_per_hand_cards':
            $state = initLiveModifiers($state);
            $state['live_modifiers'][$pid]['blade_per_hand_divisor'] = max(1, intval($ab['per_cards'] ?? 2));
            $state['live_modifiers'][$pid]['blade_per_hand_amount'] = intval($ab['amount'] ?? 1);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] +1 Blade per ' . intval($ab['per_cards'] ?? 2) . ' cards in hand until Live ends.');
            break;

        case 'reduce_yell_reveal_count':
            if (!empty($ab['requires_other_members'])) {
                $others = 0;
                foreach ($p['stage'] as $mbr) {
                    if ($mbr && ($mbr['instance_id'] ?? '') !== ($source['instance_id'] ?? '')) {
                        $others++;
                    }
                }
                if ($others < 1) break;
            }
            $state = initLiveModifiers($state);
            $state['live_modifiers'][$pid]['yell_reveal_reduction'] =
                intval($state['live_modifiers'][$pid]['yell_reveal_reduction'] ?? 0)
                + intval($ab['amount'] ?? 8);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Yell reveal count reduced by ' . intval($ab['amount'] ?? 8) . ' until Live ends.');
            break;

        case 'pick_wr_distinct_lives_opp_choice':
            if (!empty($state['pending_prompt'])) break;
            $lives = array_values(array_filter(
                $p['waiting_room'] ?? [],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            $byName = [];
            foreach ($lives as $c) {
                $label = $c['name_en'] ?? $c['name'] ?? '';
                if ($label !== '' && !isset($byName[$label])) {
                    $byName[$label] = $c;
                }
            }
            $distinct = array_values($byName);
            if (count($distinct) < intval($ab['count'] ?? 2)) break;
            $state['pending_prompt'] = [
                'type'          => 'pick_wr_distinct_lives_opp_choice',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $distinct),
                'pick_count'    => intval($ab['count'] ?? 2),
                'prompt'        => 'Choose ' . intval($ab['count'] ?? 2) .
                    ' Live cards with different names from your Waiting Room.',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose Waiting Room Lives for opponent to pick.');
            break;

        case 'score_if_fewer_success_lives':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (count($p['success_lives'] ?? [])
                < count($state['players'][$opp]['success_lives'] ?? [])) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (fewer Success Lives).');
            }
            break;

        case 'score_if_hand_more_than_opp':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (count($p['hand']) > count($state['players'][$opp]['hand'] ?? [])) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (more cards in hand).');
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
            $pickLabel = $subunit !== ''
                ? 'Choose 1 ' . $subunit . ' Member card revealed by Yell to add to your hand.'
                : 'Choose 1 card revealed by Yell to add to your hand.';
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

        case 'energy_wait_if_group_only_min_energy':
            if (stageAllMembersInGroup($p, $ab['group'] ?? '')
                && countEnergyInZone($p) >= intval($ab['min_energy'] ?? 7)) {
                $n = intval($ab['count'] ?? 1);
                for ($i = 0; $i < $n; $i++) {
                    putEnergyFromDeckInWait($p, $state, $pid);
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put $n Energy into Wait (Liella! only, Energy threshold).");
            }
            break;

        case 'optional_wait_self_look_reveal':
            if (!empty($state['pending_prompt'])) break;
            $discardNeed = intval($ab['discard'] ?? 0);
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_self_look_reveal',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => ($discardNeed > 0
                    ? "Put this Member into Wait and discard $discardNeed card(s) from your hand to look at the top "
                    : 'Put this Member into Wait to look at the top ') .
                    intval($ab['look'] ?? 4) . ' cards of your deck?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
                'discard_count' => $discardNeed,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose).');
            break;

        case 'on_enter_side_area':
            $slot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
            $state = applyOnEnterSideEffect($state, $pid, $p, $name, $ab, $slot);
            break;

        case 'allows_double_baton':
            break;

        case 'if_double_baton_group_bonus':
            if (intval($source['baton_count'] ?? 0) < intval($ab['min_baton'] ?? 2)) break;
            $group = $ab['group'] ?? '';
            $batonGroups = $source['baton_member_groups'] ?? [];
            $groupCount = count(array_filter($batonGroups, fn($g) => $g === $group));
            if ($groupCount < intval($ab['min_baton'] ?? 2)) break;
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 2));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (double Baton Touch).");
            $placed = putWrGroupMemberToEmptyStage(
                $p,
                $ab['group'] ?? '',
                intval($ab['max_cost'] ?? 4),
                intval($state['turn'] ?? 1)
            );
            if ($placed) {
                $m = $placed['member'];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put ' . cardDisplayName($m) .
                    ' from Waiting Room onto Stage.');
            }
            break;

        case 'energy_wait_if_baton_group_min_energy':
            if (empty($source['entered_via_baton'])) break;
            $batonGroup = $source['baton_from_group'] ?? '';
            if ($batonGroup !== ($ab['group'] ?? '')) break;
            if (countEnergyInZone($p) < intval($ab['min_energy'] ?? 7)) break;
            $n = intval($ab['count'] ?? 2);
            for ($i = 0; $i < $n; $i++) {
                putEnergyFromDeckInWait($p, $state, $pid);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] put $n Energy into Wait (Baton Touch + Energy).");
            break;

        case 'wait_opp_max_original_hearts':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $waited = waitOpponentStageByOriginalHearts(
                $state,
                $opp,
                intval($ab['max_original_hearts'] ?? 3),
                intval($ab['pick_count'] ?? 1) ?: null,
                $pid
            );
            if ($waited > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put $waited opponent Member(s) into Wait.");
            }
            break;

        case 'score_if_center_cost_higher':
            $mine = $p['stage']['center'] ?? null;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $theirs = $state['players'][$opp]['stage']['center'] ?? null;
            if ($mine && $theirs
                && ($mine['group'] ?? '') === ($ab['group'] ?? '')
                && getEffectiveStageMemberCost($state, $pid, $mine)
                    > getEffectiveStageMemberCost($state, $opp, $theirs)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (Center cost higher).');
            }
            break;

        case 'grant_hearts_if_slot_blade_hearts':
            $slot = $ab['slot'] ?? 'left';
            $mbr = $p['stage'][$slot] ?? null;
            if ($mbr && ($mbr['group'] ?? '') === ($ab['group'] ?? '')
                && memberBladeHeartCount($mbr) >= intval($ab['min_blade_hearts'] ?? 3)) {
                addBonusHeartsToMember($mbr, $ab['hearts'] ?? [], 1);
                $p['stage'][$slot] = $mbr;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] granted bonus hearts to ' .
                    ($mbr['name_en'] ?? $mbr['name']) . '.');
            }
            break;

        case 'grant_blade_if_slot_colored_hearts':
            $slot = $ab['slot'] ?? 'left';
            $mbr = $p['stage'][$slot] ?? null;
            $color = $ab['heart_color'] ?? 'red';
            if ($mbr && ($mbr['group'] ?? '') === ($ab['group'] ?? '')
                && memberHeartColorCount($mbr, $color) >= intval($ab['min_hearts'] ?? 3)) {
                $amt = intval($ab['blade'] ?? 2);
                $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $amt;
                $p['stage'][$slot] = $mbr;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] granted +' . $amt . ' Blade to ' .
                    ($mbr['name_en'] ?? $mbr['name']) . '.');
            }
            break;

        case 'set_center_group_hearts':
            $center = $p['stage']['center'] ?? null;
            if ($center && ($center['group'] ?? '') === ($ab['group'] ?? '')) {
                $cnt = intval($ab['heart_count'] ?? 3);
                $center['printed_heart_override'] = $cnt;
                $p['stage']['center'] = $center;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Center Member printed hearts set to $cnt.");
            }
            break;

        case 'set_center_group_blades':
            $center = $p['stage']['center'] ?? null;
            if ($center && ($center['group'] ?? '') === ($ab['group'] ?? '')) {
                $cnt = intval($ab['blade_count'] ?? 3);
                $center['printed_blade_override'] = $cnt;
                $p['stage']['center'] = $center;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Center Member printed Blades set to $cnt.");
            }
            break;

        case 'blade_bonus_if_moved_in_slot':
            $needSlot = $ab['slot'] ?? '';
            $mySlot = findMemberSlot($p, $source['instance_id'] ?? '');
            if ($needSlot !== '' && $mySlot === $needSlot && !empty($source['moved_this_turn'])) {
                $state = applyModifierEffect($state, $pid, [
                    'type'   => 'blade_bonus',
                    'amount' => intval($ab['amount'] ?? 2),
                ]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] gained +' . intval($ab['amount'] ?? 2) .
                    ' Blade until Live ends (moved in slot).');
            }
            break;

        case 'pick_named_members_grant_blade':
            if (!empty($state['pending_prompt'])) break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
                foreach ($ab['names'] ?? [] as $n) {
                    if ($label === $n || str_contains($label, $n)) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => true]);
                        break;
                    }
                }
                if (($mbr['group'] ?? '') === ($ab['group'] ?? '')) {
                    $already = false;
                    foreach ($candidates as $c) {
                        if (($c['slot'] ?? '') === $slot) { $already = true; break; }
                    }
                    if (!$already) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => false]);
                    }
                }
            }
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'          => 'pick_named_members_grant_blade',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'candidates'    => $candidates,
                'named_list'    => $ab['names'] ?? [],
                'blade'         => intval($ab['blade'] ?? 1),
                'prompt'        => 'Choose 1 named Member, then 1 other Liella! Member for +Blade.',
                'step'          => 'pick_named',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose Members for +Blade.');
            break;

        case 'score_if_center_group_moved':
            $center = $p['stage']['center'] ?? null;
            if ($center && ($center['group'] ?? '') === ($ab['group'] ?? '')
                && !empty($center['moved_this_turn'])) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (Center moved).');
            }
            break;

        case 'score_if_yell_distinct_members':
            $yell = $state['_last_yell_cards'] ?? $p['yell_cards'] ?? [];
            if (countDistinctYellMembers($yell, $ab['group'] ?? '')
                >= intval($ab['min_distinct'] ?? 5)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (Yell Members).');
            }
            break;

        case 'draw_discard_if_min_energy':
            if (countEnergyInZone($p) >= intval($ab['min_energy'] ?? 11)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 2));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Energy threshold).");
                return applyDrawThenDiscard(
                    $state,
                    $pid,
                    $p,
                    $name,
                    0,
                    intval($ab['discard'] ?? 1)
                );
            }
            break;

        case 'optional_formation_change_group':
            if (!stageAllMembersInGroup($p, $ab['group'] ?? '')) break;
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_formation_change_group',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Formation-change your Stage Members (one per area)?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional formation change (choose).');
            break;

        case 'score_if_active_energy':
            $active = count(array_filter(
                $p['energy_zone'] ?? [],
                fn($e) => $e['active'] ?? false
            ));
            if ($active > 0) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (active Energy).');
            }
            break;

        case 'pick_named_members_grant_hearts':
            if (!empty($state['pending_prompt'])) break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
                foreach ($ab['names'] ?? [] as $n) {
                    if ($label === $n || str_contains($label, $n)) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => true]);
                        break;
                    }
                }
                if (($mbr['group'] ?? '') === ($ab['group'] ?? '')) {
                    $already = false;
                    foreach ($candidates as $c) {
                        if (($c['slot'] ?? '') === $slot) { $already = true; break; }
                    }
                    if (!$already) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => false]);
                    }
                }
            }
            if (count($candidates) < 2) break;
            $state['pending_prompt'] = [
                'type'          => 'pick_named_members_grant_hearts',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => $candidates,
                'named_list'    => $ab['names'] ?? [],
                'hearts'        => $ab['hearts'] ?? [],
                'prompt'        => 'Choose 1 named Member, then 1 other Liella! Member for bonus hearts.',
                'step'          => 'pick_named',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose Members for bonus hearts.');
            break;

        case 'yell_blades_to_color':
            $state = initLiveModifiers($state);
            $color = $ab['color'] ?? 'purple';
            $state['live_modifiers'][$pid]['yell_blades_to_color'] = $color;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Yell Blade hearts become ' . ucfirst($color) . ' until Live ends.');
            break;

        case 'optional_pay_energy_up_to':
            if (!empty($state['pending_prompt'])) break;
            $max = intval($ab['max_cost'] ?? 2);
            $state['pending_prompt'] = [
                'type'          => 'optional_pay_energy_up_to',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => "Pay up to $max Energy for +1 Blade each?",
                'choices'       => ['0', '1', '2'],
                'choice_labels' => ['0', '1', '2'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional Live Start (pay Energy).');
            break;

        case 'hearts_if_combined_energy':
        case 'live_score_if_opp_success_total':
        case 'on_self_wait_draw_discard':
        case 'optional_named_live_zone_from_wr_on_hand':
        case 'member_blade_on_live_zone_faceup':
        case 'cannot_live_if_solo_stage':
        case 'blade_bonus_if_center':
        case 'cost_bonus_if_min_energy':
        case 'live_score_bonus_if_min_energy':
        case 'draw_per_yell_card':
            break;

        case 'optional_pay_energy_on_enter':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_pay_energy_on_enter',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Pay ' . intval($ab['cost'] ?? 0) . ' Energy for this On Enter effect?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Pay', 'No — Skip'],
                'ability'       => $ab,
                'pay_cost'      => intval($ab['cost'] ?? 0),
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (pay Energy).');
            break;

        case 'mandatory_discard_look_reveal':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'mandatory_discard_look_reveal',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Put 1 card from your hand into the Waiting Room (required).',
                'discard_count' => intval($ab['discard'] ?? 1),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] mandatory discard for On Enter.');
            break;

        case 'reveal_hand_member_cost_live_score':
            if (!empty($state['pending_prompt'])) break;
            $members = array_values(array_filter(
                $p['hand'] ?? [],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
            ));
            if (empty($members)) break;
            $state['pending_prompt'] = [
                'type'          => 'reveal_hand_member_cost_live_score',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $members),
                'milestones'    => $ab['milestones'] ?? [10, 20, 30, 40, 50],
                'prompt'        => 'Reveal any number of Member cards from your hand (combined cost 10/20/30/40/50 for +1 Live Score).',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] reveal hand Members (choose).');
            break;

        case 'add_wr_live_if_min_energy':
            if (countEnergyInZone($p) < intval($ab['min_energy'] ?? 11)) break;
            $added = addFromWaitingRoomFiltered($p, $ab['group'] ?? '', 'live', intval($ab['count'] ?? 1));
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $added Live card(s) from Waiting Room.");
            }
            break;

        case 'draw_if_named_on_stage':
            $pairs = $ab['name_pairs'] ?? null;
            $ok = $pairs
                ? stageHasAllNamePairs($p, $pairs)
                : stageHasNamedMember($p, $ab['names'] ?? []);
            if ($ok) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (named Members on Stage).");
            }
            break;

        case 'draw_if_min_energy':
            if (countEnergyInZone($p) >= intval($ab['min_energy'] ?? 7)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Energy threshold).");
            }
            break;

        case 'energy_wait_from_deck':
            $n = intval($ab['count'] ?? 1);
            $placed = 0;
            for ($i = 0; $i < $n; $i++) {
                if (putEnergyFromDeckInWait($p, $state, $pid)) $placed++;
            }
            if ($placed > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put $placed Energy into Wait.");
            }
            break;

        case 'grant_named_members_blade':
            $n = applyNamedMemberBladeBonus($state, $pid, $ab['grants'] ?? []);
            if ($n > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] $n named Member(s) gained Blade until Live ends.");
            }
            break;

        case 'set_required_hearts_if_distinct_group':
            if (countDistinctGroupStageWr(
                $p,
                $ab['group'] ?? '',
                $ab['filter'] ?? 'member'
            ) < intval($ab['min_distinct'] ?? 5)) {
                break;
            }
            foreach ($p['live_zone'] as &$lc) {
                if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $lc['required_hearts'] = $ab['hearts'] ?? [];
                    break;
                }
            }
            unset($lc);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Required Hearts modified (distinct group).');
            break;

        case 'score_if_min_energy':
            if (countEnergyInZone($p) >= intval($ab['min_energy'] ?? 12)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (Energy).');
            }
            break;

        case 'optional_discard_blade_draw_if_live':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_blade_draw_if_live',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => $ab['prompt'] ?? 'Optional discard for Blade bonus?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional Live Start (discard).');
            break;

        case 'live_start_pay_or_discard':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'live_start_pay_or_discard',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Pay ' . intval($ab['cost'] ?? 2) . ' Energy, or put ' .
                    intval($ab['discard'] ?? 2) . ' cards from your hand into the Waiting Room?',
                'choices'       => ['pay', 'discard'],
                'choice_labels' => [
                    'Pay ' . intval($ab['cost'] ?? 2) . ' Energy',
                    'Discard ' . intval($ab['discard'] ?? 2),
                ],
                'ability'       => $ab,
                'pay_cost'      => intval($ab['cost'] ?? 2),
                'discard_count' => intval($ab['discard'] ?? 2),
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start (pay or discard).');
            break;

        case 'optional_pay_energy_live_success':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_pay_energy_live_success',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Pay ' . intval($ab['cost'] ?? 6) . ' Energy: +1 total Live Score?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Pay', 'No — Skip'],
                'ability'       => $ab,
                'pay_cost'      => intval($ab['cost'] ?? 6),
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional Live Success (pay Energy).');
            break;

        case 'formation_rotate_all':
            if (!stageAllMembersInSubunit($p, $ab['requires_subunit_only'] ?? '')) break;
            spBp2MarkEffectAreaMove($state, $source);
            foreach (['p1', 'p2'] as $id) {
                $before = [];
                foreach (['center', 'left', 'right'] as $s) {
                    $mbr = $state['players'][$id]['stage'][$s] ?? null;
                    if ($mbr) {
                        $before[$mbr['instance_id'] ?? ''] = $s;
                    }
                }
                formationRotatePlayerStage($state['players'][$id]['stage']);
                foreach (['center', 'left', 'right'] as $s) {
                    $mbr = $state['players'][$id]['stage'][$s] ?? null;
                    if (!$mbr) {
                        continue;
                    }
                    $from = $before[$mbr['instance_id'] ?? ''] ?? $s;
                    spBp2ApplyMovedByGroupEffect($mbr, $state);
                    $state['players'][$id]['stage'][$s] = $mbr;
                    if ($from !== $s) {
                        $state = resolveAutoAreaMoveAbilities($state, $id, $mbr['instance_id'] ?? '', $from);
                        if (!empty($state['pending_prompt'])) {
                            spBp2ClearEffectAreaMove($state);
                            return $state;
                        }
                    }
                }
            }
            spBp2ClearEffectAreaMove($state);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] both players rotated Stage formation.');
            break;

        case 'blade_if_entered_or_moved':
            if (!empty($ab['heart_color'])) {
                addBonusHeartsToModifier($state, $pid, [[
                    'color' => $ab['heart_color'],
                    'count' => 1,
                ]]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] gained 1 ' . ucfirst($ab['heart_color']) .
                    ' heart until Live ends.');
            } else {
                $state = applyModifierEffect($state, $pid, [
                    'type'   => 'blade_bonus',
                    'amount' => intval($ab['amount'] ?? 1),
                ]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] gained +' . intval($ab['amount'] ?? 1) . ' Blade until Live ends.');
            }
            break;

        case 'on_enter_draw_swap_area':
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn.");
            if (!empty($state['pending_prompt'])) break;
            $slots = [];
            $mySlot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
            foreach (['center', 'left', 'right'] as $s) {
                if ($s !== $mySlot) $slots[] = $s;
            }
            $state['pending_prompt'] = [
                'type'          => 'on_enter_draw_swap_area',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_slot'   => $mySlot,
                'source_name'   => $name,
                'slots'         => $slots,
                'prompt'        => 'Choose an area to move this Member to (swap if occupied).',
                'ability'       => $ab,
            ];
            break;

        case 'draw_if_other_subunit_on_stage':
            if (countOtherSubunitOnStage($p, $ab['subunit'] ?? '', $source['instance_id'] ?? '') > 0) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (other subunit on Stage).");
            }
            break;

        case 'optional_wr_member_reenter':
            if (!empty($state['pending_prompt'])) break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                if (($mbr['group'] ?? '') !== ($ab['group'] ?? '')) continue;
                if (cardMatchesNames($mbr, $ab['exclude_names'] ?? [])) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_wr_member_reenter',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'candidates'    => $candidates,
                'prompt'        => 'Put 1 Liella! Member (not Tomari) from Stage into WR and re-enter it from WR?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional WR member re-enter (choose).');
            break;

        case 'activate_energy_up_to_if_distinct_subunit':
            if (countDistinctNamedSubunit($p, $ab['subunit'] ?? '')
                < intval($ab['min_distinct'] ?? 2)) {
                break;
            }
            if (!empty($state['pending_prompt'])) break;
            $max = intval($ab['max'] ?? 6);
            $state['pending_prompt'] = [
                'type'          => 'activate_energy_up_to',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => "Activate up to $max Energy?",
                'max'           => $max,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] activate Energy (choose amount).');
            break;

        case 'score_if_all_energy_active':
            if (allEnergyActive($p)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (all Energy active).');
            }
            break;

        case 'reduce_hearts_per_entered_moved_subunit':
            $n = countEnteredMovedSubunitThisTurn($p, $ab['subunit'] ?? '')
                * intval($ab['per_member'] ?? 1);
            if ($n > 0) {
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $n;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $n;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$n Gray heart(s)" : "$n heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced.");
            }
            break;

        case 'mill_then_blade_if_any_live':
            $n = intval($ab['count'] ?? 4);
            $milled = array_splice($p['main_deck'], 0, min($n, count($p['main_deck'])));
            $hasLive = false;
            foreach ($milled as $c) {
                if (($c['card_type'] ?? '') === 'ライブ') {
                    $hasLive = true;
                    break;
                }
            }
            if (!empty($milled)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put " . count($milled) . ' card(s) from deck top into Waiting Room.');
            }
            if ($hasLive) {
                $state = applyModifierEffect($state, $pid, [
                    'type'   => 'blade_bonus',
                    'amount' => intval($ab['amount'] ?? 2),
                ]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] gained +' . intval($ab['amount'] ?? 2) .
                    ' Blade (Live card milled).');
            }
            break;

        case 'pay_energy_reveal_live_wr_superset':
            if (!empty($state['pending_prompt'])) break;
            $lives = array_values(array_filter(
                $p['hand'] ?? [],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if (empty($lives)) break;
            $state['pending_prompt'] = [
                'type'        => 'pay_energy_reveal_live_wr_superset',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_id'   => $source['instance_id'] ?? '',
                'source_name' => $name,
                'ability_idx' => $ctx['ability_index'] ?? 0,
                'slot'        => $ctx['slot'] ?? null,
                'step'        => 'reveal_hand_live',
                'pay_cost'    => intval($ab['cost'] ?? 2),
                'candidates'  => array_map('cardPromptSummary', $lives),
                'prompt'      => 'Pay ' . intval($ab['cost'] ?? 2) .
                    ' Energy and reveal 1 Live card from your hand: add 1 Live from Waiting Room whose name contains it?',
            ];
            break;

        case 'pay_energy_play_wr_empty':
            break;

        case 'buff_member_matching_discarded_group':
            if (!empty($state['pending_prompt'])) break;
            $discGroup = $ctx['discarded_group'] ?? '';
            if ($discGroup === '') break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr || ($mbr['group'] ?? '') !== $discGroup) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'        => 'buff_member_matching_discarded_group',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'candidates'  => $candidates,
                'hearts'      => $ab['hearts'] ?? [['color' => 'pink', 'count' => 1]],
                'prompt'      => 'Choose 1 Member on your Stage with the same group as the discarded card.',
            ];
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
                'prompt'      => 'Choose 1 ' . $subunit . ' Member on your Stage.',
            ];
            break;

        case 'score_if_distinct_subunits_on_stage':
            if (countDistinctSubunitsOnStage($p, $ab['requires_group'] ?? '') >= intval($ab['min_count'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (distinct subunits on Stage).');
            }
            break;

        case 'score_if_distinct_name_and_cost':
            if (countDistinctNamesAndCostsOnStage($p) >= intval($ab['min_count'] ?? 3)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (distinct names and costs).');
            }
            break;

        case 'reduce_hearts_per_live_zone_group':
            $other = countOtherLiveZoneGroup(
                $p,
                $ab['group'] ?? '',
                !empty($ab['exclude_self']) ? ($source['instance_id'] ?? '') : ''
            );
            if ($other > 0) {
                $reduce = $other * intval($ab['per_card'] ?? 2);
                bumpLiveCardColorReduction(
                    $state,
                    $pid,
                    $source['instance_id'] ?? '',
                    $ab['color'] ?? 'pink',
                    $reduce
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required " . ($ab['color'] ?? 'pink') .
                    " hearts reduced by $reduce.");
            }
            break;

        case 'score_if_stage_group_cost_min':
            if (countStageGroupMinCost($p, $ab['group'] ?? '', intval($ab['min_cost'] ?? 10))
                >= intval($ab['min_count'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (' . ($ab['group'] ?? '') . ' cost ' . intval($ab['min_cost'] ?? 10) . '+).');
            }
            break;

        case 'treat_group_stage_hearts_as':
            $state = initLiveModifiers($state);
            $grp = $ab['group'] ?? '';
            $color = $ab['color'] ?? 'pink';
            if ($grp !== '') {
                $state['live_modifiers'][$pid]['group_hearts_as'][$grp] = $color;
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] $grp Member hearts treated as $color until Live ends.");
            break;

        case 'treat_pick_group_member_hearts_as':
            if (!empty($state['pending_prompt'])) break;
            $grp = $ab['group'] ?? 'Hasunosora';
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr || ($mbr['group'] ?? '') !== $grp) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'          => 'treat_pick_group_member_hearts_as',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'color'         => $ab['color'] ?? 'pink',
                'candidates'    => $candidates,
                'prompt'        => "Choose 1 $grp Member — until this Live ends, all hearts on that Member are treated as " .
                    ($ab['color'] ?? 'pink') . ' ♡.',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] choose a Member for heart treatment.");
            break;

        case 'live_start_edel_choice':
            if (!empty($state['pending_prompt'])) break;
            $subunit = $ab['subunit'] ?? 'Edel Note';
            $canPlay = false;
            foreach ($p['waiting_room'] as $c) {
                if (cardMatchesWrPick($c, [
                    'subunit'  => $subunit,
                    'filter'   => 'member',
                    'max_cost' => intval($ab['max_cost'] ?? 4),
                ])) {
                    $canPlay = true;
                    break;
                }
            }
            foreach (['left', 'center', 'right'] as $slot) {
                if (empty($p['stage'][$slot])) {
                    $canPlay = $canPlay && true;
                    break;
                }
            }
            $hasEmpty = false;
            foreach (['left', 'center', 'right'] as $slot) {
                if (empty($p['stage'][$slot])) {
                    $hasEmpty = true;
                    break;
                }
            }
            $canPlay = $canPlay && $hasEmpty;
            $choices = ['reduce'];
            $labels = ['Reduce required purple hearts by 1.'];
            if ($canPlay) {
                array_unshift($choices, 'play');
                array_unshift($labels, 'Play 1 Edel Note Member (cost ≤' .
                    intval($ab['max_cost'] ?? 4) . ') from Waiting Room into an empty area.');
            }
            $state['pending_prompt'] = [
                'type'          => 'live_start_edel_choice',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'choices'       => $choices,
                'choice_labels' => $labels,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start: choose an effect.');
            break;
    }

    if (nijiIsNijigasakiEffectType($type)) {
        return nijiResolveNijigasakiEffect($state, $pid, $source, $ab, $ctx);
    }

    if (hsIsHasunosoraBp6EffectType($type)) {
        return hsResolveHasunosoraEffect($state, $pid, $source, $ab, $ctx);
    }

    if (hsIsHasunosoraPb1EffectType($type)) {
        return hsResolveHasunosoraPb1Effect($state, $pid, $source, $ab, $ctx);
    }

    if (hsIsHasunosoraCl1EffectType($type)) {
        return hsResolveHasunosoraCl1Effect($state, $pid, $source, $ab, $ctx);
    }

    if (nBp5IsEffectType($type)) {
        return nBp5ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    if (sBp5IsEffectType($type)) {
        return sBp5ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    if (sBp6IsEffectType($type)) {
        return sBp6ResolveEffect($state, $pid, $source, $ab, $ctx);
    }
    if (sSd1IsEffectType($type)) {
        return sSd1ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    if (spBp5IsEffectType($type)) {
        return spBp5ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    if (plMuseGapIsEffectType($type)) {
        return plMuseGapResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    if (plSpSd2IsEffectType($type)) {
        return plSpSd2ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    if (batch99IsEffectType($type)) {
        return batch99ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    if (spBp2IsHandlerType($type)) {
        return spBp2ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    return $state;
}

// ─────────────────────────────────────────────
// [Activated] abilities on stage Members
// ─────────────────────────────────────────────

function actionActivateAbility(array $state, string $pid, array $data): array {
    $onEnterWr = !empty($data['_on_enter_wr_activate']);
    if (!$onEnterWr) {
        validateTurn($state, $pid, 'main');
        assertNoPendingPromptForPlayerAction($state, $pid);
    }

    $instanceId = $data['card_id'] ?? '';
    $abilityIdx = intval($data['ability_index'] ?? 0);

    $p = &$state['players'][$pid];
    $found = findActivatedAbilitySource($p, $instanceId);
    if (!$found) throw new Exception('Card not found on Stage or in Waiting Room');

    $slot = $found['slot'] ?? null;
    $zone = $found['zone'] ?? 'stage';
    $wrIndex = $found['wr_index'] ?? null;
    if ($zone === 'stage' && $slot !== null && !empty($p['stage'][$slot])) {
        $member = &$p['stage'][$slot];
    } elseif ($zone === 'waiting_room' && $wrIndex !== null && isset($p['waiting_room'][$wrIndex])) {
        $member = &$p['waiting_room'][$wrIndex];
    } elseif ($zone === 'hand' && isset($found['hand_index'], $p['hand'][$found['hand_index']])) {
        $member = &$p['hand'][$found['hand_index']];
    } else {
        $member = $found['card'];
    }
    mergeCardCatalogFields($member);

    $abilities = $member['abilities'] ?? [];
    if (!isset($abilities[$abilityIdx])) throw new Exception('Invalid ability');

    $ab = $abilities[$abilityIdx];
    $trigger = $ab['trigger'] ?? '';
    if ($zone === 'stage' && spBp2StageMemberAbilitiesSuppressed($state, $pid)) {
        throw new Exception('Member abilities are currently suppressed');
    }
    if ($onEnterWr && $trigger === 'on_enter') {
        $state = logAbilityChain($state, $pid, $member, 'on_enter');
        $state = resolveAbilityEffect($state, $pid, $member, $ab, [
            'slot'  => $slot ?? '',
            'phase' => 'on_enter',
            'from_wr' => true,
        ]);
        markAbilityUsed($member, $abilityIdx);
        return $state;
    }
    if ($trigger !== 'activated') throw new Exception('Not an activated ability');
    if (!empty($ab['from_wr_only']) && $zone !== 'waiting_room') {
        throw new Exception('This ability can only be used from the Waiting Room');
    }
    if (empty($ab['from_wr_only']) && $zone !== 'stage' && empty($ab['from_hand_only'])) {
        if (!$onEnterWr || activatedAbilityRequiresStageSlot($ab)) {
            throw new Exception('Member not on stage');
        }
    }
    if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $abilityIdx)) {
        throw new Exception('Ability already used this turn');
    }

    $wrBlock = activatedAbilityWrBlockReason($p, $ab);
    if ($wrBlock !== null) {
        return fizzleActivatedAbilityNoWr($state, $pid, $member, $wrBlock);
    }
    if (($ab['type'] ?? '') === 'discard_cost_add_live_subunit') {
        $subunit = $ab['require_other_subunit'] ?? '';
        if ($subunit !== '' && !stageHasOtherSubunitMember($p, $subunit, $member['instance_id'] ?? '')) {
            return fizzleActivatedAbilityNoWr($state, $pid, $member,
                'needs another ' . $subunit . ' Member on Stage.');
        }
    }

    $state = logAbilityChain($state, $pid, $member, 'activated');

    $nijiActivated = nijiResolveActivatedEffect($state, $pid, $p, $member, $slot, $ab, $abilityIdx, $data);
    if ($nijiActivated !== null) {
        return $nijiActivated;
    }

    $cl1Activated = hsCl1ResolveActivatedAbility($state, $pid, $p, $member, $slot, $ab, $abilityIdx);
    if ($cl1Activated !== null) {
        return $cl1Activated;
    }

    $nBp5Activated = nBp5ResolveActivatedAbility($state, $pid, $p, $member, $slot, $ab, $abilityIdx, $data);
    if ($nBp5Activated !== null) {
        return $nBp5Activated;
    }

    $sBp5Activated = sBp5ResolveActivatedAbility($state, $pid, $p, $member, $slot, $ab, $abilityIdx, $data);
    if ($sBp5Activated !== null) {
        return $sBp5Activated;
    }

    $sBp6Activated = sBp6ResolveActivatedAbility($state, $pid, $p, $member, $slot, $ab, $abilityIdx, $data);
    if ($sBp6Activated !== null) {
        return $sBp6Activated;
    }

    $sSd1Activated = sSd1ResolveActivatedAbility($state, $pid, $p, $member, $slot, $ab, $abilityIdx, $data);
    if ($sSd1Activated !== null) {
        return $sSd1Activated;
    }

    $spBp5Activated = spBp5ResolveActivatedAbility($state, $pid, $p, $member, $slot, $ab, $abilityIdx, $data);
    if ($spBp5Activated !== null) {
        return $spBp5Activated;
    }

    if (($ab['type'] ?? '') === 'hand_discard_named_blade') {
        if ($zone !== 'hand') throw new Exception('Activate from hand only');
        return hsResolveHandDiscardNamedBlade($state, $pid, $p, $ab, $data);
    }

    if (($ab['type'] ?? '') === 'shuffle_named_from_waiting') {
        $names = $ab['names'] ?? [];
        $max = intval($ab['max_total'] ?? 6);
        $picked = [];
        $rest = [];
        foreach ($p['waiting_room'] as $c) {
            if (count($picked) < $max && ($c['card_type'] ?? '') === 'メンバー' && cardMatchesNames($c, $names)) {
                $picked[] = $c;
            } else {
                $rest[] = $c;
            }
        }
        if (empty($picked)) throw new Exception('No matching Members in Waiting Room');
        shuffle($picked);
        $p['waiting_room'] = $rest;
        $p['main_deck'] = array_merge($p['main_deck'], $picked);
        $activated = activateEnergyForPlayer($p, intval($ab['then']['max'] ?? 6));
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] shuffled ' . count($picked) .
            " Member(s) to deck bottom and activated $activated Energy.");
    } elseif (($ab['type'] ?? '') === 'activated_pay_energy_mill') {
        $cost = intval($ab['cost'] ?? 2);
        if (!payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $n = intval($ab['count'] ?? 10);
        $milled = takeFromMainDeckTop($state, $pid, $n);
        if (!empty($milled)) {
            $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
            $state = spBp5NotifyCardsToWr($state, $pid, $milled);
        }
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] paid $cost Energy; put " .
            count($milled) . ' card(s) from deck top into Waiting Room.');
    } elseif (($ab['type'] ?? '') === 'discard_hand_add_live_from_wr') {
        $need = intval($ab['discard'] ?? 2);
        if (!empty($ab['min_success_score_sum'])) {
            $scoreSum = sumSuccessLiveScores($p);
            $minScore = intval($ab['min_success_score_sum']);
            if ($scoreSum < $minScore) {
                throw new Exception("Need Success Live total score $minScore+ to use this ability (have $scoreSum)");
            }
        }
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== $need) {
            throw new Exception("Must discard exactly $need cards from hand");
        }
        discardFromHandByIds($p, $ids);
        $cfg = wrPickCfgFromAbility($ab);
        if (empty($ab['group'])) {
            $cfg['filter'] = 'live';
            if (isset($ab['min_required_hearts'])) {
                $cfg['min_required_hearts'] = intval($ab['min_required_hearts']);
            }
            if (!empty($ab['min_required_heart_color'])) {
                $cfg['min_required_heart_color'] = (string)$ab['min_required_heart_color'];
            }
        }
        startPickWrToHandPrompt($state, $pid, $member, $slot, $abilityIdx, $ab, $cfg);
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] choose a card from Waiting Room.');
    } elseif (($ab['type'] ?? '') === 'leave_stage_add_from_wr') {
        $cfg = wrPickCfgForLeaveStageAbility($ab);
        startPickWrToHandPrompt($state, $pid, $member, $slot, $abilityIdx, $ab, $cfg, true);
        $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $mName . '] choose a card from Waiting Room.');
    } elseif (($ab['type'] ?? '') === 'reveal_live_opp_discard_or_blade') {
        $revealId = $data['card_id'] ?? '';
        $revealed = null;
        foreach ($p['hand'] as $c) {
            if (($c['instance_id'] ?? '') === $revealId && ($c['card_type'] ?? '') === 'ライブ') {
                $revealed = $c;
                break;
            }
        }
        if (!$revealed) {
            throw new Exception('Choose a Live card from your hand to reveal');
        }
        $opp = ($pid === 'p1') ? 'p2' : 'p1';
        $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
        $state['pending_prompt'] = [
            'type'          => 'reveal_live_opp_discard_or_blade',
            'owner'         => $pid,
            'responder'     => $opp,
            'source_id'     => $member['instance_id'] ?? '',
            'source_slot'   => $slot,
            'ability_index' => $abilityIdx,
            'source_name'   => $mName,
            'revealed'      => cardPromptSummary($revealed),
            'prompt'        => 'Opponent revealed ' . cardDisplayName($revealed) .
                '. Put 1 card from your hand into the Waiting Room? (If not, they gain +4 Blade.)',
            'choices'       => ['yes', 'no'],
            'choice_labels' => ['Yes — Discard 1', 'No — Opponent gains Blade'],
            'ability'       => $ab,
        ];
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$mName] revealed " . cardDisplayName($revealed) . ' from hand.');
    } elseif (($ab['type'] ?? '') === 'wait_pick_member_grant_live_score') {
        if (!empty($ab['center_only']) && $slot !== 'center') {
            throw new Exception('This ability can only be used from the Center position');
        }
        $others = [];
        foreach ($p['stage'] as $s => $mbr) {
            if (!$mbr || $s === $slot) continue;
            $others[] = ['slot' => $s, 'summary' => cardPromptSummary($mbr)];
        }
        if (empty($others)) throw new Exception('No other Member on Stage to put into Wait');
        $state['pending_prompt'] = [
            'type'          => 'wait_pick_member_grant_live_score',
            'owner'         => $pid,
            'responder'     => $pid,
            'source_id'     => $member['instance_id'] ?? '',
            'source_name'   => $member['name_en'] ?? $member['name'] ?? 'Member',
            'candidates'    => $others,
            'amount'        => intval($ab['amount'] ?? 1),
            'prompt'        => 'Choose 1 Member to put into Wait: that Member gains "[Always] +1 Live total score" until this Live ends.',
        ];
        if (!empty($ab['once_per_turn'])) {
            markAbilityUsed($member, $abilityIdx);
        }
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] choose a Member to Wait.');
    } elseif (($ab['type'] ?? '') === 'player_choice_wr_live_deck_bottom_draw') {
        $cost = intval($ab['energy_cost'] ?? 0);
        if ($cost > 0 && !payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $opp = ($pid === 'p1') ? 'p2' : 'p1';
        $selfLives = array_values(array_filter(
            $p['waiting_room'],
            fn($c) => ($c['card_type'] ?? '') === 'ライブ'
        ));
        $oppLives = array_values(array_filter(
            $state['players'][$opp]['waiting_room'],
            fn($c) => ($c['card_type'] ?? '') === 'ライブ'
        ));
        if (empty($selfLives) && empty($oppLives)) {
            throw new Exception('No Live card in either Waiting Room');
        }
        $choices = [];
        $labels = [];
        if (!empty($selfLives)) {
            $choices[] = 'self';
            $labels[] = 'Yourself';
        }
        if (!empty($oppLives)) {
            $choices[] = 'opponent';
            $labels[] = 'Opponent';
        }
        $state['pending_prompt'] = [
            'type'          => 'player_choice_wr_live_deck_bottom_draw',
            'step'          => 'pick_player',
            'owner'         => $pid,
            'responder'     => $pid,
            'source_name'   => $member['name_en'] ?? $member['name'] ?? 'Member',
            'prompt'        => 'Choose yourself or your opponent: put 1 Live from that player\'s Waiting Room on the bottom of their deck (then draw ' .
                intval($ab['draw'] ?? 1) . ').',
            'choices'       => $choices,
            'choice_labels' => $labels,
            'ability'       => $ab,
        ];
        if (!empty($ab['once_per_turn'])) {
            markAbilityUsed($member, $abilityIdx);
        }
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] activated: choose a player.');
    } elseif (($ab['type'] ?? '') === 'wait_self_discard_draw') {
        waitMember($member);
        $need = intval($ab['discard'] ?? 1);
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== $need) {
            throw new Exception("Must discard exactly $need card(s) from hand");
        }
        discardFromHandByIds($p, $ids);
        $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
        if (!empty($ab['once_per_turn'])) markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] Waited, discarded $need, drew $drawn.");
    } elseif (($ab['type'] ?? '') === 'wait_self_discard_reveal_until') {
        if (!empty($ab['center_only']) && $slot !== 'center') {
            throw new Exception('This ability can only be used from the Center position');
        }
        $filterKey = $data['reveal_filter'] ?? '';
        $choices = $ab['reveal_choices'] ?? [];
        if (!isset($choices[$filterKey])) {
            throw new Exception('Choose Live or Member (cost 10+) to search for');
        }
        waitMember($member);
        $need = intval($ab['discard'] ?? 1);
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== $need) {
            throw new Exception("Must discard exactly $need card(s) from hand");
        }
        discardFromHandByIds($p, $ids);
        $found = revealFromDeckUntil($p, $choices[$filterKey], $state, $pid);
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        if ($found) {
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . ($member['name_en'] ?? $member['name']) . '] revealed ' .
                ($found['name_en'] ?? $found['name']) . ' from deck and added it to hand.');
        } else {
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . ($member['name_en'] ?? $member['name']) . '] searched the deck; no matching card found.');
        }
    } elseif (($ab['type'] ?? '') === 'wait_self_draw_discard_activate') {
        waitMember($member);
        $p['stage'][$slot] = $member;
        $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $mName . '] put self into Wait.');
        $drawnCards = drawCardInstances($p, intval($ab['draw'] ?? 1));
        foreach ($drawnCards as $c) {
            $state = logEffectDraw($state, $pid, $mName, $c,
                [animSpec($c['instance_id'], 'main_deck', 'hand', $pid)]);
        }
        $discardNeed = intval($ab['discard'] ?? 1);
        $activateThen = [
            'type'  => 'activate_members',
            'max'   => intval($ab['activate_members'] ?? 1),
        ];
        if ($discardNeed > 0 && !empty($p['hand'])) {
            $state = startEffectDiscardHandPrompt($state, $pid, $mName, $discardNeed, '', [
                'source_id'     => $member['instance_id'] ?? '',
                'source_slot'   => $slot ?? '',
                'ability_index' => $abilityIdx,
                'ability'       => $ab,
                'then'          => $activateThen,
            ]);
        } else {
            $state = resolveAbilityEffect($state, $pid, $member, $activateThen, ['slot' => $slot ?? '']);
            markAbilityUsed($member, $abilityIdx);
            $p['stage'][$slot] = $member;
            if ($discardNeed > 0 && empty($p['hand'])) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$mName] drew " . count($drawnCards) . ' but had no cards in hand to discard.');
            }
        }
    } elseif (($ab['type'] ?? '') === 'wait_self_draw_discard') {
        waitMember($member);
        $p['stage'][$slot] = $member;
        $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $mName . '] put self into Wait.');
        $state = applyDrawThenDiscard(
            $state,
            $pid,
            $p,
            $mName,
            intval($ab['draw'] ?? 1),
            intval($ab['discard'] ?? 1)
        );
        if (!empty($state['pending_prompt'])) {
            $state['pending_prompt'] = array_merge($state['pending_prompt'], [
                'source_id'     => $member['instance_id'] ?? '',
                'source_slot'   => $slot ?? '',
                'ability_index' => $abilityIdx,
                'ability'       => $ab,
            ]);
        } else {
            markAbilityUsed($member, $abilityIdx);
            $p['stage'][$slot] = $member;
        }
    } elseif (($ab['type'] ?? '') === 'discard_cost_add_live_subunit') {
        $need = max(
            0,
            intval($ab['base_discard'] ?? 3) -
                count($p['success_lives'] ?? []) * intval($ab['reduce_per_success'] ?? 1)
        );
        if ($need > 0) {
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== $need) {
                throw new Exception("Must discard exactly $need card(s) from hand");
            }
            discardFromHandByIds($p, $ids);
        }
        $subunit = $ab['require_other_subunit'] ?? '';
        if ($subunit !== '' && !stageHasOtherSubunitMember($p, $subunit, $member['instance_id'] ?? '')) {
            throw new Exception("Need another $subunit Member on your Stage");
        }
        startPickWrToHandPrompt(
            $state,
            $pid,
            $member,
            $slot,
            $abilityIdx,
            $ab,
            wrPickCfgFromAbility($ab)
        );
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] choose a card from Waiting Room.');
    } elseif (($ab['type'] ?? '') === 'wait_self_add_wr') {
        waitMember($member);
        startPickWrToHandPrompt(
            $state,
            $pid,
            $member,
            $slot,
            $abilityIdx,
            $ab,
            wrPickCfgFromAbility($ab)
        );
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] choose a card from Waiting Room.');
    } elseif (($ab['type'] ?? '') === 'wait_self_choose_heart') {
        $choices = $ab['heart_choices'] ?? ['yellow', 'pink', 'purple'];
        $color = $data['heart_choice'] ?? '';
        if (!in_array($color, $choices, true)) {
            throw new Exception('Must choose a heart color: ' . implode(', ', $choices));
        }
        waitMember($member);
        addBonusHeartsToModifier($state, $pid, [['color' => $color, 'count' => 1]]);
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] Waited; gained 1 $color ♡ until Live ends.");
    } elseif (($ab['type'] ?? '') === 'pay_energy_opp_pick_hand_reveal') {
        $cost = intval($ab['cost'] ?? 2);
        if (!payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        if (empty($p['hand'])) {
            throw new Exception('No cards in hand');
        }
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $opp = ($pid === 'p1') ? 'p2' : 'p1';
        $state['pending_prompt'] = [
            'type'        => 'opp_pick_hidden_hand',
            'owner'       => $pid,
            'responder'   => $opp,
            'source_id'   => $member['instance_id'] ?? '',
            'source_name' => $member['name_en'] ?? $member['name'] ?? 'Member',
            'ability'     => $ab,
            'hand_slots'  => array_map(
                fn($c) => ['instance_id' => $c['instance_id'] ?? ''],
                $p['hand']
            ),
            'prompt'      => 'Choose 1 card from opponent\'s hand without looking.',
        ];
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] paid $cost Energy; opponent chooses a hand card.");
    } elseif (($ab['type'] ?? '') === 'pay_energy_add_from_wr') {
        $cfg = wrPickCfgFromAbility($ab);
        if (wrPickMatchCount($p, $cfg, max(1, intval($ab['count'] ?? 1))) < 1) {
            throw new Exception('No matching card in Waiting Room');
        }
        $cost = intval($ab['cost'] ?? 0);
        if ($cost > 0 && !payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        startPickWrToHandPrompt($state, $pid, $member, $slot, $abilityIdx, $ab, $cfg);
        $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $mName . "] paid $cost Energy; choose a card from Waiting Room.");
    } elseif (($ab['type'] ?? '') === 'pay_energy_energy_wait_from_deck') {
        $cost = intval($ab['cost'] ?? 0);
        if ($cost > 0 && !payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $placed = 0;
        $n = intval($ab['count'] ?? 1);
        for ($i = 0; $i < $n; $i++) {
            if (putEnergyFromDeckInWait($p, $state, $pid)) {
                $placed++;
            }
        }
        if ($placed < 1) {
            throw new Exception('No Energy card in Energy deck');
        }
        if (!empty($ab['once_per_turn'])) {
            markAbilityUsed($member, $abilityIdx);
        }
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] paid $cost Energy; put $placed Energy into Wait.");
    } elseif (($ab['type'] ?? '') === 'pay_energy_draw') {
        $cost = intval($ab['cost'] ?? 0);
        if (!payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] paid $cost Energy; drew $drawn.");
    } elseif (($ab['type'] ?? '') === 'pay_energy_play_wr_empty') {
        $cost = intval($ab['cost'] ?? 0);
        if ($cost > 0 && !payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $placed = null;
        foreach (['left', 'center', 'right'] as $targetSlot) {
            if (!empty($p['stage'][$targetSlot])) continue;
            foreach ($p['waiting_room'] as $i => $c) {
                if (!cardMatchesWrPick($c, [
                    'filter'   => 'member',
                    'max_cost' => intval($ab['max_cost'] ?? 2),
                    'group'    => $ab['group'] ?? '',
                    'subunit'  => $ab['subunit'] ?? '',
                ])) {
                    continue;
                }
                $played = $c;
                array_splice($p['waiting_room'], $i, 1);
                $played['active'] = true;
                $played['entered_turn'] = intval($state['turn'] ?? 1);
                $p['stage'][$targetSlot] = $played;
                $placed = $played;
                $state = resolveOnEnterAbilities($state, $pid, $played, $targetSlot);
                break 2;
            }
        }
        if (!$placed) throw new Exception('No matching Member in Waiting Room or no empty Stage area');
        if (!empty($ab['once_per_turn'])) markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] paid ' . $cost .
            ' Energy; played ' . cardDisplayName($placed) . ' from Waiting Room.');
    } elseif (($ab['type'] ?? '') === 'pay_energy_reveal_live_wr_superset') {
        $lives = array_values(array_filter(
            $p['hand'] ?? [],
            fn($c) => ($c['card_type'] ?? '') === 'ライブ'
        ));
        if (empty($lives)) throw new Exception('Need a Live card in hand');
        $state['pending_prompt'] = [
            'type'         => 'pay_energy_reveal_live_wr_superset',
            'owner'        => $pid,
            'responder'    => $pid,
            'source_id'    => $member['instance_id'] ?? '',
            'source_name'  => $member['name_en'] ?? $member['name'] ?? 'Member',
            'ability_idx'  => $abilityIdx,
            'slot'         => $slot,
            'step'         => 'reveal_hand_live',
            'pay_cost'     => intval($ab['cost'] ?? 2),
            'candidates'   => array_map('cardPromptSummary', $lives),
            'prompt'       => 'Pay ' . intval($ab['cost'] ?? 2) .
                ' Energy and reveal 1 Live card from your hand: add 1 Live from Waiting Room whose name contains it?',
        ];
        $p['stage'][$slot] = $member;
    } elseif (($ab['type'] ?? '') === 'pay_leave_stage_play_wr_member') {
        $cost = intval($ab['cost'] ?? 0);
        if ($cost > 0 && !payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $cfg = [
            'group'    => $ab['group'] ?? '',
            'max_cost' => intval($ab['max_cost'] ?? 99),
        ];
        $leavingMember = $member;
        $played = takeWrMemberToStageSlot($p, $cfg, $slot);
        if (!$played) throw new Exception('No matching Member in Waiting Room');
        $p['waiting_room'][] = $leavingMember;
        $state = resolveOnLeaveStageAbilities($state, $pid, $leavingMember);
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($leavingMember['name_en'] ?? $leavingMember['name']) . '] left Stage; played ' .
            cardDisplayName($played) . ' from Waiting Room.');
    } elseif (($ab['type'] ?? '') === 'pay_energy_add_live_zone_from_wr') {
        $cost = intval($ab['cost'] ?? 0);
        if ($cost > 0 && !payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $cfg = [
            'group' => $ab['group'] ?? '',
            'filter' => $ab['filter'] ?? 'live',
        ];
        if (isset($ab['max_live_score'])) {
            $cfg['max_live_score'] = intval($ab['max_live_score']);
        }
        $added = addLiveFromWrToZone($p, $cfg);
        if ($added < 1) throw new Exception('No matching Live card in Waiting Room or Live storage is full');
        $penalty = intval($ab['next_live_set_cap_penalty'] ?? 0);
        if ($penalty > 0) {
            $p['live_set_cap_penalty'] = intval($p['live_set_cap_penalty'] ?? 0) + $penalty;
        }
        if (!empty($ab['once_per_turn'])) markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] paid $cost Energy; placed Live card from Waiting Room into storage.");
    } elseif (($ab['type'] ?? '') === 'discard_play_self_from_wr') {
        $cost = intval($ab['cost'] ?? 0);
        if ($cost > 0 && !payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $need = intval($ab['discard'] ?? 1);
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== $need) {
            throw new Exception("Must discard exactly $need card(s) from hand");
        }
        discardFromHandByIds($p, $ids);
        $targetSlot = $data['slot'] ?? '';
        if (!in_array($targetSlot, ['left', 'center', 'right'], true)) {
            $targetSlot = null;
            foreach (['left', 'center', 'right'] as $s) {
                if (empty($p['stage'][$s])) {
                    $targetSlot = $s;
                    break;
                }
            }
        }
        if ($targetSlot === null || !empty($p['stage'][$targetSlot])) {
            throw new Exception('No empty Stage area');
        }
        $wrIdx = $found['wr_index'] ?? null;
        if ($wrIdx === null) throw new Exception('Card not in Waiting Room');
        $played = $member;
        array_splice($p['waiting_room'], $wrIdx, 1);
        $played['active'] = false;
        $played['entered_turn'] = intval($state['turn'] ?? 1);
        $p['stage'][$targetSlot] = $played;
        $state = resolveOnEnterAbilities($state, $pid, $played, $targetSlot);
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($played['name_en'] ?? $played['name']) . '] entered Stage from Waiting Room.');
    } elseif (($ab['type'] ?? '') === 'discard_hand_activate_pick') {
        $need = intval($ab['discard'] ?? 1);
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== $need) {
            throw new Exception("Must discard exactly $need card(s) from hand");
        }
        discardFromHandByIds($p, $ids);
        $pick = $data['pick'] ?? '';
        if ($pick === 'energy') {
            $activated = activateEnergyForPlayer($p, 1);
            markAbilityUsed($member, $abilityIdx);
            $p['stage'][$slot] = $member;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . ($member['name_en'] ?? $member['name']) . "] discarded $need; activated $activated Energy.");
        } elseif ($pick === 'member') {
            $mid = $data['member_id'] ?? '';
            $activated = 0;
            foreach ($p['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $mid
                    && ($mbr['group'] ?? '') === ($ab['group'] ?? 'Nijigasaki')
                    && ($mbr['active'] ?? true) === false) {
                    $mbr['active'] = true;
                    $activated = 1;
                    break;
                }
            }
            unset($mbr);
            if ($activated < 1) {
                throw new Exception('Choose a Nijigasaki Member in Wait to activate');
            }
            markAbilityUsed($member, $abilityIdx);
            $p['stage'][$slot] = $member;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . ($member['name_en'] ?? $member['name']) . "] discarded $need; activated 1 Member.");
        } else {
            throw new Exception('Choose Energy or Member to activate');
        }
    } elseif (($ab['type'] ?? '') === 'discard_activate_energy_if_group_entered') {
        $need = intval($ab['discard'] ?? 1);
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== $need) {
            throw new Exception("Must discard exactly $need card(s) from hand");
        }
        discardFromHandByIds($p, $ids);
        $turn = intval($state['turn'] ?? 1);
        $group = $ab['group'] ?? '';
        $activated = 0;
        if (groupMemberEnteredThisTurn($p, $group, $turn)) {
            $activated = activateEnergyForPlayer($p, intval($ab['count'] ?? 2));
        }
        markAbilityUsed($member, $abilityIdx);
        if ($zone === 'stage') {
            $p['stage'][$slot] = $member;
        }
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] discarded $need; activated $activated Energy.");
    } elseif (($ab['type'] ?? '') === 'wait_self_energy_wait') {
        $energyCost = intval($ab['energy_cost'] ?? 0);
        if ($energyCost > 0 && !payEnergyCost($p, $energyCost)) {
            throw new Exception("Need $energyCost active Energy");
        }
        waitMember($member);
        $n = intval($ab['count'] ?? 1);
        for ($i = 0; $i < $n; $i++) {
            putEnergyFromDeckInWait($p, $state, $pid);
        }
        if (!empty($ab['once_per_turn'])) markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] Waited self; put $n Energy into Wait.");
    } elseif (($ab['type'] ?? '') === 'activated_swap_area_member') {
        $cost = intval($ab['cost'] ?? 0);
        $energyCost = intval($ab['energy_cost'] ?? 0);
        if ($energyCost > 0 && !payEnergyCost($p, $energyCost)) {
            throw new Exception("Need $energyCost active Energy");
        }
        if ($cost > 0 && !payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $slots = ['left', 'center', 'right'];
        $choices = array_values(array_filter($slots, fn($s) => $s !== $slot));
        $state['pending_prompt'] = [
            'type'          => 'activated_swap_area_pick',
            'owner'         => $pid,
            'responder'     => $pid,
            'source_id'     => $member['instance_id'] ?? '',
            'source_slot'   => $slot,
            'ability_index' => $abilityIdx,
            'source_name'   => $member['name_en'] ?? $member['name'] ?? 'Member',
            'choices'       => $choices,
            'choice_labels' => array_map(fn($s) => ucfirst($s) . ' area', $choices),
            'prompt'        => 'Choose an area to move this Member to (swap with occupant if any).',
            'ability'       => $ab,
        ];
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] choose area to swap.');
    } elseif (($ab['type'] ?? '') === 'activated_discard_trigger_on_enter') {
        $handId = $data['hand_card_id'] ?? '';
        $discarded = null;
        foreach ($p['hand'] as $i => $c) {
            if (($c['instance_id'] ?? '') === $handId) {
                if (!cardMatchesGroup($c, $ab['group'] ?? '', 'member')) {
                    throw new Exception('Must discard a matching Member from hand');
                }
                if (intval($c['cost'] ?? 0) > intval($ab['max_cost'] ?? 4)) {
                    throw new Exception('Member cost too high');
                }
                $discarded = $c;
                array_splice($p['hand'], $i, 1);
                break;
            }
        }
        if (!$discarded) throw new Exception('Choose a Member card from your hand');
        $p['waiting_room'][] = $discarded;
        $onEnter = getAbilitiesByTrigger($discarded, 'on_enter');
        if (empty($onEnter)) {
            throw new Exception('Discarded Member has no [On Enter] abilities');
        }
        if (count($onEnter) === 1) {
            markAbilityUsed($member, $abilityIdx);
            $p['stage'][$slot] = $member;
            $state = resolveAbilityEffect($state, $pid, $discarded, $onEnter[0], ['phase' => 'on_enter']);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . ($member['name_en'] ?? $member['name']) . '] triggered [On Enter] of ' .
                cardDisplayName($discarded) . '.');
        } else {
            $state['pending_prompt'] = [
                'type'          => 'activated_pick_on_enter_ability',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $member['instance_id'] ?? '',
                'source_slot'   => $slot,
                'ability_index' => $abilityIdx,
                'discarded_id'  => $discarded['instance_id'] ?? '',
                'source_name'   => $member['name_en'] ?? $member['name'] ?? 'Member',
                'choices'       => array_map(fn($i) => (string)$i, array_keys($onEnter)),
                'choice_labels' => array_map(
                    fn($i) => 'Ability ' . ($i + 1),
                    array_keys($onEnter)
                ),
                'on_enter'      => $onEnter,
                'prompt'        => 'Choose 1 [On Enter] ability to activate from the discarded Member.',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . ($member['name_en'] ?? $member['name']) . '] choose [On Enter] to trigger.');
        }
    } elseif (($ab['type'] ?? '') === 'wait_swap_wr_member_center') {
        if (!empty($ab['center_only']) && $slot !== 'center') {
            throw new Exception('This ability can only be used from the Center position');
        }
        if (empty($p['hand'])) {
            throw new Exception('Need at least 1 card in hand to discard');
        }
        $group = $ab['group'] ?? '';
        $bonus = intval($ab['cost_bonus'] ?? 2);
        if (!waitSwapHasValidTarget($p, $group, $bonus, $slot)) {
            throw new Exception('No valid Stage Member and Waiting Room swap available');
        }
        waitMember($member);
        $p['stage'][$slot] = $member;
        $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
        $state['pending_prompt'] = [
            'type'          => 'wait_swap_wr_member_center',
            'step'          => 'discard_hand',
            'owner'         => $pid,
            'responder'     => $pid,
            'source_id'     => $member['instance_id'] ?? '',
            'source_slot'   => $slot,
            'ability_index' => $abilityIdx,
            'source_name'   => $mName,
            'ability'       => $ab,
            'prompt'        => 'Discard 1 card from your hand to the Waiting Room.',
        ];
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$mName] put self into Wait; discard 1 from hand.");
    } elseif (($ab['type'] ?? '') === 'draw_and_discard') {
        $state = applyDrawThenDiscard(
            $state,
            $pid,
            $p,
            $member['name_en'] ?? $member['name'] ?? 'Member',
            intval($ab['draw'] ?? 1),
            intval($ab['discard'] ?? 1)
        );
        if (empty($state['pending_prompt'])) {
            markAbilityUsed($member, $abilityIdx);
            persistActivatedMemberAfterUse($p, $member, $slot, $zone, $wrIndex);
        }
    } elseif (plMuseGapIsEffectType($ab['type'] ?? '')) {
        if (($ab['type'] ?? '') === 'activated_wait_opp_reduce_cost_per_group') {
            $baseEnergy = intval($ab['energy_cost'] ?? 4);
            $reduce = plMuseGapCountDistinctGroupsOnStage($p);
            $cost = max(0, $baseEnergy - $reduce);
            if ($cost > 0 && !payEnergyCost($p, $cost)) {
                throw new Exception("Need $cost active Energy");
            }
        }
        $state = plMuseGapResolveEffect($state, $pid, $member, $ab, ['slot' => $slot ?? '']);
        if (empty($state['pending_prompt'])) {
            markAbilityUsed($member, $abilityIdx);
            persistActivatedMemberAfterUse($p, $member, $slot, $zone, $wrIndex);
        }
    } else {
        throw new Exception('Ability type not implemented');
    }

    if ($onEnterWr && $zone === 'waiting_room' && isset($p['stage'][''])) {
        unset($p['stage']['']);
    }

    $state['seq']++;
    return $state;
}

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

/** Prompt types that are always optional (no mandatory phase timer). */
function isOptionalPromptType(string $type): bool {
    if (str_starts_with($type, 'optional_')) {
        return true;
    }
    static $optionalTypes = [
        'look_top_optional_wr',
        'opp_may_discard_or_modifier',
        'reveal_live_opp_discard_or_blade',
        'pick_surveil_heart_threshold',
    ];
    return in_array($type, $optionalTypes, true);
}

/** True when a pending prompt must be answered (not skip/no-only optional activation). */
function isMandatorySkillPrompt(array $prompt): bool {
    if (!empty($prompt['optional'])) {
        return false;
    }
    $type = $prompt['type'] ?? '';
    if (isOptionalPromptType($type)) {
        return false;
    }
    $choices = $prompt['choices'] ?? [];
    if (!empty($choices)) {
        $mandatory = array_values(array_diff($choices, ['skip', 'no', 'cancel']));
        if (empty($mandatory) && (in_array('skip', $choices, true) || in_array('no', $choices, true))) {
            return false;
        }
    }
    return true;
}

/** Whether the phase timer should run while this prompt is pending. */
function promptUsesPhaseTimer(array $prompt): bool {
    return in_array($prompt['responder'] ?? '', ['p1', 'p2'], true);
}

function promptTimerKey(?array $prompt): string {
    if (!$prompt) {
        return '';
    }
    return implode('|', [
        $prompt['type'] ?? '',
        $prompt['responder'] ?? '',
        $prompt['step'] ?? '',
        $prompt['source_id'] ?? '',
        $prompt['prompt'] ?? '',
    ]);
}

/** Default resolution when a PvP phase timer expires during a pending skill prompt. */
function buildTimeoutPromptResolution(array $state, string $pid, array $prompt): array {
    $type = $prompt['type'] ?? '';
    $owner = $prompt['owner'] ?? $pid;
    $ownerP = $state['players'][$owner] ?? $state['players'][$pid];

    switch ($type) {
        case 'surveil_arrange':
            $looked = $state['surveil_stash'] ?? [];
            $ids = array_column($looked, 'instance_id');
            if (empty($ids)) {
                $ids = array_column($prompt['looked_cards'] ?? [], 'instance_id');
            }
            return ['choice' => 'confirm', 'top_ids' => $ids, 'wr_ids' => []];

        case 'look_top_optional_wr':
            return ['choice' => 'no'];

        case 'effect_discard_hand':
            $need = intval($prompt['count'] ?? 1);
            $hand = $ownerP['hand'] ?? [];
            $ids = array_slice(array_column($hand, 'instance_id'), 0, $need);
            if (($prompt['pick_mode'] ?? '') === 'deck_top') {
                return ['card_ids' => $ids];
            }
            return ['discard_ids' => $ids];

        case 'mandatory_discard_look_reveal':
        case 'sbp5_draw_deck_bottom':
        case 'sbp6_discard_after_draw':
            $need = intval($prompt['count'] ?? $prompt['bottom_count'] ?? $prompt['discard_count'] ?? 1);
            $ids = array_slice(array_column($ownerP['hand'] ?? [], 'instance_id'), 0, $need);
            return ['discard_ids' => $ids];

        case 'optional_live_start':
            return ['choice' => 'no'];

        case 'optional_discard_prompt':
        case 'optional_pay_energy_on_enter':
        case 'optional_pay_energy_if_baton':
        case 'optional_pay_energy_live_success':
        case 'optional_pay_play_hand_member':
        case 'optional_discard_blade_draw_if_live':
        case 'optional_success_live_swap':
        case 'pick_surveil_heart_threshold':
        case 'spbp5_pay_energy_score':
        case 'live_success_pay_choice_wr_add':
            if (($prompt['step'] ?? '') === 'confirm') {
                return ['choice' => 'no'];
            }
            return ['choice' => 'no'];

        case 'blade_per_discarded_pick_member':
            $id = $prompt['candidates'][0]['instance_id'] ?? null;
            return $id ? ['card_id' => $id] : ['choice' => 'skip'];

        case 'pick_judge_success_live': {
            $cands = $prompt['candidates'] ?? [];
            usort($cands, fn($a, $b) => intval($b['score'] ?? 0) <=> intval($a['score'] ?? 0));
            $id = $cands[0]['instance_id'] ?? null;
            return $id ? ['card_id' => $id] : [];
        }

        case 'optional_success_wr_live_swap':
            if (($prompt['step'] ?? '') === 'confirm') {
                return ['choice' => 'no'];
            }
            $cands = $prompt['candidates'] ?? [];
            if (($prompt['step'] ?? '') === 'pick_success_live') {
                usort($cands, fn($a, $b) => intval($a['score'] ?? 0) <=> intval($b['score'] ?? 0));
            } else {
                usort($cands, fn($a, $b) => intval($b['score'] ?? 0) <=> intval($a['score'] ?? 0));
            }
            $id = $cands[0]['instance_id'] ?? null;
            return $id ? ['card_id' => $id] : ['choice' => 'skip'];

        case 'optional_success_live_swap':
            if (!empty($prompt['optional'])) {
                return ['choice' => 'skip'];
            }
            $id = ($prompt['eligible_ids'] ?? [])[0] ?? ($prompt['candidates'][0]['instance_id'] ?? null);
            return $id ? ['card_id' => $id] : ['choice' => 'skip'];

        case 'optional_wr_live_deck_bottom':
        case 'live_success_yell_live_deck_bottom':
            return ['choice' => 'skip'];

        case 'live_start_pay_or_discard':
            $choices = $prompt['choices'] ?? ['pay', 'discard'];
            foreach ($choices as $choiceKey) {
                if ($choiceKey === 'pay') {
                    $cost = intval($prompt['pay_cost'] ?? 2);
                    $ae = count(array_filter($ownerP['energy_zone'] ?? [], 'energyChipActive'));
                    if ($ae >= $cost) {
                        return ['choice' => 'pay'];
                    }
                    continue;
                }
                if ($choiceKey === 'discard') {
                    $need = intval($prompt['discard_count'] ?? 2);
                    $ids = array_slice(array_column($ownerP['hand'] ?? [], 'instance_id'), 0, $need);
                    return ['choice' => 'discard', 'discard_ids' => $ids];
                }
            }
            return ['choice' => $choices[0] ?? 'pay'];

        case 'opp_may_discard_or_modifier':
        case 'reveal_live_opp_discard_or_blade':
            return ['choice' => 'no'];

        case 'player_choice':
            $keys = $prompt['choices'] ?? [];
            return ['choice' => $keys[0] ?? 'skip'];

        case 'wait_opponent_stage_pick':
            $slot = $prompt['candidates'][0]['slot'] ?? '';
            return $slot !== '' ? ['slot' => $slot] : ['choice' => 'skip'];

        default:
            if (!isMandatorySkillPrompt($prompt)) {
                $choices = $prompt['choices'] ?? [];
                if (in_array('skip', $choices, true)) {
                    return ['choice' => 'skip'];
                }
                if (in_array('no', $choices, true)) {
                    return ['choice' => 'no'];
                }
            }
            $choices = $prompt['choices'] ?? [];
            if (!empty($choices)) {
                return ['choice' => $choices[0]];
            }
            if (!empty($prompt['eligible_ids'][0])) {
                return ['card_id' => $prompt['eligible_ids'][0]];
            }
            if (!empty($prompt['candidates'][0]['instance_id'])) {
                return ['card_id' => $prompt['candidates'][0]['instance_id']];
            }
            if (!empty($prompt['stage_members'][0]['instance_id'])) {
                return ['member_id' => $prompt['stage_members'][0]['instance_id']];
            }
            return ['choice' => 'confirm'];
    }
}

function autoResolvePendingPromptForTimeout(array $state, string $pid): array {
    $prompt = $state['pending_prompt'] ?? null;
    if (!$prompt || ($prompt['responder'] ?? '') !== $pid) {
        return $state;
    }
    try {
        $data = buildTimeoutPromptResolution($state, $pid, $prompt);
        $src = $prompt['source_name'] ?? ($prompt['type'] ?? 'effect');
        $state = addLog($state, ($state['players'][$pid]['name'] ?? $pid) .
            " — Time expired; auto-resolved [{$src}].", 'info');
        return actionResolvePrompt($state, $pid, $data);
    } catch (Throwable $e) {
        return $state;
    }
}

/** Force-clear a stuck skill prompt and advance the effect phase queue (no effect applied). */
function forceDismissPendingPromptForPlayer(array $state, string $pid, string $logPrefix = 'Dismissed'): array {
    $prompt = $state['pending_prompt'] ?? null;
    if (!$prompt || ($prompt['responder'] ?? '') !== $pid) {
        return $state;
    }
    $src = $prompt['source_name'] ?? ($prompt['type'] ?? 'effect');
    $state = addLog($state, ($state['players'][$pid]['name'] ?? $pid) .
        " — {$logPrefix} [{$src}] (no effect).", 'info');
    unset($state['pending_prompt'], $state['surveil_stash'], $state['_surveil_chain']);
    $state['seq']++;
    return finishPromptEffects($state);
}

function playerLooksLikeCpu(array $player): bool {
    $name = (string)($player['name'] ?? '');
    return str_contains($name, 'CPU') || str_contains($name, '🤖');
}

/** Anti-softlock: skip the current skill prompt without applying its effect. */
function actionAntiSoftlockSkipPrompt(array $state, string $pid): array {
    if (($state['status'] ?? '') !== 'playing') {
        throw new Exception('Game is not in progress');
    }
    $prompt = $state['pending_prompt'] ?? null;
    if (!$prompt || ($prompt['responder'] ?? '') !== $pid) {
        throw new Exception('No skill prompt to skip');
    }
    $isCpu = playerLooksLikeCpu($state['players'][$pid] ?? []);
    $dismissLabel = $isCpu ? 'CPU hung on skill; auto-skipped' : 'Anti-softlock';
    if (in_array($prompt['type'] ?? '', ['pick_wr_to_hand', 'pick_wr_leave_stage_add'], true)) {
        foreach ($prompt['candidates'] ?? [] as $cand) {
            $id = $cand['instance_id'] ?? '';
            if ($id === '') {
                continue;
            }
            try {
                return actionResolvePrompt($state, $pid, ['card_id' => $id]);
            } catch (Throwable $ignored) {
            }
        }
    }
    if (!isMandatorySkillPrompt($prompt)) {
        try {
            $state = actionResolvePrompt($state, $pid, ['choice' => 'no']);
            if (empty($state['pending_prompt']) || ($state['pending_prompt']['responder'] ?? '') !== $pid) {
                $state = addLog($state, ($state['players'][$pid]['name'] ?? $pid) .
                    ($isCpu
                        ? ' — CPU hung on skill; auto-skipped optional effect.'
                        : ' — Anti-softlock: skipped optional skill.'), 'info');
                return $state;
            }
        } catch (Throwable $ignored) {
        }
    }
    return forceDismissPendingPromptForPlayer($state, $pid, $dismissLabel);
}

// ─────────────────────────────────────────────
// Skill prompts (pending_prompt resolution)
// ─────────────────────────────────────────────
// Dispatches to set-specific handlers first, then generic prompt types (discard, pick,
// heart color, judge success Live, etc.). Called from api.php action resolve_prompt.

/**
 * Resolve optional_discard_prompt (yes/no + discard_ids). Shared by actionResolvePrompt,
 * optional_live_start, and resolveAbilityEffect confirm paths.
 * When $deferFinish is true, caller must run finishLiveStartEffects / finishPromptEffects.
 */
function resolveOptionalDiscardPromptChoice(
    array $state,
    string $owner,
    array $prompt,
    string $choice,
    array $data,
    bool $deferFinish = false
): array {
    $promptAbility = $prompt['ability'] ?? [];
    $ownerP = &$state['players'][$owner];

        if ($choice === 'skip' || $choice === 'cancel') {
            $choice = 'no';
        }
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $energyCost = intval($promptAbility['energy_cost'] ?? 0);
            if ($energyCost > 0 && !payEnergyCost($ownerP, $energyCost)) {
                throw new Exception("Need $energyCost active Energy");
            }
            $then = $promptAbility['then'] ?? [];
            if (!optionalDiscardThenViable($ownerP, $then)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional effect (deck empty).');
                unset($state['pending_prompt']);
                if (!$deferFinish) {
                    $state['seq']++;
                    if (!empty($prompt['live_start'])) {
                        return finishLiveStartEffects($state);
                    }
                    $state = finishPromptEffects($state);
                }
                return $state;
            }
            $maxDiscard = intval($promptAbility['max_discard'] ?? 0);
            $need = $maxDiscard > 0 ? $maxDiscard : intval($promptAbility['discard'] ?? 1);
            $ids = $data['discard_ids'] ?? [];
            if ($maxDiscard > 0) {
                if (count($ids) > $maxDiscard) {
                    throw new Exception("Must select at most $maxDiscard card(s) to discard");
                }
            } elseif ($need > 0 && count($ids) !== $need) {
                throw new Exception("Must select exactly $need card(s) to discard");
            }
            if (!empty($ids)) {
                $discardedCards = takeDiscardedHandCards($ownerP, $ids);
            } else {
                $discardedCards = [];
            }
            $then = $promptAbility['then'] ?? [];
            if (($then['type'] ?? '') === 'draw_equal_discarded') {
                $drawn = drawCardsForPlayer($state, $owner, count($ids));
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] discarded " . count($ids) .
                    " and drew $drawn.");
            } elseif (($then['type'] ?? '') === 'wait_opponent_stage_max_cost') {
                $opp = ($owner === 'p1') ? 'p2' : 'p1';
                $maxCost = intval($then['max_cost'] ?? 4);
                $pickCount = isset($then['pick_count']) ? intval($then['pick_count']) : null;
                $waited = waitOpponentStageByCost(
                    $state,
                    $opp,
                    $maxCost,
                    $pickCount,
                    $owner
                );
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] discarded $need; " .
                    ($waited > 0
                        ? "$waited opponent Stage Member" . ($waited === 1 ? '' : 's') . ' put into Wait.'
                        : 'no opponent Stage Members matched; none put into Wait.'));
            } elseif (($then['type'] ?? '') === 'look_reveal_filter'
                || ($then['type'] ?? '') === 'look_reveal_group') {
                $state = beginLookRevealPick(
                    $state,
                    $owner,
                    $prompt['source_name'] ?? 'Member',
                    $ownerP,
                    $then
                );
                if (!empty($state['pending_prompt'])) {
                    $state['seq']++;
                    return $state;
                }
            } elseif (($then['type'] ?? '') === 'blade_bonus') {
                $state = applyModifierEffect($state, $owner, $then);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] gained +' .
                    intval($then['amount'] ?? 0) . ' Blade until Live ends.');
            } elseif (($then['type'] ?? '') === 'blade_bonus_named_extra') {
                $state = applyModifierEffect($state, $owner, ['type' => 'blade_bonus', 'amount' => intval($then['amount'] ?? 1)]);
                $named = $then['named'] ?? '';
                foreach ($discardedCards as $dc) {
                    if (cardNameKey($dc) === $named || str_contains(cardNameKey($dc), $named)) {
                        $state = applyModifierEffect($state, $owner, [
                            'type'   => 'blade_bonus',
                            'amount' => intval($then['extra_amount'] ?? 1),
                        ]);
                        break;
                    }
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] gained Blade until Live ends.');
            } elseif (($then['type'] ?? '') === 'live_start_self_cost_plus_check') {
                $srcId = $prompt['source_id'] ?? '';
                foreach ($ownerP['stage'] as $s => &$mbr) {
                    if ($mbr && ($mbr['instance_id'] ?? '') === $srcId) {
                        $mbr['live_cost_bonus'] = intval($mbr['live_cost_bonus'] ?? 0) + intval($then['amount'] ?? 6);
                        break;
                    }
                }
                unset($mbr);
                $mySum = sumStageMemberCost($ownerP, $state, $owner);
                $opp = ($owner === 'p1') ? 'p2' : 'p1';
                $oppSum = sumStageMemberCost($state['players'][$opp], $state, $opp);
                if ($mySum > $oppSum) {
                    $heartColor = $then['heart_color'] ?? $promptAbility['heart_color'] ?? 'pink';
                    addBonusHeartsToModifier($state, $owner, [['color' => $heartColor, 'count' => 1]]);
                    $state = applyModifierEffect($state, $owner, ['type' => 'blade_bonus', 'amount' => 1]);
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] cost increased until Live ends.');
            } elseif (($then['type'] ?? '') === 'pick_subunit_member_heart'
                || ($then['type'] ?? '') === 'pick_group_member_heart') {
                $candidates = [];
                foreach ($ownerP['stage'] as $slot => $mbr) {
                    if (!$mbr) continue;
                    $ok = ($then['type'] ?? '') === 'pick_subunit_member_heart'
                        ? cardMatchesSubunit($mbr, $then['subunit'] ?? '')
                        : (($mbr['group'] ?? '') === ($then['group'] ?? ''));
                    if ($ok) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
                    }
                }
                if (empty($candidates)) {
                    $state = addLog($state, $state['players'][$owner]['name'] .
                        ' — [' . ($prompt['source_name'] ?? 'Member') . '] no matching Member on Stage.');
                } elseif (count($candidates) === 1) {
                    applyNamedMemberHeartsBlade($state, $owner, $candidates[0]['instance_id'], $then);
                    $state = addLog($state, $state['players'][$owner]['name'] .
                        ' — [' . ($prompt['source_name'] ?? 'Member') . '] granted bonus hearts.');
                } else {
                    $state['pending_prompt'] = [
                        'type'        => 'pick_member_grant_hearts',
                        'owner'       => $owner,
                        'responder'   => $owner,
                        'source_name' => $prompt['source_name'] ?? 'Member',
                        'candidates'  => $candidates,
                        'hearts'      => $then['hearts'] ?? [],
                        'prompt'      => 'Choose 1 Member for bonus hearts until Live ends.',
                    ];
                    $state['seq']++;
                    return $state;
                }
            } elseif (($then['type'] ?? '') === 'pick_member_heart_blade') {
                $candidates = [];
                foreach ($ownerP['stage'] as $slot => $mbr) {
                    if ($mbr) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
                    }
                }
                if (count($candidates) === 1) {
                    applyNamedMemberHeartsBlade($state, $owner, $candidates[0]['instance_id'], $then);
                } elseif (count($candidates) > 1) {
                    $state['pending_prompt'] = [
                        'type'        => 'pick_member_grant_hearts',
                        'owner'       => $owner,
                        'responder'   => $owner,
                        'source_name' => $prompt['source_name'] ?? 'Member',
                        'candidates'  => $candidates,
                        'hearts'      => $then['hearts'] ?? [],
                        'blade'       => intval($then['blade'] ?? 1),
                        'prompt'      => 'Choose 1 Member for bonus hearts and Blade.',
                    ];
                    $state['seq']++;
                    return $state;
                }
            } elseif (($then['type'] ?? '') === 'add_live_and_member_from_wr') {
                $liveAdded = addFromWaitingRoomFiltered($ownerP, '', 'live', 1);
                $memAdded = addFromWaitingRoomFiltered($ownerP, '', 'member', 1);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [" . ($prompt['source_name'] ?? 'Member') . "] added $liveAdded Live and $memAdded Member from WR.");
            } elseif (($then['type'] ?? '') === 'add_from_wr') {
                $added = addFromWaitingRoomFiltered(
                    $ownerP,
                    $then['group'] ?? '',
                    $then['filter'] ?? '',
                    intval($then['count'] ?? 1),
                    null,
                    array_filter(['subunit' => $then['subunit'] ?? ''])
                );
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] added $added card(s) from Waiting Room.");
            } elseif (sSd1IsEffectType($then['type'] ?? '')) {
                $source = findSourceCard($state, $owner, $prompt['source_id'] ?? '');
                if ($source) {
                    $state = sSd1ResolveEffect($state, $owner, $source, $then, []);
                    if (!empty($state['pending_prompt'])) {
                        $state['seq']++;
                        return $state;
                    }
                }
            } elseif (($then['type'] ?? '') === 'member_blade_bonus') {
                if (!empty($then['all_other'])) {
                    $then['max_members'] = 99;
                    $then['exclude_source_id'] = $prompt['source_id'] ?? '';
                }
                $n = applyMemberBladeBonus($state, $owner, $then);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] discarded $need; $n Member(s) gained +" .
                    intval($then['amount'] ?? 0) . ' Blade.');
            } elseif (($then['type'] ?? '') === 'other_member_heart') {
                $n = applyOtherMemberHeartBonus(
                    $state,
                    $owner,
                    $prompt['source_id'] ?? '',
                    $then['color'] ?? 'yellow',
                    intval($then['max_members'] ?? 1)
                );
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [" . ($prompt['source_name'] ?? 'Member') . "] discarded $need; $n Member(s) gained a heart.");
            } elseif (($then['type'] ?? '') === 'pick_yell_member') {
                $yellPool = $ownerP['_pending_yell_wr'] ?? [];
                $candidates = array_values(array_filter(
                    $yellPool,
                    fn($c) => cardMatchesYellPick($c, $then)
                ));
                if (!empty($candidates)) {
                    $pickPrompt = ($then['filter'] ?? '') === 'member_or_live'
                        ? 'Choose 1 Member (cost ≤2) or Live (score ≤2) revealed by Yell to add to your hand.'
                        : 'Choose 1 μ\'s Member revealed by Yell to add to your hand.';
                    $state['pending_prompt'] = [
                        'type'        => 'pick_yell_member',
                        'owner'       => $owner,
                        'responder'   => $owner,
                        'source_name' => $prompt['source_name'] ?? 'Live',
                        'prompt'      => $pickPrompt,
                        'candidates'  => array_map('cardPromptSummary', $candidates),
                        'ability'     => $then,
                    ];
                    $state['seq']++;
                    return $state;
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . '] no μ\'s Members among Yell cards.');
            } elseif (($then['type'] ?? '') === 'buff_named_stage_member') {
                $discardedMember = null;
                foreach ($discardedCards as $c) {
                    if (($c['card_type'] ?? '') === 'メンバー') {
                        $discardedMember = $c;
                        break;
                    }
                }
                if ($discardedMember) {
                    $nameKey = cardNameKey($discardedMember);
                    $candidates = stageMembersMatchingName($ownerP, $nameKey);
                    if (count($candidates) === 1) {
                        applyNamedMemberHeartsBlade($state, $owner, $candidates[0]['instance_id'], $then);
                        $state = addLog($state, $state['players'][$owner]['name'] .
                            ' — [' . ($prompt['source_name'] ?? 'Member') .
                            "] buffed Member matching $nameKey.");
                    } elseif (count($candidates) > 1) {
                        $state['pending_prompt'] = [
                            'type'          => 'pick_same_name_member',
                            'owner'         => $owner,
                            'responder'     => $owner,
                            'source_name'   => $prompt['source_name'] ?? 'Member',
                            'prompt'        => 'Choose 1 Member on your Stage with the same name as the discarded Member.',
                            'stage_members' => $candidates,
                            'ability'       => $then,
                        ];
                        $state['seq']++;
                        return $state;
                    } else {
                        $state = addLog($state, $state['players'][$owner]['name'] .
                            ' — [' . ($prompt['source_name'] ?? 'Member') .
                            '] no matching-name Member on Stage.');
                    }
                } else {
                    $state = addLog($state, $state['players'][$owner]['name'] .
                        ' — [' . ($prompt['source_name'] ?? 'Member') .
                        '] discarded card was not a Member.');
                }
            } elseif (($then['type'] ?? '') === 'buff_member_matching_discarded_group') {
                $discGroup = '';
                foreach ($discardedCards as $dc) {
                    $discGroup = $dc['group'] ?? '';
                    if ($discGroup !== '') break;
                }
                if ($discGroup === '') {
                    $state = addLog($state, $state['players'][$owner]['name'] .
                        ' — [' . ($prompt['source_name'] ?? 'Member') . '] could not match discarded group.');
                } else {
                    $source = findSourceCard($state, $owner, $prompt['source_id'] ?? '');
                    if ($source) {
                        $state = resolveAbilityEffect($state, $owner, $source, $then, [
                            'discarded_group' => $discGroup,
                            'phase'           => !empty($prompt['live_start']) ? 'live_start' : 'on_enter',
                        ]);
                        if (!empty($state['pending_prompt'])) {
                            $state['seq']++;
                            return $state;
                        }
                    }
                }
            } elseif (($then['type'] ?? '') === 'live_cost_from_subunit_pick') {
                $source = findSourceCard($state, $owner, $prompt['source_id'] ?? '');
                if ($source) {
                    $state = resolveAbilityEffect($state, $owner, $source, $then, [
                        'phase' => !empty($prompt['live_start']) ? 'live_start' : 'on_enter',
                    ]);
                    if (!empty($state['pending_prompt'])) {
                        $state['seq']++;
                        return $state;
                    }
                }
            } elseif (($then['type'] ?? '') === 'energy_wait_from_deck') {
                $placed = 0;
                for ($i = 0; $i < intval($then['count'] ?? 1); $i++) {
                    if (putEnergyFromDeckInWait($ownerP, $state, $owner)) $placed++;
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [" . ($prompt['source_name'] ?? 'Member') . "] put $placed Energy into Wait.");
            } elseif (($then['type'] ?? '') === 'draw_until_hand') {
                $target = intval($then['target'] ?? 5);
                $drawn = 0;
                while (count($ownerP['hand']) < $target && !empty($ownerP['main_deck'])) {
                    $ownerP['hand'][] = array_shift($ownerP['main_deck']);
                    $drawn++;
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [" . ($prompt['source_name'] ?? 'Member') . "] drew $drawn (hand size " .
                    count($ownerP['hand']) . ').');
            } elseif (($then['type'] ?? '') === 'choose_heart_other_member') {
                $choices = $then['heart_choices'] ?? ['yellow', 'pink', 'purple', 'green', 'blue', 'red'];
                $state['pending_prompt'] = [
                    'type'            => 'choose_heart_other_member',
                    'owner'           => $owner,
                    'responder'       => $owner,
                    'source_id'       => $prompt['source_id'] ?? '',
                    'source_name'     => $prompt['source_name'] ?? 'Member',
                    'prompt'          => 'Choose a heart color for another Member on your Stage.',
                    'choices'         => $choices,
                    'choice_labels'   => array_map(fn($c) => ucfirst($c) . ' ♡', $choices),
                    'ability'         => $then,
                    'after_live_start'=> !empty($prompt['live_start']),
                ];
                $state['seq']++;
                return $state;
            } elseif (($then['type'] ?? '') === 'blade_per_discarded_pick_member') {
                $source = findSourceCard($state, $owner, $prompt['source_id'] ?? '');
                if ($source) {
                    $state = resolveAbilityEffect($state, $owner, $source, $then, [
                        'discarded_count' => count($ids),
                        'phase'           => !empty($prompt['live_start']) ? 'live_start' : ($prompt['phase'] ?? ''),
                    ]);
                    if (!empty($state['pending_prompt'])) {
                        $state['seq']++;
                        return $state;
                    }
                }
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional effect.');
        }
        if (!empty($state['pending_prompt'])
            && ($state['pending_prompt']['type'] ?? '') !== 'optional_discard_prompt') {
            if (!$deferFinish) {
                $state['seq']++;
            }
            return $state;
        }
        unset($state['pending_prompt']);
        if ($deferFinish) {
            return $state;
        }
        $state['seq']++;
        if (!empty($prompt['live_start'])) {
            return finishLiveStartEffects($state);
        }
        $state = finishPromptEffects($state);
        return $state;

}

function actionResolvePrompt(array $state, string $pid, array $data): array {
    $prompt = $state['pending_prompt'] ?? null;
    if (!$prompt) throw new Exception('No pending prompt');
    if (($prompt['responder'] ?? '') !== $pid) throw new Exception('Not your prompt to answer');

    $choice = $data['choice'] ?? '';
    $promptType = $prompt['type'] ?? '';
    $ability = $prompt['ability'] ?? [];
    $owner = $prompt['owner'] ?? $pid;
    $ownerP = &$state['players'][$owner];

    $nijiPrompt = nijiHandlePrompt($state, $promptType, $prompt, $choice, $data);
    if ($nijiPrompt !== null) {
        return $nijiPrompt;
    }

    if (in_array($promptType, [
        'reveal_hand_named_stack_under',
        'play_stacked_member_from_under',
        'pl_muse_stack_heart_choice',
    ], true)) {
        $plMuseEarly = plMuseGapResolvePrompt($state, $owner, $prompt, $choice, $data);
        if ($plMuseEarly !== null) {
            return $plMuseEarly;
        }
    }

    $hsPrompt = hsResolveHasunosoraPrompt($state, $owner, $prompt, $choice, $data);
    if ($hsPrompt !== null) {
        return $hsPrompt;
    }

    $hsPb1Prompt = hsPb1ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($hsPb1Prompt !== null) {
        return $hsPb1Prompt;
    }

    $hsCl1Prompt = hsCl1ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($hsCl1Prompt !== null) {
        return $hsCl1Prompt;
    }

    $nBp5Prompt = nBp5ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($nBp5Prompt !== null) {
        return $nBp5Prompt;
    }

    $sBp5Prompt = sBp5ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($sBp5Prompt !== null) {
        return $sBp5Prompt;
    }

    $sBp6Prompt = sBp6ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($sBp6Prompt !== null) {
        return $sBp6Prompt;
    }

    $sSd1Prompt = sSd1ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($sSd1Prompt !== null) {
        return $sSd1Prompt;
    }

    $spBp5Prompt = spBp5ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($spBp5Prompt !== null) {
        return $spBp5Prompt;
    }

    $plMusePrompt = plMuseGapResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($plMusePrompt !== null) {
        return $plMusePrompt;
    }

    $plSpSd2Prompt = plSpSd2ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($plSpSd2Prompt !== null) {
        return $plSpSd2Prompt;
    }

    $batch99Prompt = batch99ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($batch99Prompt !== null) {
        return $batch99Prompt;
    }

    $spBp2Prompt = spBp2ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($spBp2Prompt !== null) {
        return $spBp2Prompt;
    }

    if ($promptType === 'surveil_arrange') {
        $looked = $state['surveil_stash'] ?? [];
        if (empty($looked)) throw new Exception('No surveil cards');
        $topIds = $data['top_ids'] ?? [];
        $wrIds = $data['wr_ids'] ?? [];
        $allIds = array_column($looked, 'instance_id');
        $picked = array_merge($topIds, $wrIds);
        sort($allIds);
        $sortedPicked = $picked;
        sort($sortedPicked);
        if ($sortedPicked !== $allIds) {
            throw new Exception('Must assign every looked card to deck top or Waiting Room');
        }
        $chain = $state['_surveil_chain'] ?? null;
        $arrangeTarget = $chain['target'] ?? $owner;
        applySurveilArrangement($state['players'][$arrangeTarget], $looked, $topIds, $wrIds);
        unset($state['surveil_stash'], $state['pending_prompt'], $state['_surveil_chain']);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] arranged ' . count($looked) . ' looked card(s).');
        if ($chain && ($chain['type'] ?? '') === 'reveal_top_live_score') {
            $source = findSourceCard($state, $owner, $chain['source_id'] ?? '');
            if ($source) {
                $state = revealDeckTopLiveScore(
                    $state,
                    $owner,
                    $source,
                    intval($chain['score_amount'] ?? 1)
                );
            }
        }
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'effect_discard_hand') {
        $need = intval($prompt['count'] ?? 1);
        $isDeckTop = ($prompt['pick_mode'] ?? '') === 'deck_top';
        $discardIds = $isDeckTop ? ($data['card_ids'] ?? []) : ($data['discard_ids'] ?? []);
        if (count($discardIds) !== $need) {
            throw new Exception("Must select exactly $need card(s)");
        }
        $srcName = $prompt['source_name'] ?? 'Member';
        if ($isDeckTop) {
            $picked = [];
            foreach ($discardIds as $id) {
                foreach ($ownerP['hand'] as $i => $c) {
                    if (($c['instance_id'] ?? '') === $id) {
                        $picked[] = $c;
                        array_splice($ownerP['hand'], $i, 1);
                        break;
                    }
                }
            }
            if (count($picked) !== $need) {
                throw new Exception('Invalid hand cards selected');
            }
            $ownerP['main_deck'] = array_merge(array_reverse($picked), $ownerP['main_deck']);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$srcName] put $need card(s) on deck top.");
        } else {
            $moved = discardHandCardsByIds($ownerP, $discardIds);
            foreach ($moved as $c) {
                $state = logEffectPutWr($state, $owner, $srcName, $c,
                    [animSpec($c['instance_id'], 'hand', 'waiting_room', $owner)]);
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        if (isset($prompt['ability_index'], $prompt['source_id'])) {
            $srcId = (string)$prompt['source_id'];
            $abIdx = intval($prompt['ability_index']);
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $srcId) {
                    markAbilityUsed($mbr, $abIdx);
                    break;
                }
            }
            unset($mbr);
        }
        $then = $prompt['then'] ?? null;
        if ($then) {
            $source = findSourceCard($state, $owner, $prompt['source_id'] ?? '') ?? [
                'name_en' => $srcName,
                'name'    => $srcName,
            ];
            $state = resolveAbilityEffect($state, $owner, $source, $then, ['phase' => 'on_enter']);
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
        }
        return finishPromptEffects($state);
    }

    if ($promptType === 'optional_live_start') {
        if ($choice === 'skip' || $choice === 'cancel') {
            $choice = 'no';
        }
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $ab = $prompt['ability'] ?? [];
            $need = intval($prompt['discard_count'] ?? $ab['discard'] ?? 0);
            $maxDiscard = intval($prompt['max_discard'] ?? $ab['max_discard'] ?? 0);
            $discardIds = $data['discard_ids'] ?? [];
            if ($maxDiscard > 0) {
                if (count($discardIds) > $maxDiscard) {
                    throw new Exception("Must select at most $maxDiscard card(s) to discard");
                }
            } elseif ($need > 0 && count($discardIds) !== $need) {
                throw new Exception("Must select exactly $need card(s) to discard");
            }
            if (($ab['type'] ?? '') === 'optional_discard_same_group' && $need > 0) {
                if (!validateSameGroupDiscard($ownerP, $discardIds, $need)) {
                    throw new Exception("Must discard exactly $need cards sharing the same unit name");
                }
            }
            $sourceId = $prompt['source_id'] ?? '';
            $source = findLiveStartSourceCard($state, $owner, $sourceId);
            if (!$source) throw new Exception('Source card not found on Stage or in Live storage');
            if (($ab['type'] ?? '') === 'optional_return_member_energy') {
                unset($state['pending_prompt']);
                $candidates = stageMembersWithStackedEnergy($ownerP);
                if (empty($candidates)) {
                    $state = addLog($state, $state['players'][$owner]['name'] .
                        ' — [' . ($prompt['source_name'] ?? 'Live') . '] no Energy stacked under Stage Members.');
                    $state['seq']++;
                    return finishLiveStartEffects($state);
                }
                $state['pending_prompt'] = [
                    'type'          => 'pick_member_return_energy',
                    'owner'         => $owner,
                    'responder'     => $owner,
                    'source_id'     => $sourceId,
                    'source_name'   => $prompt['source_name'] ?? 'Live',
                    'prompt'        => 'Choose a Stage Member and how many stacked Energy to return to your Energy deck.',
                    'members'       => array_map(function ($row) {
                        $m = $row['member'];
                        return [
                            'instance_id'   => $m['instance_id'] ?? '',
                            'slot'          => $row['slot'],
                            'name'          => $m['name_en'] ?? $m['name'] ?? 'Member',
                            'stacked_count' => countMemberStackedEnergy($ownerP, $m),
                        ];
                    }, $candidates),
                    'ability'       => $ab,
                ];
                $state['seq']++;
                return $state;
            }
            if (($ab['type'] ?? '') === 'optional_discard_prompt'
                || ($ab['type'] ?? '') === 'optional_discard_blade_named_extra') {
                unset($state['pending_prompt']);
                $promptAbility = ($ab['type'] ?? '') === 'optional_discard_blade_named_extra'
                    ? [
                        'discard' => 1,
                        'then'    => [
                            'type'         => 'blade_bonus_named_extra',
                            'amount'       => intval($ab['amount'] ?? 1),
                            'extra_amount' => intval($ab['extra_amount'] ?? 1),
                            'named'        => $ab['named'] ?? '',
                        ],
                    ]
                    : $ab;
                $state = resolveOptionalDiscardPromptChoice($state, $owner, [
                    'ability'     => $promptAbility,
                    'source_name' => $prompt['source_name'] ?? 'Live',
                    'source_id'   => $sourceId,
                    'live_start'  => true,
                ], 'yes', ['discard_ids' => $discardIds], true);
                if (!empty($state['pending_prompt'])) {
                    $state['seq']++;
                    return $state;
                }
            } else {
            $needsPay = ($choice === 'yes' && !empty($prompt['needs_pay']));
            if ($needsPay && empty($data['pay'])) {
                throw new Exception('Must confirm Energy payment');
            }
            $ctx = [
                'phase'        => 'live_start',
                'confirm'      => true,
                'discard_ids'  => $discardIds,
                'pay'          => $needsPay,
            ];
            unset($state['pending_prompt']);
            $state = resolveAbilityEffect($state, $owner, $source, $ab, $ctx);
            }
        } else {
            unset($state['pending_prompt']);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Card') . '] skipped optional Live Start effect.');
        }
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'look_top_optional_wr') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        $target = $prompt['target'] ?? $owner;
        $pl = &$state['players'][$target];
        if ($choice === 'yes' && !empty($pl['main_deck'])) {
            $top = array_shift($pl['main_deck']);
            $pl['waiting_room'][] = $top;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] put ' .
                cardDisplayName($top) . ' into Waiting Room.');
        }
        unset($pl);
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'optional_pay_play_hand_member') {
        if (($prompt['step'] ?? '') === 'pick_slot') {
            $slot = $data['choice'] ?? $data['slot'] ?? '';
            if (!in_array($slot, $prompt['slots'] ?? [], true)) {
                throw new Exception('Choose a Stage area');
            }
            $cardId = $prompt['card_id'] ?? '';
            $ability = $prompt['ability'] ?? [];
            $group = $ability['group'] ?? 'Nijigasaki';
            $played = null;
            $ownerP['hand'] = array_values(array_filter(
                $ownerP['hand'],
                function ($c) use ($cardId, $ability, $group, &$played) {
                    if (($c['instance_id'] ?? '') !== $cardId) {
                        return true;
                    }
                    if (($c['card_type'] ?? '') !== 'メンバー') {
                        throw new Exception('Must choose a Member card');
                    }
                    $names = $ability['names'] ?? [];
                    if (!empty($names)) {
                        if (!cardMatchesNames($c, $names)) {
                            throw new Exception('Must choose a matching Member');
                        }
                    } elseif (($c['group'] ?? '') !== $group) {
                        throw new Exception("Must choose a $group Member");
                    }
                    if (intval($c['cost'] ?? 0) > intval($ability['max_cost'] ?? 4)) {
                        throw new Exception('Member cost too high');
                    }
                    $played = $c;
                    return false;
                }
            ));
            if (!$played) {
                throw new Exception('Invalid hand card');
            }
            $allowOverlap = !empty($ability['allow_overlap']);
            if ($allowOverlap && !empty($ownerP['stage'][$slot])) {
                $replaced = $ownerP['stage'][$slot];
                $ownerP['waiting_room'][] = $replaced;
                $state = resolveOnLeaveStageAbilities($state, $owner, $replaced);
            }
            $played['active'] = true;
            $played['entered_turn'] = intval($state['turn'] ?? 1);
            $ownerP['stage'][$slot] = $played;
            $state = resolveOnEnterAbilities($state, $owner, $played, $slot);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] played ' .
                cardDisplayName($played) . ' from hand.');
            return returnAfterPlacedMemberEnter($state);
        }
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $cost = intval($ability['cost'] ?? 0);
            if ($cost > 0 && !payEnergyCost($ownerP, $cost)) {
                throw new Exception("Need $cost active Energy");
            }
            $cardId = $data['card_id'] ?? '';
            $slot = $data['slot'] ?? '';
            if ($cardId === '') {
                throw new Exception('Choose a Member from hand');
            }
            $group = $ability['group'] ?? 'Nijigasaki';
            $played = null;
            foreach ($ownerP['hand'] as $c) {
                if (($c['instance_id'] ?? '') !== $cardId) {
                    continue;
                }
                if (($c['card_type'] ?? '') !== 'メンバー') {
                    throw new Exception('Must choose a Member card');
                }
                $names = $ability['names'] ?? [];
                if (!empty($names)) {
                    if (!cardMatchesNames($c, $names)) {
                        throw new Exception('Must choose a matching Member');
                    }
                } elseif (($c['group'] ?? '') !== $group) {
                    throw new Exception("Must choose a $group Member");
                }
                if (intval($c['cost'] ?? 0) > intval($ability['max_cost'] ?? 4)) {
                    throw new Exception('Member cost too high');
                }
                $played = $c;
                break;
            }
            if (!$played) {
                throw new Exception('Invalid hand card');
            }
            $allowOverlap = !empty($ability['allow_overlap']);
            $blockEntered = !empty($ability['block_entered_this_turn']);
            $turn = intval($state['turn'] ?? 1);
            $validSlots = [];
            foreach (['left', 'center', 'right'] as $s) {
                $existing = $ownerP['stage'][$s] ?? null;
                if ($blockEntered && $existing && intval($existing['entered_turn'] ?? 0) === $turn) {
                    continue;
                }
                if (!$allowOverlap && $existing) {
                    continue;
                }
                $validSlots[] = $s;
            }
            if (!in_array($slot, $validSlots, true)) {
                if (count($validSlots) === 1) {
                    $slot = $validSlots[0];
                } elseif (count($validSlots) > 1) {
                    $state['pending_prompt'] = [
                        'type'          => 'optional_pay_play_hand_member',
                        'owner'         => $owner,
                        'responder'     => $owner,
                        'source_id'     => $prompt['source_id'] ?? '',
                        'source_name'   => $prompt['source_name'] ?? 'Member',
                        'prompt'        => 'Choose a Stage area for ' . cardDisplayName($played) . '.',
                        'step'          => 'pick_slot',
                        'card_id'       => $cardId,
                        'slots'         => $validSlots,
                        'ability'       => $ability,
                    ];
                    $state['seq']++;
                    return $state;
                } else {
                    throw new Exception('No valid Stage area');
                }
            }
            $ownerP['hand'] = array_values(array_filter(
                $ownerP['hand'],
                fn($c) => ($c['instance_id'] ?? '') !== $cardId
            ));
            if ($allowOverlap && !empty($ownerP['stage'][$slot])) {
                $replaced = $ownerP['stage'][$slot];
                $ownerP['waiting_room'][] = $replaced;
                $state = resolveOnLeaveStageAbilities($state, $owner, $replaced);
            }
            $played['active'] = true;
            $played['entered_turn'] = $turn;
            $ownerP['stage'][$slot] = $played;
            $state = resolveOnEnterAbilities($state, $owner, $played, $slot);
            $sourceId = $prompt['source_id'] ?? '';
            if (!empty($ability['wait_self_if_blade_heart']) && !empty($played['blade_hearts'])) {
                foreach ($ownerP['stage'] as &$mbr) {
                    if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                        waitMember($mbr);
                        break;
                    }
                }
                unset($mbr);
            }
            $payNote = $cost > 0 ? "paid $cost Energy; " : '';
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] ' . $payNote .
                'played ' . cardDisplayName($played) . ' from hand.');
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        }
        return returnAfterPlacedMemberEnter($state);
    }

    if ($promptType === 'pick_live_match_success_heart') {
        $pickId = $data['card_id'] ?? '';
        if ($pickId === '') {
            throw new Exception('Choose a Live card');
        }
        $picked = null;
        foreach ($ownerP['live_zone'] as $lc) {
            if ($lc && ($lc['instance_id'] ?? '') === $pickId) {
                $picked = $lc;
                break;
            }
        }
        if (!$picked) {
            throw new Exception('Invalid Live card');
        }
        $pName = $picked['name_en'] ?? $picked['name'] ?? '';
        $matched = false;
        foreach ($ownerP['success_lives'] ?? [] as $sl) {
            $sName = $sl['name_en'] ?? $sl['name'] ?? '';
            if ($sName === $pName) {
                $matched = true;
                break;
            }
        }
        if ($matched) {
            foreach ($ability['hearts'] ?? [['color' => 'purple', 'count' => 4]] as $h) {
                addBonusHeartsToModifier($state, $owner, [[
                    'color' => $h['color'] ?? 'purple',
                    'count' => intval($h['count'] ?? 4),
                ]]);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] gained bonus hearts (matching Success Live).');
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] no matching Success Live name.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'optional_wr_to_deck_top') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $cardId = $data['card_id'] ?? '';
            if (!putWrCardOnDeckTop($ownerP, $cardId)) {
                throw new Exception('Choose a card from Waiting Room');
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] put a card from Waiting Room on deck top.');
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_wait_group_member_draw_discard') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $mid = $data['member_id'] ?? '';
            $found = false;
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $mid
                    && ($mbr['group'] ?? '') === ($ability['group'] ?? 'Nijigasaki')) {
                    waitMember($mbr);
                    $found = true;
                    break;
                }
            }
            unset($mbr);
            if (!$found) {
                throw new Exception('Choose a Nijigasaki Member on Stage');
            }
            $drawn = drawCardsForPlayer($state, $owner, intval($ability['draw'] ?? 1));
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [" . ($prompt['source_name'] ?? 'Member') . "] Waited Member; drew $drawn.");
            $need = intval($ability['discard'] ?? 1);
            if ($need > 0 && !empty($ownerP['hand'])) {
                $state['pending_prompt'] = [
                    'type'        => 'effect_discard_hand',
                    'owner'       => $owner,
                    'responder'   => $owner,
                    'source_name' => $prompt['source_name'] ?? 'Member',
                    'count'       => $need,
                ];
                $state['seq']++;
                return $state;
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'live_success_pick_energy_or_member') {
        if (!in_array($choice, ['energy', 'member', 'both', 'skip'], true)) {
            throw new Exception('Invalid choice');
        }
        if ($choice !== 'skip') {
            $srcName = $prompt['source_name'] ?? 'Live';
            $prefix = $state['players'][$owner]['name'] . ' — [' . $srcName . '] ';
            $doEnergy = $choice === 'energy' || $choice === 'both';
            $doMember = $choice === 'member' || $choice === 'both';
            if ($doEnergy) {
                if (putEnergyFromDeckInWait($ownerP)) {
                    $state = addLog($state, $prefix . 'put 1 Energy from Energy deck into Wait.');
                } else {
                    $state = addLog($state, $prefix . 'could not put Energy into Wait (Energy deck empty).');
                }
            }
            if ($doMember) {
                $added = addFromWaitingRoomFiltered($ownerP, '', 'member', 1);
                if ($added > 0) {
                    $state = addLog($state, $prefix . "added $added Member card from Waiting Room to hand.");
                } else {
                    $state = addLog($state, $prefix . 'no Member card in Waiting Room to add to hand.');
                }
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveSuccessEffects($state);
        return $state;
    }

    if ($promptType === 'pick_member_return_energy') {
        $memberId = $data['member_id'] ?? '';
        $count = intval($data['count'] ?? 0);
        if ($memberId === '') throw new Exception('Choose a Member');
        $found = false;
        foreach ($ownerP['stage'] as &$mbr) {
            if (!$mbr || ($mbr['instance_id'] ?? '') !== $memberId) continue;
            $max = countMemberStackedEnergy($ownerP, $mbr);
            if ($max <= 0) throw new Exception('Member has no stacked Energy');
            if ($count <= 0) $count = $max;
            if ($count > $max) throw new Exception("Return at most $max Energy");
            $returned = returnMemberStackedEnergyToDeck($ownerP, $mbr, $count);
            if ($returned > 0) {
                addBonusHeartsToMember($mbr, $ability['hearts_per_energy'] ?? [['color' => 'red', 'count' => 3]], $returned);
            }
            $found = true;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] returned $returned Energy; Member gained bonus hearts.");
            break;
        }
        unset($mbr);
        if (!$found) throw new Exception('Member not found');
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'optional_wait_self') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $sourceId = $prompt['source_id'] ?? '';
            $maxCost = intval($ability['max_cost'] ?? 4);
            $pickCount = intval($ability['pick_count'] ?? 1);
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    waitMember($mbr);
                    break;
                }
            }
            unset($mbr);
            $opp = ($owner === 'p1') ? 'p2' : 'p1';
            $subunitOnly = $ability['require_stage_subunit_only'] ?? '';
            if ($subunitOnly !== '' && stageAllMembersInSubunit($ownerP, $subunitOnly)) {
                if (!empty($ability['max_original_blades'])) {
                    $waited = waitOpponentStageByOriginalBlades(
                        $state,
                        $opp,
                        intval($ability['max_original_blades']),
                        $pickCount ?: null,
                        $owner
                    );
                } elseif (!empty($ability['max_original_hearts'])) {
                    $waited = waitOpponentStageByOriginalHearts(
                        $state,
                        $opp,
                        intval($ability['max_original_hearts']),
                        $pickCount ?: null,
                        $owner
                    );
                } else {
                    $waited = waitOpponentStageByCost($state, $opp, $maxCost, $pickCount ?: null, $owner);
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] Waited self; $waited opponent Member(s) put into Wait.");
            } elseif ($subunitOnly !== '') {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] Waited self (stage not all ' . $subunitOnly . ').');
            } else {
                $waited = waitOpponentStageByCost($state, $opp, $maxCost, $pickCount ?: null, $owner);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] Waited self; $waited opponent Member(s) put into Wait.");
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional Wait effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_discard_look_reveal_subunit') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $need = intval($ability['discard'] ?? 1);
            $ids = $data['discard_ids'] ?? [];
            if ($need > 0 && count($ids) !== $need) {
                throw new Exception("Must select exactly $need card(s) to discard");
            }
            if (!empty($ids)) {
                discardFromHandByIds($ownerP, $ids);
            }
            $picked = lookRevealSubunit(
                $ownerP,
                intval($ability['look'] ?? 4),
                $ability['subunit'] ?? 'lily white'
            );
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . "] looked at deck top; added $picked card(s) to hand.");
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_wait_self_draw_discard_unless_baton') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $sourceId = $prompt['source_id'] ?? '';
            $batonSub = '';
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    $batonSub = $mbr['baton_from_subunit'] ?? '';
                    waitMember($mbr);
                    break;
                }
            }
            unset($mbr);
            $srcName = $prompt['source_name'] ?? 'Member';
            $drawnCards = drawCardInstances($ownerP, intval($ability['draw'] ?? 1));
            foreach ($drawnCards as $c) {
                $state = logEffectDraw($state, $owner, $srcName, $c,
                    [animSpec($c['instance_id'], 'main_deck', 'hand', $owner)]);
            }
            $needBaton = $ability['baton_subunit'] ?? 'Printemps';
            if ($batonSub !== $needBaton && !empty($ownerP['hand'])) {
                $discardNeed = intval($ability['discard'] ?? 1);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . $srcName . '] Waited self.');
                return startEffectDiscardHandPrompt($state, $owner, $srcName, $discardNeed);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] Waited self.');
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_wait_self_energy_subunit') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $sourceId = $prompt['source_id'] ?? '';
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    waitMember($mbr);
                    break;
                }
            }
            unset($mbr);
            $subunit = $ability['subunit'] ?? '';
            $count = countStageSubunitMembers($ownerP, $subunit);
            $activated = activateEnergyForPlayer($ownerP, $count);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . "] Waited self; activated $activated Energy ($count $subunit Member(s)).");
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_wait_members_draw') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $members = [];
            foreach ($ownerP['stage'] as $slot => $mbr) {
                if ($mbr) {
                    $members[] = cardPromptSummary($mbr) + ['slot' => $slot];
                }
            }
            if (empty($members)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] no Stage Members to Wait.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return $state;
            }
            $state['pending_prompt'] = [
                'type'          => 'wait_members_pick',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'prompt'        => 'Choose up to ' . intval($ability['max_members'] ?? 3) .
                    ' Members to put into Wait.',
                'max_members'   => intval($ability['max_members'] ?? 3),
                'draw_per'      => intval($ability['draw_per'] ?? 1),
                'stage_members' => $members,
            ];
            $state['seq']++;
            return $state;
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'wait_members_pick') {
        $ids = $data['member_ids'] ?? [];
        $max = intval($prompt['max_members'] ?? 3);
        if (count($ids) > $max) {
            throw new Exception("Choose at most $max Member(s)");
        }
        $waited = 0;
        foreach ($ownerP['stage'] as &$mbr) {
            if ($mbr && in_array($mbr['instance_id'] ?? '', $ids, true)) {
                waitMember($mbr);
                $waited++;
            }
        }
        unset($mbr);
        $drawn = 0;
        if ($waited > 0) {
            $drawn = drawCardsForPlayer($state, $owner, $waited * intval($prompt['draw_per'] ?? 1));
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] put $waited Member(s) into Wait and drew $drawn.");
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_wait_subunit_opp_active') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $state = beginWaitSubunitOppActiveChain($state, $owner, $prompt);
            if (!empty($state['pending_prompt'])) {
                $state['seq']++;
                if (($state['phase'] ?? '') === 'live_start_effects') {
                    return $state;
                }
                return $state;
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional effect.');
            unset($state['pending_prompt']);
        }
        $state['seq']++;
        if (($state['phase'] ?? '') === 'live_start_effects') {
            $state = finishLiveStartEffects($state);
        }
        return $state;
    }

    if ($promptType === 'wait_subunit_member_pick') {
        $ids = $data['member_ids'] ?? [];
        $max = intval($prompt['max_members'] ?? 1);
        if (count($ids) < 1 || count($ids) > $max) {
            throw new Exception("Choose $max Member(s) to put into Wait");
        }
        $waited = 0;
        foreach ($ownerP['stage'] as &$mbr) {
            if ($mbr && in_array($mbr['instance_id'] ?? '', $ids, true)) {
                waitMember($mbr);
                $waited++;
            }
        }
        unset($mbr);
        $sourceId = $prompt['source_id'] ?? '';
        if (!empty($prompt['ability']['center_only']) && findMemberSlot($ownerP, $sourceId) !== 'center') {
            unset($state['pending_prompt']);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] effect cancelled (no longer in Center).');
            $state['seq']++;
            return $state;
        }
        $opp = ($owner === 'p1') ? 'p2' : 'p1';
        $active = listActiveStageMembers($state['players'][$opp]);
        if ($waited > 0 && !empty($active)) {
            $state['pending_prompt'] = [
                'type'          => 'opp_pick_stage_active',
                'owner'         => $owner,
                'responder'     => $opp,
                'source_id'     => $sourceId,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'effect_source' => $owner,
                'stage_members' => $active,
                'prompt'        => 'Choose 1 active Member on your Stage to put into Wait.',
            ];
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . "] put $waited Member(s) into Wait; opponent chooses.");
            $state['seq']++;
            return $state;
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] put $waited Member(s) into Wait.");
        unset($state['pending_prompt']);
        $state['seq']++;
        if (($state['phase'] ?? '') === 'live_start_effects') {
            $state = finishLiveStartEffects($state);
        }
        return $state;
    }

    if ($promptType === 'opp_pick_stage_active') {
        $pickId = $data['member_id'] ?? $data['card_id'] ?? '';
        if ($pickId === '') throw new Exception('Choose a Member');
        $stageP = &$state['players'][$pid];
        $slot = findMemberSlot($stageP, $pickId);
        if ($slot === '' || empty($stageP['stage'][$slot]) || !($stageP['stage'][$slot]['active'] ?? true)) {
            throw new Exception('Must choose an active Member on your Stage');
        }
        $effectSource = $prompt['effect_source'] ?? $prompt['owner'] ?? $pid;
        waitOpponentMemberAtSlot($state, $pid, $slot, $effectSource);
        $mbr = $stageP['stage'][$slot];
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — put ' . ($mbr['name_en'] ?? $mbr['name'] ?? 'Member') . ' into Wait.');
        unset($state['pending_prompt']);
        $state['seq']++;
        if (($state['phase'] ?? '') === 'live_start_effects') {
            $state = finishLiveStartEffects($state);
        }
        return $state;
    }

    if ($promptType === 'opp_pick_hidden_hand') {
        $cardId = $data['card_id'] ?? '';
        if ($cardId === '') throw new Exception('Choose a card');
        $handOwnerP = &$state['players'][$owner];
        $picked = null;
        foreach ($handOwnerP['hand'] as $c) {
            if (($c['instance_id'] ?? '') === $cardId) {
                $picked = $c;
                break;
            }
        }
        if (!$picked) throw new Exception('Invalid hand card');
        $srcName = $prompt['source_name'] ?? 'Member';
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . $srcName . '] opponent revealed ' . cardDisplayName($picked) . ' from hand.');
        $ab = $prompt['ability'] ?? [];
        if (($picked['card_type'] ?? '') === 'ライブ') {
            $amount = intval($ab['live_score_amount'] ?? 1);
            grantMemberLiveScoreBonus($state, $owner, $prompt['source_id'] ?? '', $amount);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$srcName] gains +$amount Live total score until this Live ends.");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_judge_success_live') {
        return actionResolvePickJudgeSuccessLive($state, $owner, $prompt, $data);
    }

    if ($promptType === 'pick_wr_to_hand') {
        $pickId = $data['card_id'] ?? '';
        if ($pickId === '') {
            throw new Exception('Choose a card');
        }
        $cfg = $prompt['wr_pick_cfg'] ?? wrPickCfgFromAbility($ability);
        $picked = null;
        foreach ($ownerP['waiting_room'] as $i => &$c) {
            if (($c['instance_id'] ?? '') !== $pickId) {
                continue;
            }
            hydrateWrCardForPick($c);
            if (!cardMatchesWrPick($c, $cfg)) {
                throw new Exception('Invalid Waiting Room card');
            }
            $picked = $c;
            array_splice($ownerP['waiting_room'], $i, 1);
            break;
        }
        unset($c);
        if (!$picked) {
            throw new Exception('Invalid Waiting Room card');
        }
        $ownerP['hand'][] = $picked;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] added ' .
            cardDisplayName($picked) . ' from Waiting Room to hand.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_wr_leave_stage_add') {
        $pickId = $data['card_id'] ?? '';
        if ($pickId === '') {
            throw new Exception('Choose a card');
        }
        $cfg = $prompt['wr_pick_cfg'] ?? wrPickCfgForLeaveStageAbility($ability);
        $pickIndex = null;
        $picked = null;
        foreach ($ownerP['waiting_room'] as $i => &$c) {
            if (($c['instance_id'] ?? '') !== $pickId) {
                continue;
            }
            hydrateWrCardForPick($c);
            if (!cardMatchesWrPick($c, $cfg)) {
                throw new Exception('Invalid Waiting Room card');
            }
            $picked = $c;
            $pickIndex = $i;
            break;
        }
        unset($c);
        if (!$picked || $pickIndex === null) {
            throw new Exception('Invalid Waiting Room card');
        }
        $slot = $prompt['source_slot'] ?? '';
        if ($slot === '') {
            $slot = findMemberSlot($ownerP, $prompt['source_id'] ?? '');
        }
        if ($slot === '' || empty($ownerP['stage'][$slot])) {
            throw new Exception('Member no longer on Stage');
        }
        $leavingMember = $ownerP['stage'][$slot];
        $ownerP['stage'][$slot] = null;
        $pickPromptType = $prompt['type'] ?? '';
        $state = resolveOnLeaveStageAbilities($state, $owner, $leavingMember);
        if (!empty($state['pending_prompt']) && ($state['pending_prompt']['type'] ?? '') !== $pickPromptType) {
            $ownerP['stage'][$slot] = $leavingMember;
            $state['seq']++;
            return $state;
        }
        array_splice($ownerP['waiting_room'], $pickIndex, 1);
        $ownerP['waiting_room'][] = $leavingMember;
        $ownerP['hand'][] = $picked;
        $mName = $leavingMember['name_en'] ?? $leavingMember['name'] ?? 'Member';
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . $mName . '] left Stage; added ' .
            cardDisplayName($picked) . ' from Waiting Room to hand.');
        $group = $ability['group'] ?? '';
        $minScore = intval($ability['activate_energy_if_score_min'] ?? 0);
        if ($minScore > 0 && ($picked['card_type'] ?? '') === 'ライブ'
            && intval($picked['score'] ?? 0) >= $minScore
            && cardMatchesGroup($picked, $group, 'live')) {
            $activated = activateEnergyForPlayer($ownerP, intval($ability['activate_energy_count'] ?? 4));
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — activated $activated Energy (high-score Aqours Live).");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_wr_live_deck_top') {
        $pickId = $data['card_id'] ?? '';
        if ($pickId === '') throw new Exception('Choose a Live card');
        if (!putWrCardOnDeckTop($ownerP, $pickId)) {
            throw new Exception('Invalid Waiting Room card');
        }
        $ability = $prompt['ability'] ?? [];
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] put a Live card on top of deck.');
        $opp = ($owner === 'p1') ? 'p2' : 'p1';
        if (stageHasWaitMember($state, $opp)) {
            $drawn = drawCardsForPlayer($state, $owner, intval($ability['draw'] ?? 1));
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [" . ($prompt['source_name'] ?? 'Member') . "] drew $drawn (opponent has a Member in Wait).");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_wr_members_deck_top') {
        $pickIds = $data['card_ids'] ?? [];
        $need = intval($prompt['pick_count'] ?? 2);
        if (count($pickIds) !== $need) {
            throw new Exception("Choose exactly $need Member card(s)");
        }
        $picked = [];
        foreach ($pickIds as $id) {
            $found = false;
            foreach ($ownerP['waiting_room'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $id && ($c['card_type'] ?? '') === 'メンバー') {
                    $picked[] = $c;
                    array_splice($ownerP['waiting_room'], $i, 1);
                    $found = true;
                    break;
                }
            }
            if (!$found) throw new Exception('Invalid Waiting Room Member');
        }
        $picked = array_reverse($picked);
        $ownerP['main_deck'] = array_merge($picked, $ownerP['main_deck']);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] put $need Member card(s) from Waiting Room on deck top.");
        unset($state['pending_prompt']);
        $state['seq']++;
        if (($state['phase'] ?? '') === 'live_start_effects') {
            $state = finishLiveStartEffects($state);
        }
        return $state;
    }

    if ($promptType === 'optional_wait_self_add_wr') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $sourceId = $prompt['source_id'] ?? '';
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    waitMember($mbr);
                    break;
                }
            }
            unset($mbr);
            $added = addFromWaitingRoomFiltered(
                $ownerP,
                $ability['group'] ?? '',
                $ability['filter'] ?? '',
                intval($ability['count'] ?? 1)
            );
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . "] Waited self; added $added μ's Member(s) from Waiting Room.");
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_pay_energy_if_baton') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $cost = intval($ability['cost'] ?? 1);
            if (!payEnergyCost($ownerP, $cost)) {
                throw new Exception("Need $cost active Energy");
            }
            $sourceId = $prompt['source_id'] ?? '';
            $source = findSourceCard($state, $owner, $sourceId);
            if ($source && memberBatonFromLowerCostSubunit($source, $ability['baton_subunit'] ?? '')) {
                $then = $ability['then'] ?? [];
                if (!empty($then['hearts'])) {
                    addBonusHeartsToModifier($state, $owner, $then['hearts']);
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') .
                    '] paid ' . $cost . ' Energy; Baton Touch bonus applied.');
            } else {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') .
                    "] paid $cost Energy but Baton Touch condition was not met.");
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_same_name_member') {
        $memberId = $data['member_id'] ?? ($data['member_ids'][0] ?? '');
        if ($memberId === '') throw new Exception('Choose a Member');
        $effect = $prompt['ability'] ?? [];
        if (applyNamedMemberHeartsBlade($state, $owner, $memberId, $effect)) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') .
                '] granted bonus hearts and Blade to a matching-name Member.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'pick_member_grant_hearts') {
        $memberId = $data['member_id'] ?? ($data['member_ids'][0] ?? '');
        if ($memberId === '') throw new Exception('Choose a Member');
        $effect = [
            'hearts' => $prompt['hearts'] ?? [],
            'blade'  => intval($prompt['blade'] ?? 0),
        ];
        if (applyNamedMemberHeartsBlade($state, $owner, $memberId, $effect)) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] granted bonus hearts/Blade.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'optional_discard_prompt') {
        return resolveOptionalDiscardPromptChoice($state, $owner, $prompt, $choice, $data);
    }

    if ($promptType === 'optional_pay_energy_on_enter') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $cost = intval($prompt['pay_cost'] ?? $ability['cost'] ?? 0);
            if (!payEnergyCost($ownerP, $cost)) {
                throw new Exception("Need $cost active Energy");
            }
            $source = findSourceCard($state, $owner, $prompt['source_id'] ?? '');
            if ($source && !empty($ability['then'])) {
                $state = resolveAbilityEffect($state, $owner, $source, $ability['then'], [
                    'phase' => 'on_enter',
                    'slot'  => findMemberSlot($ownerP, $prompt['source_id'] ?? ''),
                    'pay'   => true,
                ]);
            }
            if (($state['pending_prompt']['type'] ?? '') === 'optional_pay_energy_on_enter') {
                unset($state['pending_prompt']);
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
            unset($state['pending_prompt']);
        }
        $state['seq']++;
        if (empty($state['pending_prompt'])) {
            $state = finishPromptEffects($state);
        }
        return $state;
    }

    if ($promptType === 'mandatory_discard_look_reveal') {
        $need = intval($prompt['discard_count'] ?? 1);
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== $need) {
            throw new Exception("Must select exactly $need card(s) to discard");
        }
        discardHandCardsByIds($ownerP, $ids);
        unset($state['pending_prompt']);
        $ab = $prompt['ability'] ?? [];
        $then = [
            'type'   => 'look_reveal_filter',
            'look'   => intval($ab['look'] ?? 5),
            'group'  => $ab['group'] ?? '',
            'filter' => $ab['filter'] ?? '',
            'pick'   => intval($ab['pick'] ?? 1),
        ];
        $state = beginLookRevealPick($state, $owner, $prompt['source_name'] ?? 'Member', $ownerP, $then);
        if (empty($state['pending_prompt'])) {
            $state['seq']++;
            $state = finishPromptEffects($state);
        }
        return $state;
    }

    if ($promptType === 'reveal_hand_member_cost_live_score') {
        $ids = $data['card_ids'] ?? [];
        $milestones = $prompt['milestones'] ?? [10, 20, 30, 40, 50];
        $total = 0;
        foreach ($ownerP['hand'] as $c) {
            if (in_array($c['instance_id'] ?? '', $ids, true)
                && ($c['card_type'] ?? '') === 'メンバー') {
                $total += intval($c['cost'] ?? 0);
            }
        }
        unset($state['pending_prompt']);
        if (in_array($total, $milestones, true)) {
            $then = $ability['then'] ?? ['type' => 'live_score_bonus', 'amount' => 1];
            $state = applyModifierEffect($state, $owner, $then);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [" . ($prompt['source_name'] ?? 'Member') . "] revealed cost $total; +1 Live Score until Live ends.");
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [" . ($prompt['source_name'] ?? 'Member') . "] revealed cost $total (no milestone).");
        }
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'optional_discard_blade_draw_if_live') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $need = intval($ability['discard'] ?? 1);
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== $need) {
                throw new Exception("Must select exactly $need card(s) to discard");
            }
            $hadLive = false;
            foreach ($ids as $id) {
                foreach ($ownerP['hand'] as $c) {
                    if (($c['instance_id'] ?? '') === $id && ($c['card_type'] ?? '') === 'ライブ') {
                        $hadLive = true;
                        break 2;
                    }
                }
            }
            discardHandCardsByIds($ownerP, $ids);
            $state = applyModifierEffect($state, $owner, [
                'type'   => 'blade_bonus',
                'amount' => intval($ability['blade_amount'] ?? 1),
            ]);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] discarded for Blade bonus.');
            if ($hadLive) {
                $drawn = drawCardsForPlayer($state, $owner, 1);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — drew $drawn (Live discarded).");
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional Live Start effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'live_start_pay_or_discard') {
        if ($choice === 'pay') {
            $cost = intval($prompt['pay_cost'] ?? 2);
            if (!payEnergyCost($ownerP, $cost)) {
                throw new Exception("Need $cost active Energy");
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [" . ($prompt['source_name'] ?? 'Member') . "] paid $cost Energy (Live Start).");
        } elseif ($choice === 'discard') {
            $need = intval($prompt['discard_count'] ?? 2);
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== $need) {
                throw new Exception("Must select exactly $need card(s) to discard");
            }
            discardHandCardsByIds($ownerP, $ids);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [" . ($prompt['source_name'] ?? 'Member') . "] discarded $need (Live Start).");
        } else {
            throw new Exception('Invalid choice');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'optional_pay_energy_live_success') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $cost = intval($prompt['pay_cost'] ?? $ability['cost'] ?? 6);
            if (!payEnergyCost($ownerP, $cost)) {
                throw new Exception("Need $cost active Energy");
            }
            if (!empty($ability['then'])) {
                $state = applyModifierEffect($state, $owner, $ability['then']);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] paid $cost Energy; +1 Live Score.");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveSuccessEffects($state);
    }

    if ($promptType === 'on_enter_draw_swap_area') {
        $slot = $choice;
        $srcSlot = $prompt['source_slot'] ?? '';
        $srcId = $prompt['source_id'] ?? '';
        if (!in_array($slot, $prompt['slots'] ?? [], true)) {
            throw new Exception('Choose a valid area');
        }
        $member = $ownerP['stage'][$srcSlot] ?? null;
        if (!$member || ($member['instance_id'] ?? '') !== $srcId) {
            throw new Exception('Member not found');
        }
        $other = $ownerP['stage'][$slot] ?? null;
        $ownerP['stage'][$slot] = $member;
        $ownerP['stage'][$srcSlot] = $other;
        if ($other) {
            $other['moved_this_turn'] = true;
            $other['moved_from_slot'] = $srcSlot;
            $ownerP['stage'][$srcSlot] = $other;
            $state = resolveAutoAreaMoveAbilities($state, $owner, $other['instance_id'] ?? '', $srcSlot);
        }
        $member['moved_this_turn'] = true;
        $member['moved_from_slot'] = $srcSlot;
        $ownerP['stage'][$slot] = $member;
        $state = resolveAutoAreaMoveAbilities($state, $owner, $srcId, $srcSlot);
        $state = addLog($state, $state['players'][$owner]['name'] .
            " — [" . ($prompt['source_name'] ?? 'Member') . "] moved to $slot.");
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'optional_wr_member_reenter') {
        if (($prompt['step'] ?? '') === 'pick_stage' || (!empty($data['slot']) && $choice === '')) {
            $slot = $data['slot'] ?? $choice;
            $mbr = $ownerP['stage'][$slot] ?? null;
            if (!$mbr) throw new Exception('Choose a Member on Stage');
            $nameKey = cardNameKey($mbr);
            $ownerP['waiting_room'][] = $mbr;
            $ownerP['stage'][$slot] = null;
            $state = resolveOnLeaveStageAbilities($state, $owner, $mbr);
            $reenter = null;
            $rest = [];
            foreach ($ownerP['waiting_room'] as $c) {
                if (!$reenter && ($c['card_type'] ?? '') === 'メンバー'
                    && cardNameKey($c) === $nameKey) {
                    $reenter = $c;
                } else {
                    $rest[] = $c;
                }
            }
            $ownerP['waiting_room'] = $rest;
            if ($reenter) {
                $reenter['entered_this_turn'] = true;
                $reenter['moved_this_turn'] = true;
                $ownerP['stage'][$slot] = $reenter;
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] re-entered ' .
                    ($reenter['name_en'] ?? $reenter['name']) . " on $slot.");
            }
            unset($state['pending_prompt']);
            $state['seq']++;
            $state = finishPromptEffects($state);
            return $state;
        }
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $slot = $data['slot'] ?? '';
            if ($slot === '') {
                $state['pending_prompt'] = array_merge($prompt, [
                    'step'    => 'pick_stage',
                    'prompt'  => 'Choose a Member on your Stage to swap with Waiting Room.',
                    'choices' => [],
                ]);
                $state['seq']++;
                return $state;
            }
            $mbr = $ownerP['stage'][$slot] ?? null;
            if (!$mbr) throw new Exception('Choose a Member on Stage');
            $nameKey = cardNameKey($mbr);
            $ownerP['waiting_room'][] = $mbr;
            $ownerP['stage'][$slot] = null;
            $state = resolveOnLeaveStageAbilities($state, $owner, $mbr);
            $reenter = null;
            $rest = [];
            foreach ($ownerP['waiting_room'] as $c) {
                if (!$reenter && ($c['card_type'] ?? '') === 'メンバー'
                    && cardNameKey($c) === $nameKey) {
                    $reenter = $c;
                } else {
                    $rest[] = $c;
                }
            }
            $ownerP['waiting_room'] = $rest;
            if ($reenter) {
                $reenter['entered_this_turn'] = true;
                $reenter['moved_this_turn'] = true;
                $ownerP['stage'][$slot] = $reenter;
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] re-entered ' .
                    ($reenter['name_en'] ?? $reenter['name']) . " on $slot.");
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'activate_energy_up_to') {
        $count = intval($choice);
        $max = intval($prompt['max'] ?? 6);
        if ($count < 0 || $count > $max) throw new Exception("Choose 0–$max");
        if ($count > 0) {
            $activated = activateEnergyForPlayer($ownerP, $count);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [" . ($prompt['source_name'] ?? 'Live') . "] activated $activated Energy.");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'pick_yell_member') {
        if (($data['choice'] ?? '') === 'skip') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped Yell member pick.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $cardId = $data['card_id'] ?? '';
        $eligibleIds = yellPromptCandidateIds($prompt);
        $picked = takeFromPendingYellPool($ownerP, $cardId, $prompt);
        if (!$picked && count($eligibleIds) === 1) {
            $picked = takeFromPendingYellPool($ownerP, $eligibleIds[0], $prompt);
        }
        if (!$picked) {
            if (empty($eligibleIds)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . '] no Yell cards available; skipped.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            throw new Exception('Invalid Yell card');
        }
        if (!cardMatchesYellPick($picked, $ability)) {
            throw new Exception('Must pick a qualifying card');
        }
        $ownerP['hand'][] = $picked;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . '] added ' .
            ($picked['name_en'] ?? $picked['name']) . ' from Yell to hand.');
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'optional_wait_self_center_blade') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $sourceId = $prompt['source_id'] ?? '';
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    waitMember($mbr);
                    break;
                }
            }
            unset($mbr);
            $group = $ability['group'] ?? 'μ\'s';
            $amount = intval($ability['amount'] ?? 1);
            if (applyCenterGroupBladeBonus($state, $owner, $group, $amount)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [" . ($prompt['source_name'] ?? 'Member') . "] Waited self; Center $group Member gained +$amount Blade.");
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional Live Start effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'optional_stage_reposition') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] may reposition Stage Members.');
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional reposition.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_position_change_all_muse') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $left = $ownerP['stage']['left'];
            $ownerP['stage']['left'] = $ownerP['stage']['center'];
            $ownerP['stage']['center'] = $left;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] position-changed Center and Left Members.');
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped optional position change.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'choose_heart_mus_member') {
        $choices = $ability['heart_choices'] ?? ['yellow', 'pink', 'purple'];
        if (!in_array($choice, $choices, true)) {
            throw new Exception('Invalid heart choice');
        }
        if (grantHeartToFirstGroupMember($state, $owner, 'μ\'s', $choice)) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] granted 1 $choice ♡ to a μ's Member.");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'choose_heart_per_success') {
        $choices = $ability['heart_choices'] ?? ['yellow', 'pink', 'purple'];
        if (!in_array($choice, $choices, true)) {
            throw new Exception('Invalid heart choice');
        }
        $n = count($ownerP['success_lives'] ?? []) * intval($ability['per_success'] ?? 1);
        if ($n > 0) {
            addBonusHeartsToModifier($state, $owner, [['color' => $choice, 'count' => $n]]);
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] chose $choice ♡ × $n until Live ends.");
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'choose_heart_modifier') {
        $choices = $ability['heart_choices'] ?? ['yellow', 'pink', 'purple'];
        if (!in_array($choice, $choices, true)) {
            throw new Exception('Invalid heart choice');
        }
        $n = intval($ability['count'] ?? 1);
        addBonusHeartsToModifier($state, $owner, [['color' => $choice, 'count' => $n]]);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] chose $choice ♡ × $n until Live ends.");
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'choose_heart_other_member') {
        $choices = $ability['heart_choices'] ?? ['yellow', 'pink', 'purple', 'green', 'blue', 'red'];
        if (!in_array($choice, $choices, true)) {
            throw new Exception('Invalid heart choice');
        }
        $excludeId = $prompt['source_id'] ?? '';
        $heartCount = intval($ability['heart_count'] ?? 1);
        $applied = 0;
        foreach ($state['players'][$owner]['stage'] as &$mbr) {
            if (!$mbr || ($mbr['instance_id'] ?? '') === $excludeId) continue;
            if (($ability['group'] ?? '') !== '' && ($mbr['group'] ?? '') !== ($ability['group'] ?? '')) {
                continue;
            }
            if (!isset($mbr['bonus_hearts'])) $mbr['bonus_hearts'] = [];
            for ($i = 0; $i < $heartCount; $i++) {
                $mbr['bonus_hearts'][] = $choice;
            }
            $applied++;
            break;
        }
        unset($mbr);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] gave $heartCount $choice ♡ to another Member.");
        unset($state['pending_prompt']);
        $state['seq']++;
        if (!empty($prompt['after_live_start'])) {
            $state = finishLiveStartEffects($state);
        } else {
            $state = finishPromptEffects($state);
        }
        return $state;
    }

    if ($promptType === 'blade_per_discarded_pick_member') {
        if ($choice === 'skip' || $choice === 'no') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped Blade bonus.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        $memberId = $data['card_id'] ?? $choice;
        $per = intval($ability['amount'] ?? 3);
        $discarded = intval($prompt['discarded'] ?? 0);
        $bonus = $per * $discarded;
        foreach ($state['players'][$owner]['stage'] as &$mbr) {
            if (!$mbr || ($mbr['instance_id'] ?? '') !== $memberId) continue;
            $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $bonus;
            break;
        }
        unset($mbr);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] chosen Member gains +$bonus Blade.");
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'waive_required_heart_color') {
        $choices = $ability['colors'] ?? ['pink', 'green', 'blue'];
        if (!in_array($choice, $choices, true)) {
            throw new Exception('Invalid heart choice');
        }
        $sourceId = $prompt['source_id'] ?? '';
        bumpLiveCardColorReduction($state, $owner, $sourceId, $choice, 1);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . "] waived required $choice ♡.");
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'choose_required_heart_pair_gray') {
        $choices = $ability['colors'] ?? ['pink', 'green', 'blue'];
        if (!in_array($choice, $choices, true)) {
            throw new Exception('Invalid heart choice');
        }
        $sourceId = $prompt['source_id'] ?? '';
        foreach ($ownerP['live_zone'] as &$lc) {
            if ($lc && ($lc['instance_id'] ?? '') === $sourceId) {
                $lc['required_hearts'] = [
                    ['color' => $choice, 'count' => 2],
                    ['color' => 'any', 'count' => 1],
                ];
                break;
            }
        }
        unset($lc);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . "] required hearts set to 2 $choice ♡ and 1 Gray ♡.");
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'treat_pick_group_member_hearts_as') {
        $slot = $choice;
        if (!isset($ownerP['stage'][$slot]) || !$ownerP['stage'][$slot]) {
            throw new Exception('Choose a Stage Member');
        }
        $color = $prompt['color'] ?? 'pink';
        $ownerP['stage'][$slot]['hearts_treat_as'] = $color;
        $mbr = $ownerP['stage'][$slot];
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . '] ' .
            ($mbr['name_en'] ?? $mbr['name'] ?? 'Member') .
            " hearts treated as $color until Live ends.");
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'optional_success_wr_live_swap') {
        $step = $prompt['step'] ?? 'confirm';
        $group = $prompt['group'] ?? 'Nijigasaki';
        $filter = $prompt['filter'] ?? 'live';
        if ($step === 'confirm') {
            if (!isset(['yes' => true, 'no' => true][$choice])) {
                throw new Exception('Invalid choice');
            }
            if ($choice === 'no') {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional effect.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $succ = array_values(array_filter(
                $ownerP['success_lives'] ?? [],
                fn($c) => cardMatchesGroup($c, $group, $filter)
            ));
            if (empty($succ)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional effect (no Success Live).');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_success_wr_live_swap',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'step'          => 'pick_success_live',
                'group'         => $group,
                'filter'        => $filter,
                'prompt'        => 'Choose 1 ' . $group . ' Live from your Success Live area to put into the Waiting Room.',
                'candidates'    => array_map('cardPromptSummary', $succ),
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_success_live') {
            $successId = $data['card_id'] ?? '';
            $successCard = null;
            foreach ($ownerP['success_lives'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $successId
                    && cardMatchesGroup($c, $group, $filter)) {
                    $successCard = $c;
                    array_splice($ownerP['success_lives'], $i, 1);
                    break;
                }
            }
            if ($successCard === null) {
                throw new Exception('Choose a Success Live card');
            }
            $ownerP['waiting_room'][] = $successCard;
            $wrLives = array_values(array_filter(
                $ownerP['waiting_room'],
                fn($c) => cardMatchesGroup($c, $group, $filter)
            ));
            if (empty($wrLives)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] put ' .
                    cardDisplayName($successCard) . ' into the Waiting Room (no WR Live to swap).');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_success_wr_live_swap',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'step'          => 'pick_wr_live',
                'group'         => $group,
                'filter'        => $filter,
                'prompt'        => 'Choose 1 ' . $group . ' Live from your Waiting Room to put into your Success Live area.',
                'candidates'    => array_map('cardPromptSummary', $wrLives),
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_wr_live') {
            $cardId = $data['card_id'] ?? '';
            $wrLive = null;
            foreach ($ownerP['waiting_room'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $cardId
                    && cardMatchesGroup($c, $group, $filter)) {
                    $wrLive = $c;
                    array_splice($ownerP['waiting_room'], $i, 1);
                    break;
                }
            }
            if ($wrLive === null) {
                throw new Exception('Choose a Live card from your Waiting Room');
            }
            $ownerP['success_lives'][] = $wrLive;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] put ' .
                cardDisplayName($wrLive) . ' from Waiting Room into Success Live area.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
    }

    if ($promptType === 'optional_success_live_swap') {
        $step = $prompt['step'] ?? 'confirm';
        if ($step === 'confirm') {
            if (!isset(['yes' => true, 'no' => true][$choice])) {
                throw new Exception('Invalid choice');
            }
            if ($choice === 'no') {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional effect.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return $state;
            }
            $liveHand = array_values(array_filter(
                $ownerP['hand'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if (empty($liveHand) || empty($ownerP['success_lives'])) {
                throw new Exception('Need a Live card in hand and a Success Live card');
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_success_live_swap',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'step'          => 'pick_hand_live',
                'prompt'        => 'Choose 1 Live card from your hand to reveal.',
                'candidates'    => array_map('cardPromptSummary', $liveHand),
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_hand_live') {
            $handLiveId = $data['card_id'] ?? '';
            $found = null;
            foreach ($ownerP['hand'] as $c) {
                if (($c['instance_id'] ?? '') === $handLiveId
                    && ($c['card_type'] ?? '') === 'ライブ') {
                    $found = $c;
                    break;
                }
            }
            if (!$found) throw new Exception('Choose a Live card from your hand');
            $srcName = $prompt['source_name'] ?? 'Member';
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] revealed ' . cardDisplayName($found) . ' from hand.',
                'effect',
                [animSpec($handLiveId, 'hand', 'hand', $owner, ['reveal' => true])]);
            $state['pending_prompt'] = [
                'type'          => 'optional_success_live_swap',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'step'          => 'pick_success_live',
                'hand_live_id'  => $handLiveId,
                'prompt'        => 'Choose 1 card from your Success Live area to add to your hand.',
                'candidates'    => array_map('cardPromptSummary', $ownerP['success_lives'] ?? []),
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_success_live') {
            $successId = $data['card_id'] ?? '';
            $handLiveId = $prompt['hand_live_id'] ?? '';
            $successIdx = null;
            $successCard = null;
            foreach ($ownerP['success_lives'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $successId) {
                    $successIdx = $i;
                    $successCard = $c;
                    break;
                }
            }
            if ($successCard === null) throw new Exception('Choose a Success Live card');
            $handLive = null;
            $handIdx = null;
            foreach ($ownerP['hand'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $handLiveId) {
                    $handLive = $c;
                    $handIdx = $i;
                    break;
                }
            }
            if ($handLive === null) throw new Exception('Revealed Live card no longer in hand');
            array_splice($ownerP['success_lives'], $successIdx, 1);
            array_splice($ownerP['hand'], $handIdx, 1);
            $ownerP['hand'][] = $successCard;
            $ownerP['success_lives'][] = $handLive;
            $srcName = $prompt['source_name'] ?? 'Member';
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] swapped ' .
                cardDisplayName($handLive) . ' into Success Live and added ' .
                cardDisplayName($successCard) . ' to hand.',
                'effect',
                [
                    animSpec($handLiveId, 'hand', 'success', $owner),
                    animSpec($successId, 'success', 'hand', $owner),
                ]);
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }
    }

    if ($promptType === 'optional_wait_mus_hearts') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            if (waitFirstGroupMember($ownerP, $ability['group'] ?? 'μ\'s')) {
                addBonusHeartsToModifier($state, $owner, $ability['hearts'] ?? []);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] Waited a μ\'s Member for bonus hearts.');
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional Live Start effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'optional_wait_self_surveil') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $sourceId = $prompt['source_id'] ?? '';
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    waitMember($mbr);
                    break;
                }
            }
            unset($mbr);
            $look = intval($ability['look'] ?? 2);
            $top = array_splice($ownerP['main_deck'], 0, min($look, count($ownerP['main_deck'])));
            if (count($top) <= 1) {
                if (count($top) === 1) {
                    $ownerP['main_deck'] = array_merge($top, $ownerP['main_deck']);
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] Waited self; looked at top $look.");
                unset($state['pending_prompt']);
                $state['seq']++;
                return $state;
            }
            $state = startSurveilArrangePrompt($state, $owner, $prompt['source_name'] ?? 'Member', $top);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . "] Waited self; arrange top $look.");
            $state['seq']++;
            return $state;
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_wait_self_look_reveal') {
        if (($prompt['step'] ?? '') === 'discard') {
            $discardNeed = intval($prompt['discard_count'] ?? $ability['discard'] ?? 0);
            $ids = $data['discard_ids'] ?? [];
            if ($discardNeed < 1 || count($ids) !== $discardNeed) {
                throw new Exception("Must discard exactly $discardNeed card(s) from hand");
            }
            discardFromHandByIds($ownerP, $ids);
            $sourceId = $prompt['source_id'] ?? '';
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    waitMember($mbr);
                    break;
                }
            }
            unset($mbr);
            $cfg = $ability;
            unset($state['pending_prompt']);
            $state = beginLookRevealPick(
                $state,
                $owner,
                $prompt['source_name'] ?? 'Member',
                $ownerP,
                $cfg
            );
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] Waited self; looked at deck top.');
            $state['seq']++;
            return $state;
        }
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $discardNeed = intval($prompt['discard_count'] ?? $ability['discard'] ?? 0);
            if ($discardNeed > 0) {
                $ids = $data['discard_ids'] ?? [];
                if (count($ids) !== $discardNeed) {
                    $state['pending_prompt'] = [
                        'type'          => 'optional_wait_self_look_reveal',
                        'owner'         => $owner,
                        'responder'     => $owner,
                        'source_id'     => $prompt['source_id'] ?? '',
                        'source_name'   => $prompt['source_name'] ?? 'Member',
                        'prompt'        => "Discard $discardNeed card(s) from your hand to look at the top of your deck.",
                        'discard_count' => $discardNeed,
                        'ability'       => $ability,
                        'step'          => 'discard',
                    ];
                    $state['seq']++;
                    return $state;
                }
                discardFromHandByIds($ownerP, $ids);
            }
            $sourceId = $prompt['source_id'] ?? '';
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    waitMember($mbr);
                    break;
                }
            }
            unset($mbr);
            $cfg = $ability;
            unset($state['pending_prompt']);
            $state = beginLookRevealPick(
                $state,
                $owner,
                $prompt['source_name'] ?? 'Member',
                $ownerP,
                $cfg
            );
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] Waited self; looked at deck top.');
            $state['seq']++;
            return $state;
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_pay_energy_up_to') {
        $paid = intval($choice);
        $max = intval($ability['max_cost'] ?? 2);
        if ($paid < 0 || $paid > $max) throw new Exception('Invalid Energy payment');
        if ($paid > 0 && !payEnergyCost($ownerP, $paid)) {
            throw new Exception("Need $paid active Energy");
        }
        $then = $ability['then'] ?? [];
        if ($paid > 0 && ($then['type'] ?? '') === 'blade_bonus_per_paid') {
            $then['paid'] = $paid;
            $state = applyModifierEffect($state, $owner, $then);
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] paid $paid Energy for Blade bonus.");
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'pick_named_members_grant_hearts') {
        $slot = $data['slot'] ?? $choice;
        $hearts = $prompt['hearts'] ?? [];
        $step = $prompt['step'] ?? 'pick_named';
        if ($step === 'pick_named') {
            $mbr = $ownerP['stage'][$slot] ?? null;
            if (!$mbr) throw new Exception('Choose a Member');
            $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
            $namedOk = false;
            foreach ($prompt['named_list'] ?? [] as $n) {
                if ($label === $n || str_contains($label, $n)) { $namedOk = true; break; }
            }
            if (!$namedOk) throw new Exception('Choose a named Member');
            addBonusHeartsToMember($mbr, $hearts, 1);
            $ownerP['stage'][$slot] = $mbr;
            $state['pending_prompt'] = array_merge($prompt, [
                'step'           => 'pick_other',
                'first_slot'     => $slot,
                'prompt'         => 'Choose 1 other Liella! Member for bonus hearts.',
                'responder'      => $owner,
            ]);
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_other') {
            if ($slot === ($prompt['first_slot'] ?? '')) {
                throw new Exception('Choose a different Member');
            }
            $mbr = $ownerP['stage'][$slot] ?? null;
            if (!$mbr || ($mbr['group'] ?? '') !== ($ability['group'] ?? 'Superstar')) {
                throw new Exception('Choose another Liella! Member');
            }
            addBonusHeartsToMember($mbr, $hearts, 1);
            $ownerP['stage'][$slot] = $mbr;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] granted bonus hearts to 2 Members.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
    }

    if ($promptType === 'pick_named_members_grant_blade') {
        $slot = $data['slot'] ?? $choice;
        $blade = intval($prompt['blade'] ?? 1);
        $step = $prompt['step'] ?? 'pick_named';
        if ($step === 'pick_named') {
            $mbr = $ownerP['stage'][$slot] ?? null;
            if (!$mbr) throw new Exception('Choose a Member');
            $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
            $namedOk = false;
            foreach ($prompt['named_list'] ?? [] as $n) {
                if ($label === $n || str_contains($label, $n)) { $namedOk = true; break; }
            }
            if (!$namedOk) throw new Exception('Choose a named Member');
            $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $blade;
            $ownerP['stage'][$slot] = $mbr;
            $state['pending_prompt'] = array_merge($prompt, [
                'step'           => 'pick_other',
                'first_slot'     => $slot,
                'prompt'         => 'Choose 1 other Liella! Member for +Blade.',
                'responder'      => $owner,
            ]);
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_other') {
            if ($slot === ($prompt['first_slot'] ?? '')) {
                throw new Exception('Choose a different Member');
            }
            $mbr = $ownerP['stage'][$slot] ?? null;
            if (!$mbr || ($mbr['group'] ?? '') !== ($ability['group'] ?? 'Superstar')) {
                throw new Exception('Choose another Liella! Member');
            }
            $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $blade;
            $ownerP['stage'][$slot] = $mbr;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] granted +$blade Blade to 2 Members.");
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
    }

    if ($promptType === 'optional_formation_change_group') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $assign = $data['assignments'] ?? null;
            if (is_array($assign)) {
                $members = [];
                foreach ($ownerP['stage'] as $slot => $mbr) {
                    if ($mbr) $members[$mbr['instance_id'] ?? ''] = $mbr;
                }
                foreach (['left', 'center', 'right'] as $slot) {
                    $ownerP['stage'][$slot] = null;
                }
                foreach (['left', 'center', 'right'] as $slot) {
                    $id = $assign[$slot] ?? '';
                    if ($id !== '' && isset($members[$id])) {
                        $ownerP['stage'][$slot] = $members[$id];
                        $ownerP['stage'][$slot]['moved_this_turn'] = true;
                    }
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . '] formation-changed Stage Members.');
            } else {
                $left = $ownerP['stage']['left'];
                $ownerP['stage']['left'] = $ownerP['stage']['right'];
                $ownerP['stage']['right'] = $left;
                if ($ownerP['stage']['left']) $ownerP['stage']['left']['moved_this_turn'] = true;
                if ($ownerP['stage']['right']) $ownerP['stage']['right']['moved_this_turn'] = true;
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . '] formation-changed (Left ↔ Right).');
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped formation change.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        if (($state['phase'] ?? '') === 'live_success_effects') {
            return finishLiveSuccessEffects($state);
        }
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'wait_pick_member_grant_live_score') {
        $slot = $data['slot'] ?? '';
        if ($slot === '' || empty($ownerP['stage'][$slot])) throw new Exception('Choose a Member');
        waitMember($ownerP['stage'][$slot]);
        grantMemberLiveScoreBonus(
            $state,
            $owner,
            $ownerP['stage'][$slot]['instance_id'] ?? '',
            intval($prompt['amount'] ?? 1)
        );
        $srcSlot = findMemberSlot($ownerP, $prompt['source_id'] ?? '');
        if ($srcSlot !== '' && !empty($ownerP['stage'][$srcSlot])) {
            foreach ($ownerP['stage'][$srcSlot]['abilities'] ?? [] as $idx => $ab) {
                if (($ab['type'] ?? '') === 'wait_pick_member_grant_live_score'
                    && !empty($ab['once_per_turn'])) {
                    markAbilityUsed($ownerP['stage'][$srcSlot], $idx);
                    break;
                }
            }
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] Waited a Member (+1 Live score until Live ends).');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'optional_discard_blade_per_card') {
        if ($choice === 'yes') {
            $ids = $data['discard_ids'] ?? [];
            $n = count($ids);
            if ($n < 1) throw new Exception('Choose at least 1 card to discard');
            discardFromHandByIds($ownerP, $ids);
            $bladePer = intval($prompt['ability']['blade_per'] ?? 1);
            $state = applyModifierEffect($state, $owner, [
                'type'   => 'blade_bonus',
                'amount' => $n * $bladePer,
            ]);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [{$prompt['source_name']}] discarded $n; gained +" . ($n * $bladePer) . ' Blade until Live ends.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'score_if_stage_member_hearts') {
        $slot = $data['slot'] ?? '';
        if ($slot === '') throw new Exception('Choose a Member');
        bumpLiveCardScore($state, $owner, $prompt['source_id'] ?? '', intval($prompt['amount'] ?? 1));
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . '] score +' . intval($prompt['amount'] ?? 1) . '.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'live_success_yell_live_deck_bottom' || $promptType === 'optional_wr_live_deck_bottom') {
        if ($choice === 'skip' || $choice === 'no') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $cardId = $data['card_id'] ?? '';
        if ($cardId === '') throw new Exception('Choose a Live card');
        $pool = $promptType === 'live_success_yell_live_deck_bottom'
            ? ($ownerP['_pending_yell_wr'] ?? [])
            : $ownerP['waiting_room'];
        $picked = null;
        $rest = [];
        foreach ($pool as $c) {
            if (($c['instance_id'] ?? '') === $cardId) {
                $picked = $c;
            } else {
                $rest[] = $c;
            }
        }
        if (!$picked) throw new Exception('Invalid card');
        if ($promptType === 'live_success_yell_live_deck_bottom') {
            $ownerP['_pending_yell_wr'] = $rest;
        } else {
            $ownerP['waiting_room'] = $rest;
        }
        $ownerP['main_deck'][] = $picked;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — put ' . cardDisplayName($picked) . ' on the bottom of the deck.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'opp_may_discard_or_modifier') {
        $ab = $prompt['ability'] ?? [];
        $ownerId = $prompt['owner'] ?? $owner;
        if ($choice === 'yes') {
            $discardIds = $data['discard_ids'] ?? [];
            if (count($discardIds) !== 1) {
                throw new Exception('Must discard exactly 1 Live card from hand');
            }
            $responderP = &$state['players'][$pid];
            $discarded = discardHandCardsByIds($responderP, $discardIds);
            foreach ($discarded as $c) {
                if (($c['card_type'] ?? '') !== 'ライブ') {
                    throw new Exception('Must discard a Live card');
                }
                $state = logEffectPutWr($state, $pid, $prompt['source_name'] ?? 'Member', $c,
                    [animSpec($c['instance_id'], 'hand', 'waiting_room', $pid)]);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — discarded Live card (' . ($prompt['source_name'] ?? 'effect') . ').');
        } else {
            $mod = $ab['else_modifier'] ?? ['type' => 'live_score_bonus', 'amount' => 1];
            $state = applyModifierEffect($state, $ownerId, $mod);
            $state = addLog($state, $state['players'][$ownerId]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] gains +' .
                intval($mod['amount'] ?? 1) . ' total Live Score until this Live ends.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'reveal_live_opp_discard_or_blade') {
        $ab = $prompt['ability'] ?? [];
        $ownerId = $prompt['owner'] ?? $owner;
        $slot = $prompt['source_slot'] ?? '';
        $abIdx = intval($prompt['ability_index'] ?? 0);
        if ($choice === 'yes') {
            $discardIds = $data['discard_ids'] ?? [];
            if (count($discardIds) !== 1) {
                throw new Exception('Must discard exactly 1 card from hand');
            }
            discardHandCardsByIds($state['players'][$pid], $discardIds);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — discarded 1 card (' . ($prompt['source_name'] ?? 'effect') . ').');
        } else {
            $amount = intval($ab['blade_amount'] ?? 4);
            if ($slot !== '' && !empty($state['players'][$ownerId]['stage'][$slot])) {
                $mbr = &$state['players'][$ownerId]['stage'][$slot];
                $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $amount;
                if (!empty($ab['once_per_turn'])) {
                    markAbilityUsed($mbr, $abIdx);
                }
                $state = addLog($state, $state['players'][$ownerId]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] gains +$amount Blade until this Live ends.");
            }
        }
        if ($choice === 'yes' && !empty($ab['once_per_turn'])
            && $slot !== '' && !empty($state['players'][$ownerId]['stage'][$slot])) {
            markAbilityUsed($state['players'][$ownerId]['stage'][$slot], $abIdx);
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_surveil_heart_threshold') {
        $looked = $state['surveil_stash'] ?? [];
        if ($choice === 'skip' || $choice === '') {
            if (!empty($looked)) {
                $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $looked);
            }
        } else {
            $rest = [];
            foreach ($looked as $c) {
                if (($c['instance_id'] ?? '') === $choice) {
                    $ownerP['hand'][] = $c;
                } else {
                    $rest[] = $c;
                }
            }
            if (!empty($rest)) {
                $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $rest);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] added 1 card from surveil to hand.');
        }
        unset($state['surveil_stash'], $state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_looked_deck_hand') {
        $looked = $state['surveil_stash'] ?? [];
        $eligibleIds = $prompt['eligible_ids'] ?? [];
        $pickCount = intval($prompt['pick_count'] ?? 1);
        $optional = !empty($prompt['optional']);
        $srcName = $prompt['source_name'] ?? 'Member';
        $resolveChoice = $data['choice'] ?? $choice;
        if ($optional && ($resolveChoice === 'no' || $resolveChoice === 'cancel')) {
            $resolveChoice = 'skip';
        }

        if ($resolveChoice === 'skip') {
            if (!$optional) {
                throw new Exception('Must pick a card');
            }
            if (!empty($looked)) {
                $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $looked);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$srcName] put all looked cards into the Waiting Room.");
        } else {
            $pickIds = [];
            if (!empty($data['card_ids'])) {
                $pickIds = array_values($data['card_ids']);
            } elseif (!empty($data['card_id'])) {
                $pickIds = [$data['card_id']];
            } elseif ($resolveChoice !== '' && $resolveChoice !== 'skip') {
                $pickIds = [$resolveChoice];
            }
            if (count($pickIds) > $pickCount) {
                throw new Exception("Must select at most $pickCount card(s)");
            }
            if (!$optional && count($pickIds) !== $pickCount) {
                throw new Exception("Must select exactly $pickCount card(s)");
            }
            if ($optional && empty($pickIds)) {
                if (!empty($looked)) {
                    $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $looked);
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$srcName] put all looked cards into the Waiting Room.");
            } else {
            $lookedIds = array_map(fn($c) => $c['instance_id'] ?? '', $looked);
            foreach ($pickIds as $id) {
                if (!in_array($id, $lookedIds, true)) {
                    throw new Exception('Invalid looked card');
                }
                if (!in_array($id, $eligibleIds, true)) {
                    throw new Exception('Card not eligible to pick');
                }
            }
            applyLookPickHand($ownerP, $looked, $pickIds);
            $pickedN = count($pickIds);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$srcName] added $pickedN card(s) from looked deck to hand.");
            $ability = $prompt['ability'] ?? [];
            if (!empty($ability['hearts_if_group_picked']) && !empty($pickIds)) {
                foreach ($looked as $c) {
                    if (!in_array($c['instance_id'] ?? '', $pickIds, true)) continue;
                    if (cardMatchesGroup($c, $ability['blade_if_group_picked'] ?? '', '')) {
                        foreach ($ability['hearts_if_group_picked'] as $h) {
                            addBonusHeartsToModifier($state, $owner, [$h]);
                        }
                        if (!empty($ability['blade_if_group_picked'])) {
                            $state = applyModifierEffect($state, $owner, [
                                'type'   => 'blade_bonus',
                                'amount' => intval($ability['blade_amount'] ?? 1),
                            ]);
                        }
                        $state = addLog($state, $state['players'][$owner]['name'] .
                            ' — [' . $srcName . '] gained bonus heart(s) and Blade (Hasunosora card added).');
                        break;
                    }
                }
            } elseif (!empty($ability['blade_if_group_picked']) && !empty($pickIds)) {
                foreach ($looked as $c) {
                    if (!in_array($c['instance_id'] ?? '', $pickIds, true)) continue;
                    if (cardMatchesGroup($c, $ability['blade_if_group_picked'], '')) {
                        $state = applyModifierEffect($state, $owner, [
                            'type'   => 'blade_bonus',
                            'amount' => intval($ability['blade_amount'] ?? 3),
                        ]);
                        $state = addLog($state, $state['players'][$owner]['name'] .
                            ' — [' . $srcName . '] gained +' . intval($ability['blade_amount'] ?? 3) .
                            ' Blade (Hasunosora card added).');
                        break;
                    }
                }
            }
            }
        }
        unset($state['surveil_stash'], $state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'live_success_pick_yell_live') {
        if (($data['choice'] ?? $choice) === 'skip') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped Yell Live pick.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveSuccessEffects($state);
        }
        $cardId = $data['card_id'] ?? $choice;
        $eligibleIds = yellPromptCandidateIds($prompt);
        $picked = takeFromPendingYellPool($ownerP, $cardId, $prompt);
        if (!$picked && count($eligibleIds) === 1) {
            $picked = takeFromPendingYellPool($ownerP, $eligibleIds[0], $prompt);
        }
        if (!$picked) {
            if (empty($eligibleIds)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . '] no Yell Live cards available; skipped.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishLiveSuccessEffects($state);
            }
            throw new Exception('Choose a Live card revealed by Yell');
        }
        if (($picked['card_type'] ?? '') !== 'ライブ') {
            throw new Exception('Choose a Live card revealed by Yell');
        }
        $ownerP['hand'][] = $picked;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] added ' .
            cardDisplayName($picked) . ' from Yell to hand.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveSuccessEffects($state);
    }

    if ($promptType === 'optional_negate_member_live_start_add_wr') {
        if ($choice === 'skip') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional negate.');
        } else {
            $targetSlot = '';
            foreach ($ownerP['stage'] as $s => $mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $choice) {
                    $targetSlot = $s;
                    $mbr['live_start_negated'] = true;
                    $ownerP['stage'][$s] = $mbr;
                    break;
                }
            }
            if ($targetSlot === '') throw new Exception('Invalid Member');
            $ab = $prompt['ability'] ?? [];
            $added = addFromWaitingRoomFiltered(
                $ownerP,
                $ab['group'] ?? '',
                '',
                intval($ab['wr_count'] ?? 1)
            );
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . "] negated Member's [Live Start]; added $added card(s) from Waiting Room.");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_wr_distinct_lives_opp_choice') {
        $pickIds = $data['card_ids'] ?? [];
        $need = intval($prompt['pick_count'] ?? 2);
        if (count($pickIds) !== $need) {
            throw new Exception("Choose exactly $need Live cards");
        }
        $stash = [];
        $names = [];
        $rest = [];
        foreach ($ownerP['waiting_room'] as $c) {
            $id = $c['instance_id'] ?? '';
            if (in_array($id, $pickIds, true)) {
                $label = $c['name_en'] ?? $c['name'] ?? '';
                if (isset($names[$label])) {
                    throw new Exception('Live cards must have different names');
                }
                $names[$label] = true;
                $stash[] = $c;
            } else {
                $rest[] = $c;
            }
        }
        if (count($stash) !== $need) throw new Exception('Invalid Waiting Room selection');
        $ownerP['waiting_room'] = $rest;
        $opp = ($owner === 'p1') ? 'p2' : 'p1';
        $state['_wr_live_offer'] = $stash;
        $state['pending_prompt'] = [
            'type'          => 'opp_pick_wr_live_offer',
            'owner'         => $owner,
            'responder'     => $opp,
            'source_name'   => $prompt['source_name'] ?? 'Member',
            'candidates'    => array_map('cardPromptSummary', $stash),
            'prompt'        => 'Choose 1 Live card for your opponent to add to their hand.',
            'choices'       => $pickIds,
            'choice_labels' => array_map(fn($c) => cardDisplayName($c), $stash),
            'ability'       => $prompt['ability'] ?? [],
        ];
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'opp_pick_wr_live_offer') {
        $cardId = $choice;
        $stash = $state['_wr_live_offer'] ?? [];
        $picked = null;
        $leftover = [];
        foreach ($stash as $c) {
            if (($c['instance_id'] ?? '') === $cardId) {
                $picked = $c;
            } else {
                $leftover[] = $c;
            }
        }
        if (!$picked) throw new Exception('Invalid Live card choice');
        $ownerP['hand'][] = $picked;
        if (!empty($leftover)) {
            $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $leftover);
        }
        unset($state['_wr_live_offer']);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] received ' .
            cardDisplayName($picked) . ' from Waiting Room (opponent chose).');
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'activated_swap_area_pick') {
        $toSlot = $choice;
        if (!in_array($toSlot, ['left', 'center', 'right'], true)) {
            throw new Exception('Invalid area');
        }
        $fromSlot = $prompt['source_slot'] ?? '';
        $member = $ownerP['stage'][$fromSlot] ?? null;
        if (!$member) throw new Exception('Member not on Stage');
        $other = $ownerP['stage'][$toSlot] ?? null;
        $ownerP['stage'][$toSlot] = $member;
        $ownerP['stage'][$fromSlot] = $other;
        $idx = intval($prompt['ability_index'] ?? 0);
        markAbilityUsed($ownerP['stage'][$toSlot], $idx);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] moved to $toSlot area" .
            ($other ? ' (swapped).' : '.'));
        unset($state['pending_prompt']);
        $state = resolveAutoAreaMoveAbilities($state, $owner, $member['instance_id'] ?? '');
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_reveal_live_deck_bottom_surveil') {
        $step = $prompt['step'] ?? 'confirm';
        $lookN = intval($ability['look'] ?? 2);
        if ($step === 'confirm') {
            if (!isset(['yes' => true, 'no' => true][$choice])) {
                throw new Exception('Invalid choice');
            }
            if ($choice === 'no') {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return $state;
            }
            $liveHand = array_values(array_filter(
                $ownerP['hand'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if (empty($liveHand)) throw new Exception('Choose a Live card from your hand');
            $state['pending_prompt'] = [
                'type'          => 'optional_reveal_live_deck_bottom_surveil',
                'step'          => 'pick_hand_live',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'prompt'        => 'Reveal 1 Live card from your hand to put on the bottom of your deck.',
                'candidates'    => array_map('cardPromptSummary', $liveHand),
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_hand_live') {
            $cardId = $data['card_id'] ?? '';
            $picked = putHandLiveOnDeckBottom($ownerP, $cardId);
            if (!$picked) throw new Exception('Choose a Live card from your hand');
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] revealed ' .
                cardDisplayName($picked) . ' and put it on the bottom of the deck.');
            $top = array_splice($ownerP['main_deck'], 0, min($lookN, count($ownerP['main_deck'])));
            if (empty($top)) {
                unset($state['pending_prompt']);
                $state['seq']++;
                return $state;
            }
            if (count($top) === 1) {
                $ownerP['main_deck'] = array_merge($top, $ownerP['main_deck']);
                unset($state['pending_prompt']);
                $state['seq']++;
                return $state;
            }
            $state = startSurveilArrangePrompt(
                $state,
                $owner,
                $prompt['source_name'] ?? 'Member',
                $top
            );
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] looked at ' . count($top) . ' card(s).');
            return $state;
        }
    }

    if ($promptType === 'optional_wr_member_deck_top_blade') {
        $step = $prompt['step'] ?? 'confirm';
        $bladeAmt = intval($ability['blade_amount'] ?? 1);
        if ($step === 'confirm') {
            if (!isset(['yes' => true, 'no' => true][$choice])) {
                throw new Exception('Invalid choice');
            }
            if ($choice === 'no') {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped optional On Enter effect.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return $state;
            }
            $wrMembers = array_values(array_filter(
                $ownerP['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
            ));
            if (empty($wrMembers)) throw new Exception('No Member in Waiting Room');
            $state['pending_prompt'] = [
                'type'          => 'optional_wr_member_deck_top_blade',
                'step'          => 'pick_wr_member',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Live',
                'prompt'        => 'Choose 1 Member from your Waiting Room to put on top of your deck.',
                'candidates'    => array_map('cardPromptSummary', $wrMembers),
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_wr_member') {
            $cardId = $data['card_id'] ?? '';
            $picked = putWrMemberOnDeckTop($ownerP, $cardId);
            if (!$picked) throw new Exception('Choose a Member from Waiting Room');
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] put ' .
                cardDisplayName($picked) . ' on deck top.');
            $stageMembers = listStageMemberChoices($ownerP);
            if (empty($stageMembers)) {
                unset($state['pending_prompt']);
                $state['seq']++;
                return $state;
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_wr_member_deck_top_blade',
                'step'          => 'pick_stage_blade',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Live',
                'prompt'        => 'Choose 1 Stage Member to gain +' . $bladeAmt . ' Blade until this Live ends.',
                'candidates'    => $stageMembers,
                'blade_amount'  => $bladeAmt,
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_stage_blade') {
            $slot = $data['slot'] ?? '';
            if ($slot === '' || empty($ownerP['stage'][$slot])) {
                throw new Exception('Choose a Member on Stage');
            }
            $mbr = &$ownerP['stage'][$slot];
            $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $bladeAmt;
            unset($mbr);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] +$bladeAmt Blade on Stage Member until Live ends.");
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }
    }

    if ($promptType === 'player_choice_wr_live_deck_bottom_draw') {
        $step = $prompt['step'] ?? 'pick_player';
        $drawN = intval($ability['draw'] ?? 1);
        if ($step === 'pick_player') {
            if (!in_array($choice, ['self', 'opponent'], true)) {
                throw new Exception('Choose yourself or your opponent');
            }
            $targetPid = $choice === 'self' ? $owner : (($owner === 'p1') ? 'p2' : 'p1');
            $targetP = $state['players'][$targetPid];
            $lives = array_values(array_filter(
                $targetP['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if (empty($lives)) throw new Exception('No Live card in that player\'s Waiting Room');
            $state['pending_prompt'] = [
                'type'          => 'player_choice_wr_live_deck_bottom_draw',
                'step'          => 'pick_wr_live',
                'owner'         => $owner,
                'responder'     => $owner,
                'target'        => $targetPid,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'prompt'        => 'Choose 1 Live card from ' .
                    ($choice === 'self' ? 'your' : 'opponent\'s') . ' Waiting Room.',
                'candidates'    => array_map('cardPromptSummary', $lives),
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_wr_live') {
            $cardId = $data['card_id'] ?? '';
            $targetPid = $prompt['target'] ?? $owner;
            $targetP = &$state['players'][$targetPid];
            $picked = putWrLiveOnDeckBottom($targetP, $cardId);
            if (!$picked) throw new Exception('Choose a Live card from Waiting Room');
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] put ' .
                cardDisplayName($picked) . ' on the bottom of ' .
                $state['players'][$targetPid]['name'] . '\'s deck.');
            $drawn = drawCardsForPlayer($state, $owner, $drawN);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [" . ($prompt['source_name'] ?? 'Member') . "] drew $drawn.");
            unset($state['pending_prompt']);
            $state['seq']++;
            $phase = $state['phase'] ?? '';
            if ($phase === 'live_start_effects') {
                return finishLiveStartEffects($state);
            }
            return finishPromptEffects($state);
        }
    }

    if ($promptType === 'pay_energy_reveal_live_wr_superset') {
        $step = $prompt['step'] ?? 'reveal_hand_live';
        $slot = $prompt['slot'] ?? null;
        $abilityIdx = intval($prompt['ability_idx'] ?? 0);
        $srcName = $prompt['source_name'] ?? 'Member';

        if ($step === 'pick_wr_live') {
            $cardId = $data['card_id'] ?? $choice;
            $needle = $prompt['revealed_needle'] ?? '';
            $wrLive = null;
            foreach ($ownerP['waiting_room'] as $i => $c) {
                if (($c['instance_id'] ?? '') !== $cardId) continue;
                if (($c['card_type'] ?? '') !== 'ライブ') continue;
                $label = $c['name_en'] ?? $c['name'] ?? '';
                if ($needle !== '' && !wrLiveNameContainsNeedle($label, $needle)) {
                    throw new Exception('Choose a matching Live card from your Waiting Room');
                }
                $wrLive = $c;
                array_splice($ownerP['waiting_room'], $i, 1);
                break;
            }
            if (!$wrLive) {
                throw new Exception('Choose a Live card from your Waiting Room');
            }
            $ownerP['hand'][] = $wrLive;
            if ($slot !== null && !empty($ownerP['stage'][$slot])) {
                markAbilityUsed($ownerP['stage'][$slot], $abilityIdx);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] added ' . cardDisplayName($wrLive) . ' from Waiting Room to hand.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }

        $liveId = $data['card_id'] ?? $choice;
        $cost = intval($prompt['pay_cost'] ?? 2);
        if (!payEnergyCost($ownerP, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $revealed = null;
        foreach ($ownerP['hand'] as $c) {
            if (($c['instance_id'] ?? '') === $liveId && ($c['card_type'] ?? '') === 'ライブ') {
                $revealed = $c;
                break;
            }
        }
        if (!$revealed) {
            throw new Exception('Choose a Live card from your hand');
        }
        $needle = $revealed['name_en'] ?? $revealed['name'] ?? '';
        $wrLives = array_values(array_filter(
            $ownerP['waiting_room'],
            fn($c) => ($c['card_type'] ?? '') === 'ライブ'
                && wrLiveNameContainsNeedle($c['name_en'] ?? $c['name'] ?? '', $needle)
        ));
        if (empty($wrLives)) {
            if ($slot !== null && !empty($ownerP['stage'][$slot])) {
                markAbilityUsed($ownerP['stage'][$slot], $abilityIdx);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] revealed ' . cardDisplayName($revealed) .
                '; no matching Live in Waiting Room.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }
        if (count($wrLives) === 1) {
            $ownerP['hand'][] = $wrLives[0];
            $ownerP['waiting_room'] = array_values(array_filter(
                $ownerP['waiting_room'],
                fn($c) => ($c['instance_id'] ?? '') !== ($wrLives[0]['instance_id'] ?? '')
            ));
            if ($slot !== null && !empty($ownerP['stage'][$slot])) {
                markAbilityUsed($ownerP['stage'][$slot], $abilityIdx);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] revealed ' . cardDisplayName($revealed) .
                '; added ' . cardDisplayName($wrLives[0]) . ' from Waiting Room.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }
        $state['pending_prompt'] = [
            'type'            => 'pay_energy_reveal_live_wr_superset',
            'owner'           => $owner,
            'responder'       => $owner,
            'source_id'       => $prompt['source_id'] ?? '',
            'source_name'     => $srcName,
            'ability_idx'     => $abilityIdx,
            'slot'            => $slot,
            'pay_cost'        => $cost,
            'step'            => 'pick_wr_live',
            'revealed_needle' => $needle,
            'revealed_live'   => cardPromptSummary($revealed),
            'candidates'      => array_map('cardPromptSummary', $wrLives),
            'prompt'          => 'Choose 1 Live from your Waiting Room whose name contains ' .
                cardDisplayName($revealed) . '.',
        ];
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . $srcName . '] revealed ' . cardDisplayName($revealed) . ' (choose WR Live).');
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'buff_member_matching_discarded_group') {
        $slot = $data['slot'] ?? $choice;
        if ($slot === '' || empty($ownerP['stage'][$slot])) {
            throw new Exception('Choose a Member on Stage');
        }
        $mbr = &$ownerP['stage'][$slot];
        addBonusHeartsToMember($mbr, $prompt['hearts'] ?? [['color' => 'pink', 'count' => 1]]);
        unset($mbr);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] granted bonus heart(s) until Live ends.');
        unset($state['pending_prompt']);
        $state['seq']++;
        if (($state['phase'] ?? '') === 'live_start_effects') {
            return finishLiveStartEffects($state);
        }
        return $state;
    }

    if ($promptType === 'live_cost_from_subunit_pick') {
        $slot = $data['slot'] ?? $choice;
        $sourceId = $prompt['source_id'] ?? '';
        if ($slot === '' || empty($ownerP['stage'][$slot])) {
            throw new Exception('Choose a Member on Stage');
        }
        $picked = $ownerP['stage'][$slot];
        $newCost = max(0, intval($picked['cost'] ?? 0) - 1);
        foreach ($ownerP['stage'] as $s => &$mbr) {
            if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                $mbr['live_cost_override'] = $newCost;
                if ($newCost >= 10) {
                    $heartColor = $then['heart_color'] ?? 'any';
                    addBonusHeartsToModifier($state, $owner, [['color' => $heartColor, 'count' => 1]]);
                }
                break;
            }
        }
        unset($mbr);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] cost set to $newCost until Live ends.");
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'live_start_edel_choice') {
        if ($choice === 'no' || $choice === 'skip' || $choice === 'cancel') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped optional Live Start effect.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        if ($choice === 'reduce') {
            $liveId = $prompt['source_id'] ?? '';
            bumpLiveCardColorReduction(
                $state,
                $owner,
                $liveId,
                $ability['reduce_color'] ?? 'purple',
                1
            );
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] required purple hearts reduced by 1.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        if ($choice === 'play') {
            $subunit = $ability['subunit'] ?? 'Edel Note';
            $maxCost = intval($ability['max_cost'] ?? 4);
            $candidates = [];
            foreach ($ownerP['waiting_room'] as $c) {
                if (cardMatchesWrPick($c, [
                    'subunit'  => $subunit,
                    'filter'   => 'member',
                    'max_cost' => $maxCost,
                ])) {
                    $candidates[] = cardPromptSummary($c);
                }
            }
            if (empty($candidates)) throw new Exception('No matching Member in Waiting Room');
            $state['pending_prompt'] = [
                'type'        => 'live_start_edel_play_wr',
                'owner'       => $owner,
                'responder'   => $owner,
                'source_name' => $prompt['source_name'] ?? 'Live',
                'subunit'     => $subunit,
                'max_cost'    => $maxCost,
                'candidates'  => $candidates,
                'prompt'      => 'Choose 1 Edel Note Member from Waiting Room to play into an empty area.',
            ];
            $state['seq']++;
            return $state;
        }
        throw new Exception('Invalid choice');
    }

    if ($promptType === 'live_start_edel_play_wr') {
        $cardId = $data['card_id'] ?? $choice;
        $subunit = $prompt['subunit'] ?? 'Edel Note';
        $maxCost = intval($prompt['max_cost'] ?? 4);
        $played = null;
        $targetSlot = null;
        foreach (['left', 'center', 'right'] as $s) {
            if (empty($ownerP['stage'][$s])) {
                $targetSlot = $s;
                break;
            }
        }
        if ($targetSlot === null) throw new Exception('No empty Stage area');
        foreach ($ownerP['waiting_room'] as $i => $c) {
            if (($c['instance_id'] ?? '') !== $cardId) continue;
            if (!cardMatchesWrPick($c, [
                'subunit'  => $subunit,
                'filter'   => 'member',
                'max_cost' => $maxCost,
            ])) {
                throw new Exception('Invalid Member choice');
            }
            $played = $c;
            array_splice($ownerP['waiting_room'], $i, 1);
            break;
        }
        if (!$played) throw new Exception('Card not in Waiting Room');
        $played['active'] = true;
        $played['entered_turn'] = intval($state['turn'] ?? 1);
        $ownerP['stage'][$targetSlot] = $played;
        $state = resolveOnEnterAbilities($state, $owner, $played, $targetSlot);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . '] played ' .
            cardDisplayName($played) . ' from Waiting Room.');
        return returnAfterPlacedMemberEnter($state, true);
    }

    if ($promptType === 'wait_opponent_stage_pick') {
        if (($prompt['step'] ?? '') !== 'pick_opp_wait') {
            throw new Exception('Invalid wait opponent pick step');
        }
        $slot = $data['slot'] ?? '';
        $opp = $prompt['opp'] ?? (($owner === 'p1') ? 'p2' : 'p1');
        if ($slot === '' || empty($state['players'][$opp]['stage'][$slot])) {
            throw new Exception('Choose an opponent Member');
        }
        $maxCost = intval($prompt['max_cost'] ?? $ability['max_cost'] ?? 4);
        if (intval($state['players'][$opp]['stage'][$slot]['cost'] ?? 0) > $maxCost) {
            throw new Exception('Member cost too high');
        }
        waitOpponentMemberAtSlot($state, $opp, $slot, $owner);
        $mbr = $state['players'][$opp]['stage'][$slot];
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] put opponent ' .
            ($mbr['name_en'] ?? $mbr['name'] ?? 'Member') . ' into Wait.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishAfterBranchChoicePrompt($state, $prompt);
    }

    if ($promptType === 'live_start_center_cost_choice') {
        $step = $prompt['step'] ?? 'pick_mode';
        if ($step === 'pick_mode') {
            if (!in_array($choice, ['blade', 'wait_opp'], true)) {
                throw new Exception('Invalid choice');
            }
            if ($choice === 'blade') {
                $members = listStageMemberChoices($ownerP);
                if (empty($members)) throw new Exception('No Member on Stage');
                $state['pending_prompt'] = [
                    'type'          => 'live_start_center_cost_choice',
                    'step'          => 'pick_stage_blade',
                    'owner'         => $owner,
                    'responder'     => $owner,
                    'source_name'   => $prompt['source_name'] ?? 'Live',
                    'prompt'        => 'Choose 1 Stage Member to gain +' .
                        intval($ability['blade_amount'] ?? 2) . ' Blade until this Live ends.',
                    'candidates'    => $members,
                    'blade_amount'  => intval($ability['blade_amount'] ?? 2),
                    'ability'       => $ability,
                ];
            } else {
                $opp = ($owner === 'p1') ? 'p2' : 'p1';
                $members = listOppStageMembersByMaxCost(
                    $state,
                    $opp,
                    intval($ability['wait_opp_max_cost'] ?? 4)
                );
                if (empty($members)) throw new Exception('No valid opponent Member');
                $state['pending_prompt'] = [
                    'type'          => 'live_start_center_cost_choice',
                    'step'          => 'pick_opp_wait',
                    'owner'         => $owner,
                    'responder'     => $owner,
                    'opp'           => $opp,
                    'source_name'   => $prompt['source_name'] ?? 'Live',
                    'prompt'        => 'Choose 1 opponent Stage Member (cost ≤' .
                        intval($ability['wait_opp_max_cost'] ?? 4) . ') to put into Wait.',
                    'candidates'    => $members,
                    'ability'       => $ability,
                ];
            }
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_stage_blade') {
            $slot = $data['slot'] ?? '';
            if ($slot === '' || empty($ownerP['stage'][$slot])) {
                throw new Exception('Choose a Member on Stage');
            }
            $amt = intval($prompt['blade_amount'] ?? 2);
            $mbr = &$ownerP['stage'][$slot];
            $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $amt;
            unset($mbr);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] +$amt Blade on Stage Member until Live ends.");
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        if ($step === 'pick_opp_wait') {
            $slot = $data['slot'] ?? '';
            $opp = $prompt['opp'] ?? (($owner === 'p1') ? 'p2' : 'p1');
            if ($slot === '' || empty($state['players'][$opp]['stage'][$slot])) {
                throw new Exception('Choose an opponent Member');
            }
            $maxCost = intval($ability['wait_opp_max_cost'] ?? 4);
            if (intval($state['players'][$opp]['stage'][$slot]['cost'] ?? 0) > $maxCost) {
                throw new Exception('Member cost too high');
            }
            waitOpponentMemberAtSlot($state, $opp, $slot, $owner);
            $mbr = $state['players'][$opp]['stage'][$slot];
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] put opponent ' .
                ($mbr['name_en'] ?? $mbr['name'] ?? 'Member') . ' into Wait.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
    }

    if ($promptType === 'wait_swap_wr_member_center') {
        $step = $prompt['step'] ?? 'discard_hand';
        $group = $ability['group'] ?? '';
        $bonus = intval($ability['cost_bonus'] ?? 2);
        $sourceSlot = $prompt['source_slot'] ?? '';
        $abIdx = intval($prompt['ability_index'] ?? 0);
        if ($step === 'discard_hand') {
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== 1) {
                throw new Exception('Must discard exactly 1 card from hand');
            }
            discardFromHandByIds($ownerP, $ids);
            $others = listStageMemberChoices($ownerP, $group, $prompt['source_id'] ?? '');
            if (empty($others)) throw new Exception('No other group Member on Stage');
            $state['pending_prompt'] = [
                'type'          => 'wait_swap_wr_member_center',
                'step'          => 'pick_stage_member',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_id'     => $prompt['source_id'] ?? '',
                'source_slot'   => $sourceSlot,
                'ability_index' => $abIdx,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'prompt'        => 'Choose 1 other group Member on your Stage to put into the Waiting Room.',
                'candidates'    => $others,
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_stage_member') {
            $slot = $data['slot'] ?? '';
            if ($slot === '' || $slot === $sourceSlot || empty($ownerP['stage'][$slot])) {
                throw new Exception('Choose another Member on Stage');
            }
            $mbr = $ownerP['stage'][$slot];
            if ($group !== '' && ($mbr['group'] ?? '') !== $group) {
                throw new Exception('Must choose a group Member');
            }
            $targetCost = intval($mbr['cost'] ?? 0) + $bonus;
            $wrCands = [];
            foreach ($ownerP['waiting_room'] as $c) {
                if (($c['card_type'] ?? '') !== 'メンバー') continue;
                if ($group !== '' && ($c['group'] ?? '') !== $group) continue;
                if (intval($c['cost'] ?? 0) !== $targetCost) continue;
                $wrCands[] = cardPromptSummary($c);
            }
            if (empty($wrCands)) throw new Exception('No Waiting Room Member with cost ' . $targetCost);
            $ownerP['waiting_room'][] = $mbr;
            $ownerP['stage'][$slot] = null;
            $state = resolveOnLeaveStageAbilities($state, $owner, $mbr);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] put ' .
                cardDisplayName($mbr) . ' into the Waiting Room.');
            $state['pending_prompt'] = [
                'type'          => 'wait_swap_wr_member_center',
                'step'          => 'pick_wr_member',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_id'     => $prompt['source_id'] ?? '',
                'source_slot'   => $sourceSlot,
                'ability_index' => $abIdx,
                'target_slot'   => $slot,
                'target_cost'   => $targetCost,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'prompt'        => 'Play 1 Member from your Waiting Room with cost ' . $targetCost . '.',
                'candidates'    => $wrCands,
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_wr_member') {
            $cardId = $data['card_id'] ?? '';
            $targetSlot = $prompt['target_slot'] ?? '';
            $targetCost = intval($prompt['target_cost'] ?? 0);
            if ($targetSlot === '' || !empty($ownerP['stage'][$targetSlot])) {
                throw new Exception('Target Stage area is not empty');
            }
            $played = null;
            foreach ($ownerP['waiting_room'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $cardId
                    && ($c['card_type'] ?? '') === 'メンバー'
                    && ($group === '' || ($c['group'] ?? '') === $group)
                    && intval($c['cost'] ?? 0) === $targetCost) {
                    $played = $c;
                    array_splice($ownerP['waiting_room'], $i, 1);
                    break;
                }
            }
            if (!$played) throw new Exception('Choose a valid Member from Waiting Room');
            $played['active'] = true;
            $played['entered_turn'] = intval($state['turn'] ?? 1);
            $played['entered_from_wr'] = true;
            unset($played['entered_from_hand'], $played['entered_via_baton']);
            $ownerP['stage'][$targetSlot] = $played;
            $state = resolveOnEnterAbilities($state, $owner, $played, $targetSlot);
            if ($sourceSlot !== '' && !empty($ownerP['stage'][$sourceSlot])) {
                markAbilityUsed($ownerP['stage'][$sourceSlot], $abIdx);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] played ' .
                cardDisplayName($played) . ' from Waiting Room into the ' . $targetSlot . ' area.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return returnAfterPlacedMemberEnter($state);
        }
    }

    if ($promptType === 'activated_pick_on_enter_ability') {
        $abIdx = intval($choice);
        $onEnter = $prompt['on_enter'] ?? [];
        if (!isset($onEnter[$abIdx])) throw new Exception('Invalid ability');
        $discId = $prompt['discarded_id'] ?? '';
        $discarded = null;
        foreach ($ownerP['waiting_room'] as $c) {
            if (($c['instance_id'] ?? '') === $discId) {
                $discarded = $c;
                break;
            }
        }
        if (!$discarded) throw new Exception('Discarded Member not found');
        $slot = $prompt['source_slot'] ?? '';
        if ($slot !== '' && !empty($ownerP['stage'][$slot])) {
            markAbilityUsed($ownerP['stage'][$slot], intval($prompt['ability_index'] ?? 0));
        }
        $state = resolveAbilityEffect($state, $owner, $discarded, $onEnter[$abIdx], ['phase' => 'on_enter']);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] triggered [On Enter] ability ' .
            ($abIdx + 1) . '.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'auto_yell_no_live_retry') {
        unset($state['pending_prompt']);
        if ($choice === 'yes') {
            $state = executeYellRetry($state, $owner, $prompt);
            if (!empty($state['pending_prompt'])) {
                $state['phase'] = 'live_success_effects';
                $state['_performance_continue'] = $owner;
                $state['seq']++;
                return $state;
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — kept Yell cards (declined retry).');
        }
        $state['seq']++;
        if (!empty($state['_yell_retry_offers'])) {
            return openNextYellRetryPrompt($state);
        }
        return finishYellRetryAndHearts($state);
    }

    if ($promptType === 'opponent_text_answer') {
        $answerText = trim($data['answer_text'] ?? $data['text'] ?? '');
        if ($answerText === '') {
            throw new Exception('Type an answer');
        }
        $choice = classifyOpponentTextAnswer($ability, $answerText);
        $choices = $ability['choices'] ?? [];
        if (!isset($choices[$choice])) {
            $choice = 'other';
        }
        $outcomeLabel = opponentTextAnswerOutcomeLabel($choices[$choice] ?? []);
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' answered: "' . $answerText . '" → ' . $outcomeLabel . '.');
        $choiceEntry = $choices[$choice];
        $effect = $choiceEntry['effect'] ?? [];
        unset($state['pending_prompt']);
        $state = applyChoiceEffect($state, $owner, $ownerP, $effect, $prompt);
        if (!empty($state['pending_prompt'])) {
            return $state;
        }
        $state['seq']++;
        return finishAfterBranchChoicePrompt($state, $prompt);
    }

    if (!in_array($promptType, ['player_choice', 'opponent_choice'], true)) {
        throw new Exception('Unhandled prompt: ' . $promptType);
    }

    $choices = $ability['choices'] ?? [];
    if (!isset($choices[$choice])) throw new Exception('Invalid choice');

    $choiceEntry = $choices[$choice];
    $choiceLabel = playerChoiceLabelText(is_array($choiceEntry) ? $choiceEntry : []);
    $state = addLog($state, $state['players'][$owner]['name'] .
        ' — [' . ($prompt['source_name'] ?? 'Member') . '] chose: ' . $choiceLabel);

    $effect = $choiceEntry['effect'] ?? [];
    unset($state['pending_prompt']);
    $state = applyChoiceEffect($state, $owner, $ownerP, $effect, $prompt);
    if (!empty($state['pending_prompt'])) {
        return $state;
    }

    $state['seq']++;
    return finishAfterBranchChoicePrompt($state, $prompt);
}

function actionLiveStartChoice(array $state, string $pid, array $data): array {
    if ($state['phase'] !== 'live_start_effects') throw new Exception('Not resolving Live Start effects');

    $instanceId = $data['card_id'] ?? '';
    $abilityIdx = intval($data['ability_index'] ?? 0);
    $skip = !empty($data['skip']);

    $source = findLiveStartSourceCard($state, $pid, $instanceId);
    if (!$source) throw new Exception('Card not found on Stage or in Live storage');

    $abilities = $source['abilities'] ?? [];
    if (!isset($abilities[$abilityIdx])) throw new Exception('Invalid ability');
    $ab = $abilities[$abilityIdx];

    if (!$skip) {
        $ctx = [
            'discard_ids' => $data['discard_ids'] ?? [],
            'pay'         => !empty($data['pay']),
            'confirm'     => true,
        ];
        $state = resolveAbilityEffect($state, $pid, $source, $ab, $ctx);
    }

    if (empty($state['pending_prompt'])) {
        $state = finishLiveStartEffects($state);
    }
    $state['seq']++;
    return $state;
}

// ─────────────────────────────────────────────
// Live Start effect queue (live_start_effects phase)
// ─────────────────────────────────────────────

function beginLiveStartEffectPhase(array $state, bool $p1Attempt = true, bool $p2Attempt = true): array {
    $state['live_attempt'] = [];
    if ($p1Attempt) $state['live_attempt'][] = 'p1';
    if ($p2Attempt) $state['live_attempt'][] = 'p2';

    $state['live_round_success'] = [];
    foreach (['p1', 'p2'] as $pid) {
        if (!in_array($pid, $state['live_attempt'], true)) {
            $state['live_round_success'][$pid] = false;
        }
    }

    $state = initLiveModifiers($state);
    $state['phase'] = 'live_start_effects';
    if (performanceRoundHasLiveCards($state)) {
        $state = addLog($state, '=== Live Start Effects ===');
    }
    foreach ($state['live_attempt'] as $pid) {
        $state = resolveLiveStartAbilities($state, $pid);
        if (!empty($state['pending_prompt'])) {
            $state['_live_start_resume_from'] = $pid;
            if (!array_key_exists('live_start_optional_queue', $state)) {
                $state['live_start_optional_queue'] = collectOptionalLiveStartAbilities($state);
            }
            return $state;
        }
    }
    if (empty($state['live_start_optional_queue'])) {
        $state['live_start_optional_queue'] = collectOptionalLiveStartAbilities($state);
    }
    return finishLiveStartEffects($state);
}
