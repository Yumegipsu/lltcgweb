<?php
/**
 * BP07 "Mellow Moment" effect handlers (PL!N-bp7 / PL!S-bp7 / PL!SP-bp7 / LL-bp7).
 * Included by effects.php.
 *
 * Routine numeric/gate types live in LLTCG\Game\EffectHandlers (see EffectRegistry).
 * Everything with a prompt, a zone shuffle, or a board-wide aura lives here.
 */

function bp7EffectTypes(): array {
    return [
        // --- Nijigasaki ---
        'if_stage_subunit_count',
        'auto_on_energy_stacked_energy_wait',
        'activated_mill_stack_wr_member_copy_hearts',
        'blade_per_stacked_distinct_member',
        'activated_stack_energy_wait_opp_by_stacked_blade',
        'energy_deck_stack_under_member',
        'activated_mill_group_live_or_bladeless_choice',
        'hearts_per_stacked_energy',
        'hearts_per_energy_above_threshold',
        'optional_wr_bladeless_deck_bottom_activate_energy',
        'activated_stack_energy_play_wr_empty_wait',
        'auto_self_milled_discard_recover',
        'play_cost_reduction_if_shuffle_wr_members',
        'optional_wait_group_member_choose_heart',
        'auto_on_leave_add_from_wr',
        'auto_on_leave_baton_energy_deck_stack',
        'mill_then_heart_if_blade_colors',
        'auto_on_ally_wait_activate',
        'look_reveal_group_bladeless',
        'live_start_pick_group_member_blade',
        'live_success_score_if_yell_distinct_heart_colors',
        'live_start_discard_up_to_grant_members_blade',
        'live_success_score_if_yell_bladeless_members',
        'live_success_score_if_member_most_blade',
        'live_start_shuffle_wr_all_deck_bottom_group_hearts',
        'live_success_unstack_energy_score',
        'look_top_arrange_rest_wr',
        'live_success_return_self_to_hand_discard',
        'auto_on_live_success_mill_add_live_score',
        // --- Sunshine ---
        'optional_discard_add_wr_min_cost_named_blade',
        'look_top_optional_deck_bottom',
        'protect_group_from_opp_wait',
        'look_bottom_arrange_rest_wr',
        'stage_group_blade_if_stacked_member',
        'activated_discard_trigger_on_enter_self_and_other',
        'mill_then_heart_if_all_group_bottom',
        'add_from_wr_max_cost_named_play',
        'wr_group_members_deck_bottom_blade_per',
        'look_top_arrange_rest_bottom',
        'optional_mill_bottom_add_if_named',
        'front_opp_lose_blade_max_cost',
        'look_deck_bottom_optional_deck_position',
        'wait_self_mill_bottom_activate_blade_if_all_group',
        'optional_formation_change_group_subunit_blade',
        'choose_player_wr_members_deck_bottom',
        'hearts_if_opp_more_energy',
        'mill_then_heart_if_live_bottom',
        'hearts_if_min_stage_members',
        'mill_then_heart_if_min_cost_member_bottom',
        'position_change_to_center',
        'reduce_hearts_if_all_active',
        'mill_then_reduce_hearts_if_group_bottom',
        'mill_bottom_then_draw_score_by_members',
        'yell_from_deck_bottom',
        'live_success_score_if_yell_group_heart_colors',
        'live_start_return_energy_score_by_opp_gap',
        'wait_opponent_max_cost_skip_next_activate',
        // --- Liella! ---
        'stacked_under_grant_blade',
        'auto_on_leave_baton_stack_self_under',
        'stage_cost_bonus_if_min_energy_and_more_than_opp',
        'blade_per_stacked_member',
        'live_score_if_stacked_members',
        'reveal_hand_cost_stack_under_draw',
        'optional_wr_group_members_deck_bottom_blade_if_bladeless',
        'auto_on_enter_or_energy_returned_energy_wait_locked',
        'auto_on_energy_placed_blade',
        'optional_return_energy_to_deck',
        'live_score_if_energy_returned_this_turn',
        'auto_on_area_move_activate_self',
        'leave_stage_return_energy_add_from_wr',
        'optional_discard_hand_all_draw',
        'optional_wr_one_per_subunit_deck_bottom_draw',
        'auto_on_area_move_blade',
        'optional_discard_live_look_reveal',
        'blade_bonus_if_more_energy_than_opp',
        'live_start_shuffle_wr_group_members_deck_bottom_blade',
        'live_success_score_if_all_yell_group',
        // --- Cross-series ---
        'play_cost_set_if_discard_named',
    ];
}

function bp7IsEffectType(string $type): bool {
    return in_array($type, bp7EffectTypes(), true);
}

/** Continuous-only types: never dispatched as an effect, but must be discoverable. */
function bp7ContinuousOnlyTypes(): array {
    return [
        'hearts_per_stacked_energy',
        'hearts_per_energy_above_threshold',
        'hearts_if_opp_more_energy',
        'hearts_if_min_stage_members',
        'yell_from_deck_bottom',
        'stacked_under_grant_blade',
        'stage_group_blade_if_stacked_member',
        'front_opp_lose_blade_max_cost',
        'blade_per_stacked_member',
        'blade_bonus_if_more_energy_than_opp',
        'live_score_if_stacked_members',
        'stage_cost_bonus_if_min_energy_and_more_than_opp',
        'play_cost_reduction_if_shuffle_wr_members',
        'play_cost_set_if_discard_named',
        'protect_group_from_opp_wait',
    ];
}

/* ------------------------------------------------------------------ *
 * Shared helpers
 * ------------------------------------------------------------------ */

function bp7IsMemberCard(array $card): bool {
    return ($card['card_type'] ?? '') === 'メンバー' || ($card['card_type_en'] ?? '') === 'Member';
}

function bp7IsLiveCard(array $card): bool {
    return ($card['card_type'] ?? '') === 'ライブ' || ($card['card_type_en'] ?? '') === 'Live';
}

/** True when the Member prints at least one Blade heart (not printed Blade count). */
function bp7MemberHasBladeHearts(array $card): bool {
    foreach ($card['blade_hearts'] ?? [] as $h) {
        if ($h === null || $h === '') {
            continue;
        }
        if (is_array($h) && ($h['color'] ?? '') === '') {
            continue;
        }
        return true;
    }
    return false;
}

/** Official ブレードハートを持たない — Member with no Blade hearts (may still have Blade). */
function bp7IsBladelessMember(array $card): bool {
    return bp7IsMemberCard($card) && !bp7MemberHasBladeHearts($card);
}

function bp7StackedMembers(array $member): array {
    return array_values(array_filter($member['stacked_members'] ?? [], 'is_array'));
}

function bp7FlushPendingAllyWaits(array $state): array {
    if (!empty($state['pending_prompt'])) {
        return $state;
    }
    foreach (['p1', 'p2'] as $pid) {
        foreach ($state['players'][$pid]['stage'] ?? [] as $slot => $mbr) {
            if (!$mbr || empty($mbr['_ally_wait_pending'])) {
                continue;
            }
            unset($state['players'][$pid]['stage'][$slot]['_ally_wait_pending']);
            $state = bp7ResolveAutoOnAllyWait($state, $pid, $state['players'][$pid]['stage'][$slot]);
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
        }
    }
    return $state;
}

/** Detach Member cards under a host (do not append — caller places them). Nested stacks flatten. */
function bp7TakeStackedMembersFromHost(array &$member): array {
    $stacked = [];
    foreach ($member['stacked_members'] ?? [] as $sm) {
        if (!is_array($sm)) {
            continue;
        }
        $nested = bp7TakeStackedMembersFromHost($sm);
        unset($sm['stacked_members']);
        $stacked[] = $sm;
        foreach ($nested as $n) {
            if (is_array($n)) {
                $stacked[] = $n;
            }
        }
    }
    unset($member['stacked_members']);
    return $stacked;
}

function bp7StackedEnergyCount(array $member): int {
    // Legacy snapshots kept only id refs (`stacked_energy_ids`); never sum both.
    return max(
        count($member['stacked_energy'] ?? []),
        count($member['stacked_energy_ids'] ?? [])
    );
}

function bp7DistinctStackedMemberNames(array $member): int {
    $names = [];
    foreach (bp7StackedMembers($member) as $c) {
        $label = $c['name_en'] ?? $c['name'] ?? '';
        if ($label !== '') {
            $names[$label] = true;
        }
    }
    return count($names);
}

/** Distinct Blade heart colors printed on the Member cards in $cards. */
function bp7CountDistinctBladeHeartColors(array $cards): int {
    $colors = [];
    foreach ($cards as $c) {
        if (!is_array($c) || !bp7IsMemberCard($c)) {
            continue;
        }
        foreach ($c['blade_hearts'] ?? [] as $h) {
            $color = is_array($h) ? ($h['color'] ?? '') : (string)$h;
            if ($color !== '' && $color !== 'draw') {
                $colors[$color] = true;
            }
        }
    }
    return count($colors);
}

function bp7CardHasHeartColor(array $card, string $color): bool {
    foreach ($card['hearts'] ?? [] as $h) {
        if (($h['color'] ?? '') === $color) {
            return true;
        }
    }
    foreach ($card['blade_hearts'] ?? [] as $h) {
        $c = is_array($h) ? ($h['color'] ?? '') : (string)$h;
        if ($c === $color) {
            return true;
        }
    }
    return false;
}

function bp7SourceName(array $source): string {
    return $source['name_en'] ?? $source['name'] ?? 'Member';
}

/** Yell pool for the current resolution (ctx first, then the per-seat snapshots). */
function bp7YellCards(array $state, string $pid, array $ctx): array {
    $yell = $ctx['yell_cards'] ?? null;
    if (is_array($yell) && !empty($yell)) {
        return $yell;
    }
    $p = $state['players'][$pid] ?? [];
    return $p['yell_cards']
        ?? $state['yell_reveal'][$pid]
        ?? $state['_last_yell_cards']
        ?? [];
}

/** Mill from deck top into WR and fire the bp7 "this card was milled" auto hook. */
function bp7MillFromDeckTop(array $state, string $pid, int $count, string $srcName): array {
    $taken = takeFromMainDeckTop($state, $pid, $count);
    if (empty($taken)) {
        return [$state, []];
    }
    $state = appendCardsToWaitingRoom($state, $pid, $taken);
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$srcName] put " . count($taken) . ' card(s) from the top of the deck into the Waiting Room.');
    $state = bp7ResolveAutoSelfMilled($state, $pid, $taken);
    return [$state, $taken];
}

/** Mill from deck bottom into WR (BP07 Aqours "bottom N cards" pattern). */
function bp7MillFromDeckBottom(array $state, string $pid, int $count, string $srcName): array {
    $taken = takeFromMainDeckBottom($state, $pid, $count);
    if (empty($taken)) {
        return [$state, []];
    }
    $state = appendCardsToWaitingRoom($state, $pid, $taken);
    $labels = [];
    foreach ($taken as $c) {
        $labels[] = cardDisplayName($c);
    }
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$srcName] put " . implode(', ', $labels) .
        ' from the bottom of the deck into the Waiting Room.');
    $state = bp7ResolveAutoSelfMilled($state, $pid, $taken);
    return [$state, $taken];
}

function bp7TakeCardsFromWaitingRoom(array &$p, array $ids): array {
    $picked = [];
    foreach ($ids as $id) {
        if (!is_string($id) || $id === '') {
            continue;
        }
        foreach ($p['waiting_room'] as $i => $c) {
            if (($c['instance_id'] ?? '') === $id) {
                $picked[] = $c;
                array_splice($p['waiting_room'], $i, 1);
                break;
            }
        }
    }
    return $picked;
}

function bp7TakeCardsFromHand(array &$p, array $ids): array {
    $picked = [];
    foreach ($ids as $id) {
        if (!is_string($id) || $id === '') {
            continue;
        }
        foreach ($p['hand'] as $i => $c) {
            if (($c['instance_id'] ?? '') === $id) {
                $picked[] = $c;
                array_splice($p['hand'], $i, 1);
                break;
            }
        }
    }
    return $picked;
}

/**
 * Move $count Energy from the Energy zone back into the Energy deck.
 * Prefers Active chips so the available-energy HUD actually drops (GitHub #129).
 * $movedCards (if passed) is filled with the relocated Energy cards for log anims.
 */
function bp7ReturnEnergyToDeck(
    array &$state,
    string $pid,
    int $count,
    bool $activeOnly = false,
    ?array &$movedCards = null
): int {
    $p = &$state['players'][$pid];
    if (!isset($p['energy_deck']) || !is_array($p['energy_deck'])) {
        $p['energy_deck'] = [];
    }
    $moved = 0;
    $movedCards = [];
    $take = static function (bool $wantActive) use (&$p, &$moved, &$movedCards, $count): void {
        foreach ($p['energy_zone'] as $i => $e) {
            if ($moved >= $count) {
                return;
            }
            if (!is_array($e)) {
                continue;
            }
            $isActive = !empty($e['active']);
            if ($wantActive !== $isActive) {
                continue;
            }
            unset($e['active'], $e['skip_activate_next_turn']);
            $p['energy_deck'][] = $e;
            $movedCards[] = $e;
            unset($p['energy_zone'][$i]);
            $moved++;
        }
        $p['energy_zone'] = array_values($p['energy_zone']);
    };
    $take(true);
    if ($moved < $count && !$activeOnly) {
        $take(false);
    }
    if ($moved > 0) {
        shuffle($p['energy_deck']);
        $p['_bp7_energy_returned_turn'] = intval($state['turn'] ?? 0);
        unset($p);
        $state = bp7ResolveAutoEnergyReturnedToDeck($state, $pid);
    }
    return $moved;
}

/** True when this seat put Energy from the zone into the Energy deck this turn. */
function bp7EnergyReturnedThisTurn(array $state, string $pid): bool {
    $p = $state['players'][$pid] ?? [];
    return intval($p['_bp7_energy_returned_turn'] ?? -1) === intval($state['turn'] ?? 0);
}

/**
 * Put N Energy cards from the Energy deck into Wait. `$locked` marks them as skipping
 * the owner's next Active Phase (PL!SP-bp7-005 / -007 / -017 / -027).
 */
function bp7EnergyDeckToWait(array &$state, string $pid, int $count, bool $locked): int {
    $p = &$state['players'][$pid];
    $placed = 0;
    for ($i = 0; $i < $count; $i++) {
        if (empty($p['energy_deck'])) {
            break;
        }
        $e = array_shift($p['energy_deck']);
        $e['active'] = false;
        if ($locked) {
            $e['skip_activate_next_turn'] = true;
        }
        $p['energy_zone'][] = $e;
        $placed++;
    }
    if ($placed > 0) {
        $state = bp7ResolveAutoOnEnergyPlaced($state, $pid, $placed);
    }
    return $placed;
}

/** Attach N Energy cards straight from the Energy deck under a Stage Member. */
function bp7EnergyDeckUnderMember(array &$state, string $pid, string $slot, int $count): int {
    $p = &$state['players'][$pid];
    if (empty($p['stage'][$slot])) {
        return 0;
    }
    $taken = [];
    for ($i = 0; $i < $count; $i++) {
        if (empty($p['energy_deck'])) {
            break;
        }
        $taken[] = array_shift($p['energy_deck']);
    }
    if (empty($taken)) {
        return 0;
    }
    $member = $p['stage'][$slot];
    attachStackedEnergyCardsToMember($member, $taken);
    $p['stage'][$slot] = $member;
    $state = bp7ResolveAutoOnEnergyStackedUnderMember($state, $pid);
    return count($taken);
}

/**
 * When the Energy zone has more chips than needed, open Mia-style stack_energy_zone_pick.
 * Returns a new state with a prompt, or null to stack automatically.
 */
function bp7MaybeStartZoneEnergyPick(
    array $state,
    string $pid,
    array $source,
    array $ab,
    array $ctx,
    string $slot,
    int $need,
    string $then
): ?array {
    $p = $state['players'][$pid] ?? [];
    $cands = function_exists('energyZoneStackCandidates') ? energyZoneStackCandidates($p) : ($p['energy_zone'] ?? []);
    $cands = array_values(array_filter($cands, 'is_array'));
    if (count($cands) <= $need) {
        return null;
    }
    $state['pending_prompt'] = [
        'type'          => 'stack_energy_zone_pick',
        'owner'         => $pid,
        'responder'     => $pid,
        'source_id'     => $source['instance_id'] ?? '',
        'source_slot'   => $slot,
        'ability_index' => intval($ctx['ability_index'] ?? 0),
        'source_name'   => bp7SourceName($source),
        'energy_count'  => $need,
        'min_pick'      => $need,
        'max_pick'      => $need,
        'candidates'    => array_map('cardPromptSummary', $cands),
        'prompt'        => $need === 1
            ? 'Choose 1 Energy from your Energy Zone to place under this Member (unused Energy is listed first).'
            : "Choose $need Energy from your Energy Zone to place under this Member (unused Energy is listed first).",
        'ability'       => $ab,
        'then'          => $then,
        'live_start'    => !empty($ctx['live_start']),
    ];
    $state['seq']++;
    return addLog($state, $state['players'][$pid]['name'] .
        ' — [' . bp7SourceName($source) . '] choose Energy to stack.');
}

function bp7ContinueAfterZoneEnergyStacked(
    array $state,
    string $pid,
    array $source,
    array $ab,
    string $then,
    bool $liveStart = false
): array {
    $name = bp7SourceName($source);
    $srcId = (string)($source['instance_id'] ?? '');
    $slot = bp7FindSlotByInstance($state['players'][$pid], $srcId);
    if ($then === 'wait_opp_by_stacked_blade') {
        if ($slot === '') {
            return $state;
        }
        $stacked = bp7StackedEnergyCount($state['players'][$pid]['stage'][$slot]);
        $maxBlade = $stacked + intval($ab['blade_offset'] ?? 1);
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] Energy under this Member ($stacked total); wait an opponent Member with original Blade ≤$maxBlade.");
        return beginWaitOpponentStagePick($state, $pid, $name, [
            'max_original_blade' => $maxBlade,
            'pick_count'         => intval($ab['pick_count'] ?? 1),
        ], $srcId, $liveStart);
    }
    if ($then === 'play_wr_empty_wait') {
        $cands = array_values(array_filter(
            $state['players'][$pid]['waiting_room'] ?? [],
            fn($c) => is_array($c) && bp7IsMemberCard($c)
                && cardMatchesGroup($c, $ab['group'] ?? '', 'member')
                && intval($c['cost'] ?? 0) <= intval($ab['max_cost'] ?? 2)
        ));
        if (empty($cands)) {
            return addLog($state, $state['players'][$pid]['name'] .
                " — [$name] no eligible Waiting Room Member after stacking Energy.");
        }
        return bp7StartCardPick(
            $state, $pid, $source, $ab, ['live_start' => $liveStart], 'play_wr_empty_wait', $cands, 1, 1,
            'Choose a ' . ($ab['group'] ?? '') . ' Member card (cost ' .
            intval($ab['max_cost'] ?? 2) . ' or less) to play to an empty Stage area in Wait.'
        );
    }
    return $state;
}

/** Move active Energy from the zone under a Stage Member (cost-style "put 1 Energy under"). */
function bp7ZoneEnergyUnderMember(array &$state, string $pid, string $slot, int $count): int {
    $p = &$state['players'][$pid];
    if (empty($p['stage'][$slot])) {
        return 0;
    }
    $taken = takeActiveEnergyFromZone($p, $count);
    if (empty($taken)) {
        return 0;
    }
    $member = $p['stage'][$slot];
    attachStackedEnergyCardsToMember($member, $taken);
    $p['stage'][$slot] = $member;
    $state = bp7ResolveAutoOnEnergyStackedUnderMember($state, $pid);
    return count($taken);
}

/** @return list<array{slot:string,instance_id:string,name_en:string,cost:int}> */
function bp7StageMemberCandidates(array $p, array $cfg = []): array {
    $group = $cfg['group'] ?? '';
    $out = [];
    foreach ($p['stage'] as $slot => $mbr) {
        if (!$mbr) {
            continue;
        }
        if ($group !== '' && !cardMatchesGroup($mbr, $group, 'member')) {
            continue;
        }
        if (!empty($cfg['require_stacked_energy']) && bp7StackedEnergyCount($mbr) < 1) {
            continue;
        }
        if (!empty($cfg['exclude_id']) && ($mbr['instance_id'] ?? '') === $cfg['exclude_id']) {
            continue;
        }
        $out[] = [
            'slot'        => (string)$slot,
            'instance_id' => $mbr['instance_id'] ?? '',
            'name_en'     => $mbr['name_en'] ?? $mbr['name'] ?? 'Member',
            'name'        => $mbr['name'] ?? '',
            'card_no'     => $mbr['card_no'] ?? '',
            'cost'        => intval($mbr['cost'] ?? 0),
        ];
    }
    return $out;
}

function bp7FindSlotByInstance(array $p, string $iid): string {
    foreach ($p['stage'] as $slot => $mbr) {
        if ($mbr && ($mbr['instance_id'] ?? '') === $iid) {
            return (string)$slot;
        }
    }
    return '';
}

/** Score bump helper: BP07 "this card's score +N" always targets the performing Live card. */
function bp7BumpSelfScore(array &$state, string $pid, array $source, int $amount, string $srcName, string $why): void {
    $iid = $source['instance_id'] ?? '';
    if ($iid === '' || $amount === 0) {
        return;
    }
    if (!bumpLiveCardScore($state, $pid, $iid, $amount)) {
        $state = applyModifierEffect($state, $pid, ['type' => 'live_score_bonus', 'amount' => $amount]);
    }
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$srcName] score +$amount ($why).");
}

/** Queue a nested `then` effect once the gating/optional part resolved. */
function bp7ResolveThen(array $state, string $pid, array $source, array $ab, array $ctx): array {
    $then = $ab['then'] ?? null;
    if (!is_array($then) || empty($then['type'])) {
        return $state;
    }
    if (!isset($then['trigger']) && isset($ab['trigger'])) {
        $then['trigger'] = $ab['trigger'];
    }
    return resolveAbilityEffect($state, $pid, $source, $then, $ctx);
}

/* ------------------------------------------------------------------ *
 * Continuous hooks
 * ------------------------------------------------------------------ */

/** Extra Blade from BP07 continuous abilities on this Member (and auras aimed at it). */
function bp7ApplyContinuousBlade(array $state, string $pid, array $member, string $slot, array $ab): int {
    if (($ab['trigger'] ?? '') !== 'continuous') {
        return 0;
    }
    switch ($ab['type'] ?? '') {
        case 'blade_per_stacked_member':
            return count(bp7StackedMembers($member)) * intval($ab['amount'] ?? 1);
        case 'blade_bonus_if_more_energy_than_opp':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (countEnergyInZone($state['players'][$pid] ?? [])
                > countEnergyInZone($state['players'][$opp] ?? [])) {
                return intval($ab['amount'] ?? 2);
            }
            return 0;
    }
    return 0;
}

/**
 * Board auras that change a Member's Blade from *another* card:
 *  - PL!SP-bp7-001 while stacked under a Liella! Member (host gains +1)
 *  - PL!S-bp7-005 Aqours Stage Members with a Member card under them gain +1
 *  - PL!S-bp7-009 the opposing Member with cost ≤N loses 1 Blade
 */
function bp7ApplyBladeAuras(array $state, string $pid, array $member, string $slot): int {
    $delta = 0;
    $p = $state['players'][$pid] ?? [];

    foreach (bp7StackedMembers($member) as $stacked) {
        foreach ($stacked['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') continue;
            if (($ab['type'] ?? '') !== 'stacked_under_grant_blade') continue;
            $group = $ab['group'] ?? '';
            if ($group !== '' && !cardMatchesGroup($member, $group, 'member')) continue;
            $delta += intval($ab['amount'] ?? 1);
        }
    }

    if (!empty(bp7StackedMembers($member))) {
        foreach ($p['stage'] ?? [] as $other) {
            if (!$other) continue;
            foreach ($other['abilities'] ?? [] as $ab) {
                if (($ab['trigger'] ?? '') !== 'continuous') continue;
                if (($ab['type'] ?? '') !== 'stage_group_blade_if_stacked_member') continue;
                $group = $ab['group'] ?? '';
                if ($group !== '' && !cardMatchesGroup($member, $group, 'member')) continue;
                $delta += intval($ab['amount'] ?? 1);
            }
        }
    }

    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    $across = $slot !== '' ? ($state['players'][$opp]['stage'][$slot] ?? null) : null;
    if ($across) {
        foreach ($across['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') continue;
            if (($ab['type'] ?? '') !== 'front_opp_lose_blade_max_cost') continue;
            if (intval($member['cost'] ?? 0) > intval($ab['max_cost'] ?? 4)) continue;
            $delta -= intval($ab['amount'] ?? 1);
        }
    }
    return $delta;
}

/** Continuous hearts contributed by BP07 abilities on this Stage Member. */
function bp7ApplyContinuousHearts(
    array $state,
    string $pid,
    array $member,
    string $slot,
    array $ab,
    array $hearts
): array {
    if (($ab['trigger'] ?? '') !== 'continuous') {
        return $hearts;
    }
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    switch ($ab['type'] ?? '') {
        case 'hearts_per_stacked_energy':
            $n = bp7StackedEnergyCount($member);
            for ($i = 0; $i < $n; $i++) {
                appendContinuousHeartsFromSpec($hearts, $ab['hearts'] ?? []);
            }
            break;
        case 'hearts_per_energy_above_threshold':
            $above = countEnergyInZone($state['players'][$pid] ?? []) - intval($ab['threshold'] ?? 6);
            for ($i = 0; $i < max(0, $above); $i++) {
                appendContinuousHeartsFromSpec($hearts, $ab['hearts'] ?? []);
            }
            break;
        case 'hearts_if_opp_more_energy':
            if (countEnergyInZone($state['players'][$opp] ?? [])
                > countEnergyInZone($state['players'][$pid] ?? [])) {
                appendContinuousHeartsFromSpec($hearts, $ab['hearts'] ?? []);
            }
            break;
        case 'hearts_if_min_stage_members':
            if (countStageMembers($state['players'][$pid] ?? []) >= intval($ab['min_members'] ?? 3)) {
                appendContinuousHeartsFromSpec($hearts, $ab['hearts'] ?? []);
            }
            break;
        case 'if_stage_subunit_count':
            // PL!SP-bp7-013: continuous gate wrapping hearts_and_blade_bonus.
            $then = $ab['then'] ?? [];
            if (is_array($then) && !empty($then['hearts'])
                && bp7StageSubunitCountMet($state['players'][$pid] ?? [], $ab)) {
                appendContinuousHeartsFromSpec($hearts, $then['hearts']);
            }
            break;
    }
    return $hearts;
}

/** Continuous Live total score from BP07 abilities. */
function bp7ApplyContinuousLiveScore(array $state, string $pid, array $member, array $ab): int {
    if (($ab['trigger'] ?? '') !== 'continuous') {
        return 0;
    }
    if (($ab['type'] ?? '') === 'live_score_if_stacked_members') {
        if (count(bp7StackedMembers($member)) >= intval($ab['min_members'] ?? 3)) {
            return intval($ab['amount'] ?? 1);
        }
    }
    return 0;
}

/** Continuous Stage cost bonus (PL!SP-bp7-002). */
function bp7ApplyContinuousMemberCost(array $state, string $pid, array $member, int $base): int {
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') continue;
        if (($ab['type'] ?? '') !== 'stage_cost_bonus_if_min_energy_and_more_than_opp') continue;
        $mine = countEnergyInZone($state['players'][$pid] ?? []);
        if ($mine >= intval($ab['min_energy'] ?? 7)
            && $mine > countEnergyInZone($state['players'][$opp] ?? [])) {
            $base += intval($ab['amount'] ?? 2);
        }
    }
    return $base;
}

/** Subunit gate shared by `if_stage_subunit_count` (effect + continuous forms). */
function bp7StageSubunitCountMet(array $p, array $ab): bool {
    $subunit = (string)($ab['subunit'] ?? '');
    if ($subunit === '') {
        return false;
    }
    $min = intval($ab['min_count'] ?? 3);
    $distinct = !empty($ab['distinct_names']);
    $seen = [];
    $count = 0;
    foreach ($p['stage'] ?? [] as $mbr) {
        if (!$mbr || !bp7IsMemberCard($mbr)) continue;
        if (!cardMatchesSubunit($mbr, $subunit)) continue;
        if ($distinct) {
            $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
            if ($label === '' || isset($seen[$label])) continue;
            $seen[$label] = true;
        }
        $count++;
    }
    return $count >= $min;
}

/** PL!S-bp7-003: Aqours Members with low original Blade ignore opponent Wait effects. */
function bp7ProtectedFromOppWait(array $state, string $pid, array $member): bool {
    $lm = $state['live_modifiers'][$pid]['bp7_wait_protection'] ?? [];
    if (!is_array($lm) || empty($lm)) {
        return false;
    }
    foreach ($lm as $rule) {
        if (!is_array($rule)) continue;
        $group = $rule['group'] ?? '';
        if ($group !== '' && !cardMatchesGroup($member, $group, 'member')) continue;
        if (memberBladeIconCount($member) > intval($rule['max_original_blade'] ?? 3)) continue;
        return true;
    }
    return false;
}

/** PL!S-bp7-022: this seat performs Yell from the bottom of the deck. */
function bp7YellFromDeckBottom(array $state, string $pid): bool {
    foreach ($state['players'][$pid]['stage'] ?? [] as $mbr) {
        if (!$mbr) continue;
        foreach ($mbr['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') === 'continuous' && ($ab['type'] ?? '') === 'yell_from_deck_bottom') {
                return true;
            }
        }
    }
    return false;
}

/* ------------------------------------------------------------------ *
 * Play-cost hooks (continuous "when you play this card" cost changes)
 * ------------------------------------------------------------------ */

/**
 * PL!N-bp7-011 (shuffle every WR Member to deck bottom → cost −2) and
 * LL-bp7-001 (discard 1 each of 3 named Members → cost becomes 10) are optional
 * costs the player opts into before paying. The client sets `bp7_cost_opt` on the
 * play action; this returns the reduced cost when the option is legal + accepted.
 *
 * @return array{0:int,1:?array} adjusted cost + the option spec that applied
 */
function bp7AdjustHandPlayCost(array $state, string $pid, array $card, int $cost, array $opts = []): array {
    $p = $state['players'][$pid] ?? [];
    foreach ($card['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') continue;
        $type = $ab['type'] ?? '';
        if ($type === 'play_cost_reduction_if_shuffle_wr_members') {
            if (empty($opts['shuffle_wr_members'])) continue;
            if (!bp7WrHasMemberCards($p)) continue;
            return [max(0, $cost - intval($ab['amount'] ?? 2)), $ab];
        }
        if ($type === 'play_cost_set_if_discard_named') {
            $ids = $opts['discard_named_ids'] ?? [];
            if (!is_array($ids) || empty($ids)) continue;
            if (!bp7HandCoversNamedSet($p, $ab, $ids)) continue;
            return [max(0, intval($ab['cost'] ?? 10)), $ab];
        }
    }
    return [$cost, null];
}

function bp7WrHasMemberCards(array $p): bool {
    foreach ($p['waiting_room'] ?? [] as $c) {
        if (is_array($c) && bp7IsMemberCard($c)) {
            return true;
        }
    }
    return false;
}

/**
 * LL-bp7-001 needs `count_each` distinct hand Members per name group. The picked ids
 * must cover every listed name exactly once (names list holds EN+JP aliases).
 */
function bp7HandCoversNamedSet(array $p, array $ab, array $ids): bool {
    $names = $ab['names'] ?? [];
    $each = max(1, intval($ab['count_each'] ?? 1));
    $filter = $ab['filter'] ?? 'member';
    $byId = [];
    foreach ($p['hand'] ?? [] as $c) {
        $byId[$c['instance_id'] ?? ''] = $c;
    }
    $groups = bp7GroupNameAliases($names);
    $matched = array_fill(0, count($groups), 0);
    foreach ($ids as $id) {
        $card = $byId[$id] ?? null;
        if (!is_array($card)) {
            return false;
        }
        if ($filter === 'member' && !bp7IsMemberCard($card)) {
            return false;
        }
        $hit = -1;
        foreach ($groups as $gi => $aliases) {
            if (cardMatchesNames($card, $aliases)) {
                $hit = $gi;
                break;
            }
        }
        if ($hit < 0) {
            return false;
        }
        $matched[$hit]++;
    }
    foreach ($matched as $n) {
        if ($n !== $each) {
            return false;
        }
    }
    return count($ids) === count($groups) * $each;
}

/**
 * Name lists in the IR interleave EN + JP for the same character
 * (["Hanamaru Kunikida", "国木田花丸", "Setsuna Yuki", ...]) — pair them up so a
 * single hand card cannot satisfy two different characters.
 */
function bp7GroupNameAliases(array $names): array {
    $groups = [];
    $names = array_values(array_filter($names, 'is_string'));
    for ($i = 0; $i < count($names); $i += 2) {
        $group = [$names[$i]];
        if (isset($names[$i + 1])) {
            $group[] = $names[$i + 1];
        }
        $groups[] = $group;
    }
    return $groups;
}

/** Pay the opted-in play-cost option (shuffle WR Members / discard the named set). */
function bp7ApplyHandPlayCostOption(array $state, string $pid, array $card, array $ab, array $opts): array {
    $type = $ab['type'] ?? '';
    $name = bp7SourceName($card);
    $p = &$state['players'][$pid];
    if ($type === 'play_cost_reduction_if_shuffle_wr_members') {
        $members = [];
        $rest = [];
        foreach ($p['waiting_room'] as $c) {
            if (is_array($c) && bp7IsMemberCard($c)) {
                $members[] = $c;
            } else {
                $rest[] = $c;
            }
        }
        if (empty($members)) {
            return $state;
        }
        $p['waiting_room'] = $rest;
        shuffle($members);
        putCardsOnMainDeckBottom($state, $pid, $members);
        return addLog($state, $state['players'][$pid]['name'] .
            " — [$name] shuffled " . count($members) .
            ' Member card(s) from the Waiting Room onto the bottom of the deck (cost −' .
            intval($ab['amount'] ?? 2) . ').');
    }
    if ($type === 'play_cost_set_if_discard_named') {
        $picked = bp7TakeCardsFromHand($p, $opts['discard_named_ids'] ?? []);
        if (empty($picked)) {
            return $state;
        }
        $state = appendCardsToWaitingRoom($state, $pid, $picked);
        $labels = [];
        foreach ($picked as $c) {
            $labels[] = cardDisplayName($c);
        }
        return addLog($state, $state['players'][$pid]['name'] .
            " — [$name] put " . implode(', ', $labels) .
            ' into the Waiting Room (cost becomes ' . intval($ab['cost'] ?? 10) . ').');
    }
    return $state;
}

/* ------------------------------------------------------------------ *
 * Prompt builders
 * ------------------------------------------------------------------ */

function bp7PromptBase(array $state, string $pid, array $source, array $ab, array $ctx, string $action): array {
    $isLiveStart = ($ctx['phase'] ?? '') === 'live_start'
        || ($state['phase'] ?? '') === 'live_start_effects'
        || ($ab['trigger'] ?? '') === 'live_start';
    return [
        'owner'       => $pid,
        'responder'   => $pid,
        'source_id'   => $source['instance_id'] ?? '',
        'source_name' => bp7SourceName($source),
        'live_start'  => $isLiveStart,
        'bp7_action'  => $action,
        'ability'     => $ab,
    ];
}

function bp7StartConfirm(
    array $state,
    string $pid,
    array $source,
    array $ab,
    array $ctx,
    string $action,
    string $promptText,
    array $extra = [],
    string $yesLabel = 'Yes',
    string $noLabel = 'No — Skip'
): array {
    $state['pending_prompt'] = array_merge(
        bp7PromptBase($state, $pid, $source, $ab, $ctx, $action),
        [
            'type'          => 'bp7_confirm',
            'prompt'        => $promptText,
            'choices'       => ['yes', 'no'],
            'choice_labels' => [$yesLabel, $noLabel],
        ],
        $extra
    );
    $state['seq']++;
    return addLog($state, $state['players'][$pid]['name'] .
        ' — [' . bp7SourceName($source) . '] optional effect offered.');
}

/**
 * Card pick from a fixed candidate list (Waiting Room, hand, looked cards).
 * `pick_min`/`pick_max` bound the selection; the resolver reads `card_ids` in pick order.
 */
function bp7StartCardPick(
    array $state,
    string $pid,
    array $source,
    array $ab,
    array $ctx,
    string $action,
    array $candidates,
    int $min,
    int $max,
    string $promptText,
    array $extra = []
): array {
    $summaries = [];
    foreach ($candidates as $c) {
        if (!is_array($c)) continue;
        $summaries[] = cardPromptSummary($c);
    }
    $state['pending_prompt'] = array_merge(
        bp7PromptBase($state, $pid, $source, $ab, $ctx, $action),
        [
            'type'       => 'bp7_pick_cards',
            'prompt'     => $promptText,
            'candidates' => $summaries,
            'pick_min'   => max(0, $min),
            'pick_max'   => max(1, $max),
        ],
        $extra
    );
    $state['seq']++;
    return addLog($state, $state['players'][$pid]['name'] .
        ' — [' . bp7SourceName($source) . '] choose card(s).');
}

function bp7StartStagePick(
    array $state,
    string $pid,
    array $source,
    array $ab,
    array $ctx,
    string $action,
    array $candidates,
    string $promptText,
    array $extra = []
): array {
    $state['pending_prompt'] = array_merge(
        bp7PromptBase($state, $pid, $source, $ab, $ctx, $action),
        [
            'type'       => 'bp7_pick_stage_member',
            'prompt'     => $promptText,
            'candidates' => $candidates,
        ],
        $extra
    );
    $state['seq']++;
    return addLog($state, $state['players'][$pid]['name'] .
        ' — [' . bp7SourceName($source) . '] choose a Stage Member.');
}

function bp7StartChoices(
    array $state,
    string $pid,
    array $source,
    array $ab,
    array $ctx,
    string $action,
    array $choices,
    array $labels,
    string $promptText,
    array $extra = [],
    string $type = 'bp7_pick_slot'
): array {
    $state['pending_prompt'] = array_merge(
        bp7PromptBase($state, $pid, $source, $ab, $ctx, $action),
        [
            'type'          => $type,
            'prompt'        => $promptText,
            'choices'       => $choices,
            'choice_labels' => $labels,
        ],
        $extra
    );
    $state['seq']++;
    return addLog($state, $state['players'][$pid]['name'] .
        ' — [' . bp7SourceName($source) . '] choose an option.');
}

/* ------------------------------------------------------------------ *
 * Activated entry point
 * ------------------------------------------------------------------ */

/**
 * [Activated] hook for BP07 abilities, called from `actionActivateAbility`.
 *
 * `actionActivateAbility` runs its own per-product dispatch chain before a legacy
 * type switch, so BP07 activated types are unreachable from `resolveAbilityEffect`
 * alone and would raise "Ability type not implemented".
 *
 * @return array|null New state, or null when `$ab` is not a BP07 activated type.
 */
function bp7ResolveActivatedAbility(
    array $state,
    string $pid,
    array &$p,
    array $member,
    $slot,
    array $ab,
    int|string $abilityIdx,
    array $data
): ?array {
    $type = $ab['type'] ?? '';
    if (!bp7IsEffectType($type) || in_array($type, bp7ContinuousOnlyTypes(), true)) {
        return null;
    }

    // `once_per_turn` is checked by the caller; `max_uses_per_turn` is ours to enforce.
    $maxUses = intval($ab['max_uses_per_turn'] ?? 0);
    $useKey = '_auto_uses_' . $abilityIdx;
    if ($maxUses > 0 && intval($member[$useKey] ?? 0) >= $maxUses) {
        throw new Exception('Ability already used the maximum number of times this turn');
    }

    $cost = intval($ab['energy_cost'] ?? 0);
    if ($cost > 0 && !payEnergyCost($p, $cost)) {
        throw new Exception("Need $cost active Energy");
    }

    // Costs are paid inside the effect, so commit the use counters before resolving:
    // an abandoned prompt must not hand back a free re-mill / re-discard.
    if (!empty($ab['once_per_turn'])) {
        markAbilityUsed($member, $abilityIdx);
    }
    if ($maxUses > 0) {
        $member[$useKey] = intval($member[$useKey] ?? 0) + 1;
    }
    if ($slot !== null && $slot !== '' && array_key_exists($slot, $p['stage'] ?? [])) {
        $p['stage'][$slot] = $member;
    }

    $state = bp7ResolveEffect($state, $pid, $member, $ab, [
        'slot'          => $slot ?? '',
        'phase'         => 'activated',
        'ability_index' => $abilityIdx,
        'data'          => $data,
    ]);

    if (!empty($state['pending_prompt'])) {
        $state['pending_prompt']['ability_index'] = $abilityIdx;
        $state['pending_prompt']['source_slot'] = $slot ?? '';
    }
    return $state;
}

/* ------------------------------------------------------------------ *
 * Effect resolution
 * ------------------------------------------------------------------ */

function bp7ResolveEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (!bp7IsEffectType($type) || in_array($type, bp7ContinuousOnlyTypes(), true)) {
        // Continuous-only types are read by the Blade/heart/cost hooks, never dispatched.
        if ($type === 'protect_group_from_opp_wait') {
            $state = initLiveModifiers($state);
            $state['live_modifiers'][$pid]['bp7_wait_protection'][] = [
                'group'              => $ab['group'] ?? '',
                'max_original_blade' => intval($ab['max_original_blade'] ?? 3),
            ];
            return addLog($state, $state['players'][$pid]['name'] .
                ' — [' . bp7SourceName($source) . '] ' . ($ab['group'] ?? 'Group') .
                ' Members with original Blade ' . intval($ab['max_original_blade'] ?? 3) .
                ' or less are protected from opponent Wait effects until this Live ends.');
        }
        return $state;
    }
    $name = bp7SourceName($source);
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    $srcId = $source['instance_id'] ?? '';

    switch ($type) {

        /* ---------------- Shared gates ---------------- */

        case 'if_stage_subunit_count': {
            // PL!N-bp7-024 / PL!SP-bp7-019 resolve `then` on entry; PL!SP-bp7-013 uses the
            // same shape as a continuous gate, which the hearts hook reads instead.
            if (($ab['trigger'] ?? '') === 'continuous') break;
            if (!bp7StageSubunitCountMet($state['players'][$pid] ?? [], $ab)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] fewer than " . intval($ab['min_count'] ?? 3) . ' ' .
                    ($ab['subunit'] ?? '') . ' Members on Stage.');
                break;
            }
            $state = bp7ResolveThen($state, $pid, $source, $ab, $ctx);
            break;
        }

        /* ---------------- Nijigasaki ---------------- */

        case 'activated_mill_stack_wr_member_copy_hearts': {
            if (!empty($state['pending_prompt'])) break;
            [$state, ] = bp7MillFromDeckTop($state, $pid, intval($ab['mill'] ?? 5), $name);
            $cands = array_values(array_filter(
                $state['players'][$pid]['waiting_room'],
                fn($c) => is_array($c) && bp7IsMemberCard($c)
                    && cardMatchesGroup($c, $ab['group'] ?? '', 'member')
                    && intval($c['cost'] ?? 0) <= intval($ab['max_cost'] ?? 17)
            ));
            if (empty($cands)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no eligible Member in the Waiting Room to put under this Member.");
                break;
            }
            $state = bp7StartCardPick(
                $state, $pid, $source, $ab, $ctx, 'stack_wr_copy_hearts', $cands, 0, 1,
                'Put 1 ' . ($ab['group'] ?? '') . ' Member card (cost ' .
                intval($ab['max_cost'] ?? 17) . ' or less) from your Waiting Room under this Member.'
            );
            break;
        }

        case 'blade_per_stacked_distinct_member': {
            $slot = bp7FindSlotByInstance($state['players'][$pid], $srcId);
            $live = $slot !== '' ? $state['players'][$pid]['stage'][$slot] : $source;
            $bonus = bp7DistinctStackedMemberNames($live) * intval($ab['amount'] ?? 1);
            if ($bonus <= 0) break;
            if ($slot !== '') {
                $state['players'][$pid]['stage'][$slot]['live_blade_bonus'] =
                    intval($state['players'][$pid]['stage'][$slot]['live_blade_bonus'] ?? 0) + $bonus;
            } else {
                $state = applyModifierEffect($state, $pid, ['type' => 'blade_bonus', 'amount' => $bonus]);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] gains +$bonus Blade (differently named Member cards underneath).");
            break;
        }

        case 'activated_stack_energy_wait_opp_by_stacked_blade': {
            if (!empty($state['pending_prompt'])) break;
            $slot = bp7FindSlotByInstance($state['players'][$pid], $srcId);
            if ($slot === '') break;
            $need = intval($ab['energy'] ?? 1);
            $picked = bp7MaybeStartZoneEnergyPick($state, $pid, $source, $ab, $ctx, $slot, $need, 'wait_opp_by_stacked_blade');
            if ($picked !== null) {
                $state = $picked;
                break;
            }
            $moved = bp7ZoneEnergyUnderMember($state, $pid, $slot, $need);
            if ($moved < $need) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] not enough active Energy to put under this Member.");
                break;
            }
            $state = bp7ContinueAfterZoneEnergyStacked($state, $pid, $source, $ab, 'wait_opp_by_stacked_blade', !empty($ctx['live_start']));
            break;
        }

        case 'energy_deck_stack_under_member': {
            if (!empty($state['pending_prompt'])) break;
            $count = max(1, intval($ab['count'] ?? 1));
            if (($ab['target'] ?? '') === 'self') {
                $slot = bp7FindSlotByInstance($state['players'][$pid], $srcId);
                if ($slot === '') break;
                $n = bp7EnergyDeckUnderMember($state, $pid, $slot, $count);
                if ($n > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] put $n Energy card(s) from the Energy deck under this Member.");
                }
                break;
            }
            $cands = bp7StageMemberCandidates($state['players'][$pid], ['group' => $ab['group'] ?? '']);
            if (empty($cands) || empty($state['players'][$pid]['energy_deck'])) {
                break;
            }
            if (!empty($ab['optional'])) {
                $state = bp7StartConfirm(
                    $state, $pid, $source, $ab, $ctx, 'energy_deck_stack_optional',
                    'Put ' . $count . ' Energy card(s) from your Energy deck under a ' .
                    ($ab['group'] ?? '') . ' Member on your Stage?'
                );
                break;
            }
            if (count($cands) === 1) {
                $n = bp7EnergyDeckUnderMember($state, $pid, $cands[0]['slot'], $count);
                if ($n > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] put $n Energy card(s) from the Energy deck under " .
                        $cands[0]['name_en'] . '.');
                }
                break;
            }
            $state = bp7StartStagePick(
                $state, $pid, $source, $ab, $ctx, 'energy_deck_stack_pick', $cands,
                'Choose a ' . ($ab['group'] ?? '') .
                ' Member to put ' . $count . ' Energy card(s) from your Energy deck under.'
            );
            break;
        }

        case 'activated_mill_group_live_or_bladeless_choice': {
            if (!empty($state['pending_prompt'])) break;
            [$state, $milled] = bp7MillFromDeckTop($state, $pid, intval($ab['mill'] ?? 3), $name);
            $group = $ab['group'] ?? '';
            $hit = false;
            foreach ($milled as $c) {
                if (bp7IsLiveCard($c) && cardMatchesGroup($c, $group, 'live')) { $hit = true; break; }
                if (bp7IsBladelessMember($c) && cardMatchesGroup($c, $group, 'member')) { $hit = true; break; }
            }
            if (!$hit) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no $group Live card or $group Member with no Blade hearts among the milled cards.");
                break;
            }
            $state = resolveAbilityEffect($state, $pid, $source, [
                'type'    => 'player_choice',
                'trigger' => $ab['trigger'] ?? 'activated',
                'prompt'  => $ab['prompt'] ?? 'Choose one:',
                'choices' => $ab['choices'] ?? [],
            ], $ctx);
            break;
        }

        case 'optional_wr_bladeless_deck_bottom_activate_energy': {
            if (!empty($state['pending_prompt'])) break;
            $cands = array_values(array_filter(
                $state['players'][$pid]['waiting_room'],
                fn($c) => is_array($c) && bp7IsBladelessMember($c)
            ));
            if (empty($cands)) break;
            $max = max(1, intval($ab['max_pick'] ?? 4));
            $state = bp7StartCardPick(
                $state, $pid, $source, $ab, $ctx, 'wr_bladeless_bottom_activate', $cands, 0, $max,
                "You may put up to $max Member card(s) with no Blade hearts from your Waiting Room on the bottom " .
                'of your deck (pick order = deck order): activate 1 Energy for each.'
            );
            break;
        }

        case 'activated_stack_energy_play_wr_empty_wait': {
            if (!empty($state['pending_prompt'])) break;
            $slot = bp7FindSlotByInstance($state['players'][$pid], $srcId);
            if ($slot === '') break;
            $emptySlots = bp7EmptyPlayableSlots($state['players'][$pid], intval($state['turn'] ?? 1));
            $cands = array_values(array_filter(
                $state['players'][$pid]['waiting_room'],
                fn($c) => is_array($c) && bp7IsMemberCard($c)
                    && cardMatchesGroup($c, $ab['group'] ?? '', 'member')
                    && intval($c['cost'] ?? 0) <= intval($ab['max_cost'] ?? 2)
            ));
            if (empty($emptySlots) || empty($cands)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no empty Stage area or eligible Waiting Room Member.");
                break;
            }
            $need = intval($ab['energy'] ?? 1);
            $picked = bp7MaybeStartZoneEnergyPick($state, $pid, $source, $ab, $ctx, $slot, $need, 'play_wr_empty_wait');
            if ($picked !== null) {
                $state = $picked;
                break;
            }
            $moved = bp7ZoneEnergyUnderMember($state, $pid, $slot, $need);
            if ($moved < $need) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] not enough active Energy to put under this Member.");
                break;
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] put $moved Energy under this Member.");
            $state = bp7ContinueAfterZoneEnergyStacked($state, $pid, $source, $ab, 'play_wr_empty_wait', !empty($ctx['live_start']));
            break;
        }

        case 'optional_wait_group_member_choose_heart': {
            if (!empty($state['pending_prompt'])) break;
            $cands = bp7StageMemberCandidates($state['players'][$pid], ['group' => $ab['group'] ?? '']);
            $cands = array_values(array_filter(
                $cands,
                fn($c) => !memberIsInWait($state['players'][$pid]['stage'][$c['slot']] ?? [])
            ));
            if (empty($cands)) break;
            $state = bp7StartStagePick(
                $state, $pid, $source, $ab, $ctx, 'wait_group_member_choose_heart', $cands,
                'You may put 1 ' . ($ab['group'] ?? '') .
                ' Member into Wait: choose a heart color to gain until this Live ends.',
                ['allow_skip' => true]
            );
            break;
        }

        case 'auto_on_leave_add_from_wr': {
            $state = resolveAbilityEffect($state, $pid, $source, [
                'type'    => 'add_from_wr',
                'trigger' => 'on_leave',
                'group'   => $ab['group'] ?? '',
                'filter'  => $ab['filter'] ?? '',
                'count'   => intval($ab['count'] ?? 1),
            ], $ctx);
            break;
        }

        case 'look_reveal_group_bladeless': {
            if (!empty($state['pending_prompt'])) break;
            $looked = takeFromMainDeckTop($state, $pid, max(1, intval($ab['look'] ?? 5)));
            if (empty($looked)) break;
            $state['_bp7_look_stash'] = $looked;
            $cands = array_values(array_filter(
                $looked,
                fn($c) => bp7IsBladelessMember($c)
                    && cardMatchesGroup($c, $ab['group'] ?? '', 'member')
            ));
            if (empty($cands)) {
                unset($state['_bp7_look_stash']);
                $state = appendDeckCardsToWaitingRoom($state, $pid, $looked);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at " . count($looked) .
                    ' card(s); no ' . ($ab['group'] ?? '') .
                    ' Member with no Blade hearts to reveal. Rest to the Waiting Room.');
                break;
            }
            $state = bp7StartCardPick(
                $state, $pid, $source, $ab, $ctx, 'look_reveal_bladeless', $cands, 0,
                max(1, intval($ab['pick'] ?? 1)),
                'You may reveal 1 ' . ($ab['group'] ?? '') .
                ' Member card with no Blade hearts and add it to your hand. The rest go to the Waiting Room.'
            );
            break;
        }

        case 'live_start_pick_group_member_blade': {
            if (!empty($state['pending_prompt'])) break;
            $cands = bp7StageMemberCandidates($state['players'][$pid], ['group' => $ab['group'] ?? '']);
            $cands = array_values(array_filter(
                $cands,
                fn($c) => !memberIsInWait($state['players'][$pid]['stage'][$c['slot']] ?? [])
            ));
            $n = max(1, intval($ab['count'] ?? $ab['max_members'] ?? 1));
            $amount = intval($ab['amount'] ?? 1);
            if (empty($cands)) break;
            if (count($cands) <= $n) {
                foreach ($cands as $cand) {
                    $slot = $cand['slot'];
                    $state['players'][$pid]['stage'][$slot]['live_blade_bonus'] =
                        intval($state['players'][$pid]['stage'][$slot]['live_blade_bonus'] ?? 0) + $amount;
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] " . count($cands) . ' ' . ($ab['group'] ?? '') .
                    " Member(s) gained +$amount Blade until this Live ends.");
                break;
            }
            $state = bp7StartStagePick(
                $state, $pid, $source, $ab, $ctx, 'give_group_member_blade', $cands,
                'Choose ' . $n . ' ' . ($ab['group'] ?? '') .
                " Member(s) to give +$amount Blade until this Live ends.",
                ['pick_min' => $n, 'pick_max' => $n]
            );
            break;
        }

        case 'mill_then_heart_if_blade_colors': {
            [$state, $milled] = bp7MillFromDeckTop($state, $pid, intval($ab['count'] ?? 3), $name);
            $colors = bp7CountDistinctBladeHeartColors($milled);
            if ($colors >= intval($ab['min_colors'] ?? 2)) {
                addBonusHeartsToModifier($state, $pid, $ab['hearts'] ?? []);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] gained bonus heart(s) ($colors different Blade heart colors milled).");
            } else {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] only $colors different Blade heart color(s) milled; no heart gained.");
            }
            break;
        }

        case 'live_success_score_if_yell_distinct_heart_colors': {
            $want = $ab['colors'] ?? ['pink', 'red', 'yellow', 'green', 'blue', 'purple'];
            $found = [];
            foreach (bp7YellCards($state, $pid, $ctx) as $c) {
                if (!is_array($c)) continue;
                foreach ($want as $color) {
                    if (bp7CardHasHeartColor($c, $color)) {
                        $found[$color] = true;
                    }
                }
            }
            if (count($found) >= intval($ab['min_colors'] ?? 3)) {
                bp7BumpSelfScore($state, $pid, $source, intval($ab['amount'] ?? 1), $name,
                    count($found) . ' different Yell heart colors');
            }
            break;
        }

        case 'live_start_discard_up_to_grant_members_blade': {
            if (!empty($state['pending_prompt'])) break;
            $hand = $state['players'][$pid]['hand'] ?? [];
            $members = bp7StageMemberCandidates($state['players'][$pid], ['group' => $ab['group'] ?? '']);
            if (empty($hand) || empty($members)) break;
            $max = min(count($hand), max(1, intval($ab['max_discard'] ?? 2)));
            $state = bp7StartCardPick(
                $state, $pid, $source, $ab, $ctx, 'discard_up_to_grant_blade', $hand, 0, $max,
                "You may put up to $max card(s) from your hand into the Waiting Room: that many " .
                ($ab['group'] ?? '') . ' Members on your Stage gain +' . intval($ab['amount'] ?? 1) . ' Blade.',
                ['pick_zone' => 'hand']
            );
            break;
        }

        case 'live_success_score_if_yell_bladeless_members': {
            $n = 0;
            foreach (bp7YellCards($state, $pid, $ctx) as $c) {
                if (is_array($c) && bp7IsBladelessMember($c)) $n++;
            }
            if ($n >= intval($ab['min_count'] ?? 2)) {
                bp7BumpSelfScore($state, $pid, $source, intval($ab['amount'] ?? 1), $name,
                    "$n Member card(s) with no Blade hearts revealed by Yell");
            }
            break;
        }

        case 'live_success_score_if_member_most_blade': {
            if (!empty($state['pending_prompt'])) break;
            $cands = bp7StageMemberCandidates($state['players'][$pid], ['group' => $ab['group'] ?? '']);
            if (empty($cands)) break;
            if (count($cands) === 1) {
                $state = bp7ApplyMostBladeScore($state, $pid, $source, $ab, $cands[0]['slot'], $name);
                break;
            }
            $state = bp7StartStagePick(
                $state, $pid, $source, $ab, $ctx, 'score_if_member_most_blade', $cands,
                'Choose 1 ' . ($ab['group'] ?? '') .
                ' Member — if it has more Blade than every other Member on both Stages, this card\'s score +' .
                intval($ab['amount'] ?? 1) . '.'
            );
            break;
        }

        case 'live_start_shuffle_wr_all_deck_bottom_group_hearts': {
            if (!empty($state['pending_prompt'])) break;
            $wr = $state['players'][$pid]['waiting_room'] ?? [];
            if (empty($wr)) break;
            if (!empty($ab['require_wr_live'])) {
                $ok = false;
                foreach ($wr as $c) {
                    if (is_array($c) && bp7IsLiveCard($c) && cardMatchesGroup($c, $ab['group'] ?? '', 'live')) {
                        $ok = true; break;
                    }
                }
                if (!$ok) break;
            }
            if (!empty($ab['require_wr_bladeless_member'])) {
                $ok = false;
                foreach ($wr as $c) {
                    if (is_array($c) && bp7IsBladelessMember($c)
                        && cardMatchesGroup($c, $ab['group'] ?? '', 'member')) {
                        $ok = true; break;
                    }
                }
                if (!$ok) break;
            }
            $state = bp7StartConfirm(
                $state, $pid, $source, $ab, $ctx, 'shuffle_wr_all_bottom_group_hearts',
                'Shuffle every card in your Waiting Room and put them on the bottom of your deck? ' .
                'If you do, every ' . ($ab['group'] ?? '') . ' Member on your Stage gains bonus heart(s).'
            );
            break;
        }

        case 'live_success_unstack_energy_score': {
            if (!empty($state['pending_prompt'])) break;
            $cands = bp7StageMemberCandidates($state['players'][$pid], ['require_stacked_energy' => true]);
            if (empty($cands)) break;
            $state = bp7StartStagePick(
                $state, $pid, $source, $ab, $ctx, 'unstack_energy_score', $cands,
                'You may put every Energy card under 1 Member into your Energy zone in Wait. ' .
                'If you have ' . intval($ab['min_energy'] ?? 10) . '+ Energy afterwards, this card\'s score +' .
                intval($ab['amount'] ?? 1) . '.',
                ['allow_skip' => true]
            );
            break;
        }

        case 'look_top_arrange_rest_wr': {
            if (!empty($state['pending_prompt'])) break;
            $looked = takeFromMainDeckTop($state, $pid, max(1, intval($ab['look'] ?? 3)));
            if (empty($looked)) break;
            $state = startSurveilArrangePrompt($state, $pid, $name, $looked, ['target' => $pid], $srcId);
            $state['seq']++;
            break;
        }

        case 'live_success_return_self_to_hand_discard': {
            if (!empty($state['pending_prompt'])) break;
            $moved = bp7ReturnLiveCardToHand($state, $pid, $srcId);
            if (!$moved) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] could not be returned from Live storage.");
                break;
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] returned from Live storage to hand.");
            $need = max(1, intval($ab['discard'] ?? 1));
            if (count($state['players'][$pid]['hand'] ?? []) < $need) break;
            $state = startEffectDiscardHandPrompt($state, $pid, $name, $need,
                "Put $need card(s) from your hand into the Waiting Room.");
            break;
        }

        /* ---------------- Sunshine ---------------- */

        case 'optional_discard_add_wr_min_cost_named_blade': {
            if (!empty($state['pending_prompt'])) break;
            $hand = $state['players'][$pid]['hand'] ?? [];
            $wrHit = false;
            foreach ($state['players'][$pid]['waiting_room'] ?? [] as $c) {
                if (is_array($c) && bp7IsMemberCard($c)
                    && intval($c['cost'] ?? 0) >= intval($ab['min_cost'] ?? 10)) {
                    $wrHit = true; break;
                }
            }
            if (empty($hand) || !$wrHit) break;
            $state = bp7StartCardPick(
                $state, $pid, $source, $ab, $ctx, 'discard_add_wr_min_cost_named', $hand, 0,
                max(1, intval($ab['discard'] ?? 1)),
                'You may put ' . intval($ab['discard'] ?? 1) .
                ' card(s) from your hand into the Waiting Room: add 1 Member card with cost ' .
                intval($ab['min_cost'] ?? 10) . ' or more from your Waiting Room to your hand.',
                ['pick_zone' => 'hand']
            );
            break;
        }

        case 'look_top_optional_deck_bottom': {
            if (!empty($state['pending_prompt'])) break;
            $looked = takeFromMainDeckTop($state, $pid, max(1, intval($ab['look'] ?? 1)));
            if (empty($looked)) break;
            $state['_bp7_look_stash'] = $looked;
            $state = bp7StartConfirm(
                $state, $pid, $source, $ab, $ctx, 'look_top_optional_bottom',
                'Top card: ' . cardDisplayName($looked[0]) .
                ' — put it on the bottom of your deck?',
                ['looked_cards' => array_map('cardPromptSummary', $looked)],
                'Bottom of deck',
                'Leave on top'
            );
            break;
        }

        case 'look_bottom_arrange_rest_wr': {
            if (!empty($state['pending_prompt'])) break;
            $looked = takeFromMainDeckBottom($state, $pid, max(1, intval($ab['look'] ?? 3)));
            if (empty($looked)) break;
            $state = startSurveilArrangePrompt($state, $pid, $name, $looked, [
                'target'   => $pid,
                'bp7_rest' => 'wr',
                'bp7_keep' => 'bottom',
            ], $srcId);
            $state['pending_prompt']['prompt'] = 'Look at the bottom ' . count($looked) .
                ' card(s) of your deck. Put any number of them on the bottom of your deck in any order ' .
                'and put the rest into the Waiting Room.';
            $state['seq']++;
            break;
        }

        case 'activated_discard_trigger_on_enter_self_and_other': {
            if (!empty($state['pending_prompt'])) break;
            $need = max(1, intval($ab['discard'] ?? 2));
            if (count($state['players'][$pid]['hand'] ?? []) < $need) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] needs $need card(s) in hand.");
                break;
            }
            $others = bp7StageMemberCandidates($state['players'][$pid], [
                'group'      => $ab['group'] ?? '',
                'exclude_id' => $srcId,
            ]);
            $others = array_values(array_filter(
                $others,
                fn($c) => !empty(getAbilitiesByTrigger($state['players'][$pid]['stage'][$c['slot']] ?? [], 'on_enter'))
            ));
            if (empty($others)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no other " . ($ab['group'] ?? '') . ' Member with an [On Enter] ability.');
                break;
            }
            $state = bp7StartCardPick(
                $state, $pid, $source, $ab, $ctx, 'discard_trigger_on_enter_pair',
                $state['players'][$pid]['hand'], $need, $need,
                "Put $need card(s) from your hand into the Waiting Room: trigger 1 [On Enter] ability of " .
                'this Member and 1 other ' . ($ab['group'] ?? '') . ' Member.',
                ['pick_zone' => 'hand']
            );
            break;
        }

        case 'mill_then_heart_if_all_group_bottom': {
            [$state, $milled] = bp7MillFromDeckBottom($state, $pid, intval($ab['count'] ?? 3), $name);
            $all = !empty($milled);
            foreach ($milled as $c) {
                if (!bp7IsMemberCard($c) || !cardMatchesGroup($c, $ab['group'] ?? '', 'member')) {
                    $all = false;
                    break;
                }
            }
            if ($all && count($milled) >= intval($ab['count'] ?? 3)) {
                addBonusHeartsToModifier($state, $pid, $ab['hearts'] ?? []);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] gained bonus heart(s) (all bottom cards were " .
                    ($ab['group'] ?? '') . ' Members).');
            } else {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] bottom cards were not all " . ($ab['group'] ?? '') . ' Members.');
            }
            break;
        }

        case 'add_from_wr_max_cost_named_play': {
            if (!empty($state['pending_prompt'])) break;
            $cands = array_values(array_filter(
                $state['players'][$pid]['waiting_room'],
                fn($c) => is_array($c) && bp7IsMemberCard($c)
                    && intval($c['cost'] ?? 0) <= intval($ab['max_cost'] ?? 2)
            ));
            if (empty($cands)) break;
            $state = bp7StartCardPick(
                $state, $pid, $source, $ab, $ctx, 'add_wr_max_cost_named_play', $cands, 1,
                max(1, intval($ab['count'] ?? 1)),
                'Add 1 Member card with cost ' . intval($ab['max_cost'] ?? 2) .
                ' or less from your Waiting Room to your hand.'
            );
            break;
        }

        case 'wr_group_members_deck_bottom_blade_per': {
            if (!empty($state['pending_prompt'])) break;
            $cands = array_values(array_filter(
                $state['players'][$pid]['waiting_room'],
                fn($c) => is_array($c) && bp7IsMemberCard($c)
                    && cardMatchesGroup($c, $ab['group'] ?? '', 'member')
            ));
            if (empty($cands)) break;
            $max = max(1, intval($ab['max_pick'] ?? 3));
            $state = bp7StartCardPick(
                $state, $pid, $source, $ab, $ctx, 'wr_group_bottom_blade_per', $cands, 0, $max,
                "Put up to $max " . ($ab['group'] ?? '') .
                ' Member card(s) from your Waiting Room on the bottom of your deck (pick order = deck order): +' .
                intval($ab['blade_per'] ?? 1) . ' Blade for each.'
            );
            break;
        }

        case 'look_top_arrange_rest_bottom': {
            if (!empty($state['pending_prompt'])) break;
            $looked = takeFromMainDeckTop($state, $pid, max(1, intval($ab['look'] ?? 3)));
            if (empty($looked)) break;
            $state = startSurveilArrangePrompt($state, $pid, $name, $looked, [
                'target'   => $pid,
                'bp7_rest' => 'bottom',
            ], $srcId);
            $state['pending_prompt']['prompt'] = 'Look at the top ' . count($looked) .
                ' card(s) of your deck. Put any number of them back on top in any order and put the rest ' .
                'on the bottom of your deck.';
            $state['seq']++;
            break;
        }

        case 'optional_mill_bottom_add_if_named': {
            if (!empty($state['pending_prompt'])) break;
            if (empty($state['players'][$pid]['main_deck'])) break;
            $state = bp7StartConfirm(
                $state, $pid, $source, $ab, $ctx, 'mill_bottom_add_if_named',
                'Put the bottom card of your deck into the Waiting Room? ' .
                'If it is a named Member, add it to your hand instead.'
            );
            break;
        }

        case 'look_deck_bottom_optional_deck_position': {
            if (!empty($state['pending_prompt'])) break;
            $looked = takeFromMainDeckBottom($state, $pid, max(1, intval($ab['look'] ?? 1)));
            if (empty($looked)) break;
            $state['_bp7_look_stash'] = $looked;
            $pos = max(1, intval($ab['deck_position'] ?? 4));
            $state = bp7StartConfirm(
                $state, $pid, $source, $ab, $ctx, 'deck_bottom_to_position',
                'Bottom card: ' . cardDisplayName($looked[0]) .
                " — put it {$pos}th from the top of your deck?",
                ['looked_cards' => array_map('cardPromptSummary', $looked)],
                "Put {$pos}th from top",
                'Leave on the bottom'
            );
            break;
        }

        case 'wait_self_mill_bottom_activate_blade_if_all_group': {
            $slot = bp7FindSlotByInstance($state['players'][$pid], $srcId);
            if ($slot === '') break;
            $member = $state['players'][$pid]['stage'][$slot];
            waitMember($member, $state);
            $state['players'][$pid]['stage'][$slot] = $member;
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] put into Wait.");
            [$state, $milled] = bp7MillFromDeckBottom($state, $pid, intval($ab['mill'] ?? 2), $name);
            $all = !empty($milled) && count($milled) >= intval($ab['mill'] ?? 2);
            foreach ($milled as $c) {
                if (!bp7IsMemberCard($c) || !cardMatchesGroup($c, $ab['group'] ?? '', 'member')) {
                    $all = false;
                    break;
                }
            }
            if (!$all) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] bottom cards were not all " . ($ab['group'] ?? '') . ' Members.');
                break;
            }
            $slot = bp7FindSlotByInstance($state['players'][$pid], $srcId);
            if ($slot !== '') {
                $mbr = $state['players'][$pid]['stage'][$slot];
                clearMemberWait($mbr);
                $mbr['active'] = true;
                $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + intval($ab['amount'] ?? 2);
                $state['players'][$pid]['stage'][$slot] = $mbr;
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] activated and gains +" . intval($ab['amount'] ?? 2) . ' Blade.');
            break;
        }

        case 'optional_formation_change_group_subunit_blade': {
            if (!empty($state['pending_prompt'])) break;
            $group = $ab['group'] ?? '';
            $subunit = $ab['subunit'] ?? '';
            $ok = countStageMembers($state['players'][$pid]) > 0;
            foreach ($state['players'][$pid]['stage'] as $mbr) {
                if (!$mbr) continue;
                if (cardMatchesGroup($mbr, $group, 'member')) continue;
                if ($subunit !== '' && cardMatchesSubunit($mbr, $subunit)) continue;
                $ok = false;
                break;
            }
            if (!$ok) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] not every Stage Member is $group or $subunit.");
                break;
            }
            $state = bp7StartConfirm(
                $state, $pid, $source, $ab, $ctx, 'formation_change_subunit_blade',
                'Formation Change your Stage? If a ' . $subunit .
                ' Member moves, gain +' . intval($ab['amount'] ?? 2) . ' Blade until this Live ends.'
            );
            break;
        }

        case 'choose_player_wr_members_deck_bottom': {
            if (!empty($state['pending_prompt'])) break;
            $state = bp7StartChoices(
                $state, $pid, $source, $ab, $ctx, 'choose_player_wr_bottom',
                ['self', 'opponent'],
                ['Yourself', 'Your opponent'],
                'Choose a player: put up to ' . intval($ab['max_pick'] ?? 2) .
                ' Member card(s) from that player\'s Waiting Room on the bottom of their deck.',
                [],
                'bp7_choose_player'
            );
            break;
        }

        case 'mill_then_heart_if_live_bottom': {
            [$state, $milled] = bp7MillFromDeckBottom($state, $pid, intval($ab['count'] ?? 1), $name);
            $hit = false;
            foreach ($milled as $c) {
                if (bp7IsLiveCard($c)) { $hit = true; break; }
            }
            if ($hit) {
                addBonusHeartsToModifier($state, $pid, $ab['hearts'] ?? []);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] gained bonus heart(s) (bottom card was a Live card).");
            }
            break;
        }

        case 'mill_then_heart_if_min_cost_member_bottom': {
            [$state, $milled] = bp7MillFromDeckBottom($state, $pid, intval($ab['count'] ?? 1), $name);
            $hit = false;
            foreach ($milled as $c) {
                if (bp7IsMemberCard($c) && intval($c['cost'] ?? 0) >= intval($ab['min_cost'] ?? 10)) {
                    $hit = true; break;
                }
            }
            if ($hit) {
                addBonusHeartsToModifier($state, $pid, $ab['hearts'] ?? []);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] gained bonus heart(s) (bottom card was a cost " .
                    intval($ab['min_cost'] ?? 10) . '+ Member).');
            }
            break;
        }

        case 'position_change_to_center': {
            if (!empty($state['pending_prompt'])) break;
            $cands = array_values(array_filter(
                bp7StageMemberCandidates($state['players'][$pid]),
                fn($c) => $c['slot'] !== 'center'
            ));
            if (empty($cands)) break;
            if (count($cands) === 1) {
                $state = bp7PositionChangeToCenter($state, $pid, $cands[0]['slot'], $name);
                break;
            }
            $state = bp7StartStagePick(
                $state, $pid, $source, $ab, $ctx, 'position_change_to_center', $cands,
                'Position Change 1 Member on your Stage to the Center area.'
            );
            break;
        }

        case 'reduce_hearts_if_all_active': {
            $any = false;
            $all = true;
            foreach ($state['players'][$pid]['stage'] as $mbr) {
                if (!$mbr) continue;
                $any = true;
                if (memberIsInWait($mbr) || !memberIsActiveForGame($mbr)) {
                    $all = false;
                    break;
                }
            }
            if (!$any || !$all) break;
            $color = ($ab['reduce_heart_color'] ?? '') === 'gray' ? 'any' : ($ab['reduce_heart_color'] ?? 'any');
            bumpLiveCardColorReduction($state, $pid, $srcId, $color, intval($ab['amount'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] required hearts reduced by " . intval($ab['amount'] ?? 1) .
                ' (every Stage Member is active).');
            break;
        }

        case 'mill_then_reduce_hearts_if_group_bottom': {
            [$state, $milled] = bp7MillFromDeckBottom($state, $pid, intval($ab['count'] ?? 1), $name);
            $hit = false;
            foreach ($milled as $c) {
                if (bp7IsMemberCard($c) && cardMatchesGroup($c, $ab['group'] ?? '', 'member')) {
                    $hit = true; break;
                }
            }
            if (!$hit) break;
            $color = ($ab['reduce_heart_color'] ?? '') === 'gray' ? 'any' : ($ab['reduce_heart_color'] ?? 'any');
            bumpLiveCardColorReduction($state, $pid, $srcId, $color, intval($ab['amount'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] required hearts reduced by " . intval($ab['amount'] ?? 1) .
                ' (bottom card was a ' . ($ab['group'] ?? '') . ' Member).');
            break;
        }

        case 'mill_bottom_then_draw_score_by_members': {
            if (countStageMembers($state['players'][$pid]) < intval($ab['min_stage_members'] ?? 3)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] needs " . intval($ab['min_stage_members'] ?? 3) . '+ Stage Members.');
                break;
            }
            $want = max(1, intval($ab['count'] ?? 5));
            [$state, $milled] = bp7MillFromDeckBottom($state, $pid, $want, $name);
            $members = 0;
            foreach ($milled as $c) {
                if (bp7IsMemberCard($c)) $members++;
            }
            if ($members >= intval($ab['min_members_draw'] ?? 3)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn ($members Member cards among the bottom cards).");
            }
            if ($members === count($milled) && count($milled) >= $want) {
                bp7BumpSelfScore($state, $pid, $source, intval($ab['amount'] ?? 1), $name,
                    'every bottom card was a Member');
            }
            break;
        }

        case 'live_success_score_if_yell_group_heart_colors': {
            $colors = $ab['colors'] ?? ['red', 'green', 'blue'];
            $hit = [];
            foreach (bp7YellCards($state, $pid, $ctx) as $c) {
                if (!is_array($c) || !bp7IsMemberCard($c)) continue;
                if (!cardMatchesGroup($c, $ab['group'] ?? '', 'member')) continue;
                foreach ($colors as $color) {
                    if (bp7CardHasHeartColor($c, $color)) {
                        $hit[$color] = true;
                    }
                }
            }
            if (count($hit) >= count($colors)) {
                bp7BumpSelfScore($state, $pid, $source, intval($ab['amount'] ?? 1), $name,
                    'Yell revealed ' . implode(' / ', $colors) . ' ' . ($ab['group'] ?? '') . ' Members');
            }
            break;
        }

        case 'live_start_return_energy_score_by_opp_gap': {
            if (!empty($state['pending_prompt'])) break;
            if (countGroupMembersOnStage($state['players'][$pid], $ab['group'] ?? '')
                < intval($ab['min_stage_group'] ?? 2)) {
                break;
            }
            if (countEnergyInZone($state['players'][$pid]) < intval($ab['energy'] ?? 1)) break;
            $state = bp7StartConfirm(
                $state, $pid, $source, $ab, $ctx, 'return_energy_score_by_opp_gap',
                'Put ' . intval($ab['energy'] ?? 1) .
                ' Energy from your Energy zone into your Energy deck? ' .
                'Score +' . intval($ab['amount'] ?? 1) . ' / +' . intval($ab['amount_if_gap_2'] ?? 2) .
                ' depending on your opponent\'s Energy lead.'
            );
            break;
        }

        case 'wait_opponent_max_cost_skip_next_activate': {
            if (!empty($state['pending_prompt'])) break;
            $state['_bp7_wait_skip_activate'] = [
                'pid'      => $pid,
                'max_cost' => intval($ab['max_cost'] ?? 4),
            ];
            $state = beginWaitOpponentStagePick($state, $pid, $name, [
                'max_cost'   => intval($ab['max_cost'] ?? 4),
                'pick_count' => intval($ab['pick_count'] ?? 2),
            ], $srcId, !empty($ctx['live_start']));
            if (empty($state['pending_prompt'])) {
                // Auto-resolved (0 or 1 legal target) — mark whatever went into Wait.
                $state = bp7MarkOppWaitSkipActivate($state, $pid, intval($ab['max_cost'] ?? 4), $name);
            }
            break;
        }

        /* ---------------- Liella! ---------------- */

        case 'reveal_hand_cost_stack_under_draw': {
            if (!empty($state['pending_prompt'])) break;
            $costs = $ab['costs'] ?? [10, 20];
            $cands = array_values(array_filter(
                $state['players'][$pid]['hand'],
                fn($c) => is_array($c) && bp7IsMemberCard($c) && in_array(intval($c['cost'] ?? 0), $costs, true)
            ));
            if (empty($cands)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no Member card with cost " . implode(' or ', $costs) . ' in hand.');
                break;
            }
            $state = bp7StartCardPick(
                $state, $pid, $source, $ab, $ctx, 'reveal_hand_stack_under_draw', $cands, 1, 1,
                'Reveal 1 Member card with cost ' . implode(' or ', $costs) .
                ' from your hand: put it under this Member, then draw ' . intval($ab['draw'] ?? 2) . '.',
                ['pick_zone' => 'hand']
            );
            break;
        }

        case 'optional_wr_group_members_deck_bottom_blade_if_bladeless': {
            if (!empty($state['pending_prompt'])) break;
            $cands = array_values(array_filter(
                $state['players'][$pid]['waiting_room'],
                fn($c) => is_array($c) && bp7IsMemberCard($c)
                    && cardMatchesGroup($c, $ab['group'] ?? '', 'member')
            ));
            $need = max(1, intval($ab['count'] ?? 3));
            if (count($cands) < $need) break;
            $state = bp7StartCardPick(
                $state, $pid, $source, $ab, $ctx, 'wr_group_bottom_blade_if_bladeless', $cands, $need, $need,
                "Put $need " . ($ab['group'] ?? '') .
                ' Member card(s) from your Waiting Room on the bottom of your deck: if ' .
                intval($ab['min_bladeless'] ?? 1) . '+ of them are bladeless, gain +' .
                intval($ab['amount'] ?? 2) . ' Blade.'
            );
            break;
        }

        case 'optional_return_energy_to_deck': {
            if (!empty($state['pending_prompt'])) break;
            $need = max(1, intval($ab['energy'] ?? 1));
            if (countEnergyInZone($state['players'][$pid]) < $need) break;
            $state = bp7StartConfirm(
                $state, $pid, $source, $ab, $ctx, 'return_energy_to_deck_then',
                "Put $need Energy from your Energy zone into your Energy deck?"
            );
            break;
        }

        case 'live_score_if_energy_returned_this_turn': {
            if (!empty($ab['center_only'])
                && bp7FindSlotByInstance($state['players'][$pid], $srcId) !== 'center'
                && ($ctx['slot'] ?? '') !== 'center') {
                // Live cards resolve from the Live zone; only gate Stage Members on Center.
                if (bp7FindSlotByInstance($state['players'][$pid], $srcId) !== '') {
                    break;
                }
            }
            if (!bp7EnergyReturnedThisTurn($state, $pid)) break;
            $amount = intval($ab['amount'] ?? 1);
            $state = applyModifierEffect($state, $pid, ['type' => 'live_score_bonus', 'amount' => $amount]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Live total score +$amount (Energy returned to the Energy deck this turn).");
            break;
        }

        case 'leave_stage_return_energy_add_from_wr': {
            if (!empty($state['pending_prompt'])) break;
            $slot = bp7FindSlotByInstance($state['players'][$pid], $srcId);
            if ($slot === '') break;
            $leaving = $state['players'][$pid]['stage'][$slot];
            $state['players'][$pid]['stage'][$slot] = null;
            $state = appendCardsToWaitingRoom($state, $pid, [$leaving]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] put from the Stage into the Waiting Room.");
            $state = resolveOnLeaveStageAbilities($state, $pid, $leaving, ['self_leave' => true]);
            $need = max(1, intval($ab['energy'] ?? 1));
            $movedCards = [];
            $moved = bp7ReturnEnergyToDeck($state, $pid, $need, false, $movedCards);
            $anims = [];
            foreach ($movedCards as $e) {
                $iid = (string)($e['instance_id'] ?? '');
                if ($iid !== '') {
                    $anims[] = animSpec($iid, 'energy', 'energy_deck', $pid);
                }
            }
            if ($moved > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put $moved Energy into the Energy deck.", 'effect', $anims);
            } else {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no Energy in the Energy zone to put into the Energy deck.");
            }
            // 「その後」: WR add only if Energy actually returned.
            if ($moved > 0 && empty($state['pending_prompt'])) {
                $state = resolveAbilityEffect($state, $pid, $leaving, [
                    'type'    => 'add_from_wr',
                    'trigger' => $ab['trigger'] ?? 'activated',
                    'filter'  => $ab['filter'] ?? '',
                    'count'   => intval($ab['count'] ?? 1),
                ], $ctx);
            }
            break;
        }

        case 'optional_discard_hand_all_draw': {
            if (!empty($state['pending_prompt'])) break;
            if (empty($state['players'][$pid]['hand'])) break;
            $state = bp7StartConfirm(
                $state, $pid, $source, $ab, $ctx, 'discard_hand_all_draw',
                'Put your entire hand (' . count($state['players'][$pid]['hand']) .
                ' card(s)) into the Waiting Room and draw ' . intval($ab['draw'] ?? 6) . '?'
            );
            break;
        }

        case 'optional_wr_one_per_subunit_deck_bottom_draw': {
            if (!empty($state['pending_prompt'])) break;
            $subunits = $ab['subunits'] ?? [];
            $cands = [];
            foreach ($state['players'][$pid]['waiting_room'] as $c) {
                if (!is_array($c)) continue;
                foreach ($subunits as $su) {
                    if (cardMatchesSubunit($c, $su)) {
                        $cands[] = $c;
                        break;
                    }
                }
            }
            // Every listed subunit must be coverable, otherwise the effect cannot be paid.
            foreach ($subunits as $su) {
                $has = false;
                foreach ($cands as $c) {
                    if (cardMatchesSubunit($c, $su)) { $has = true; break; }
                }
                if (!$has) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] Waiting Room has no $su card.");
                    break 2;
                }
            }
            if (empty($cands)) break;
            $state = bp7StartCardPick(
                $state, $pid, $source, $ab, $ctx, 'wr_one_per_subunit_bottom_draw', $cands,
                0, count($subunits),
                'You may choose 1 ' . implode(', 1 ', $subunits) .
                ' card from your Waiting Room and put them on the bottom of your deck: draw ' .
                intval($ab['draw'] ?? 1) . '.'
            );
            break;
        }

        case 'optional_discard_live_look_reveal': {
            if (!empty($state['pending_prompt'])) break;
            $lives = array_values(array_filter(
                $state['players'][$pid]['hand'],
                fn($c) => is_array($c) && bp7IsLiveCard($c)
            ));
            if (empty($lives)) break;
            $state = bp7StartCardPick(
                $state, $pid, $source, $ab, $ctx, 'discard_live_look_reveal', $lives, 0,
                max(1, intval($ab['discard'] ?? 1)),
                'You may put 1 Live card from your hand into the Waiting Room: look at the top ' .
                intval($ab['look'] ?? 5) . ' cards of your deck, add ' . intval($ab['pick'] ?? 1) .
                ' to your hand and put the rest into the Waiting Room.',
                ['pick_zone' => 'hand']
            );
            break;
        }

        case 'live_start_shuffle_wr_group_members_deck_bottom_blade': {
            if (!empty($state['pending_prompt'])) break;
            $cands = array_values(array_filter(
                $state['players'][$pid]['waiting_room'],
                fn($c) => is_array($c) && bp7IsMemberCard($c)
                    && cardMatchesGroup($c, $ab['group'] ?? '', 'member')
            ));
            $need = max(1, intval($ab['count'] ?? 9));
            if (count($cands) < $need) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] needs $need " . ($ab['group'] ?? '') .
                    ' Member cards in the Waiting Room (has ' . count($cands) . ').');
                break;
            }
            $state = bp7StartCardPick(
                $state, $pid, $source, $ab, $ctx, 'shuffle_wr_group_bottom_all_blade', $cands, $need, $need,
                "Choose $need " . ($ab['group'] ?? '') .
                ' Member cards in your Waiting Room, shuffle them, and put them on the bottom of your deck: ' .
                'every Member on your Stage gains +' . intval($ab['amount'] ?? 1) . ' Blade.'
            );
            break;
        }

        case 'live_success_score_if_all_yell_group': {
            $yell = bp7YellCards($state, $pid, $ctx);
            if (empty($yell)) break;
            $all = true;
            foreach ($yell as $c) {
                if (!is_array($c) || !cardMatchesGroup($c, $ab['group'] ?? '', '')) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                bp7BumpSelfScore($state, $pid, $source, intval($ab['amount'] ?? 1), $name,
                    'every Yell card is ' . ($ab['group'] ?? ''));
            }
            break;
        }
    }
    return $state;
}

/* ------------------------------------------------------------------ *
 * Board helpers used by effects + prompts
 * ------------------------------------------------------------------ */

/** Empty Stage areas that are not locked by an "in Wait" summon this turn (PL!N-bp7-010). */
function bp7EmptyPlayableSlots(array $p, int $turn): array {
    $locked = $p['_bp7_locked_slots'] ?? [];
    $out = [];
    foreach (['left', 'center', 'right'] as $slot) {
        if (!empty($p['stage'][$slot])) continue;
        if (intval($locked[$slot] ?? -1) === $turn) continue;
        $out[] = $slot;
    }
    return $out;
}

function bp7StageSlotLocked(array $p, string $slot, int $turn): bool {
    return intval(($p['_bp7_locked_slots'] ?? [])[$slot] ?? -1) === $turn;
}

/** Effective Blade of every Member on both Stages, keyed "pid:slot". */
function bp7AllStageBlades(array $state): array {
    $out = [];
    foreach (['p1', 'p2'] as $id) {
        foreach ($state['players'][$id]['stage'] ?? [] as $slot => $mbr) {
            if (!$mbr) continue;
            $out["$id:$slot"] = getMemberBlade($mbr, $state, $id, (string)$slot);
        }
    }
    return $out;
}

function bp7ApplyMostBladeScore(
    array $state,
    string $pid,
    array $source,
    array $ab,
    string $slot,
    string $name
): array {
    $blades = bp7AllStageBlades($state);
    $key = "$pid:$slot";
    if (!isset($blades[$key])) {
        return $state;
    }
    $mine = $blades[$key];
    foreach ($blades as $k => $v) {
        if ($k === $key) continue;
        if ($v >= $mine) {
            return addLog($state, $state['players'][$pid]['name'] .
                " — [$name] chosen Member does not have more Blade than every other Member.");
        }
    }
    bp7BumpSelfScore($state, $pid, $source, intval($ab['amount'] ?? 1), $name,
        "chosen Member has the most Blade ($mine)");
    return $state;
}

/** Return a performing/stored Live card to hand (PL!N-bp7-030). */
function bp7ReturnLiveCardToHand(array &$state, string $pid, string $iid): bool {
    if ($iid === '') {
        return false;
    }
    $p = &$state['players'][$pid];
    foreach (['live_zone', 'success_lives'] as $zone) {
        foreach ($p[$zone] ?? [] as $i => $c) {
            if (is_array($c) && ($c['instance_id'] ?? '') === $iid) {
                array_splice($p[$zone], $i, 1);
                $p[$zone] = array_values($p[$zone]);
                $p['hand'][] = $c;
                return true;
            }
        }
    }
    return false;
}

function bp7PositionChangeToCenter(array $state, string $pid, string $slot, string $name): array {
    if ($slot === '' || $slot === 'center' || empty($state['players'][$pid]['stage'][$slot])) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $moving = $p['stage'][$slot];
    $center = $p['stage']['center'] ?? null;
    $p['stage']['center'] = $moving;
    $p['stage']['center']['moved_this_turn'] = true;
    $p['stage'][$slot] = $center;
    if ($p['stage'][$slot]) {
        $p['stage'][$slot]['moved_this_turn'] = true;
    }
    unset($p);
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$name] Position Change: " . cardDisplayName($moving) . ' moved to Center.');
    $moved = [['id' => $moving['instance_id'] ?? '', 'slot' => 'center']];
    if ($center) {
        $moved[] = ['id' => $center['instance_id'] ?? '', 'slot' => $slot];
    }
    return bp7ResolveAutoOnAreaMove($state, $pid, $moved);
}

/** Mark opponent Members that just went into Wait as skipping their next Active Phase. */
function bp7MarkOppWaitSkipActivate(array $state, string $pid, int $maxCost, string $name): array {
    unset($state['_bp7_wait_skip_activate']);
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    $turn = intval($state['turn'] ?? 1);
    $marked = 0;
    foreach ($state['players'][$opp]['stage'] ?? [] as $slot => $mbr) {
        if (!$mbr || !memberIsInWait($mbr)) continue;
        if (intval($mbr['waited_turn'] ?? 0) !== $turn) continue;
        if (intval($mbr['cost'] ?? 0) > $maxCost) continue;
        $state['players'][$opp]['stage'][$slot]['skip_activate_next_turn'] = true;
        $marked++;
    }
    if ($marked > 0) {
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] $marked Waited Member(s) will not activate during their next Active Phase.");
    }
    return $state;
}

/** Trigger one [On Enter] ability of a Stage Member without re-entering it (PL!S-bp7-005). */
function bp7TriggerFirstOnEnter(array $state, string $pid, string $slot, string $srcName): array {
    $member = $state['players'][$pid]['stage'][$slot] ?? null;
    if (!$member) {
        return $state;
    }
    $abilities = getAbilitiesByTrigger($member, 'on_enter');
    if (empty($abilities)) {
        return $state;
    }
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$srcName] re-triggered [On Enter] of " . cardDisplayName($member) . '.');
    return resolveAbilityEffect($state, $pid, $member, $abilities[0], ['slot' => $slot, 'retriggered' => true]);
}

/* ------------------------------------------------------------------ *
 * Automatic-trigger hooks
 * ------------------------------------------------------------------ */

/** PL!N-bp7-001: Energy from the Energy zone was placed under a Member. */
function bp7ResolveAutoOnEnergyStackedUnderMember(array $state, string $pid): array {
    $p = &$state['players'][$pid];
    foreach ($p['stage'] as $slot => $mbr) {
        if (!$mbr) continue;
        foreach ($mbr['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') continue;
            if (($ab['type'] ?? '') !== 'auto_on_energy_stacked_energy_wait') continue;
            if (!empty($ab['once_per_turn']) && isAbilityUsed($mbr, $idx)) continue;
            $member = $p['stage'][$slot];
            if (!empty($ab['once_per_turn'])) {
                markAbilityUsed($member, $idx);
                $p['stage'][$slot] = $member;
            }
            unset($p);
            $placed = bp7EnergyDeckToWait($state, $pid, max(1, intval($ab['count'] ?? 1)), false);
            if ($placed > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . bp7SourceName($mbr) . "] put $placed Energy card(s) from the Energy deck into Wait.");
            }
            $p = &$state['players'][$pid];
        }
    }
    unset($p);
    return $state;
}

/** PL!SP-bp7-005 / -016: an Energy was placed in the Energy zone by one of your effects. */
function bp7ResolveAutoOnEnergyPlaced(array $state, string $pid, int $placed): array {
    if ($placed <= 0) {
        return $state;
    }
    foreach ($state['players'][$pid]['stage'] ?? [] as $slot => $mbr) {
        if (!$mbr) continue;
        foreach ($mbr['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') continue;
            if (($ab['type'] ?? '') !== 'auto_on_energy_placed_blade') continue;
            $limit = !empty($ab['once_per_turn']) ? 1 : intval($ab['max_uses_per_turn'] ?? 0);
            $key = '_auto_uses_energy_placed_' . $idx;
            if ($limit > 0 && intval($mbr[$key] ?? 0) >= $limit) continue;
            $state['players'][$pid]['stage'][$slot][$key] = intval($mbr[$key] ?? 0) + 1;
            $state['players'][$pid]['stage'][$slot]['live_blade_bonus'] =
                intval($mbr['live_blade_bonus'] ?? 0) + intval($ab['amount'] ?? 1);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . bp7SourceName($mbr) . '] gains +' . intval($ab['amount'] ?? 1) .
                ' Blade (Energy placed by a card effect).');
        }
    }
    return $state;
}

/** PL!SP-bp7-005: your Energy went from the Energy zone into the Energy deck. */
function bp7ResolveAutoEnergyReturnedToDeck(array $state, string $pid): array {
    return bp7ResolveEnergyWaitLocked($state, $pid, 'returned');
}

/** PL!SP-bp7-005 shares one [Automatic] between "this Member enters" and "Energy returned". */
function bp7ResolveEnergyWaitLocked(array $state, string $pid, string $why, string $onlyIid = ''): array {
    foreach ($state['players'][$pid]['stage'] ?? [] as $slot => $mbr) {
        if (!$mbr) continue;
        if ($onlyIid !== '' && ($mbr['instance_id'] ?? '') !== $onlyIid) continue;
        foreach ($mbr['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') continue;
            if (($ab['type'] ?? '') !== 'auto_on_enter_or_energy_returned_energy_wait_locked') continue;
            if (!empty($ab['once_per_turn']) && isAbilityUsed($mbr, $idx)) continue;
            $member = $state['players'][$pid]['stage'][$slot];
            if (!empty($ab['once_per_turn'])) {
                markAbilityUsed($member, $idx);
                $state['players'][$pid]['stage'][$slot] = $member;
            }
            $placed = bp7EnergyDeckToWait($state, $pid, max(1, intval($ab['count'] ?? 1)), true);
            if ($placed > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . bp7SourceName($mbr) . "] put $placed Energy card(s) from the Energy deck into Wait; " .
                    'they do not activate during your next Active Phase (' . $why . ').');
            }
        }
    }
    return $state;
}

/** PL!SP-bp7-008 / -014: this Member moved to a different area. */
function bp7ResolveAutoOnAreaMove(array $state, string $pid, array $moved): array {
    foreach ($moved as $entry) {
        $iid = (string)($entry['id'] ?? '');
        $slot = (string)($entry['slot'] ?? '');
        if ($iid === '' || $slot === '') continue;
        $mbr = $state['players'][$pid]['stage'][$slot] ?? null;
        if (!$mbr || ($mbr['instance_id'] ?? '') !== $iid) continue;
        foreach ($mbr['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') continue;
            $type = $ab['type'] ?? '';
            if ($type === 'auto_on_area_move_blade') {
                if (!empty($ab['once_per_turn']) && isAbilityUsed($mbr, $idx)) continue;
                $member = $state['players'][$pid]['stage'][$slot];
                if (!empty($ab['once_per_turn'])) {
                    markAbilityUsed($member, $idx);
                }
                $member['live_blade_bonus'] =
                    intval($member['live_blade_bonus'] ?? 0) + intval($ab['amount'] ?? 2);
                $state['players'][$pid]['stage'][$slot] = $member;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . bp7SourceName($mbr) . '] gains +' . intval($ab['amount'] ?? 2) .
                    ' Blade (moved to a different area).');
                continue;
            }
            if ($type === 'auto_on_area_move_activate_self') {
                if (!empty($ab['wait_only']) && !memberIsInWait($mbr)) continue;
                $member = $state['players'][$pid]['stage'][$slot];
                clearMemberWait($member);
                $member['active'] = true;
                $state['players'][$pid]['stage'][$slot] = $member;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . bp7SourceName($mbr) . '] activated (moved area while in Wait).');
            }
        }
    }
    return $state;
}

/**
 * PL!N-bp7-022: during the Live Phase, a group Member on your Stage went into Wait.
 * The controller may discard to re-activate that Member.
 */
function bp7ResolveAutoOnAllyWait(array $state, string $pid, array $waited): array {
    if (!empty($state['pending_prompt'])) {
        return $state;
    }
    $phase = (string)($state['phase'] ?? '');
    $inLive = str_contains($phase, 'live') || str_contains($phase, 'performance');
    $waitedId = (string)($waited['instance_id'] ?? '');
    if ($waitedId === '') {
        return $state;
    }
    foreach ($state['players'][$pid]['stage'] ?? [] as $slot => $mbr) {
        if ($mbr && ($mbr['instance_id'] ?? '') === $waitedId) {
            unset($state['players'][$pid]['stage'][$slot]['_ally_wait_pending']);
            break;
        }
    }
    foreach ($state['players'][$pid]['stage'] ?? [] as $slot => $mbr) {
        if (!$mbr) continue;
        foreach ($mbr['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') continue;
            if (($ab['type'] ?? '') !== 'auto_on_ally_wait_activate') continue;
            if (!empty($ab['live_phase_only']) && !$inLive) continue;
            if (($ab['group'] ?? '') !== '' && !cardMatchesGroup($waited, $ab['group'], 'member')) continue;
            if (!empty($ab['once_per_turn']) && isAbilityUsed($mbr, $idx)) continue;
            if (empty($state['players'][$pid]['hand'])) continue;
            $member = $state['players'][$pid]['stage'][$slot];
            if (!empty($ab['once_per_turn'])) {
                markAbilityUsed($member, $idx);
                $state['players'][$pid]['stage'][$slot] = $member;
            }
            return bp7StartConfirm(
                $state, $pid, $mbr, $ab, [], 'ally_wait_activate',
                'Put ' . max(1, intval($ab['optional_discard'] ?? 1)) .
                ' card(s) from your hand into the Waiting Room to activate ' .
                cardDisplayName($waited) . '?',
                ['waited_id' => $waitedId]
            );
        }
    }
    return $state;
}

/** PL!N-bp7-011: this card itself was put from the deck into the Waiting Room. */
function bp7ResolveAutoSelfMilled(array $state, string $pid, array $milled): array {
    if (!empty($state['pending_prompt']) || empty($state['players'][$pid]['hand'])) {
        return $state;
    }
    foreach ($milled as $c) {
        if (!is_array($c)) continue;
        foreach ($c['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') continue;
            if (($ab['type'] ?? '') !== 'auto_self_milled_discard_recover') continue;
            $iid = (string)($c['instance_id'] ?? '');
            if ($iid === '' || !bp7CardInWaitingRoom($state['players'][$pid], $iid)) continue;
            return bp7StartConfirm(
                $state, $pid, $c, $ab, [], 'self_milled_recover',
                'Put ' . max(1, intval($ab['discard'] ?? 1)) .
                ' card(s) from your hand into the Waiting Room to add ' .
                cardDisplayName($c) . ' from the Waiting Room to your hand?',
                ['recover_id' => $iid]
            );
        }
    }
    return $state;
}

function bp7CardInWaitingRoom(array $p, string $iid): bool {
    foreach ($p['waiting_room'] ?? [] as $c) {
        if (is_array($c) && ($c['instance_id'] ?? '') === $iid) {
            return true;
        }
    }
    return false;
}

/**
 * PL!N-bp7-031: cards went deck → Waiting Room via one of your [Live Success] abilities.
 * Called by the Live Success mill paths with the cards that moved.
 */
function bp7ResolveAutoOnLiveSuccessMill(array $state, string $pid, array $milled): array {
    if (!empty($state['pending_prompt']) || empty($milled)) {
        return $state;
    }
    foreach ($state['players'][$pid]['live_zone'] ?? [] as $lc) {
        if (!is_array($lc)) continue;
        foreach ($lc['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') continue;
            if (($ab['type'] ?? '') !== 'auto_on_live_success_mill_add_live_score') continue;
            if (!empty($ab['once_per_turn']) && isAbilityUsed($lc, $idx)) continue;
            $cands = [];
            foreach ($milled as $c) {
                if (!is_array($c)) continue;
                if (($ab['filter'] ?? '') === 'live' && !bp7IsLiveCard($c)) continue;
                if (($ab['group'] ?? '') !== '' && !cardMatchesGroup($c, $ab['group'], $ab['filter'] ?? '')) continue;
                if (!bp7CardInWaitingRoom($state['players'][$pid], (string)($c['instance_id'] ?? ''))) continue;
                $cands[] = $c;
            }
            if (empty($cands)) continue;
            $state = bp7MarkLiveZoneAbilityUsed($state, $pid, $lc['instance_id'] ?? '', $idx);
            return bp7StartCardPick(
                $state, $pid, $lc, $ab, [], 'live_success_mill_add_live', $cands, 0, 1,
                'You may add 1 ' . ($ab['group'] ?? '') .
                ' Live card from among the milled cards to your hand: this card\'s score +' .
                intval($ab['amount'] ?? 1) . '.'
            );
        }
    }
    return $state;
}

function bp7MarkLiveZoneAbilityUsed(array $state, string $pid, string $iid, int|string $idx): array {
    foreach ($state['players'][$pid]['live_zone'] ?? [] as $i => $lc) {
        if (is_array($lc) && ($lc['instance_id'] ?? '') === $iid) {
            $card = $lc;
            markAbilityUsed($card, $idx);
            $state['players'][$pid]['live_zone'][$i] = $card;
            break;
        }
    }
    return $state;
}

/**
 * BP07 [Automatic] on-leave abilities. `resolveOnLeaveStageAbilities` walks
 * `on_leave` / `on_wait` triggers; these are authored with `trigger: auto`.
 */
function bp7ResolveOnLeaveAbilities(array $state, string $pid, array $leaving, array $ctx = []): array {
    foreach ($leaving['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'auto') continue;
        $type = $ab['type'] ?? '';
        if ($type === 'auto_on_leave_add_from_wr') {
            if (!empty($state['pending_prompt'])) continue;
            $state = bp7ResolveEffect($state, $pid, $leaving, $ab, $ctx);
            continue;
        }
        if ($type === 'auto_on_leave_baton_stack_self_under'
            || $type === 'auto_on_leave_baton_energy_deck_stack') {
            // Incoming is already on Stage when leave runs after place; queue then
            // apply in actionPlayMember (after this on-leave), not before.
            if (empty($ctx['baton_incoming'])) continue;
            $state['_bp7_baton_stack_pending'][] = [
                'kind'      => $type,
                'leaving'   => $leaving,
                'ability'   => $ab,
                'incoming'  => (string)(($ctx['baton_incoming']['instance_id'] ?? '')),
            ];
        }
    }
    return $state;
}

/**
 * Apply deferred Baton Touch stacks once the incoming Member is on Stage
 * (PL!SP-bp7-001 stacks itself; PL!N-bp7-019 stacks an Energy deck card).
 */
function bp7ApplyPendingBatonStacks(array $state, string $pid, string $targetSlot): array {
    $pending = $state['_bp7_baton_stack_pending'] ?? [];
    if (empty($pending)) {
        return $state;
    }
    unset($state['_bp7_baton_stack_pending']);
    $incoming = $state['players'][$pid]['stage'][$targetSlot] ?? null;
    if (!$incoming) {
        return $state;
    }
    foreach ($pending as $entry) {
        $ab = $entry['ability'] ?? [];
        $leaving = $entry['leaving'] ?? [];
        if (!is_array($leaving) || empty($leaving)) continue;
        $wantIid = (string)($entry['incoming'] ?? '');
        if ($wantIid !== '' && ($incoming['instance_id'] ?? '') !== $wantIid) continue;
        $lname = bp7SourceName($leaving);
        if (($entry['kind'] ?? '') === 'auto_on_leave_baton_stack_self_under') {
            $group = $ab['group'] ?? '';
            if ($group !== '' && !cardMatchesGroup($incoming, $group, 'member')) continue;
            $leaveId = (string)($leaving['instance_id'] ?? '');
            $member = $state['players'][$pid]['stage'][$targetSlot];
            foreach ($member['stacked_members'] ?? [] as $existing) {
                if (($existing['instance_id'] ?? '') === $leaveId) {
                    continue 2;
                }
            }
            $card = bp7TakeCardsFromWaitingRoom(
                $state['players'][$pid],
                [$leaveId]
            );
            $stacked = $card[0] ?? $leaving;
            mergeCardCatalogFields($stacked);
            $member['stacked_members'] = array_merge($member['stacked_members'] ?? [], [$stacked]);
            $state['players'][$pid]['stage'][$targetSlot] = $member;
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$lname] put itself under " . cardDisplayName($incoming) . ' (Baton Touch).');
            continue;
        }
        if (($entry['kind'] ?? '') === 'auto_on_leave_baton_energy_deck_stack') {
            $group = $ab['group'] ?? '';
            if ($group !== '' && !cardMatchesGroup($leaving, $group, 'member')
                && !cardMatchesGroup($incoming, $group, 'member')) {
                continue;
            }
            $n = bp7EnergyDeckUnderMember($state, $pid, $targetSlot, max(1, intval($ab['count'] ?? 1)));
            if ($n > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$lname] put $n Energy card(s) from the Energy deck under " .
                    cardDisplayName($incoming) . ' (Baton Touch).');
            }
        }
    }
    return $state;
}

/** PL!SP-bp7-005 "when this Member enters" half of the shared [Automatic]. */
function bp7ResolveOnEnterAutos(array $state, string $pid, array $entered): array {
    return bp7ResolveEnergyWaitLocked($state, $pid, 'entered', (string)($entered['instance_id'] ?? ''));
}

/* ------------------------------------------------------------------ *
 * Prompt resolution
 * ------------------------------------------------------------------ */

function bp7PromptSource(array $state, string $owner, array $prompt): array {
    $src = findSourceCard($state, $owner, (string)($prompt['source_id'] ?? ''));
    if (is_array($src)) {
        return $src;
    }
    return [
        'instance_id' => (string)($prompt['source_id'] ?? ''),
        'name_en'     => (string)($prompt['source_name'] ?? 'Member'),
    ];
}

/**
 * Park a freshly opened prompt so bp7FinishPrompt can publish it after the current
 * one is cleared. Without this, `unset($state['pending_prompt'])` in the finisher
 * would swallow the follow-up step of a multi-stage BP07 effect.
 */
function bp7QueueChain(array $state): array {
    if (!empty($state['pending_prompt'])) {
        $state['_bp7_chain_prompt'] = $state['pending_prompt'];
        unset($state['pending_prompt']);
    }
    return $state;
}

function bp7FinishPrompt(array $state, array $prompt): array {
    unset($state['pending_prompt']);
    $state['seq']++;
    if (!empty($state['_bp7_chain_prompt'])) {
        $state['pending_prompt'] = $state['_bp7_chain_prompt'];
        unset($state['_bp7_chain_prompt']);
        return $state;
    }
    // PL!S-bp7-005 triggers two [On Enter] abilities; the second waits for the first.
    if (!empty($state['_bp7_deferred_on_enter'])) {
        $deferred = $state['_bp7_deferred_on_enter'];
        unset($state['_bp7_deferred_on_enter']);
        $owner = (string)($prompt['owner'] ?? '');
        $slot = (string)($deferred['slot'] ?? '');
        if ($owner !== '' && $slot !== '') {
            $state = bp7TriggerFirstOnEnter($state, $owner, $slot, (string)($deferred['name'] ?? 'Member'));
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
        }
    }
    if (!empty($state['_bp7_deferred_then'])) {
        $deferred = $state['_bp7_deferred_then'];
        unset($state['_bp7_deferred_then']);
        $owner = (string)($prompt['owner'] ?? '');
        $src = findSourceCard($state, $owner, (string)($deferred['source_id'] ?? ''));
        if ($owner !== '' && is_array($deferred['ability'] ?? null)) {
            $state = bp7ResolveThen($state, $owner, is_array($src) ? $src : [], $deferred['ability'], []);
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
        }
    }
    return finishAfterBranchChoicePrompt($state, $prompt);
}

function bp7PickedIds(array $prompt, string $choice, array $data): array {
    $ids = $data['card_ids'] ?? $data['discard_ids'] ?? null;
    if (!is_array($ids)) {
        $single = $data['card_id'] ?? null;
        $ids = is_string($single) && $single !== '' ? [$single] : [];
    }
    if (empty($ids) && ($choice === 'skip' || $choice === 'no')) {
        return [];
    }
    return array_values(array_filter($ids, 'is_string'));
}

function bp7ResolvePrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $promptType = (string)($prompt['type'] ?? '');

    // Intercept the shared surveil UI when BP07 routes the leftovers somewhere new.
    if ($promptType === 'surveil_arrange' && !empty($state['_surveil_chain']['bp7_rest'])) {
        return bp7ResolveSurveilArrange($state, $owner, $prompt, $data);
    }

    if (!in_array($promptType, [
        'bp7_confirm',
        'bp7_pick_cards',
        'bp7_pick_stage_member',
        'bp7_pick_slot',
        'bp7_choose_player',
    ], true)) {
        return null;
    }

    $action = (string)($prompt['bp7_action'] ?? '');
    $ab = is_array($prompt['ability'] ?? null) ? $prompt['ability'] : [];
    $name = (string)($prompt['source_name'] ?? 'Member');
    $source = bp7PromptSource($state, $owner, $prompt);
    $ids = bp7PickedIds($prompt, $choice, $data);
    $slot = (string)($data['slot'] ?? '');
    if ($slot === '' && $promptType === 'bp7_pick_stage_member') {
        foreach ($prompt['candidates'] ?? [] as $cand) {
            if (($cand['instance_id'] ?? '') === ($data['card_id'] ?? $choice)) {
                $slot = (string)($cand['slot'] ?? '');
                break;
            }
        }
    }
    $yes = $choice === 'yes';
    $turn = intval($state['turn'] ?? 1);
    $opp = ($owner === 'p1') ? 'p2' : 'p1';

    switch ($action) {

        case 'stack_wr_copy_hearts': {
            $picked = bp7TakeCardsFromWaitingRoom($state['players'][$owner], array_slice($ids, 0, 1));
            if (empty($picked)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] no Member card put underneath.");
                break;
            }
            $card = $picked[0];
            mergeCardCatalogFields($card);
            $slot = bp7FindSlotByInstance($state['players'][$owner], (string)($prompt['source_id'] ?? ''));
            if ($slot === '') {
                $state = appendCardsToWaitingRoom($state, $owner, [$card]);
                break;
            }
            $member = $state['players'][$owner]['stage'][$slot];
            $member['stacked_members'] = array_merge($member['stacked_members'] ?? [], [$card]);
            if (!empty($card['hearts'])) {
                $member['bp7_hearts_override'] = $card['hearts'];
            }
            $state['players'][$owner]['stage'][$slot] = $member;
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] put " . cardDisplayName($card) .
                ' underneath; original hearts become that card\'s hearts until this Live ends.');
            break;
        }

        case 'energy_deck_stack_optional': {
            if (!$yes) break;
            $cands = bp7StageMemberCandidates($state['players'][$owner], ['group' => $ab['group'] ?? '']);
            if (empty($cands)) break;
            $count = max(1, intval($ab['count'] ?? 1));
            if (count($cands) === 1) {
                $n = bp7EnergyDeckUnderMember($state, $owner, $cands[0]['slot'], $count);
                if ($n > 0) {
                    $state = addLog($state, $state['players'][$owner]['name'] .
                        " — [$name] put $n Energy card(s) from the Energy deck under " .
                        $cands[0]['name_en'] . '.');
                }
                break;
            }
            $chained = bp7StartStagePick(
                $state, $owner, $source, $ab, ['phase' => !empty($prompt['live_start']) ? 'live_start' : ''],
                'energy_deck_stack_pick', $cands,
                'Choose a Member to put ' . $count . ' Energy card(s) from your Energy deck under.'
            );
            $state = bp7QueueChain($chained);
            break;
        }

        case 'energy_deck_stack_pick': {
            $n = bp7EnergyDeckUnderMember($state, $owner, $slot, max(1, intval($ab['count'] ?? 1)));
            if ($n > 0) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] put $n Energy card(s) from the Energy deck under " .
                    cardDisplayName($state['players'][$owner]['stage'][$slot] ?? []) . '.');
            }
            break;
        }

        case 'give_group_member_blade': {
            $amount = intval($ab['amount'] ?? 1);
            $pickedSlots = [];
            if ($slot !== '') {
                $pickedSlots[] = $slot;
            }
            foreach ($ids as $iid) {
                $found = bp7FindSlotByInstance($state['players'][$owner], $iid);
                if ($found !== '' && !in_array($found, $pickedSlots, true)) {
                    $pickedSlots[] = $found;
                }
            }
            $n = max(1, intval($ab['count'] ?? $ab['max_members'] ?? 1));
            $applied = 0;
            foreach (array_slice($pickedSlots, 0, $n) as $ps) {
                if (empty($state['players'][$owner]['stage'][$ps])) {
                    continue;
                }
                $state['players'][$owner]['stage'][$ps]['live_blade_bonus'] =
                    intval($state['players'][$owner]['stage'][$ps]['live_blade_bonus'] ?? 0) + $amount;
                $applied++;
            }
            if ($applied > 0) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] $applied Member(s) gained +$amount Blade until this Live ends.");
            }
            break;
        }

        case 'wr_bladeless_bottom_activate': {
            $picked = bp7TakeCardsFromWaitingRoom($state['players'][$owner], $ids);
            if (empty($picked)) break;
            putCardsOnMainDeckBottom($state, $owner, $picked);
            $activated = activateEnergyForPlayer(
                $state['players'][$owner],
                count($picked) * max(1, intval($ab['energy_per'] ?? 1))
            );
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] put " . count($picked) .
                " Member card(s) with no Blade hearts on the bottom of the deck and activated $activated Energy.");
            break;
        }

        case 'play_wr_empty_wait': {
            $slots = bp7EmptyPlayableSlots($state['players'][$owner], $turn);
            if (empty($ids) || empty($slots)) break;
            if (count($slots) === 1) {
                $state = bp7PlayWrMemberInWait($state, $owner, $ids[0], $slots[0], $name, $turn);
                break;
            }
            $chained = bp7StartChoices(
                $state, $owner, $source, $ab, [], 'play_wr_empty_slot',
                $slots,
                array_map(fn($s) => ucfirst($s) . ' area', $slots),
                'Choose the empty Stage area to play that Member into (in Wait).',
                ['wr_card_id' => $ids[0]]
            );
            $state = bp7QueueChain($chained);
            break;
        }

        case 'play_wr_empty_slot': {
            $state = bp7PlayWrMemberInWait(
                $state, $owner, (string)($prompt['wr_card_id'] ?? ''), $choice, $name, $turn
            );
            break;
        }

        case 'wait_group_member_choose_heart': {
            if ($slot === '' || empty($state['players'][$owner]['stage'][$slot])) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] skipped putting a Member into Wait.");
                break;
            }
            $member = $state['players'][$owner]['stage'][$slot];
            waitMember($member, $state);
            $state['players'][$owner]['stage'][$slot] = $member;
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] put " . cardDisplayName($member) . ' into Wait.');
            $state = bp7ResolveAutoOnAllyWait($state, $owner, $member);
            $state = bp7QueueChain($state);
            $heart = resolveAbilityEffect($state, $owner, $source, [
                'type'          => 'choose_heart_modifier',
                'trigger'       => $ab['trigger'] ?? 'live_start',
                'heart_choices' => ['pink', 'red', 'yellow', 'green', 'blue', 'purple'],
                'count'         => max(1, intval($ab['count'] ?? 1)),
            ], []);
            $state = bp7QueueChain($heart);
            break;
        }

        case 'look_reveal_bladeless':
        case 'look_reveal_any': {
            $looked = $state['_bp7_look_stash'] ?? [];
            unset($state['_bp7_look_stash']);
            $keepIds = array_slice($ids, 0, max(1, intval($ab['pick'] ?? 1)));
            $kept = [];
            $rest = [];
            foreach ($looked as $c) {
                if (in_array($c['instance_id'] ?? '', $keepIds, true)) {
                    $kept[] = $c;
                } else {
                    $rest[] = $c;
                }
            }
            foreach ($kept as $c) {
                $state['players'][$owner]['hand'][] = $c;
            }
            unset($state['pending_prompt']);
            if (!empty($rest)) {
                $state = appendDeckCardsToWaitingRoom($state, $owner, $rest);
                $state = bp7QueueChain($state);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] added " . count($kept) . ' card(s) to hand and put ' . count($rest) .
                ' into the Waiting Room.');
            break;
        }

        case 'discard_up_to_grant_blade': {
            $picked = bp7TakeCardsFromHand($state['players'][$owner], $ids);
            if (empty($picked)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] discarded nothing; no Blade granted.");
                break;
            }
            $state = appendCardsToWaitingRoom($state, $owner, $picked);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] put " . count($picked) . ' card(s) into the Waiting Room.');
            $cands = bp7StageMemberCandidates($state['players'][$owner], ['group' => $ab['group'] ?? '']);
            if (empty($cands)) break;
            $chained = bp7StartStagePick(
                $state, $owner, $source, $ab, [], 'grant_blade_members', $cands,
                'Choose up to ' . count($picked) . ' ' . ($ab['group'] ?? '') .
                ' Member(s) to gain +' . intval($ab['amount'] ?? 1) . ' Blade.',
                ['pick_max' => count($picked), 'multi' => true]
            );
            $state = bp7QueueChain($chained);
            break;
        }

        case 'grant_blade_members': {
            $slots = $data['slots'] ?? ($slot !== '' ? [$slot] : []);
            $max = max(1, intval($prompt['pick_max'] ?? 1));
            $done = 0;
            foreach ($slots as $s) {
                if ($done >= $max) break;
                if (empty($state['players'][$owner]['stage'][$s])) continue;
                $state['players'][$owner]['stage'][$s]['live_blade_bonus'] =
                    intval($state['players'][$owner]['stage'][$s]['live_blade_bonus'] ?? 0)
                    + intval($ab['amount'] ?? 1);
                $done++;
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] granted +" . intval($ab['amount'] ?? 1) . " Blade to $done Member(s).");
            break;
        }

        case 'score_if_member_most_blade': {
            if ($slot === '') break;
            $state = bp7ApplyMostBladeScore($state, $owner, $source, $ab, $slot, $name);
            break;
        }

        case 'shuffle_wr_all_bottom_group_hearts': {
            if (!$yes) break;
            $wr = $state['players'][$owner]['waiting_room'] ?? [];
            if (empty($wr)) break;
            $state['players'][$owner]['waiting_room'] = [];
            shuffle($wr);
            putCardsOnMainDeckBottom($state, $owner, $wr);
            $granted = 0;
            foreach ($state['players'][$owner]['stage'] as $s => $mbr) {
                if (!$mbr) continue;
                if (($ab['group'] ?? '') !== '' && !cardMatchesGroup($mbr, $ab['group'], 'member')) continue;
                $bonus = $mbr['bonus_hearts'] ?? [];
                foreach ($ab['hearts'] ?? [] as $h) {
                    $bonus[] = ['color' => $h['color'] ?? 'pink', 'count' => intval($h['count'] ?? 1)];
                }
                $state['players'][$owner]['stage'][$s]['bonus_hearts'] = $bonus;
                $granted++;
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] shuffled " . count($wr) .
                " Waiting Room card(s) onto the bottom of the deck; $granted Member(s) gained bonus heart(s).");
            break;
        }

        case 'unstack_energy_score': {
            if ($slot === '' || empty($state['players'][$owner]['stage'][$slot])) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] kept the Energy under the Member.");
                break;
            }
            $member = $state['players'][$owner]['stage'][$slot];
            $stack = $member['stacked_energy'] ?? [];
            unset($member['stacked_energy'], $member['stacked_energy_ids']);
            $state['players'][$owner]['stage'][$slot] = $member;
            foreach ($stack as $e) {
                $e['active'] = false;
                $state['players'][$owner]['energy_zone'][] = $e;
            }
            $moved = count($stack);
            if ($moved > 0) {
                $state = bp7ResolveAutoOnEnergyPlaced($state, $owner, $moved);
            }
            $total = countEnergyInZone($state['players'][$owner]);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] moved $moved Energy card(s) into the Energy zone in Wait (total $total).");
            if ($moved > 0 && $total >= intval($ab['min_energy'] ?? 10)) {
                bp7BumpSelfScore($state, $owner, $source, intval($ab['amount'] ?? 1), $name,
                    "$total Energy");
            }
            break;
        }

        case 'discard_add_wr_min_cost_named': {
            $picked = bp7TakeCardsFromHand($state['players'][$owner], $ids);
            if (empty($picked)) break;
            $state = appendCardsToWaitingRoom($state, $owner, $picked);
            $cands = array_values(array_filter(
                $state['players'][$owner]['waiting_room'],
                fn($c) => is_array($c) && bp7IsMemberCard($c)
                    && intval($c['cost'] ?? 0) >= intval($ab['min_cost'] ?? 10)
            ));
            if (empty($cands)) break;
            $chained = bp7StartCardPick(
                $state, $owner, $source, $ab, [], 'add_wr_min_cost_named_pick', $cands, 1,
                max(1, intval($ab['count'] ?? 1)),
                'Add 1 Member card with cost ' . intval($ab['min_cost'] ?? 10) .
                ' or more from your Waiting Room to your hand.'
            );
            $state = bp7QueueChain($chained);
            break;
        }

        case 'add_wr_min_cost_named_pick': {
            $picked = bp7TakeCardsFromWaitingRoom($state['players'][$owner], $ids);
            if (empty($picked)) break;
            $named = false;
            foreach ($picked as $c) {
                $state['players'][$owner]['hand'][] = $c;
                if (cardMatchesNames($c, $ab['names'] ?? [])) {
                    $named = true;
                }
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] added " . cardDisplayName($picked[0]) . ' to hand.');
            if ($named) {
                $srcSlot = bp7FindSlotByInstance($state['players'][$owner], (string)($prompt['source_id'] ?? ''));
                $amount = intval($ab['blade_amount'] ?? 2);
                if ($srcSlot !== '') {
                    $state['players'][$owner]['stage'][$srcSlot]['live_blade_bonus'] =
                        intval($state['players'][$owner]['stage'][$srcSlot]['live_blade_bonus'] ?? 0) + $amount;
                } else {
                    $state = applyModifierEffect($state, $owner, ['type' => 'blade_bonus', 'amount' => $amount]);
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] gains +$amount Blade (named Member added).");
            }
            break;
        }

        case 'look_top_optional_bottom': {
            $looked = $state['_bp7_look_stash'] ?? [];
            unset($state['_bp7_look_stash']);
            if (empty($looked)) break;
            if ($yes) {
                putCardsOnMainDeckBottom($state, $owner, $looked);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] put " . count($looked) . ' card(s) on the bottom of the deck.');
            } else {
                foreach (array_reverse($looked) as $c) {
                    array_unshift($state['players'][$owner]['main_deck'], $c);
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] left the card(s) on top of the deck.");
            }
            break;
        }

        case 'deck_bottom_to_position': {
            $looked = $state['_bp7_look_stash'] ?? [];
            unset($state['_bp7_look_stash']);
            if (empty($looked)) break;
            if ($yes) {
                $pos = max(1, intval($ab['deck_position'] ?? 4));
                $deck = $state['players'][$owner]['main_deck'] ?? [];
                $at = min($pos - 1, count($deck));
                array_splice($deck, $at, 0, $looked);
                $state['players'][$owner]['main_deck'] = $deck;
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] put " . cardDisplayName($looked[0]) . " {$pos}th from the top of the deck.");
            } else {
                putCardsOnMainDeckBottom($state, $owner, $looked);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] left the card on the bottom of the deck.");
            }
            break;
        }

        case 'discard_trigger_on_enter_pair': {
            $picked = bp7TakeCardsFromHand($state['players'][$owner], $ids);
            if (count($picked) < max(1, intval($ab['discard'] ?? 2))) {
                foreach ($picked as $c) {
                    $state['players'][$owner]['hand'][] = $c;
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] not enough cards discarded.");
                break;
            }
            $state = appendCardsToWaitingRoom($state, $owner, $picked);
            $others = bp7StageMemberCandidates($state['players'][$owner], [
                'group'      => $ab['group'] ?? '',
                'exclude_id' => (string)($prompt['source_id'] ?? ''),
            ]);
            $others = array_values(array_filter(
                $others,
                fn($c) => !empty(getAbilitiesByTrigger($state['players'][$owner]['stage'][$c['slot']] ?? [], 'on_enter'))
            ));
            if (empty($others)) break;
            $chained = bp7StartStagePick(
                $state, $owner, $source, $ab, [], 'trigger_on_enter_other', $others,
                'Choose 1 other ' . ($ab['group'] ?? '') .
                ' Member — trigger 1 [On Enter] ability of this Member and that Member.'
            );
            $state = bp7QueueChain($chained);
            break;
        }

        case 'trigger_on_enter_other': {
            $srcSlot = bp7FindSlotByInstance($state['players'][$owner], (string)($prompt['source_id'] ?? ''));
            if ($srcSlot !== '') {
                $state = bp7TriggerFirstOnEnter($state, $owner, $srcSlot, $name);
            }
            if (!empty($state['pending_prompt'])) {
                // The first [On Enter] opened its own prompt; the partner fires after it.
                $state['_bp7_deferred_on_enter'] = ['slot' => $slot, 'name' => $name];
                $state = bp7QueueChain($state);
                break;
            }
            if ($slot !== '') {
                $state = bp7TriggerFirstOnEnter($state, $owner, $slot, $name);
                $state = bp7QueueChain($state);
            }
            break;
        }

        case 'add_wr_max_cost_named_play': {
            $picked = bp7TakeCardsFromWaitingRoom($state['players'][$owner], $ids);
            if (empty($picked)) break;
            $named = null;
            foreach ($picked as $c) {
                $state['players'][$owner]['hand'][] = $c;
                if ($named === null && cardMatchesNames($c, $ab['names'] ?? [])) {
                    $named = $c;
                }
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] added " . cardDisplayName($picked[0]) . ' to hand.');
            if ($named === null) break;
            $slots = bp7EmptyPlayableSlots($state['players'][$owner], $turn);
            if (empty($slots)) break;
            $chained = bp7StartConfirm(
                $state, $owner, $source, $ab, [], 'play_added_named',
                'Play ' . cardDisplayName($named) . ' to an empty Stage area?',
                ['play_card_id' => $named['instance_id'] ?? '']
            );
            $state = bp7QueueChain($chained);
            break;
        }

        case 'play_added_named': {
            if (!$yes) break;
            $slots = bp7EmptyPlayableSlots($state['players'][$owner], $turn);
            $cardId = (string)($prompt['play_card_id'] ?? '');
            if (empty($slots) || $cardId === '') break;
            if (count($slots) === 1) {
                $state = bp7PlayHandMemberToSlot($state, $owner, $cardId, $slots[0], $name, $turn, false);
                break;
            }
            $chained = bp7StartChoices(
                $state, $owner, $source, $ab, [], 'play_added_named_slot',
                $slots,
                array_map(fn($s) => ucfirst($s) . ' area', $slots),
                'Choose the empty Stage area.',
                ['play_card_id' => $cardId]
            );
            $state = bp7QueueChain($chained);
            break;
        }

        case 'play_added_named_slot': {
            $state = bp7PlayHandMemberToSlot(
                $state, $owner, (string)($prompt['play_card_id'] ?? ''), $choice, $name, $turn, false
            );
            break;
        }

        case 'wr_group_bottom_blade_per': {
            $picked = bp7TakeCardsFromWaitingRoom($state['players'][$owner], $ids);
            if (empty($picked)) break;
            putCardsOnMainDeckBottom($state, $owner, $picked);
            $bonus = count($picked) * intval($ab['blade_per'] ?? 1);
            $srcSlot = bp7FindSlotByInstance($state['players'][$owner], (string)($prompt['source_id'] ?? ''));
            if ($srcSlot !== '') {
                $state['players'][$owner]['stage'][$srcSlot]['live_blade_bonus'] =
                    intval($state['players'][$owner]['stage'][$srcSlot]['live_blade_bonus'] ?? 0) + $bonus;
            } else {
                $state = applyModifierEffect($state, $owner, ['type' => 'blade_bonus', 'amount' => $bonus]);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] put " . count($picked) .
                " card(s) on the bottom of the deck and gains +$bonus Blade.");
            break;
        }

        case 'wr_group_bottom_blade_if_bladeless': {
            $picked = bp7TakeCardsFromWaitingRoom($state['players'][$owner], $ids);
            if (empty($picked)) break;
            putCardsOnMainDeckBottom($state, $owner, $picked);
            $bladeless = 0;
            foreach ($picked as $c) {
                if (bp7IsBladelessMember($c)) $bladeless++;
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] put " . count($picked) . " card(s) on the bottom of the deck ($bladeless bladeless).");
            if ($bladeless >= intval($ab['min_bladeless'] ?? 1)) {
                $amount = intval($ab['amount'] ?? 2);
                $srcSlot = bp7FindSlotByInstance($state['players'][$owner], (string)($prompt['source_id'] ?? ''));
                if ($srcSlot !== '') {
                    $state['players'][$owner]['stage'][$srcSlot]['live_blade_bonus'] =
                        intval($state['players'][$owner]['stage'][$srcSlot]['live_blade_bonus'] ?? 0) + $amount;
                } else {
                    $state = applyModifierEffect($state, $owner, ['type' => 'blade_bonus', 'amount' => $amount]);
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] gains +$amount Blade.");
            }
            break;
        }

        case 'shuffle_wr_group_bottom_all_blade': {
            $picked = bp7TakeCardsFromWaitingRoom($state['players'][$owner], $ids);
            $need = max(1, intval($ab['count'] ?? 9));
            if (count($picked) < $need) {
                foreach ($picked as $c) {
                    $state['players'][$owner]['waiting_room'][] = $c;
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] needs exactly $need card(s); nothing moved.");
                break;
            }
            shuffle($picked);
            putCardsOnMainDeckBottom($state, $owner, $picked);
            $amount = intval($ab['amount'] ?? 1);
            $n = 0;
            foreach ($state['players'][$owner]['stage'] as $s => $mbr) {
                if (!$mbr) continue;
                $state['players'][$owner]['stage'][$s]['live_blade_bonus'] =
                    intval($mbr['live_blade_bonus'] ?? 0) + $amount;
                $n++;
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] shuffled " . count($picked) .
                " card(s) onto the bottom of the deck; $n Stage Member(s) gain +$amount Blade.");
            break;
        }

        case 'mill_bottom_add_if_named': {
            if (!$yes) break;
            $taken = takeFromMainDeckBottom($state, $owner, max(1, intval($ab['count'] ?? 1)));
            if (empty($taken)) break;
            $toHand = [];
            $toWr = [];
            foreach ($taken as $c) {
                if (cardMatchesNames($c, $ab['names'] ?? [])) {
                    $toHand[] = $c;
                } else {
                    $toWr[] = $c;
                }
            }
            foreach ($toHand as $c) {
                $state['players'][$owner]['hand'][] = $c;
            }
            if (!empty($toWr)) {
                $state = appendCardsToWaitingRoom($state, $owner, $toWr);
                $state = bp7QueueChain(bp7ResolveAutoSelfMilled($state, $owner, $toWr));
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] bottom card: " . cardDisplayName($taken[0]) .
                (empty($toHand) ? ' → Waiting Room.' : ' → hand (named).'));
            break;
        }

        case 'formation_change_subunit_blade': {
            if (!$yes) break;
            $before = [];
            foreach ($state['players'][$owner]['stage'] as $s => $mbr) {
                $before[$s] = $mbr['instance_id'] ?? null;
            }
            formationRotatePlayerStage($state['players'][$owner]['stage']);
            $moved = [];
            $subunitMoved = false;
            foreach ($state['players'][$owner]['stage'] as $s => $mbr) {
                if (!$mbr) continue;
                if (($before[$s] ?? null) === ($mbr['instance_id'] ?? '')) continue;
                $moved[] = ['id' => $mbr['instance_id'] ?? '', 'slot' => (string)$s];
                if (($ab['subunit'] ?? '') !== '' && cardMatchesSubunit($mbr, $ab['subunit'])) {
                    $subunitMoved = true;
                }
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] Formation Change.");
            if ($subunitMoved) {
                $amount = intval($ab['amount'] ?? 2);
                $srcSlot = bp7FindSlotByInstance($state['players'][$owner], (string)($prompt['source_id'] ?? ''));
                if ($srcSlot !== '') {
                    $state['players'][$owner]['stage'][$srcSlot]['live_blade_bonus'] =
                        intval($state['players'][$owner]['stage'][$srcSlot]['live_blade_bonus'] ?? 0) + $amount;
                } else {
                    $state = applyModifierEffect($state, $owner, ['type' => 'blade_bonus', 'amount' => $amount]);
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] gains +$amount Blade (" . ($ab['subunit'] ?? '') . ' Member moved).');
            }
            $state = bp7ResolveAutoOnAreaMove($state, $owner, $moved);
            break;
        }

        case 'choose_player_wr_bottom': {
            $target = $choice === 'opponent' ? $opp : $owner;
            $cands = array_values(array_filter(
                $state['players'][$target]['waiting_room'] ?? [],
                fn($c) => is_array($c) && bp7IsMemberCard($c)
            ));
            if (empty($cands)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] that player's Waiting Room has no Member cards.");
                break;
            }
            $chained = bp7StartCardPick(
                $state, $owner, $source, $ab, [], 'player_wr_bottom_pick', $cands, 0,
                max(1, intval($ab['max_pick'] ?? 2)),
                'Put up to ' . intval($ab['max_pick'] ?? 2) .
                ' Member card(s) on the bottom of that deck (pick order = deck order).',
                ['wr_target' => $target]
            );
            $state = bp7QueueChain($chained);
            break;
        }

        case 'player_wr_bottom_pick': {
            $target = (string)($prompt['wr_target'] ?? $owner);
            $picked = bp7TakeCardsFromWaitingRoom($state['players'][$target], $ids);
            if (empty($picked)) break;
            putCardsOnMainDeckBottom($state, $target, $picked);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] put " . count($picked) . ' Member card(s) on the bottom of ' .
                $state['players'][$target]['name'] . "'s deck.");
            break;
        }

        case 'position_change_to_center': {
            if ($slot === '') break;
            $state = bp7PositionChangeToCenter($state, $owner, $slot, $name);
            break;
        }

        case 'return_energy_score_by_opp_gap': {
            if (!$yes) break;
            $moved = bp7ReturnEnergyToDeck($state, $owner, max(1, intval($ab['energy'] ?? 1)));
            if ($moved <= 0) break;
            $mine = countEnergyInZone($state['players'][$owner]);
            $theirs = countEnergyInZone($state['players'][$opp]);
            $gap = $theirs - $mine;
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] put $moved Energy into the Energy deck (opponent lead $gap).");
            if ($gap >= 2) {
                bp7BumpSelfScore($state, $owner, $source, intval($ab['amount_if_gap_2'] ?? 2), $name,
                    "opponent has $gap more Energy");
            } elseif ($gap === 1) {
                bp7BumpSelfScore($state, $owner, $source, intval($ab['amount'] ?? 1), $name,
                    'opponent has exactly 1 more Energy');
            }
            break;
        }

        case 'reveal_hand_stack_under_draw': {
            $picked = bp7TakeCardsFromHand($state['players'][$owner], array_slice($ids, 0, 1));
            if (empty($picked)) break;
            $card = $picked[0];
            $srcSlot = bp7FindSlotByInstance($state['players'][$owner], (string)($prompt['source_id'] ?? ''));
            if ($srcSlot === '') {
                $state['players'][$owner]['hand'][] = $card;
                break;
            }
            $member = $state['players'][$owner]['stage'][$srcSlot];
            $member['stacked_members'] = array_merge($member['stacked_members'] ?? [], [$card]);
            $state['players'][$owner]['stage'][$srcSlot] = $member;
            $state = queuePublicSkillReveal($state, $owner, [$card], $name, 'hand');
            $drawn = drawCardsForPlayer($state, $owner, max(0, intval($ab['draw'] ?? 2)));
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] revealed " . cardDisplayName($card) .
                " and put it underneath, then drew $drawn.");
            break;
        }

        case 'return_energy_to_deck_then': {
            if (!$yes) break;
            $moved = bp7ReturnEnergyToDeck($state, $owner, max(1, intval($ab['energy'] ?? 1)));
            if ($moved < max(1, intval($ab['energy'] ?? 1))) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] not enough Energy to return.");
                break;
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] put $moved Energy into the Energy deck.");
            if (!empty($state['pending_prompt'])) {
                // A hook (e.g. PL!SP-bp7-005) already opened a prompt; nest the `then` after it.
                $state['_bp7_deferred_then'] = ['ability' => $ab, 'source_id' => $source['instance_id'] ?? ''];
                $state = bp7QueueChain($state);
                break;
            }
            $state = bp7QueueChain(bp7ResolveThen($state, $owner, $source, $ab, [
                'phase' => !empty($prompt['live_start']) ? 'live_start' : '',
            ]));
            break;
        }

        case 'discard_hand_all_draw': {
            if (!$yes) break;
            $hand = $state['players'][$owner]['hand'] ?? [];
            if (empty($hand)) break;
            $state['players'][$owner]['hand'] = [];
            $state = appendCardsToWaitingRoom($state, $owner, $hand);
            $drawn = drawCardsForPlayer($state, $owner, max(1, intval($ab['draw'] ?? 6)));
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] put " . count($hand) . " card(s) into the Waiting Room and drew $drawn.");
            break;
        }

        case 'wr_one_per_subunit_bottom_draw': {
            $subunits = $ab['subunits'] ?? [];
            $byId = [];
            foreach ($state['players'][$owner]['waiting_room'] as $c) {
                $byId[$c['instance_id'] ?? ''] = $c;
            }
            $covered = [];
            foreach ($ids as $id) {
                $card = $byId[$id] ?? null;
                if (!is_array($card)) continue;
                foreach ($subunits as $su) {
                    if (isset($covered[$su])) continue;
                    if (cardMatchesSubunit($card, $su)) {
                        $covered[$su] = $id;
                        break;
                    }
                }
            }
            if (count($covered) < count($subunits)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] needs 1 card per subunit (" . implode(', ', $subunits) . '); skipped.');
                break;
            }
            $picked = bp7TakeCardsFromWaitingRoom($state['players'][$owner], array_values($covered));
            putCardsOnMainDeckBottom($state, $owner, $picked);
            $drawn = drawCardsForPlayer($state, $owner, max(1, intval($ab['draw'] ?? 1)));
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] put " . count($picked) .
                " card(s) on the bottom of the deck and drew $drawn.");
            break;
        }

        case 'discard_live_look_reveal': {
            $picked = bp7TakeCardsFromHand($state['players'][$owner], $ids);
            if (empty($picked)) break;
            $state = appendCardsToWaitingRoom($state, $owner, $picked);
            $looked = takeFromMainDeckTop($state, $owner, max(1, intval($ab['look'] ?? 5)));
            if (empty($looked)) break;
            $state['_bp7_look_stash'] = $looked;
            $chained = bp7StartCardPick(
                $state, $owner, $source, $ab, [], 'look_reveal_any', $looked, 1,
                max(1, intval($ab['pick'] ?? 1)),
                'Add ' . intval($ab['pick'] ?? 1) .
                ' of the looked cards to your hand; the rest go to the Waiting Room.'
            );
            $state = bp7QueueChain($chained);
            break;
        }

        case 'self_milled_recover': {
            if (!$yes) break;
            $need = max(1, intval($ab['discard'] ?? 1));
            if (count($state['players'][$owner]['hand'] ?? []) < $need) break;
            $chained = bp7StartCardPick(
                $state, $owner, $source, $ab, [], 'self_milled_discard',
                $state['players'][$owner]['hand'], $need, $need,
                "Put $need card(s) from your hand into the Waiting Room.",
                ['pick_zone' => 'hand', 'recover_id' => (string)($prompt['recover_id'] ?? '')]
            );
            $state = bp7QueueChain($chained);
            break;
        }

        case 'self_milled_discard': {
            $need = max(1, intval($ab['discard'] ?? 1));
            $picked = bp7TakeCardsFromHand($state['players'][$owner], array_slice($ids, 0, $need));
            if (count($picked) < $need) {
                foreach ($picked as $c) {
                    $state['players'][$owner]['hand'][] = $c;
                }
                break;
            }
            $state = appendCardsToWaitingRoom($state, $owner, $picked);
            $recovered = bp7TakeCardsFromWaitingRoom(
                $state['players'][$owner],
                [(string)($prompt['recover_id'] ?? '')]
            );
            if (!empty($recovered)) {
                $state['players'][$owner]['hand'][] = $recovered[0];
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] added " . cardDisplayName($recovered[0]) .
                    ' from the Waiting Room to hand.');
            }
            break;
        }

        case 'ally_wait_activate': {
            if (!$yes) break;
            $need = max(1, intval($ab['optional_discard'] ?? 1));
            if (count($state['players'][$owner]['hand'] ?? []) < $need) break;
            $chained = bp7StartCardPick(
                $state, $owner, $source, $ab, [], 'ally_wait_discard',
                $state['players'][$owner]['hand'], $need, $need,
                "Put $need card(s) from your hand into the Waiting Room to activate that Member.",
                ['pick_zone' => 'hand', 'waited_id' => (string)($prompt['waited_id'] ?? '')]
            );
            $state = bp7QueueChain($chained);
            break;
        }

        case 'ally_wait_discard': {
            $need = max(1, intval($ab['optional_discard'] ?? 1));
            $picked = bp7TakeCardsFromHand($state['players'][$owner], array_slice($ids, 0, $need));
            if (count($picked) < $need) {
                foreach ($picked as $c) {
                    $state['players'][$owner]['hand'][] = $c;
                }
                break;
            }
            $state = appendCardsToWaitingRoom($state, $owner, $picked);
            $waitedId = (string)($prompt['waited_id'] ?? '');
            foreach ($state['players'][$owner]['stage'] as $s => $mbr) {
                if (!$mbr || ($mbr['instance_id'] ?? '') !== $waitedId) continue;
                $member = $mbr;
                clearMemberWait($member);
                $member['active'] = true;
                $state['players'][$owner]['stage'][$s] = $member;
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$name] activated " . cardDisplayName($member) . '.');
                break;
            }
            break;
        }

        case 'live_success_mill_add_live': {
            $picked = bp7TakeCardsFromWaitingRoom($state['players'][$owner], array_slice($ids, 0, 1));
            if (empty($picked)) break;
            $state['players'][$owner]['hand'][] = $picked[0];
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$name] added " . cardDisplayName($picked[0]) . ' from the milled cards to hand.');
            bp7BumpSelfScore($state, $owner, $source, intval($ab['amount'] ?? 1), $name,
                'added a milled Live card to hand');
            break;
        }

        default:
            return null;
    }

    return bp7FinishPrompt($state, $prompt);
}

/** BP07 surveil variants: leftovers go to the deck bottom, or the keep pile is the bottom. */
function bp7ResolveSurveilArrange(array $state, string $owner, array $prompt, array $data): array {
    $looked = $state['surveil_stash'] ?? [];
    if (empty($looked)) {
        throw new Exception('No surveil cards');
    }
    $chain = $state['_surveil_chain'] ?? [];
    $keepIds = $data['top_ids'] ?? [];
    $restIds = $data['wr_ids'] ?? [];
    $allIds = array_column($looked, 'instance_id');
    $picked = array_merge($keepIds, $restIds);
    sort($allIds);
    sort($picked);
    if ($picked !== $allIds) {
        throw new Exception('Must assign every looked card');
    }
    $byId = [];
    foreach ($looked as $c) {
        $byId[$c['instance_id'] ?? ''] = $c;
    }
    $keep = [];
    foreach ($data['top_ids'] ?? [] as $id) {
        if (isset($byId[$id])) $keep[] = $byId[$id];
    }
    $rest = [];
    foreach ($data['wr_ids'] ?? [] as $id) {
        if (isset($byId[$id])) $rest[] = $byId[$id];
    }
    $target = (string)($chain['target'] ?? $owner);
    $name = (string)($prompt['source_name'] ?? 'Member');
    unset($state['surveil_stash'], $state['_surveil_chain'], $state['pending_prompt']);

    if (($chain['bp7_keep'] ?? '') === 'bottom') {
        putCardsOnMainDeckBottom($state, $target, $keep);
    } else {
        foreach (array_reverse($keep) as $c) {
            array_unshift($state['players'][$target]['main_deck'], $c);
        }
    }
    if (($chain['bp7_rest'] ?? '') === 'bottom') {
        putCardsOnMainDeckBottom($state, $target, $rest);
        $restLabel = 'the bottom of the deck';
    } else {
        if (!empty($rest)) {
            $state = appendCardsToWaitingRoom($state, $target, $rest);
        }
        $restLabel = 'the Waiting Room';
    }
    $state = addLog($state, $state['players'][$owner]['name'] .
        " — [$name] kept " . count($keep) . ' card(s) and put ' . count($rest) . " into $restLabel.");
    $state['seq']++;
    return finishAfterBranchChoicePrompt($state, $prompt);
}

/** PL!N-bp7-010: play a Waiting Room Member into an empty area in Wait and lock that area. */
function bp7PlayWrMemberInWait(
    array $state,
    string $pid,
    string $cardId,
    string $slot,
    string $srcName,
    int $turn
): array {
    if ($cardId === '' || $slot === '' || !empty($state['players'][$pid]['stage'][$slot])) {
        return $state;
    }
    $picked = bp7TakeCardsFromWaitingRoom($state['players'][$pid], [$cardId]);
    if (empty($picked)) {
        return $state;
    }
    $member = $picked[0];
    mergeCardCatalogFields($member);
    clearMemberWait($member);
    $member['entered_turn'] = $turn;
    $member['active'] = false;
    waitMember($member, $state);
    $state['players'][$pid]['stage'][$slot] = $member;
    $state['players'][$pid]['_bp7_locked_slots'][$slot] = $turn;
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$srcName] played " . cardDisplayName($member) .
        " from the Waiting Room to the $slot area in Wait (area locked this turn).");
    $state = resolveOnEnterAbilities($state, $pid, $member, $slot);
    return bp7ResolveOnEnterAutos($state, $pid, $member);
}

/** PL!S-bp7-007: play the just-added Member from hand for free. */
function bp7PlayHandMemberToSlot(
    array $state,
    string $pid,
    string $cardId,
    string $slot,
    string $srcName,
    int $turn,
    bool $inWait
): array {
    if ($cardId === '' || $slot === '' || !empty($state['players'][$pid]['stage'][$slot])) {
        return $state;
    }
    $picked = bp7TakeCardsFromHand($state['players'][$pid], [$cardId]);
    if (empty($picked)) {
        return $state;
    }
    $member = $picked[0];
    mergeCardCatalogFields($member);
    clearMemberWait($member);
    $member['entered_turn'] = $turn;
    $member['entered_from_hand'] = true;
    $member['active'] = !$inWait;
    if ($inWait) {
        waitMember($member, $state);
    }
    $state['players'][$pid]['stage'][$slot] = $member;
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$srcName] played " . cardDisplayName($member) . " to the $slot area.");
    $state = resolveOnEnterAbilities($state, $pid, $member, $slot);
    return bp7ResolveOnEnterAutos($state, $pid, $member);
}
