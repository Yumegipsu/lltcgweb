<?php
/**
 * BP08 "まじかる♡とり～と" (Magical Treat) effect handlers: PL!-bp8 (μ's), PL!N-bp8 (Nijigasaki),
 * PL!HS-bp8 (Hasunosora) and LL-bp8-001. Included by effects.php after bp7_effects.php.
 *
 * Prompts reuse the generic BP07 shapes (bp7_confirm / bp7_pick_cards / bp7_pick_stage_member /
 * bp7_pick_slot) so the client and CPU already know how to answer them. A BP08 prompt is told apart by
 * `bp7_action` starting with "bp8:" and resolved by bp8ResolvePrompt (called before bp7ResolvePrompt).
 * Every prompt carries `ability_index` + `effect_text` (the printed English line) so the client shows the
 * card's own rules line in the player's language.
 */

function bp8EffectTypes(): array {
    return [
        // --- μ's ---
        'bp8_reduce_live_zone_hearts_if_success_sum',
        'bp8_leave_self_deck_bottom',
        'bp8_wait_opp_max_original_hearts',
        'bp8_grant_group_wait_baton_cost_plus',
        'bp8_wait_discard_look_pick',
        'bp8_wr_bladeless_bottom_cost_plus',
        'bp8_add_wr_live_heart_then_discard',
        'bp8_wr_self_bottom_add_wr',
        'bp8_mill_top_blade_if_group_live',
        'bp8_optional_discard_pick_yell',
        'bp8_bonus_per_distinct_stacked_cost',
        'bp8_stack_hand_member_under_self',
        'bp8_wait_self_wr_to_bottom',
        'bp8_wait_opp_max_blade_if_success_sum',
        'bp8_wait_discard_add_wr',
        'bp8_success_sum_bonus',
        'bp8_optional_wr_bladeless_bottom_draw',
        'bp8_reveal_bladeless_bottom_look_pick',
        'bp8_score_if_live_zone_placed_max',
        'bp8_activate_named_wait_then_wait_opp',
        'bp8_on_success_placed_look_pick',
        'bp8_score_if_yell_blade_heart_count',
        'bp8_yell_orange_low_cost_bladeless',
        'bp8_pick_group_member_blade_per_opp_distinct',
        'bp8_named_member_most_stats_score',
        // --- Nijigasaki ---
        'bp8_zone_energy_under_self_and_other',
        'bp8_optional_unstack_energy_draw_discard',
        'bp8_wr_self_bottom_grant_heart',
        'bp8_hand_self_discard_add_wr',
        'bp8_reveal_hand_deck_top_choose_heart',
        'bp8_hand_cost_plus_per_energy_deck',
        'bp8_hearts_aura_group_and_opp',
        'bp8_stack_zone_energy_add_wr',
        'bp8_choose_heart_per_energy_stacked_group',
        'bp8_reveal_hand_self_bottom_wait_opp',
        'bp8_hand_bottom_draw',
        'bp8_mill_bottom_live_swap_zone',
        'bp8_live_total_if_yell_bladeless_group',
        'bp8_auto_on_refresh_draw_discard',
        'bp8_optional_mill_top',
        'bp8_optional_wr_bladeless_bottom_blade',
        'bp8_reveal_hand_bottom_wait_opp',
        'bp8_choose_heart_if_group_lacks',
        'bp8_discard_wr_live_to_zone_if_empty',
        'bp8_blade_per_other_group',
        'bp8_optional_discard_group_heart',
        'bp8_draw_per_energy_stacked_group',
        'bp8_member_hearts_to_color_score',
        'bp8_wr_to_deck_top',
        'bp8_mill_top_reduce_by_shared_colors',
        'bp8_score_per_surplus_color_lose_surplus',
        'bp8_auto_wr_to_live_zone_reduce',
        'bp8_mill_top_score_if_group_bladeless_cost',
        'bp8_most_hearts_member_live_total',
        // --- Hasunosora ---
        'bp8_mill_top_activate_energy_if_all_subunit',
        'bp8_auto_wr_to_deck_blade',
        'bp8_blade_per_own_cost',
        'bp8_optional_discard_cost_plus_activate_draw',
        'bp8_both_trim_hand',
        'bp8_blade_per_hand_below',
        'bp8_draw_plus_if_baton_named',
        'bp8_discard_copy_on_enter',
        'bp8_play_requires_subunit_on_stage',
        'bp8_auto_leave_if_no_other_subunit',
        'bp8_optional_discard_group_choose',
        'bp8_lock_low_cost_activated_if_named_active',
        'bp8_look_reveal_live_required_heart',
        'bp8_immune_opp_wait_if_named',
        'bp8_discard_reenter_subunit_member',
        'bp8_optional_wr_member_to_deck_top',
        'bp8_optional_discard_add_wr_if_stage_cost',
        'bp8_shuffle_wr_members_bottom_hearts_if_subunit',
        'bp8_blade_if_live_zone_subunit',
        'bp8_draw_if_other_group_member',
        'bp8_forced_discard',
        'bp8_optional_discard_add_wr_max_score',
        'bp8_return_self_boost_live_zone',
        'bp8_auto_yell_card_moved_score',
        'bp8_optional_pay_shuffle_wr_members_bottom',
        'bp8_score_if_subunit_and_deck_min',
        'bp8_optional_wait_members_reduce_hearts',
        'bp8_subunit_cost_plus_score_if_total',
        'bp8_return_subunit_member_play_same_cost',
        'bp8_optional_opp_draw_grant_heart',
        'bp8_score_hearts_per_success_subunit',
        // --- crossover ---
        'bp8_look_reveal_member_stack_under',
        'bp8_inherit_stacked_named_abilities',
    ];
}

function bp8IsEffectType(string $type): bool {
    return str_starts_with($type, 'bp8_') && in_array($type, bp8EffectTypes(), true);
}

/** Read by continuous hooks / automatic triggers only — never dispatched as an effect. */
function bp8PassiveTypes(): array {
    return [
        'bp8_reduce_live_zone_hearts_if_success_sum',
        'bp8_grant_group_wait_baton_cost_plus',
        'bp8_bonus_per_distinct_stacked_cost',
        'bp8_success_sum_bonus',
        'bp8_hand_cost_plus_per_energy_deck',
        'bp8_hearts_aura_group_and_opp',
        'bp8_blade_per_other_group',
        'bp8_blade_per_own_cost',
        'bp8_blade_per_hand_below',
        'bp8_play_requires_subunit_on_stage',
        'bp8_lock_low_cost_activated_if_named_active',
        'bp8_immune_opp_wait_if_named',
        'bp8_inherit_stacked_named_abilities',
        'bp8_auto_on_refresh_draw_discard',
        'bp8_auto_wr_to_live_zone_reduce',
        'bp8_auto_wr_to_deck_blade',
        'bp8_auto_leave_if_no_other_subunit',
        'bp8_auto_yell_card_moved_score',
        'bp8_on_success_placed_look_pick',
    ];
}

/* ------------------------------------------------------------------ *
 * Shared helpers
 * ------------------------------------------------------------------ */

function bp8Opp(string $pid): string {
    return $pid === 'p1' ? 'p2' : 'p1';
}

function bp8Name(array $source): string {
    return bp7SourceName($source);
}

function bp8PlayerName(array $state, string $pid): string {
    return (string)($state['players'][$pid]['name'] ?? $pid);
}

function bp8Log(array $state, string $pid, array $source, string $msg): array {
    return addLog($state, bp8PlayerName($state, $pid) . ' — [' . bp8Name($source) . '] ' . $msg);
}

function bp8NameMatches(array $card, array $names): bool {
    if ($names === []) {
        return false;
    }
    mergeCardCatalogFields($card);
    $own = [];
    foreach (['name', 'name_en'] as $k) {
        $v = trim((string)($card[$k] ?? ''));
        if ($v !== '') {
            $own[] = $v;
            $own[] = str_replace([' ', '　'], '', $v);
        }
    }
    foreach ($names as $n) {
        $n = trim((string)$n);
        if ($n === '') continue;
        if (in_array($n, $own, true) || in_array(str_replace([' ', '　'], '', $n), $own, true)) {
            return true;
        }
    }
    return false;
}

/** Printed name identity for "different name" comparisons (Japanese name, spaces removed). */
function bp8NameKey(array $card): string {
    mergeCardCatalogFields($card);
    return str_replace([' ', '　'], '', (string)($card['name'] ?? $card['name_en'] ?? ''));
}

/** Index of $ab inside $source['abilities'] (type + trigger, then param equality for duplicates). */
function bp8AbilityIndex(array $source, array $ab): int {
    $abs = $source['abilities'] ?? [];
    if (!$abs) {
        mergeCardCatalogFields($source);
        $abs = $source['abilities'] ?? [];
    }
    $fallback = -1;
    foreach ($abs as $i => $a) {
        if (($a['type'] ?? '') !== ($ab['type'] ?? '')) continue;
        if (($a['trigger'] ?? '') === ($ab['trigger'] ?? '')) {
            return intval($i);
        }
        if ($fallback < 0) $fallback = intval($i);
    }
    return $fallback;
}

/** The printed English line of an ability (blocks split on the trigger labels, like the client does). */
function bp8PrintedLine(array $source, array $ab): string {
    mergeCardCatalogFields($source);
    $text = (string)($source['text'] ?? '');
    if ($text === '') {
        return '';
    }
    $labels = [
        'on_enter' => '[On Enter]', 'live_start' => '[Live Start]', 'live_success' => '[Live Success]',
        'activated' => '[Activated]', 'continuous' => '[Always]', 'auto' => '[Automatic]',
        'on_leave_stage' => '[Automatic]',
    ];
    $lines = preg_split('/\n(?!•)/u', $text) ?: [$text];
    $trigger = (string)($ab['trigger'] ?? '');
    $label = $labels[$trigger] ?? '';
    $abs = $source['abilities'] ?? [];
    $idx = bp8AbilityIndex($source, $ab);
    if (count($lines) === count($abs) && $idx >= 0 && isset($lines[$idx])) {
        return trim($lines[$idx]);
    }
    if ($label !== '') {
        foreach ($lines as $line) {
            if (str_contains($line, $label)) {
                return trim($line);
            }
        }
    }
    return trim($lines[0]);
}

/** Prompt fields tying a prompt to its printed ability (client localizes from these). */
function bp8PromptMeta(array $source, array $ab): array {
    $idx = bp8AbilityIndex($source, $ab);
    $meta = ['effect_text' => bp8PrintedLine($source, $ab)];
    if ($idx >= 0) {
        $meta['ability_index'] = $idx;
    }
    return $meta;
}

function bp8SelfSlot(array $state, string $pid, array $source): string {
    return bp7FindSlotByInstance($state['players'][$pid], (string)($source['instance_id'] ?? ''));
}

function bp8IsBladeless(array $card): bool {
    mergeCardCatalogFields($card);
    return bp7IsBladelessMember($card);
}

function bp8CardMatches(array $card, array $cfg): bool {
    mergeCardCatalogFields($card);
    $filter = (string)($cfg['filter'] ?? '');
    if ($filter === 'member' && !bp7IsMemberCard($card)) return false;
    if ($filter === 'live' && !bp7IsLiveCard($card)) return false;
    $group = (string)($cfg['group'] ?? '');
    if ($group !== '' && !cardInGroup($card, $group)) return false;
    $sub = (string)($cfg['subunit'] ?? '');
    if ($sub !== '' && !cardMatchesSubunit($card, $sub)) return false;
    if (!empty($cfg['bladeless']) && !bp8IsBladeless($card)) return false;
    if (!empty($cfg['require_blade_hearts']) && (!bp7IsMemberCard($card) || !bp7MemberHasBladeHearts($card))) {
        return false;
    }
    if (isset($cfg['max_cost']) && (!bp7IsMemberCard($card) || intval($card['cost'] ?? 0) > intval($cfg['max_cost']))) {
        return false;
    }
    if (isset($cfg['min_cost']) && (!bp7IsMemberCard($card) || intval($card['cost'] ?? 0) < intval($cfg['min_cost']))) {
        return false;
    }
    if (isset($cfg['max_score']) && (!bp7IsLiveCard($card) || intval($card['score'] ?? 0) > intval($cfg['max_score']))) {
        return false;
    }
    if (!empty($cfg['required_heart']) && !bp8LiveRequiresColor($card, (string)$cfg['required_heart'])) {
        return false;
    }
    return true;
}

function bp8LiveRequiresColor(array $card, string $color): bool {
    if (!bp7IsLiveCard($card)) return false;
    foreach ($card['required_hearts'] ?? $card['hearts'] ?? [] as $h) {
        if (normalizeHeartColor((string)($h['color'] ?? '')) === $color && intval($h['count'] ?? 0) > 0) {
            return true;
        }
    }
    return false;
}

/** Waiting Room cards matching $cfg (catalog fields hydrated). */
function bp8WrCandidates(array $p, array $cfg): array {
    $out = [];
    foreach ($p['waiting_room'] ?? [] as $c) {
        if (is_array($c) && bp8CardMatches($c, $cfg)) {
            mergeCardCatalogFields($c);
            $out[] = $c;
        }
    }
    return $out;
}

function bp8HandCandidates(array $p, array $cfg, string $excludeId = ''): array {
    $out = [];
    foreach ($p['hand'] ?? [] as $c) {
        if (!is_array($c) || ($excludeId !== '' && ($c['instance_id'] ?? '') === $excludeId)) continue;
        if (bp8CardMatches($c, $cfg)) {
            mergeCardCatalogFields($c);
            $out[] = $c;
        }
    }
    return $out;
}

/** Take cards by id from a zone, keeping the order of $ids (first id first). */
function bp8TakeFromZone(array &$p, string $zone, array $ids): array {
    $byId = [];
    $rest = [];
    foreach ($p[$zone] ?? [] as $c) {
        $iid = is_array($c) ? (string)($c['instance_id'] ?? '') : '';
        if ($iid !== '' && in_array($iid, $ids, true) && !isset($byId[$iid])) {
            $byId[$iid] = $c;
        } else {
            $rest[] = $c;
        }
    }
    $p[$zone] = array_values($rest);
    $out = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) $out[] = $byId[$id];
    }
    return $out;
}

/** Success Live score total as card effects see it (includes PL!-bp8-013's +2). */
function bp8SuccessSum(array $state, string $pid): int {
    return sumSuccessLiveScores($state['players'][$pid] ?? [], $state, $pid);
}

/** PL!-bp8-013: +2 per copy while the Success zone has a card. Applied inside sumSuccessLiveScores. */
function bp8SuccessSumBonus(array $p): int {
    if (empty($p['success_lives'])) {
        return 0;
    }
    $bonus = 0;
    foreach ($p['stage'] ?? [] as $mbr) {
        if (!$mbr) continue;
        foreach ($mbr['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') === 'continuous' && ($ab['type'] ?? '') === 'bp8_success_sum_bonus') {
                $bonus += intval($ab['amount'] ?? 2);
            }
        }
    }
    return $bonus;
}

function bp8DistinctStackedCosts(array $member): int {
    $costs = [];
    foreach (bp7StackedMembers($member) as $sm) {
        mergeCardCatalogFields($sm);
        if (!bp7IsMemberCard($sm)) continue;
        $costs[intval($sm['cost'] ?? 0)] = true;
    }
    return count($costs);
}

function bp8StageHasSubunit(array $p, string $subunit, string $excludeId = ''): bool {
    foreach ($p['stage'] ?? [] as $mbr) {
        if (!$mbr || ($excludeId !== '' && ($mbr['instance_id'] ?? '') === $excludeId)) continue;
        if (cardMatchesSubunit($mbr, $subunit)) return true;
    }
    return false;
}

function bp8StageGroupCostTotal(array $state, string $pid, string $group): int {
    $sum = 0;
    foreach ($state['players'][$pid]['stage'] ?? [] as $mbr) {
        if ($mbr && ($group === '' || cardInGroup($mbr, $group))) {
            $sum += getEffectiveStageMemberCost($state, $pid, $mbr);
        }
    }
    return $sum;
}

/** Stage Member summaries (with slot) matching $fn. */
function bp8StageCandidates(array $p, callable $fn): array {
    $out = [];
    foreach ($p['stage'] ?? [] as $slot => $mbr) {
        if (!$mbr || !$fn($mbr, (string)$slot)) continue;
        $out[] = array_merge(cardPromptSummary($mbr), ['slot' => (string)$slot]);
    }
    return $out;
}

/** Heart colors a Stage Member currently has (printed after replacements + bonuses). */
function bp8MemberHeartColors(array $member): array {
    $colors = [];
    foreach (memberPerformanceHeartsFlat($member) as $c) {
        $c = normalizeHeartColor((string)$c);
        if (in_array($c, ['pink', 'red', 'yellow', 'green', 'blue', 'purple'], true)) {
            $colors[$c] = true;
        }
    }
    return array_keys($colors);
}

function bp8PrintedHeartColors(array $card): array {
    $colors = [];
    foreach ($card['hearts'] ?? [] as $h) {
        $c = normalizeHeartColor((string)($h['color'] ?? ''));
        if (in_array($c, ['pink', 'red', 'yellow', 'green', 'blue', 'purple'], true) && intval($h['count'] ?? 1) > 0) {
            $colors[$c] = true;
        }
    }
    return array_keys($colors);
}

/** Put Energy from the Energy zone under a Member: Wait (used) Energy first, then active. */
function bp8ZoneEnergyUnderMember(array &$state, string $pid, string $slot, int $count): int {
    $p = &$state['players'][$pid];
    if ($count <= 0 || empty($p['stage'][$slot])) {
        return 0;
    }
    $taken = [];
    foreach ([false, true] as $wantActive) {
        foreach ($p['energy_zone'] as $i => $e) {
            if (count($taken) >= $count) break;
            if (!is_array($e) || (bool)($e['active'] ?? false) !== $wantActive) continue;
            $taken[$i] = $e;
        }
    }
    if (empty($taken)) {
        return 0;
    }
    foreach (array_keys($taken) as $i) {
        unset($p['energy_zone'][$i]);
    }
    $p['energy_zone'] = array_values($p['energy_zone']);
    $member = $p['stage'][$slot];
    attachStackedEnergyCardsToMember($member, array_values($taken));
    $p['stage'][$slot] = $member;
    $state = bp7ResolveAutoOnEnergyStackedUnderMember($state, $pid);
    return count($taken);
}

/** Printed (original) heart count of a Member. */
function bp8OriginalHeartTotal(array $member): int {
    $card = $member;
    $no = (string)($member['card_no'] ?? '');
    if ($no !== '' && function_exists('tcgCatalogCardByNo')) {
        $cat = tcgCatalogCardByNo($no);
        if (is_array($cat)) $card = $cat;
    }
    $n = 0;
    foreach ($card['hearts'] ?? [] as $h) {
        $n += max(0, intval($h['count'] ?? 1));
    }
    return $n;
}

/**
 * Put 1 opponent Stage Member matching $cfg into Wait. 0 or 1 legal target resolves here; with 2+ targets the
 * shared wait_opponent_stage_pick prompt opens (it finishes the effect chain itself when answered).
 * Protected Members (PL!S-bp7-003, PL!HS-bp8-008 Izumi) are never offered.
 */
function bp8WaitOpp(array $state, string $pid, array $source, array $cfg, array $ctx): array {
    $opp = bp8Opp($pid);
    $slots = [];
    foreach ($state['players'][$opp]['stage'] ?? [] as $slot => $m) {
        if (!$m || memberIsInWait($m)) continue;
        if (bp7ProtectedFromOppWait($state, $opp, $m)) continue;
        if (isset($cfg['max_cost']) && intval($m['cost'] ?? 0) > intval($cfg['max_cost'])) continue;
        if (isset($cfg['max_original_blade']) && intval($m['blade'] ?? 0) > intval($cfg['max_original_blade'])) continue;
        if (isset($cfg['max_original_hearts']) && bp8OriginalHeartTotal($m) > intval($cfg['max_original_hearts'])) continue;
        $slots[] = (string)$slot;
    }
    if ($slots === []) {
        return bp8Log($state, $pid, $source, 'no opponent Member can be put into Wait.');
    }
    if (count($slots) === 1) {
        $target = $state['players'][$opp]['stage'][$slots[0]];
        if (waitOpponentMemberAtSlot($state, $opp, $slots[0], $pid)) {
            $state = addLog($state, bp8PlayerName($state, $opp) . ' — ' . cardDisplayName($target) .
                ' was put into Wait ([' . bp8Name($source) . ']).');
        }
        return $state;
    }
    return beginWaitOpponentStagePick(
        $state,
        $pid,
        bp8Name($source),
        array_merge(['pick_count' => 1], $cfg),
        (string)($source['instance_id'] ?? ''),
        ($ctx['phase'] ?? '') === 'live_start' || ($state['phase'] ?? '') === 'live_start_effects'
    );
}

/** Grant hearts until Live ends to the source Member (or the seat when it left the Stage). */
function bp8GrantSourceHearts(array $state, string $pid, array $source, array $hearts, int $times = 1): array {
    if ($times < 1 || $hearts === []) {
        return $state;
    }
    $slot = bp8SelfSlot($state, $pid, $source);
    if ($slot !== '') {
        return bp7Cl1GrantBonusHearts($state, $pid, $slot, $hearts, $times);
    }
    for ($i = 0; $i < $times; $i++) {
        addBonusHeartsToModifier($state, $pid, $hearts);
    }
    return $state;
}

function bp8HeartLabel(string $color): string {
    return ucfirst($color) . ' heart';
}

/* ------------------------------------------------------------------ *
 * Continuous hooks (called from effects.php / api.php)
 * ------------------------------------------------------------------ */

/** Blade from this Member's own BP08 Always abilities. */
function bp8ApplyContinuousBlade(array $state, string $pid, array $member, string $slot, array $ab): int {
    if (($ab['trigger'] ?? '') !== 'continuous') {
        return 0;
    }
    $p = $state['players'][$pid] ?? [];
    switch ($ab['type'] ?? '') {
        case 'bp8_bonus_per_distinct_stacked_cost':
            return bp8DistinctStackedCosts($member) * intval($ab['blade_per'] ?? 1);
        case 'bp8_blade_per_other_group': {
            $n = 0;
            $selfId = (string)($member['instance_id'] ?? '');
            foreach ($p['stage'] ?? [] as $other) {
                if (!$other || ($other['instance_id'] ?? '') === $selfId) continue;
                if (cardInGroup($other, (string)($ab['group'] ?? ''))) $n++;
            }
            return $n * intval($ab['amount'] ?? 1);
        }
        case 'bp8_blade_per_own_cost':
            return intdiv(max(0, getEffectiveStageMemberCost($state, $pid, $member)), max(1, intval($ab['per'] ?? 5)));
        case 'bp8_blade_per_hand_below': {
            $diff = intval($ab['threshold'] ?? 7) - count($p['hand'] ?? []);
            return max(0, $diff);
        }
    }
    return 0;
}

/** Hearts from this Member's own BP08 Always abilities. */
function bp8ApplyContinuousHearts(array $state, string $pid, array $member, string $slot, array $ab, array $hearts): array {
    if (($ab['trigger'] ?? '') !== 'continuous') {
        return $hearts;
    }
    if (($ab['type'] ?? '') === 'bp8_bonus_per_distinct_stacked_cost') {
        $n = bp8DistinctStackedCosts($member);
        for ($i = 0; $i < $n; $i++) {
            appendContinuousHeartsFromSpec($hearts, $ab['hearts'] ?? []);
        }
    }
    return $hearts;
}

/**
 * Board auras that give hearts to a Member from *another* card: PL!N-bp8-005 (Ai) gives 1 Red heart to every
 * Nijigasaki Member on its owner's Stage and to every Member on the opposing Stage (including itself).
 */
function bp8ApplyHeartAuras(array $state, string $pid, array $member, array $hearts): array {
    $opp = bp8Opp($pid);
    foreach ([$pid, $opp] as $owner) {
        foreach ($state['players'][$owner]['stage'] ?? [] as $src) {
            if (!$src) continue;
            foreach ($src['abilities'] ?? [] as $ab) {
                if (($ab['trigger'] ?? '') !== 'continuous' || ($ab['type'] ?? '') !== 'bp8_hearts_aura_group_and_opp') {
                    continue;
                }
                $applies = $owner === $pid
                    ? cardInGroup($member, (string)($ab['group'] ?? ''))
                    : true;
                if ($applies) {
                    appendContinuousHeartsFromSpec($hearts, $ab['hearts'] ?? []);
                }
            }
        }
    }
    return $hearts;
}

/** Stage cost from BP08 Always abilities (PL!-bp8-009 Nico: +2 per distinct-cost Member under it). */
function bp8ApplyContinuousMemberCost(array $state, string $pid, array $member, int $base): int {
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') continue;
        if (($ab['type'] ?? '') === 'bp8_bonus_per_distinct_stacked_cost') {
            $base += bp8DistinctStackedCosts($member) * intval($ab['cost_per'] ?? 2);
        }
    }
    return $base;
}

/** Hand play cost from BP08 Always abilities (PL!N-bp8-005 Ai: +1 per card in the Energy deck). */
function bp8ApplyHandCost(array $state, string $pid, array $card, int $cost): int {
    foreach ($card['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') continue;
        if (($ab['type'] ?? '') === 'bp8_hand_cost_plus_per_energy_deck') {
            $cost += count($state['players'][$pid]['energy_deck'] ?? []) * intval($ab['amount'] ?? 1);
        }
    }
    return $cost;
}

/** PL!HS-bp8-005 Kosuzu: can only be played while a DOLLCHESTRA Member is on the Stage. */
function bp8PlayBlockedReason(array $state, string $pid, array $card): ?string {
    mergeCardCatalogFields($card);
    foreach ($card['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') continue;
        if (($ab['type'] ?? '') === 'bp8_play_requires_subunit_on_stage') {
            $sub = (string)($ab['subunit'] ?? '');
            if (!bp8StageHasSubunit($state['players'][$pid] ?? [], $sub)) {
                return "You need a $sub Member on your Stage to play this card.";
            }
        }
    }
    return null;
}

/**
 * PL!-bp8-003 Kotori: μ's Members on her owner's Stage gain "when this Member in Wait is Baton Touched by a μ's
 * Member, the reduction equals this Member's cost + 1". Returns the extra reduction (0 when it does not apply).
 */
function bp8BatonExtraReduction(array $state, string $pid, array $incoming, array $occupant): int {
    if (!memberIsInWait($occupant)) {
        return 0;
    }
    $extra = 0;
    foreach ($state['players'][$pid]['stage'] ?? [] as $src) {
        if (!$src) continue;
        foreach ($src['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous' || ($ab['type'] ?? '') !== 'bp8_grant_group_wait_baton_cost_plus') {
                continue;
            }
            $group = (string)($ab['group'] ?? '');
            if (!cardInGroup($occupant, $group) || !cardInGroup($incoming, $group)) continue;
            // One grant is enough: the reduction becomes cost + bonus, it does not stack per Kotori.
            $extra = max($extra, intval($ab['bonus'] ?? 1));
        }
    }
    return $extra;
}

/** PL!HS-bp8-008 Izumi: not put into Wait by opponent effects while Ceras is on her Stage. */
function bp8ProtectedFromOppWait(array $state, string $pid, array $member): bool {
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous' || ($ab['type'] ?? '') !== 'bp8_immune_opp_wait_if_named') continue;
        $iid = (string)($member['instance_id'] ?? '');
        foreach ($state['players'][$pid]['stage'] ?? [] as $other) {
            if (!$other || ($other['instance_id'] ?? '') === $iid) continue;
            if (bp8NameMatches($other, $ab['names'] ?? [])) return true;
        }
    }
    return false;
}

/**
 * PL!HS-bp8-007 Ceras: while her owner has an active "Izumi Katsuragi", [Activated] abilities of cost ≤4 Members
 * on either Stage cannot be activated.
 */
function bp8ActivatedLockReason(array $state, string $pid, array $member, string $zone): ?string {
    if ($zone !== 'stage') {
        return null;
    }
    $cost = getEffectiveStageMemberCost($state, $pid, $member);
    foreach (['p1', 'p2'] as $owner) {
        foreach ($state['players'][$owner]['stage'] ?? [] as $src) {
            if (!$src) continue;
            foreach ($src['abilities'] ?? [] as $ab) {
                if (($ab['trigger'] ?? '') !== 'continuous'
                    || ($ab['type'] ?? '') !== 'bp8_lock_low_cost_activated_if_named_active') {
                    continue;
                }
                if ($cost > intval($ab['max_cost'] ?? 4)) continue;
                foreach ($state['players'][$owner]['stage'] ?? [] as $other) {
                    if ($other && bp8NameMatches($other, $ab['names'] ?? []) && !memberIsInWait($other)) {
                        return '[Activated] abilities of cost ' . intval($ab['max_cost'] ?? 4)
                            . ' or less Members cannot be activated (' . bp8Name($src) . ').';
                    }
                }
            }
        }
    }
    return null;
}

/** LL-bp8-001: [Activated] + [Live Start] abilities of the named Member cards under it. */
function bp8InheritedAbilitiesForTrigger(array $member, string $trigger): array {
    $names = null;
    $allowed = [];
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') === 'continuous' && ($ab['type'] ?? '') === 'bp8_inherit_stacked_named_abilities') {
            $names = $ab['names'] ?? [];
            $allowed = $ab['ability_triggers'] ?? ['activated', 'live_start'];
            break;
        }
    }
    if ($names === null || !in_array($trigger, $allowed, true)) {
        return [];
    }
    $out = [];
    foreach (bp7StackedMembers($member) as $sm) {
        mergeCardCatalogFields($sm);
        if (!bp7IsMemberCard($sm) || !bp8NameMatches($sm, $names)) continue;
        foreach ($sm['abilities'] ?? [] as $sab) {
            $t = $sab['trigger'] ?? '';
            if ($t === $trigger || ($trigger === 'live_start' && $t === 'on_enter_or_live_start')) {
                $out[] = $sab;
            }
        }
    }
    return $out;
}

/** The stacked-name predicate for LL-bp8-001, used by the shared inherit:{id}:{idx} activation path. */
function bp8InheritsStacked(array $member, array $stacked): bool {
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') === 'continuous' && ($ab['type'] ?? '') === 'bp8_inherit_stacked_named_abilities') {
            return bp8NameMatches($stacked, $ab['names'] ?? []);
        }
    }
    return false;
}

/**
 * PL!-bp8-001 Honoka: continuous gray reduction on μ's Lives (original score ≤4) while the Success total is ≥6.
 * Stored as `bp8_cont_reduction` on each Live card and re-evaluated at Live Start and before the heart check.
 */
function bp8RefreshContinuousLiveReductions(array $state, string $pid): array {
    if (empty($state['players'][$pid]['live_zone'])) {
        return $state;
    }
    $rules = [];
    foreach ($state['players'][$pid]['stage'] ?? [] as $src) {
        if (!$src) continue;
        foreach ($src['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'bp8_reduce_live_zone_hearts_if_success_sum') {
                $rules[] = $ab;
            }
        }
    }
    $sum = $rules ? bp8SuccessSum($state, $pid) : 0;
    foreach ($state['players'][$pid]['live_zone'] as &$lc) {
        if (!is_array($lc) || !isLiveTypeCard($lc)) continue;
        $n = 0;
        foreach ($rules as $ab) {
            if ($sum < intval($ab['min_success_score_sum'] ?? 6)) continue;
            $orig = $lc;
            mergeCardCatalogFields($orig);
            $printed = intval(tcgCatalogCardByNo((string)($lc['card_no'] ?? ''))['score'] ?? $orig['score'] ?? 0);
            if ($printed > intval($ab['max_original_score'] ?? 4)) continue;
            if (!cardInGroup($orig, (string)($ab['group'] ?? ''))) continue;
            $n += intval($ab['amount'] ?? 1);
        }
        if ($n > 0) {
            $lc['bp8_cont_reduction'] = $n;
        } else {
            unset($lc['bp8_cont_reduction']);
        }
    }
    unset($lc);
    return $state;
}

/* ------------------------------------------------------------------ *
 * Yell helpers (orange heart, recolors)
 * ------------------------------------------------------------------ */

/** True when PL!-bp8-023 makes this Yell card carry an orange Blade heart. */
function bp8YellCardHasOrange(array $state, string $pid, array $card): bool {
    $rule = $state['live_modifiers'][$pid]['bp8_yell_orange'] ?? null;
    if (!is_array($rule)) {
        return false;
    }
    mergeCardCatalogFields($card);
    return bp7IsMemberCard($card)
        && !bp7MemberHasBladeHearts($card)
        && intval($card['cost'] ?? 0) <= intval($rule['max_cost'] ?? 4);
}

/** Blade hearts a Yell card shows this Live: printed, after "become {color}" recolors, plus orange. */
function bp8YellCardBladeHearts(array $state, string $pid, array $card): array {
    mergeCardCatalogFields($card);
    $bh = is_array($card['blade_hearts'] ?? null) ? $card['blade_hearts'] : [];
    $lm = $state['live_modifiers'][$pid] ?? [];
    $color = !empty($lm['yell_blades_to_blue']) ? 'blue' : (string)($lm['yell_blades_to_color'] ?? '');
    if ($color !== '' && $bh !== []) {
        $bh = recolorYellBladeHeartsForLive($bh, $color);
    }
    if (bp8YellCardHasOrange($state, $pid, $card)) {
        $bh[] = 'orange';
    }
    return $bh;
}

/** Mark Yell cards for the client (orange heart) and give the heart check its wild heart. */
function bp8ApplyYellOrange(array $state, string $pid, array &$yellCards): void {
    foreach ($yellCards as &$yc) {
        if (!is_array($yc) || !bp8YellCardHasOrange($state, $pid, $yc)) continue;
        $bh = is_array($yc['blade_hearts'] ?? null) ? $yc['blade_hearts'] : [];
        if (!in_array('orange', $bh, true)) {
            $bh[] = 'orange';
        }
        $yc['blade_hearts'] = $bh;
        $yc['bp8_orange'] = true;
    }
    unset($yc);
}

/* ------------------------------------------------------------------ *
 * Zone helpers with BP08 bookkeeping
 * ------------------------------------------------------------------ */

/** Mill the top N cards into the Waiting Room (milled-card autos fire); returns [state, milled]. */
function bp8MillTop(array $state, string $pid, int $n): array {
    $milled = takeFromMainDeckTop($state, $pid, max(0, $n));
    if ($milled !== []) {
        $state = appendDeckCardsToWaitingRoom($state, $pid, $milled);
        refreshMainDeckFromWaitingRoom($state, $pid);
    }
    return [$state, $milled];
}

/** Stage Member → Waiting Room with leave triggers (stacked cards / Energy handled by the shared helpers). */
function bp8StageMemberToWaitingRoom(array $state, string $pid, string $slot): array {
    $mbr = $state['players'][$pid]['stage'][$slot] ?? null;
    if (!$mbr) {
        return [$state, null];
    }
    $state['players'][$pid]['stage'][$slot] = null;
    $leaving = $mbr;
    $state = appendCardsToWaitingRoom($state, $pid, [$mbr]);
    $state = resolveOnLeaveStageAbilities($state, $pid, $leaving);
    return [$state, $mbr];
}

/** Play a Waiting Room Member card to $slot (active, On Enter fires unless $silent). */
function bp8PlayFromWaitingRoom(array $state, string $pid, string $cardId, string $slot, string $srcName, bool $silent = false): array {
    if ($cardId === '' || $slot === '' || !empty($state['players'][$pid]['stage'][$slot])) {
        return $state;
    }
    $picked = bp8TakeFromZone($state['players'][$pid], 'waiting_room', [$cardId]);
    if (empty($picked)) {
        return $state;
    }
    $member = stripStageWaitState($picked[0]);
    mergeCardCatalogFields($member);
    clearMemberWait($member);
    $member['active'] = true;
    $member['entered_turn'] = intval($state['turn'] ?? 1);
    $member['entered_from_wr'] = true;
    unset($member['entered_from_hand'], $member['entered_via_baton']);
    $state['players'][$pid]['stage'][$slot] = $member;
    if (function_exists('notifyMemberEnteredStage')) {
        notifyMemberEnteredStage($state, $pid, $member);
    }
    $state = addLog($state, bp8PlayerName($state, $pid) . " — [$srcName] played " . cardDisplayName($member) .
        " from the Waiting Room to the $slot area.");
    if ($silent) {
        return $state;
    }
    $state = resolveOnEnterAbilities($state, $pid, $member, $slot);
    return bp7ResolveOnEnterAutos($state, $pid, $member);
}

/** Return a Stage Member to hand. Energy under it returns to the Energy deck; Member cards under it go to WR. */
function bp8StageMemberToHand(array $state, string $pid, string $slot): array {
    $mbr = $state['players'][$pid]['stage'][$slot] ?? null;
    if (!$mbr) {
        return [$state, null];
    }
    $p = &$state['players'][$pid];
    $p['stage'][$slot] = null;
    returnStackedEnergyOnLeave($p, $mbr);
    $under = bp7TakeStackedMembersFromHost($mbr);
    unset($mbr['stacked_members'], $mbr['stacked_energy'], $mbr['stacked_energy_ids']);
    $card = stripStageWaitState($mbr);
    unset($card['live_blade_bonus'], $card['bonus_hearts'], $card['live_cost_bonus'], $card['abilities_used']);
    $p['hand'][] = $card;
    unset($p);
    if (!empty($under)) {
        $state = appendCardsToWaitingRoom($state, $pid, $under);
    }
    return [$state, $card];
}

function bp8NoteLiveZoneDeparture(array &$state, string $pid): void {
    $turn = intval($state['turn'] ?? 0);
    $rec = $state['players'][$pid]['_bp8_live_zone_departed'] ?? null;
    if (!is_array($rec) || intval($rec['turn'] ?? -1) !== $turn) {
        $rec = ['turn' => $turn, 'n' => 0];
    }
    $rec['n']++;
    $state['players'][$pid]['_bp8_live_zone_departed'] = $rec;
}

function bp8LiveZonePlacedThisTurn(array $state, string $pid): int {
    $p = $state['players'][$pid] ?? [];
    $n = count(array_filter($p['live_zone'] ?? [], 'is_array'));
    $rec = $p['_bp8_live_zone_departed'] ?? null;
    if (is_array($rec) && intval($rec['turn'] ?? -1) === intval($state['turn'] ?? 0)) {
        $n += intval($rec['n'] ?? 0);
    }
    return $n;
}

/** PL!N-bp8-030 Stay: placed from WR into Live storage → Required Hearts −3 gray. Called by addLiveFromWrToZone. */
function bp8OnLiveFromWrToZone(array $card): array {
    mergeCardCatalogFields($card);
    foreach ($card['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') === 'auto' && ($ab['type'] ?? '') === 'bp8_auto_wr_to_live_zone_reduce') {
            if (!isset($card['hearts_color_reduction']) || !is_array($card['hearts_color_reduction'])) {
                $card['hearts_color_reduction'] = [];
            }
            $card['hearts_color_reduction']['any'] = intval($card['hearts_color_reduction']['any'] ?? 0)
                + intval($ab['amount'] ?? 3);
            $card['bp8_stay_reduced'] = true;
        }
    }
    return $card;
}

/* ------------------------------------------------------------------ *
 * Queued automatic abilities (flushed when no prompt is open)
 * ------------------------------------------------------------------ */

function bp8QueueAuto(array &$state, string $pid, array $entry): void {
    $state['_bp8_auto_queue'][] = array_merge(['pid' => $pid], $entry);
}

/** Snapshot Waiting Room ids at the start of an action (for "a WR card was put into the deck"). */
function bp8SnapshotWaitingRooms(array &$state): void {
    foreach (['p1', 'p2'] as $pid) {
        $ids = [];
        foreach ($state['players'][$pid]['waiting_room'] ?? [] as $c) {
            if (is_array($c) && ($c['instance_id'] ?? '') !== '') $ids[] = (string)$c['instance_id'];
        }
        $state['_bp8_wr_snapshot'][$pid] = $ids;
    }
}

/** PL!HS-bp8-001 Kaho: a card that was in the WR is now in the main deck → +3 Blade (once per turn). */
function bp8DetectWrToDeck(array &$state): void {
    foreach (['p1', 'p2'] as $pid) {
        $snap = $state['_bp8_wr_snapshot'][$pid] ?? null;
        if (!is_array($snap) || $snap === []) continue;
        $deckIds = [];
        foreach ($state['players'][$pid]['main_deck'] ?? [] as $c) {
            if (is_array($c)) $deckIds[(string)($c['instance_id'] ?? '')] = true;
        }
        $moved = false;
        foreach ($snap as $id) {
            if (isset($deckIds[$id])) { $moved = true; break; }
        }
        if (!$moved) continue;
        $turn = intval($state['turn'] ?? 0);
        foreach ($state['players'][$pid]['stage'] ?? [] as $slot => $mbr) {
            if (!$mbr) continue;
            foreach ($mbr['abilities'] ?? [] as $idx => $ab) {
                if (($ab['type'] ?? '') !== 'bp8_auto_wr_to_deck_blade') continue;
                if (!empty($ab['once_per_turn']) && isAbilityUsed($mbr, $idx)) continue;
                markAbilityUsed($mbr, $idx);
                $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + intval($ab['amount'] ?? 3);
                $state['players'][$pid]['stage'][$slot] = $mbr;
                $state = addLog($state, bp8PlayerName($state, $pid) . ' — [' . bp8Name($mbr) .
                    '] a Waiting Room card was put into the deck: +' . intval($ab['amount'] ?? 3) . ' Blade until this Live ends.');
            }
        }
        unset($turn);
    }
    bp8SnapshotWaitingRooms($state);
}

/** Deck refresh happened (ZoneMovement) — queue PL!N-bp8-011 Mia. */
function bp8NotifyDeckRefreshed(array &$state, string $pid): void {
    foreach ($state['players'][$pid]['stage'] ?? [] as $slot => $mbr) {
        if (!$mbr) continue;
        foreach ($mbr['abilities'] ?? [] as $idx => $ab) {
            if (($ab['type'] ?? '') !== 'bp8_auto_on_refresh_draw_discard') continue;
            if (!empty($ab['once_per_turn']) && isAbilityUsed($mbr, $idx)) continue;
            bp8QueueAuto($state, $pid, ['kind' => 'refresh', 'source_id' => $mbr['instance_id'] ?? '', 'idx' => $idx]);
        }
    }
}

/**
 * A Yell-revealed card of $pid was moved to another area by $pid's effect. PL!HS-bp8-018 Holiday∞Holiday
 * reads this flag (once per turn) in getLiveScoreBonusBreakdown and when the card enters the Success zone.
 */
function bp8NotifyYellCardMoved(array &$state, string $pid): void {
    $state['_bp8_yell_moved'][$pid] = intval($state['turn'] ?? 0);
}

function bp8YellMovedThisTurn(array $state, string $pid): bool {
    return isset($state['_bp8_yell_moved'][$pid]) && intval($state['_bp8_yell_moved'][$pid]) === intval($state['turn'] ?? 0);
}

/** PL!HS-bp8-018 score bonus while it sits in Live storage this turn (0 when no Yell card moved). */
function bp8HolidayScoreBonus(array $state, string $pid, array $card): int {
    if (!bp8YellMovedThisTurn($state, $pid)) {
        return 0;
    }
    mergeCardCatalogFields($card);
    $n = 0;
    foreach ($card['abilities'] ?? [] as $ab) {
        if (($ab['type'] ?? '') === 'bp8_auto_yell_card_moved_score') {
            $n += intval($ab['amount'] ?? 1);
        }
    }
    return $n;
}

/** PL!-bp8-021: this Live was placed in the Success Live storage → queue its look-and-pick. */
function bp8NotifySuccessPlaced(array &$state, string $pid, array $live): void {
    $card = $live;
    mergeCardCatalogFields($card);
    // The Holiday bonus belongs to the card itself, so it stays on the Success card's score.
    $holiday = bp8HolidayScoreBonus($state, $pid, $card);
    if ($holiday > 0) {
        $last = count($state['players'][$pid]['success_lives'] ?? []) - 1;
        if ($last >= 0 && ($state['players'][$pid]['success_lives'][$last]['instance_id'] ?? '') === ($live['instance_id'] ?? '')) {
            $state['players'][$pid]['success_lives'][$last]['score'] =
                intval($state['players'][$pid]['success_lives'][$last]['score'] ?? $card['score'] ?? 0) + $holiday;
        }
    }
    foreach ($card['abilities'] ?? [] as $ab) {
        if (($ab['type'] ?? '') !== 'bp8_on_success_placed_look_pick') continue;
        if (count($state['players'][$pid]['success_lives'] ?? []) > intval($ab['max_success'] ?? 1)) continue;
        bp8QueueAuto($state, $pid, ['kind' => 'success_look', 'source_id' => $live['instance_id'] ?? '', 'ability' => $ab,
            'source_name' => bp8Name($card)]);
    }
}

/** PL!HS-bp8-005 Kosuzu: no other DOLLCHESTRA Member on the Stage → this Member goes to the WR. */
function bp8CheckStateAutos(array $state): array {
    foreach (['p1', 'p2'] as $pid) {
        foreach ($state['players'][$pid]['stage'] ?? [] as $slot => $mbr) {
            if (!$mbr) continue;
            foreach ($mbr['abilities'] ?? [] as $ab) {
                if (($ab['type'] ?? '') !== 'bp8_auto_leave_if_no_other_subunit') continue;
                $sub = (string)($ab['subunit'] ?? '');
                if (bp8StageHasSubunit($state['players'][$pid], $sub, (string)($mbr['instance_id'] ?? ''))) continue;
                $state = addLog($state, bp8PlayerName($state, $pid) . ' — [' . bp8Name($mbr) .
                    "] no other $sub Member on the Stage: put into the Waiting Room.");
                [$state] = bp8StageMemberToWaitingRoom($state, $pid, (string)$slot);
                if (!empty($state['pending_prompt'])) {
                    return $state;
                }
                continue 2;
            }
        }
    }
    return $state;
}

/** Run queued BP08 automatic abilities while no prompt is open. Safe to call anywhere a prompt may close. */
function bp8FlushPendingAutos(array $state): array {
    if (!empty($state['pending_prompt'])) {
        return $state;
    }
    if (isset($state['_bp8_wr_snapshot'])) {
        bp8DetectWrToDeck($state);
    }
    $state = bp8CheckStateAutos($state);
    while (empty($state['pending_prompt']) && !empty($state['_bp8_auto_queue'])) {
        $entry = array_shift($state['_bp8_auto_queue']);
        $pid = (string)($entry['pid'] ?? '');
        if ($pid !== 'p1' && $pid !== 'p2') continue;
        $kind = (string)($entry['kind'] ?? '');
        $srcId = (string)($entry['source_id'] ?? '');
        if ($kind === 'refresh') {
            $slot = bp7FindSlotByInstance($state['players'][$pid], $srcId);
            if ($slot === '') continue;
            $mbr = $state['players'][$pid]['stage'][$slot];
            $idx = intval($entry['idx'] ?? 0);
            $ab = $mbr['abilities'][$idx] ?? null;
            if (!is_array($ab) || isAbilityUsed($mbr, $idx)) continue;
            markAbilityUsed($mbr, $idx);
            $state['players'][$pid]['stage'][$slot] = $mbr;
            $state = bp8Log($state, $pid, $mbr, 'deck refreshed: draw ' . intval($ab['draw'] ?? 2) .
                ', then put ' . intval($ab['discard'] ?? 1) . ' card(s) from hand into the Waiting Room.');
            $state = bp8DrawThenDiscard($state, $pid, $mbr, $ab, ['phase' => 'auto'],
                intval($ab['draw'] ?? 2), intval($ab['discard'] ?? 1));
            continue;
        }
        if ($kind === 'success_look') {
            $ab = is_array($entry['ability'] ?? null) ? $entry['ability'] : [];
            $src = findSourceCard($state, $pid, $srcId) ?? ['instance_id' => $srcId, 'name_en' => (string)($entry['source_name'] ?? 'Live')];
            $state = bp8LookPick($state, $pid, $src, $ab, ['phase' => 'auto'], intval($ab['look'] ?? 4),
                ['group' => $ab['group'] ?? '', 'filter' => $ab['filter'] ?? 'member'], 'hand');
            continue;
        }
    }
    return $state;
}

/** Called at the start of every player action and before Yell totals are read. */
function bp8BeginAction(array &$state): void {
    bp8SnapshotWaitingRooms($state);
}

/** Called after every player action (api.php applyAction). */
function bp8AfterAction(array $state): array {
    if (!empty($state['pending_prompt'])) {
        return $state;
    }
    return bp8FlushPendingAutos($state);
}

/* ------------------------------------------------------------------ *
 * Reusable effect steps
 * ------------------------------------------------------------------ */

/** Draw N, then discard M (prompted when the hand is larger than M). */
function bp8DrawThenDiscard(array $state, string $pid, array $source, array $ab, array $ctx, int $draw, int $discard): array {
    if ($draw > 0) {
        $drawn = drawCardsForPlayerWithEffectLog($state, $pid, bp8Name($source), $draw);
        unset($drawn);
    }
    return bp8StartDiscard($state, $pid, $source, $ab, $ctx, $discard, 'discard_done');
}

/** Mandatory discard of $n hand cards; opens a pick unless the whole hand goes. */
function bp8StartDiscard(array $state, string $pid, array $source, array $ab, array $ctx, int $n, string $then, array $extra = []): array {
    $hand = array_values(array_filter($state['players'][$pid]['hand'] ?? [], 'is_array'));
    if ($n <= 0 || $hand === []) {
        return $state;
    }
    // A discard that pays for more effect always opens the pick so the continuation runs from the answer.
    if ($then !== 'discard_done') {
        $k = min($n, count($hand));
        return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:' . $then, $hand, $k, $k,
            "Choose $k card(s) from your hand to put into the Waiting Room.",
            array_merge(bp8PromptMeta($source, $ab), ['bp8_discard' => true], $extra));
    }
    if (count($hand) <= $n) {
        $ids = array_map(fn($c) => (string)($c['instance_id'] ?? ''), $hand);
        $moved = takeDiscardedHandCards($state['players'][$pid], $ids, $state, $pid);
        return bp8Log($state, $pid, $source, 'put ' . count($moved) . ' card(s) from hand into the Waiting Room.');
    }
    return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:' . $then, $hand, $n, $n,
        "Choose $n card(s) from your hand to put into the Waiting Room.",
        array_merge(bp8PromptMeta($source, $ab), ['bp8_discard' => true], $extra));
}

/** Look at the top N: pick up to 1 matching card for $dest ('hand' | 'under'), the rest go to the WR. */
function bp8LookPick(array $state, string $pid, array $source, array $ab, array $ctx, int $look, array $cfg, string $dest, int $min = 0): array {
    $looked = takeFromMainDeckTop($state, $pid, $look);
    if ($looked === []) {
        return bp8Log($state, $pid, $source, 'no cards to look at.');
    }
    foreach ($looked as &$c) {
        mergeCardCatalogFields($c);
    }
    unset($c);
    $cands = array_values(array_filter($looked, fn($c) => bp8CardMatches($c, $cfg)));
    if ($cands === []) {
        $state = appendDeckCardsToWaitingRoom($state, $pid, $looked);
        refreshMainDeckFromWaitingRoom($state, $pid);
        return bp8Log($state, $pid, $source, 'looked at ' . count($looked) .
            ' card(s); none could be chosen. They went to the Waiting Room.');
    }
    $state['_bp8_look_stash'] = $looked;
    return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:look_pick', $cands, $min, 1,
        $dest === 'under'
            ? 'You may reveal 1 Member card and put it under this Member. The rest go to the Waiting Room.'
            : 'You may reveal 1 card and add it to your hand. The rest go to the Waiting Room.',
        array_merge(bp8PromptMeta($source, $ab), ['bp8_dest' => $dest, 'looked_cards' => array_map('cardPromptSummary', $looked)]));
}

/** Pick N cards from the WR (in order) and put them on the deck bottom. */
function bp8StartWrToBottom(array $state, string $pid, array $source, array $ab, array $ctx, array $cands, int $min, int $max, string $then): array {
    return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:' . $then, $cands, $min, $max,
        $max === 1
            ? 'Choose 1 card from your Waiting Room to put on the bottom of your deck.'
            : "Choose $max card(s) from your Waiting Room to put on the bottom of your deck, in order (first chosen goes in first).",
        bp8PromptMeta($source, $ab));
}

function bp8StartHeartChoice(array $state, string $pid, array $source, array $ab, array $ctx, string $then, array $colors, array $extra = []): array {
    return bp7StartChoices($state, $pid, $source, $ab, $ctx, 'bp8:' . $then, $colors,
        array_map(fn($c) => ucfirst($c) . ' ♡', $colors), 'Choose a heart color.',
        array_merge(bp8PromptMeta($source, $ab), $extra));
}

/* ------------------------------------------------------------------ *
 * Activated entry point
 * ------------------------------------------------------------------ */

/** Why an activated BP08 ability cannot start now (checked before any cost is paid). */
function bp8ActivatedBlockReason(array $state, string $pid, array $member, array $ab, string $zone): ?string {
    $p = $state['players'][$pid];
    $iid = (string)($member['instance_id'] ?? '');
    $hand = array_values(array_filter($p['hand'] ?? [], fn($c) => is_array($c) && ($c['instance_id'] ?? '') !== $iid));
    $type = (string)($ab['type'] ?? '');
    $needActive = in_array($type, ['bp8_wait_discard_look_pick', 'bp8_wait_self_wr_to_bottom', 'bp8_wait_discard_add_wr'], true);
    if ($needActive && $zone === 'stage' && memberIsInWait($member)) {
        return 'This Member is already in Wait.';
    }
    switch ($type) {
        case 'bp8_wait_discard_look_pick':
            if (count($hand) < intval($ab['discard'] ?? 1)) return 'You need a card in hand to discard.';
            return null;
        case 'bp8_wait_discard_add_wr':
            if (count($hand) < intval($ab['discard'] ?? 2)) return 'You need ' . intval($ab['discard'] ?? 2) . ' cards in hand to discard.';
            if (!bp8WrCandidates($p, ['group' => $ab['group'] ?? '', 'filter' => $ab['filter'] ?? 'live'])) {
                return 'No matching Live card in your Waiting Room.';
            }
            return null;
        case 'bp8_wr_bladeless_bottom_cost_plus':
            if (count(bp8WrCandidates($p, ['group' => $ab['group'] ?? '', 'filter' => 'member', 'bladeless' => true]))
                < intval($ab['count'] ?? 2)) {
                return 'Not enough Member cards with no Blade Hearts in your Waiting Room.';
            }
            return null;
        case 'bp8_wr_self_bottom_add_wr':
            if (!array_filter(bp8WrCandidates($p, ['group' => $ab['group'] ?? '', 'filter' => $ab['filter'] ?? 'live']),
                fn($c) => ($c['instance_id'] ?? '') !== $iid)) {
                return 'No matching Live card in your Waiting Room.';
            }
            return null;
        case 'bp8_wr_self_bottom_grant_heart':
            if (!bp8StageCandidates($p, fn($m) => cardInGroup($m, (string)($ab['group'] ?? '')))) {
                return 'You need a ' . groupPromptLabel((string)($ab['group'] ?? '')) . ' Member on your Stage.';
            }
            return null;
        case 'bp8_stack_hand_member_under_self':
            if (!bp8HandCandidates($p, ['filter' => 'member'], $iid)) return 'You have no Member card in hand.';
            return null;
        case 'bp8_hand_self_discard_add_wr':
            if (!bp8WrCandidates($p, ['filter' => 'member', 'require_blade_hearts' => true])) {
                return 'No Member card with Blade Hearts in your Waiting Room.';
            }
            return null;
        case 'bp8_stack_zone_energy_add_wr':
            if (count($p['energy_zone'] ?? []) < 1 + intval($ab['energy'] ?? 1)
                && countActiveEnergyInZone($p) < intval($ab['energy_cost'] ?? 1)) {
                return 'Not enough Energy.';
            }
            if (!bp8WrCandidates($p, ['group' => $ab['group'] ?? '', 'filter' => 'member'])) {
                return 'No matching Member card in your Waiting Room.';
            }
            return null;
        case 'bp8_hand_bottom_draw':
            if (count($hand) < 1) return 'You need a card in hand.';
            return null;
        case 'bp8_discard_wr_live_to_zone_if_empty':
            if (count($hand) < intval($ab['discard'] ?? 2)) return 'You need ' . intval($ab['discard'] ?? 2) . ' cards in hand to discard.';
            return null;
        case 'bp8_discard_copy_on_enter':
            if (count($hand) < 1) return 'You need a card in hand to discard.';
            return null;
        case 'bp8_discard_reenter_subunit_member':
            if (count($hand) < 1) return 'You need a card in hand to discard.';
            if (!bp8StageCandidates($p, fn($m) => ($m['instance_id'] ?? '') !== $iid
                && cardMatchesSubunit($m, (string)($ab['subunit'] ?? '')))) {
                return 'You need another ' . (string)($ab['subunit'] ?? '') . ' Member on your Stage.';
            }
            return null;
    }
    return null;
}

/**
 * [Activated] hook for BP08, called from actionActivateAbility before the BP07 hook.
 * @return array|null new state, or null when $ab is not a BP08 activated type
 */
function bp8ResolveActivatedAbility(array $state, string $pid, array &$p, array $member, $slot, array $ab, int|string $abilityIdx, array $data, string $zone = 'stage'): ?array {
    $type = (string)($ab['type'] ?? '');
    if (!bp8IsEffectType($type) || in_array($type, bp8PassiveTypes(), true)) {
        return null;
    }
    $maxUses = intval($ab['max_uses_per_turn'] ?? 0);
    $useKey = '_auto_uses_' . $abilityIdx;
    if ($maxUses > 0 && intval($member[$useKey] ?? 0) >= $maxUses) {
        throw new Exception('Ability already used the maximum number of times this turn');
    }
    $block = bp8ActivatedBlockReason($state, $pid, $member, $ab, $zone);
    if ($block !== null) {
        throw new Exception($block);
    }
    $cost = intval($ab['energy_cost'] ?? 0);
    if ($cost > 0 && !payEnergyCost($p, $cost)) {
        throw new Exception("Need $cost active Energy");
    }
    if (!empty($ab['once_per_turn'])) {
        markAbilityUsed($member, $abilityIdx);
    }
    if ($maxUses > 0) {
        $member[$useKey] = intval($member[$useKey] ?? 0) + 1;
    }
    if ($zone === 'stage' && $slot !== null && $slot !== '' && array_key_exists($slot, $p['stage'] ?? [])) {
        $p['stage'][$slot] = $member;
    }
    $state['players'][$pid] = $p;
    $state = bp8ResolveEffect($state, $pid, $member, $ab, [
        'slot'          => (string)($slot ?? ''),
        'phase'         => 'activated',
        'zone'          => $zone,
        'ability_index' => $abilityIdx,
        'data'          => $data,
    ]);
    if (!empty($state['pending_prompt'])) {
        $state['pending_prompt']['source_slot'] = (string)($slot ?? '');
    }
    $p = $state['players'][$pid];
    return $state;
}

/* ------------------------------------------------------------------ *
 * Effect resolution
 * ------------------------------------------------------------------ */

function bp8ResolveEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = (string)($ab['type'] ?? '');
    if (!bp8IsEffectType($type)) {
        return $state;
    }
    if (in_array($type, bp8PassiveTypes(), true)) {
        return $state;
    }
    mergeCardCatalogFields($source);
    $name = bp8Name($source);
    $opp = bp8Opp($pid);
    $iid = (string)($source['instance_id'] ?? '');
    $meta = bp8PromptMeta($source, $ab);
    $p = $state['players'][$pid];

    switch ($type) {

        /* ---------------- μ's ---------------- */

        case 'bp8_leave_self_deck_bottom': {
            $inWr = false;
            foreach ($p['waiting_room'] ?? [] as $c) {
                if (is_array($c) && ($c['instance_id'] ?? '') === $iid) { $inWr = true; break; }
            }
            if (!$inWr) {
                return bp8Log($state, $pid, $source, 'this card is no longer in the Waiting Room.');
            }
            return bp7StartConfirm($state, $pid, $source, $ab, $ctx, 'bp8:leave_self_bottom',
                'Put this card on the bottom of your deck?', $meta);
        }

        case 'bp8_wait_opp_max_original_hearts':
            return bp8WaitOpp($state, $pid, $source, ['max_original_hearts' => intval($ab['max_hearts'] ?? 4),
                'pick_count' => intval($ab['pick_count'] ?? 1)], $ctx);

        case 'bp8_wait_discard_look_pick':
        case 'bp8_wait_discard_add_wr': {
            $slot = (string)($ctx['slot'] ?? bp8SelfSlot($state, $pid, $source));
            if ($slot !== '' && !empty($state['players'][$pid]['stage'][$slot])) {
                waitMember($state['players'][$pid]['stage'][$slot], $state);
            }
            $state = bp8Log($state, $pid, $source, 'put this Member into Wait.');
            return bp8StartDiscard($state, $pid, $source, $ab, $ctx, intval($ab['discard'] ?? 1),
                $type === 'bp8_wait_discard_look_pick' ? 'cost_then_look' : 'cost_then_add_wr');
        }

        case 'bp8_wr_bladeless_bottom_cost_plus':
        case 'bp8_optional_wr_bladeless_bottom_draw': {
            $n = intval($ab['count'] ?? 2);
            $cands = bp8WrCandidates($p, ['group' => $ab['group'] ?? '', 'filter' => 'member', 'bladeless' => true]);
            if (count($cands) < $n) {
                return bp8Log($state, $pid, $source, "fewer than $n matching Member cards in the Waiting Room.");
            }
            $optional = $type === 'bp8_optional_wr_bladeless_bottom_draw';
            return bp8StartWrToBottom($state, $pid, $source, $ab, $ctx, $cands, $optional ? 0 : $n, $n,
                $optional ? 'bottom_then_draw' : 'bottom_then_cost_plus');
        }

        case 'bp8_add_wr_live_heart_then_discard': {
            $cands = bp8WrCandidates($p, ['filter' => 'live', 'required_heart' => (string)($ab['heart_color'] ?? 'yellow')]);
            if ($cands === []) {
                $state = bp8Log($state, $pid, $source, 'no Live card with ' . bp8HeartLabel((string)($ab['heart_color'] ?? 'yellow')) .
                    ' in its Required Hearts in the Waiting Room.');
                return bp8StartDiscard($state, $pid, $source, $ab, $ctx, intval($ab['discard'] ?? 1), 'discard_done');
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:wr_to_hand_then_discard', $cands, 1, 1,
                'Choose 1 Live card from your Waiting Room to add to your hand.', $meta);
        }

        case 'bp8_wr_self_bottom_add_wr': {
            $moved = bp8TakeFromZone($state['players'][$pid], 'waiting_room', [$iid]);
            if ($moved) {
                putCardsOnMainDeckBottom($state, $pid, $moved);
                $state = bp8Log($state, $pid, $source, 'put this card from the Waiting Room on the bottom of the deck.');
            }
            $cands = bp8WrCandidates($state['players'][$pid], ['group' => $ab['group'] ?? '', 'filter' => $ab['filter'] ?? 'live']);
            if ($cands === []) {
                return bp8Log($state, $pid, $source, 'no matching card to add.');
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:wr_to_hand', $cands, 1, 1,
                'Choose 1 card from your Waiting Room to add to your hand.', $meta);
        }

        case 'bp8_mill_top_blade_if_group_live': {
            [$state, $milled] = bp8MillTop($state, $pid, intval($ab['count'] ?? 4));
            $hit = false;
            foreach ($milled as $c) {
                if (bp8CardMatches($c, ['filter' => 'live', 'group' => $ab['group'] ?? ''])) { $hit = true; break; }
            }
            $state = bp8Log($state, $pid, $source, 'put ' . count($milled) . ' card(s) from the deck top into the Waiting Room' .
                ($hit ? '; a ' . groupPromptLabel((string)($ab['group'] ?? '')) . ' Live card was among them.' : '.'));
            if ($hit) {
                $state = bp7Cl1GrantSourceBlade($state, $pid, $iid, intval($ab['amount'] ?? 1));
                $state = bp8Log($state, $pid, $source, '+' . intval($ab['amount'] ?? 1) . ' Blade until this Live ends.');
            }
            return $state;
        }

        case 'bp8_optional_discard_pick_yell': {
            $pool = array_values(array_filter(bp7Cl1YellPool($state, $pid, $ctx),
                fn($c) => bp8CardMatches($c, ['group' => $ab['group'] ?? '', 'filter' => $ab['filter'] ?? ''])));
            if ($pool === [] || empty($p['hand'])) {
                return $state;
            }
            return bp7StartConfirm($state, $pid, $source, $ab, $ctx, 'bp8:discard_then_yell_pick',
                'Put 1 card from your hand into the Waiting Room to add 1 ' . groupPromptLabel((string)($ab['group'] ?? '')) .
                ' card revealed by Yell to your hand?', $meta);
        }

        case 'bp8_stack_hand_member_under_self': {
            $cands = bp8HandCandidates($p, ['filter' => 'member'], $iid);
            if ($cands === []) {
                return bp8Log($state, $pid, $source, 'no Member card in hand.');
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:hand_member_under_self', $cands, 1, 1,
                'Choose 1 Member card from your hand to put under this Member.', $meta);
        }

        case 'bp8_wait_self_wr_to_bottom': {
            $slot = (string)($ctx['slot'] ?? bp8SelfSlot($state, $pid, $source));
            if ($slot !== '' && !empty($state['players'][$pid]['stage'][$slot])) {
                waitMember($state['players'][$pid]['stage'][$slot], $state);
            }
            $state = bp8Log($state, $pid, $source, 'put this Member into Wait.');
            $cands = array_values(array_filter($state['players'][$pid]['waiting_room'] ?? [], 'is_array'));
            if ($cands === []) {
                return bp8Log($state, $pid, $source, 'no cards in the Waiting Room.');
            }
            return bp8StartWrToBottom($state, $pid, $source, $ab, $ctx, $cands, 0,
                min(intval($ab['max_pick'] ?? 2), count($cands)), 'bottom_done');
        }

        case 'bp8_wait_opp_max_blade_if_success_sum': {
            $sum = bp8SuccessSum($state, $pid);
            if ($sum < intval($ab['min_success_score_sum'] ?? 6)) {
                return bp8Log($state, $pid, $source, "Success Live total score is $sum (needs " .
                    intval($ab['min_success_score_sum'] ?? 6) . '); no effect.');
            }
            return bp8WaitOpp($state, $pid, $source, ['max_original_blade' => intval($ab['max_blade'] ?? 3),
                'pick_count' => intval($ab['pick_count'] ?? 1)], $ctx);
        }

        case 'bp8_reveal_bladeless_bottom_look_pick': {
            $cands = bp8HandCandidates($p, ['filter' => 'member', 'bladeless' => true], $iid);
            if ($cands === []) {
                return $state;
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:reveal_bottom_then_look', $cands, 0, 1,
                'You may reveal 1 Member card with no Blade Hearts from your hand to put it on the bottom of your deck.',
                array_merge($meta, ['choices' => ['skip']]));
        }

        case 'bp8_score_if_live_zone_placed_max': {
            $n = bp8LiveZonePlacedThisTurn($state, $pid);
            if ($n <= intval($ab['max_placed'] ?? 1)) {
                bp7BumpSelfScore($state, $pid, $source, intval($ab['amount'] ?? 2), $name,
                    "$n card(s) placed in Live storage this turn");
            }
            return $state;
        }

        case 'bp8_activate_named_wait_then_wait_opp': {
            $cands = bp8StageCandidates($p, fn($m) => memberIsInWait($m) && bp8NameMatches($m, $ab['names'] ?? []));
            if ($cands === []) {
                return bp8Log($state, $pid, $source, 'no matching Member in Wait on the Stage.');
            }
            if (count($cands) === 1) {
                return bp8ActivateThenWaitOpp($state, $pid, $source, $ab, $ctx, $cands[0]['slot']);
            }
            return bp7StartStagePick($state, $pid, $source, $ab, $ctx, 'bp8:activate_then_wait_opp', $cands,
                'Choose 1 Member in Wait to activate.', $meta);
        }

        case 'bp8_score_if_yell_blade_heart_count': {
            $color = (string)($ab['color'] ?? 'yellow');
            $n = 0;
            foreach (bp7YellCards($state, $pid, $ctx) as $c) {
                if (!is_array($c)) continue;
                foreach (bp8YellCardBladeHearts($state, $pid, $c) as $bh) {
                    $t = strtolower(is_array($bh) ? (string)($bh['type'] ?? $bh['color'] ?? '') : (string)$bh);
                    if ($t === $color) { $n++; break; }
                }
            }
            if ($n >= intval($ab['min_count'] ?? 5)) {
                bp7BumpSelfScore($state, $pid, $source, intval($ab['amount'] ?? 1), $name,
                    "$n Yell card(s) with a " . bp8HeartLabel($color) . ' Blade heart');
            } else {
                $state = bp8Log($state, $pid, $source, "$n Yell card(s) with a " . bp8HeartLabel($color) .
                    ' Blade heart (needs ' . intval($ab['min_count'] ?? 5) . ').');
            }
            return $state;
        }

        case 'bp8_yell_orange_low_cost_bladeless':
            $state = initLiveModifiers($state);
            $state['live_modifiers'][$pid]['bp8_yell_orange'] = ['max_cost' => intval($ab['max_cost'] ?? 4)];
            return bp8Log($state, $pid, $source, 'Yell Member cards with cost ' . intval($ab['max_cost'] ?? 4) .
                ' or less and no Blade Hearts have an orange Blade heart until this Live ends.');

        case 'bp8_pick_group_member_blade_per_opp_distinct': {
            $cands = bp8StageCandidates($p, fn($m) => cardInGroup($m, (string)($ab['group'] ?? '')));
            if ($cands === []) {
                return bp8Log($state, $pid, $source, 'no ' . groupPromptLabel((string)($ab['group'] ?? '')) . ' Member on the Stage.');
            }
            if (count($cands) === 1) {
                return bp8BladePerOppDistinct($state, $pid, $source, $cands[0]['slot']);
            }
            return bp7StartStagePick($state, $pid, $source, $ab, $ctx, 'bp8:blade_per_opp_distinct', $cands,
                'Choose 1 ' . groupPromptLabel((string)($ab['group'] ?? '')) . ' Member on your Stage.', $meta);
        }

        case 'bp8_named_member_most_stats_score': {
            $cands = bp8StageCandidates($p, fn($m) => bp8NameMatches($m, $ab['names'] ?? []));
            if ($cands === []) {
                return bp8Log($state, $pid, $source, 'no matching Member on the Stage.');
            }
            if (count($cands) === 1) {
                return bp8MostStatsScore($state, $pid, $source, $ab, $cands[0]['slot']);
            }
            return bp7StartStagePick($state, $pid, $source, $ab, $ctx, 'bp8:most_stats_score', $cands,
                'Choose 1 Member on your Stage.', $meta);
        }

        /* ---------------- Nijigasaki ---------------- */

        case 'bp8_zone_energy_under_self_and_other': {
            if (empty($p['energy_zone'])) {
                return bp8Log($state, $pid, $source, 'no Energy to place.');
            }
            return bp7StartConfirm($state, $pid, $source, $ab, $ctx, 'bp8:energy_under_self',
                'Put 1 Energy from your Energy storage under this Member?', $meta);
        }

        case 'bp8_optional_unstack_energy_draw_discard': {
            $cands = bp8StageCandidates($p, fn($m) => bp7StackedEnergyCount($m) > 0);
            if ($cands === []) {
                return $state;
            }
            return bp7StartStagePick($state, $pid, $source, $ab, $ctx, 'bp8:unstack_energy_draw', $cands,
                'You may choose a Member: put 1 Energy card from under it into your Energy storage in Wait.',
                array_merge($meta, ['choices' => ['skip']]));
        }

        case 'bp8_wr_self_bottom_grant_heart': {
            $moved = bp8TakeFromZone($state['players'][$pid], 'waiting_room', [$iid]);
            if ($moved) {
                putCardsOnMainDeckBottom($state, $pid, $moved);
                $state = bp8Log($state, $pid, $source, 'put this card from the Waiting Room on the bottom of the deck.');
            }
            $cands = bp8StageCandidates($state['players'][$pid], fn($m) => cardInGroup($m, (string)($ab['group'] ?? '')));
            if ($cands === []) {
                return $state;
            }
            if (count($cands) === 1) {
                return bp8GrantStageHearts($state, $pid, $source, $cands[0]['slot'], $ab['hearts'] ?? []);
            }
            return bp7StartStagePick($state, $pid, $source, $ab, $ctx, 'bp8:grant_hearts', $cands,
                'Choose 1 ' . groupPromptLabel((string)($ab['group'] ?? '')) . ' Member on your Stage.', $meta);
        }

        case 'bp8_hand_self_discard_add_wr': {
            $moved = bp8TakeFromZone($state['players'][$pid], 'hand', [$iid]);
            if ($moved) {
                $state = appendCardsToWaitingRoom($state, $pid, $moved);
                $state = bp8Log($state, $pid, $source, 'put this card from hand into the Waiting Room.');
            }
            $cands = bp8WrCandidates($state['players'][$pid], ['filter' => 'member', 'require_blade_hearts' => true]);
            if ($cands === []) {
                return $state;
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:wr_to_hand', $cands, 1, 1,
                'Choose 1 Member card with Blade Hearts from your Waiting Room to add to your hand.', $meta);
        }

        case 'bp8_reveal_hand_deck_top_choose_heart': {
            $cands = bp8HandCandidates($p, ['group' => $ab['group'] ?? '', 'filter' => 'member'], $iid);
            if ($cands === []) {
                return $state;
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:reveal_top_then_heart', $cands, 0, 1,
                'You may reveal 1 ' . groupPromptLabel((string)($ab['group'] ?? '')) .
                ' Member card from your hand to put it on top of your deck.', array_merge($meta, ['choices' => ['skip']]));
        }

        case 'bp8_stack_zone_energy_add_wr': {
            $slot = (string)($ctx['slot'] ?? bp8SelfSlot($state, $pid, $source));
            if ($slot !== '') {
                $n = bp8ZoneEnergyUnderMember($state, $pid, $slot, intval($ab['energy'] ?? 1));
                if ($n > 0) {
                    $state = bp8Log($state, $pid, $source, "put $n Energy under this Member.");
                }
            }
            $cands = bp8WrCandidates($state['players'][$pid], ['group' => $ab['group'] ?? '', 'filter' => $ab['filter'] ?? 'member']);
            if ($cands === []) {
                return $state;
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:wr_to_hand', $cands, 1, 1,
                'Choose 1 ' . groupPromptLabel((string)($ab['group'] ?? '')) . ' Member card from your Waiting Room to add to your hand.', $meta);
        }

        case 'bp8_choose_heart_per_energy_stacked_group': {
            $n = count(bp8StageCandidates($p, fn($m) => cardInGroup($m, (string)($ab['group'] ?? ''))
                && bp7StackedEnergyCount($m) > 0));
            if ($n < 1) {
                return bp8Log($state, $pid, $source, 'no ' . groupPromptLabel((string)($ab['group'] ?? '')) .
                    ' Member with Energy under it; no hearts gained.');
            }
            return bp8StartHeartChoice($state, $pid, $source, $ab, $ctx, 'heart_times', $ab['heart_choices'] ?? ['pink', 'red', 'yellow'],
                ['bp8_times' => $n]);
        }

        case 'bp8_reveal_hand_self_bottom_wait_opp': {
            $moved = bp8TakeFromZone($state['players'][$pid], 'hand', [$iid]);
            if ($moved) {
                putCardsOnMainDeckBottom($state, $pid, $moved);
                $state = bp8Log($state, $pid, $source, 'revealed this card from hand and put it on the bottom of the deck.');
            }
            $hasGroup = (bool)bp8StageCandidates($state['players'][$pid], fn($m) => cardInGroup($m, (string)($ab['group'] ?? '')));
            if (!$hasGroup) {
                return bp8Log($state, $pid, $source, 'no ' . groupPromptLabel((string)($ab['group'] ?? '')) . ' Member on the Stage; no Wait.');
            }
            return bp8WaitOpp($state, $pid, $source, ['max_cost' => intval($ab['max_cost'] ?? 2)], $ctx);
        }

        case 'bp8_hand_bottom_draw': {
            $cands = array_values(array_filter($p['hand'] ?? [], 'is_array'));
            if ($cands === []) {
                return $state;
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:hand_bottom_then_draw', $cands,
                intval($ab['count'] ?? 1), intval($ab['count'] ?? 1),
                'Choose 1 card from your hand to put on the bottom of your deck.', $meta);
        }

        case 'bp8_mill_bottom_live_swap_zone':
            if (empty($p['main_deck']) && empty($p['waiting_room'])) {
                return $state;
            }
            return bp7StartConfirm($state, $pid, $source, $ab, $ctx, 'bp8:mill_bottom_swap',
                'Put the bottom card of your deck into the Waiting Room?', $meta);

        case 'bp8_live_total_if_yell_bladeless_group': {
            $n = 0;
            foreach (bp7YellCards($state, $pid, $ctx) as $c) {
                if (is_array($c) && bp8CardMatches($c, ['filter' => 'member', 'group' => $ab['group'] ?? '', 'bladeless' => true])) $n++;
            }
            if ($n >= intval($ab['min_count'] ?? 3)) {
                $state = applyModifierEffect($state, $pid, ['type' => 'live_score_bonus', 'amount' => intval($ab['amount'] ?? 1)]);
                return bp8Log($state, $pid, $source, "$n Member card(s) with no Blade Hearts revealed by Yell: Live total score +" .
                    intval($ab['amount'] ?? 1) . '.');
            }
            return $state;
        }

        case 'bp8_optional_mill_top':
            return bp7StartConfirm($state, $pid, $source, $ab, $ctx, 'bp8:mill_top',
                'Put the top ' . intval($ab['count'] ?? 5) . ' cards of your deck into the Waiting Room?', $meta);

        case 'bp8_optional_wr_bladeless_bottom_blade': {
            $cands = bp8WrCandidates($p, ['filter' => 'member', 'bladeless' => true]);
            if ($cands === []) {
                return $state;
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:wr_bottom_then_blade', $cands, 0, 1,
                'You may put 1 Member card with no Blade Hearts from your Waiting Room on the bottom of your deck.',
                array_merge($meta, ['choices' => ['skip']]));
        }

        case 'bp8_reveal_hand_bottom_wait_opp': {
            $cands = bp8HandCandidates($p, ['group' => $ab['group'] ?? '', 'filter' => 'member', 'bladeless' => true,
                'min_cost' => intval($ab['min_cost'] ?? 11)], $iid);
            if ($cands === []) {
                return $state;
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:reveal_bottom_then_wait', $cands, 0, 1,
                'You may reveal 1 matching Member card from your hand to put it on the bottom of your deck.',
                array_merge($meta, ['choices' => ['skip']]));
        }

        case 'bp8_choose_heart_if_group_lacks': {
            $members = bp8StageCandidates($p, fn($m) => cardInGroup($m, (string)($ab['group'] ?? '')));
            if (count($members) < intval($ab['min_members'] ?? 3)) {
                return bp8Log($state, $pid, $source, 'fewer than ' . intval($ab['min_members'] ?? 3) . ' ' .
                    groupPromptLabel((string)($ab['group'] ?? '')) . ' Members on the Stage; no heart gained.');
            }
            return bp8StartHeartChoice($state, $pid, $source, $ab, $ctx, 'heart_if_lacking',
                ['pink', 'red', 'yellow', 'green', 'blue', 'purple']);
        }

        case 'bp8_discard_wr_live_to_zone_if_empty':
            return bp8StartDiscard($state, $pid, $source, $ab, $ctx, intval($ab['discard'] ?? 2), 'cost_then_live_to_zone');

        case 'bp8_optional_discard_group_heart': {
            $cands = bp8HandCandidates($p, ['group' => $ab['group'] ?? ''], $iid);
            if ($cands === []) {
                return $state;
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:discard_then_hearts', $cands, 0, 1,
                'You may put 1 ' . groupPromptLabel((string)($ab['group'] ?? '')) . ' card from your hand into the Waiting Room.',
                array_merge($meta, ['choices' => ['skip'], 'bp8_discard' => true]));
        }

        case 'bp8_draw_per_energy_stacked_group': {
            $n = count(bp8StageCandidates($p, fn($m) => cardInGroup($m, (string)($ab['group'] ?? ''))
                && bp7StackedEnergyCount($m) > 0));
            if ($n > 0) {
                drawCardsForPlayerWithEffectLog($state, $pid, $name, $n);
            }
            return bp8Log($state, $pid, $source, "drew $n card(s).");
        }

        case 'bp8_member_hearts_to_color_score': {
            $cands = bp8StageCandidates($p, fn($m) => cardInGroup($m, (string)($ab['group'] ?? '')));
            if ($cands === []) {
                return bp8Log($state, $pid, $source, 'no ' . groupPromptLabel((string)($ab['group'] ?? '')) . ' Member on the Stage.');
            }
            if (count($cands) === 1) {
                return bp8RecolorMemberHearts($state, $pid, $source, $ab, $cands[0]['slot']);
            }
            return bp7StartStagePick($state, $pid, $source, $ab, $ctx, 'bp8:recolor_hearts', $cands,
                'Choose 1 ' . groupPromptLabel((string)($ab['group'] ?? '')) . ' Member on your Stage.', $meta);
        }

        case 'bp8_wr_to_deck_top': {
            $cands = bp8WrCandidates($p, ['group' => $ab['group'] ?? '']);
            if ($cands === []) {
                return bp8Log($state, $pid, $source, 'no matching card in the Waiting Room.');
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:wr_to_top', $cands, 1, 1,
                'Choose 1 card from your Waiting Room to put on top of your deck.', $meta);
        }

        case 'bp8_mill_top_reduce_by_shared_colors': {
            [$state, $milled] = bp8MillTop($state, $pid, 1);
            $card = $milled[0] ?? null;
            if (!is_array($card)) {
                return bp8Log($state, $pid, $source, 'no card to put into the Waiting Room.');
            }
            mergeCardCatalogFields($card);
            if (!bp7IsMemberCard($card)) {
                return bp8Log($state, $pid, $source, 'put ' . cardDisplayName($card) . ' into the Waiting Room (not a Member card).');
            }
            $colors = bp8PrintedHeartColors($card);
            $stageColors = [];
            foreach ($state['players'][$pid]['stage'] ?? [] as $m) {
                if (!$m) continue;
                foreach (bp8MemberHeartColors($m) as $c) $stageColors[$c] = true;
            }
            $missing = array_values(array_filter($colors, fn($c) => !isset($stageColors[$c])));
            if ($colors === [] || $missing !== []) {
                return bp8Log($state, $pid, $source, 'put ' . cardDisplayName($card) .
                    ' into the Waiting Room; its heart colors are not all on your Stage.');
            }
            bumpLiveCardColorReduction($state, $pid, $iid, 'any', count($colors));
            return bp8Log($state, $pid, $source, 'put ' . cardDisplayName($card) . ' into the Waiting Room: required hearts reduced by ' .
                count($colors) . ' (one per shared color).');
        }

        case 'bp8_score_per_surplus_color_lose_surplus': {
            $color = (string)($ab['color'] ?? 'red');
            $surplus = $state['_live_excess_heart_colors'][$pid] ?? [];
            $n = 0;
            foreach (is_array($surplus) ? $surplus : [] as $h) {
                $c = normalizeHeartColor(is_array($h) ? (string)($h['color'] ?? '') : (string)$h);
                if ($c === $color) $n++;
            }
            $gain = min(intval($ab['max'] ?? 3), intdiv($n, max(1, intval($ab['per'] ?? 2))));
            if ($gain > 0) {
                bp7BumpSelfScore($state, $pid, $source, $gain, $name, "$n surplus " . bp8HeartLabel($color) . '(s)');
            }
            $state['_live_excess_hearts'][$pid] = 0;
            $state['_live_excess_heart_colors'][$pid] = [];
            $state['_live_success_no_excess'][$pid] = true;
            return bp8Log($state, $pid, $source, 'lost all surplus hearts.');
        }

        case 'bp8_mill_top_score_if_group_bladeless_cost': {
            [$state, $milled] = bp8MillTop($state, $pid, intval($ab['count'] ?? 5));
            $hit = false;
            foreach ($milled as $c) {
                if (bp8CardMatches($c, ['filter' => 'member', 'group' => $ab['group'] ?? '', 'bladeless' => true,
                    'min_cost' => intval($ab['min_cost'] ?? 9)])) { $hit = true; break; }
            }
            $state = bp8Log($state, $pid, $source, 'put ' . count($milled) . ' card(s) from the deck top into the Waiting Room.');
            if ($hit) {
                bp7BumpSelfScore($state, $pid, $source, intval($ab['amount'] ?? 1), $name,
                    'a matching Member card was among them');
            }
            return $state;
        }

        case 'bp8_most_hearts_member_live_total': {
            $best = null;
            $bestN = -1;
            $tie = false;
            foreach (['p1', 'p2'] as $owner) {
                foreach ($state['players'][$owner]['stage'] ?? [] as $slot => $m) {
                    if (!$m) continue;
                    $n = stageMemberTotalHeartCount($state, $owner, $m, (string)$slot);
                    if ($n > $bestN) { $bestN = $n; $best = [$owner, (string)$slot, $m]; $tie = false; }
                    elseif ($n === $bestN) { $tie = true; }
                }
            }
            if ($best === null || $tie) {
                return bp8Log($state, $pid, $source, 'no single Member has the most hearts; no effect.');
            }
            [$owner, $slot, $m] = $best;
            $state['players'][$owner]['stage'][$slot]['bp8_live_total_bonus'] =
                intval($state['players'][$owner]['stage'][$slot]['bp8_live_total_bonus'] ?? 0) + intval($ab['amount'] ?? 1);
            $state = applyModifierEffect($state, $owner, ['type' => 'live_score_bonus', 'amount' => intval($ab['amount'] ?? 1)]);
            return bp8Log($state, $pid, $source, cardDisplayName($m) . ' has the most hearts (' . $bestN .
                '): its owner\'s Live total score +' . intval($ab['amount'] ?? 1) . '.');
        }

        /* ---------------- Hasunosora ---------------- */

        case 'bp8_mill_top_activate_energy_if_all_subunit': {
            [$state, $milled] = bp8MillTop($state, $pid, intval($ab['count'] ?? 3));
            $all = $milled !== [];
            foreach ($milled as $c) {
                if (!bp8CardMatches($c, ['subunit' => (string)($ab['subunit'] ?? '')])) { $all = false; break; }
            }
            $state = bp8Log($state, $pid, $source, 'put ' . count($milled) . ' card(s) from the deck top into the Waiting Room.');
            if ($all && count($milled) >= intval($ab['count'] ?? 3)) {
                $n = activateEnergyForPlayer($state['players'][$pid], intval($ab['energy'] ?? 2));
                $state = bp8Log($state, $pid, $source, "all were " . (string)($ab['subunit'] ?? '') . " cards: activated $n Energy.");
            }
            return $state;
        }

        case 'bp8_optional_discard_cost_plus_activate_draw': {
            $cands = array_values(array_filter($p['hand'] ?? [], 'is_array'));
            if ($cands === []) {
                return $state;
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:discard_then_cost_plus', $cands, 0, 1,
                'You may put 1 card from your hand into the Waiting Room.', array_merge($meta, ['choices' => ['skip'], 'bp8_discard' => true]));
        }

        case 'bp8_both_trim_hand':
            return bp8StartTrim($state, $pid, $source, $ab, $ctx, $pid, true);

        case 'bp8_draw_plus_if_baton_named': {
            $n = intval($ab['draw'] ?? 1);
            $from = $source['baton_wr_member'] ?? null;
            if (is_array($from) && bp8NameMatches($from, $ab['names'] ?? [])) {
                $n += intval($ab['bonus_draw'] ?? 1);
            }
            drawCardsForPlayerWithEffectLog($state, $pid, $name, $n);
            return bp8Log($state, $pid, $source, "drew $n card(s).");
        }

        case 'bp8_discard_copy_on_enter':
            return bp8StartDiscard($state, $pid, $source, $ab, $ctx, 1, 'cost_then_copy_on_enter');

        case 'bp8_optional_discard_group_choose': {
            $cands = bp8HandCandidates($p, ['group' => $ab['group'] ?? ''], $iid);
            if ($cands === []) {
                return $state;
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:discard_then_choose', $cands, 0, 1,
                'You may put 1 ' . groupPromptLabel((string)($ab['group'] ?? '')) . ' card from your hand into the Waiting Room.',
                array_merge($meta, ['choices' => ['skip'], 'bp8_discard' => true]));
        }

        case 'bp8_look_reveal_live_required_heart':
            return bp8LookPick($state, $pid, $source, $ab, $ctx, intval($ab['look'] ?? 5),
                ['filter' => 'live', 'required_heart' => (string)($ab['heart_color'] ?? 'purple')], 'hand');

        case 'bp8_discard_reenter_subunit_member':
            return bp8StartDiscard($state, $pid, $source, $ab, $ctx, intval($ab['discard'] ?? 1), 'cost_then_reenter');

        case 'bp8_optional_wr_member_to_deck_top': {
            $cands = bp8WrCandidates($p, ['filter' => 'member', 'max_cost' => intval($ab['max_cost'] ?? 9)]);
            if ($cands === []) {
                return $state;
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:wr_to_top', $cands, 0, 1,
                'You may put 1 Member card with cost ' . intval($ab['max_cost'] ?? 9) . ' or less from your Waiting Room on top of your deck.',
                array_merge($meta, ['choices' => ['skip']]));
        }

        case 'bp8_optional_discard_add_wr_if_stage_cost': {
            $cands = array_values(array_filter($p['hand'] ?? [], fn($c) => is_array($c) && ($c['instance_id'] ?? '') !== $iid));
            if ($cands === []) {
                return $state;
            }
            return bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:discard_then_add_if_cost', $cands, 0, 1,
                'You may put 1 card from your hand into the Waiting Room.', array_merge($meta, ['choices' => ['skip'], 'bp8_discard' => true]));
        }

        case 'bp8_shuffle_wr_members_bottom_hearts_if_subunit': {
            $members = [];
            $rest = [];
            foreach ($state['players'][$pid]['waiting_room'] ?? [] as $c) {
                if (is_array($c) && bp7IsMemberCard($c)) $members[] = $c; else $rest[] = $c;
            }
            $state['players'][$pid]['waiting_room'] = $rest;
            shuffle($members);
            putCardsOnMainDeckBottom($state, $pid, $members);
            $n = 0;
            foreach ($members as $c) {
                if (cardMatchesSubunit($c, (string)($ab['subunit'] ?? ''))) $n++;
            }
            $state = bp8Log($state, $pid, $source, 'shuffled ' . count($members) . " Member card(s) from the Waiting Room to the deck bottom ($n " .
                (string)($ab['subunit'] ?? '') . ').');
            if ($n >= intval($ab['min_count'] ?? 3)) {
                $state = bp8GrantSourceHearts($state, $pid, $source, $ab['hearts'] ?? []);
                $state = bp8Log($state, $pid, $source, 'gained hearts until this Live ends.');
            }
            return $state;
        }

        case 'bp8_blade_if_live_zone_subunit': {
            $hit = false;
            foreach ($p['live_zone'] ?? [] as $lc) {
                if (is_array($lc) && cardMatchesSubunit($lc, (string)($ab['subunit'] ?? ''))) { $hit = true; break; }
            }
            if ($hit) {
                $state = bp7Cl1GrantSourceBlade($state, $pid, $iid, intval($ab['amount'] ?? 3));
                $state = bp8Log($state, $pid, $source, '+' . intval($ab['amount'] ?? 3) . ' Blade until this Live ends.');
            }
            return $state;
        }

        case 'bp8_draw_if_other_group_member': {
            $hit = (bool)bp8StageCandidates($p, fn($m) => ($m['instance_id'] ?? '') !== $iid && cardInGroup($m, (string)($ab['group'] ?? '')));
            if ($hit) {
                drawCardsForPlayerWithEffectLog($state, $pid, $name, intval($ab['draw'] ?? 1));
            }
            return $state;
        }

        case 'bp8_forced_discard':
            return bp8StartDiscard($state, $pid, $source, $ab, $ctx, intval($ab['count'] ?? 2), 'discard_done');

        case 'bp8_optional_discard_add_wr_max_score': {
            $need = intval($ab['discard'] ?? 2);
            $hand = array_values(array_filter($p['hand'] ?? [], 'is_array'));
            $wr = bp8WrCandidates($p, ['group' => $ab['group'] ?? '', 'filter' => 'live', 'max_score' => intval($ab['max_score'] ?? 3)]);
            if (count($hand) < $need || $wr === []) {
                return $state;
            }
            return bp7StartConfirm($state, $pid, $source, $ab, $ctx, 'bp8:discard_n_then_add',
                "Put $need cards from your hand into the Waiting Room to add 1 matching Live card from your Waiting Room?", $meta);
        }

        case 'bp8_return_self_boost_live_zone': {
            $zone = array_values(array_filter($p['live_zone'] ?? [], 'is_array'));
            $all = count($zone) >= intval($ab['min_cards'] ?? 2);
            foreach ($zone as $c) {
                if (!cardInGroup($c, (string)($ab['group'] ?? ''))) { $all = false; break; }
            }
            if (!$all) {
                return bp8Log($state, $pid, $source, 'Live storage is not 2+ ' . groupPromptLabel((string)($ab['group'] ?? '')) . ' cards; no effect.');
            }
            if (!bp7ReturnLiveCardToHand($state, $pid, $iid)) {
                return $state;
            }
            bp8NoteLiveZoneDeparture($state, $pid);
            $state = bp8Log($state, $pid, $source, 'returned this card to hand.');
            return bp8StartDiscard($state, $pid, $source, $ab, $ctx, 1, 'discard_then_boost_zone');
        }

        case 'bp8_optional_pay_shuffle_wr_members_bottom': {
            $max = intval($ab['max_cost'] ?? 9);
            $has = (bool)bp8WrCandidates($p, ['filter' => 'member', 'max_cost' => $max]);
            if (!$has || countActiveEnergyInZone($p) < intval($ab['energy_cost'] ?? 1)) {
                return $state;
            }
            return bp7StartConfirm($state, $pid, $source, $ab, $ctx, 'bp8:pay_shuffle_bottom',
                'Pay ' . intval($ab['energy_cost'] ?? 1) . " Energy to shuffle all Member cards with cost $max or less from your Waiting Room to the deck bottom?", $meta);
        }

        case 'bp8_score_if_subunit_and_deck_min': {
            $hasSub = bp8StageHasSubunit($p, (string)($ab['subunit'] ?? ''));
            $deck = count($p['main_deck'] ?? []);
            if ($hasSub && $deck >= intval($ab['min_deck'] ?? 35)) {
                bp7BumpSelfScore($state, $pid, $source, intval($ab['amount'] ?? 2), $name, "$deck cards in deck");
            } else {
                $state = bp8Log($state, $pid, $source, "condition not met ($deck cards in deck).");
            }
            return $state;
        }

        case 'bp8_optional_wait_members_reduce_hearts': {
            $cands = bp8StageCandidates($p, fn($m) => !memberIsInWait($m));
            $n = intval($ab['count'] ?? 2);
            if (count($cands) < $n) {
                return $state;
            }
            return bp7StartStagePick($state, $pid, $source, $ab, $ctx, 'bp8:wait_members_reduce', $cands,
                "You may choose $n Members on your Stage to put into Wait.",
                array_merge($meta, ['pick_min' => $n, 'pick_max' => $n, 'multi' => true, 'choices' => ['skip']]));
        }

        case 'bp8_subunit_cost_plus_score_if_total': {
            $cands = bp8StageCandidates($p, fn($m) => cardMatchesSubunit($m, (string)($ab['subunit'] ?? '')));
            if ($cands === []) {
                return bp8SubunitCostScoreCheck($state, $pid, $source, $ab);
            }
            if (count($cands) === 1) {
                return bp8SubunitCostPlus($state, $pid, $source, $ab, $cands[0]['slot']);
            }
            return bp7StartStagePick($state, $pid, $source, $ab, $ctx, 'bp8:subunit_cost_plus', $cands,
                'Choose 1 ' . (string)($ab['subunit'] ?? '') . ' Member on your Stage.', $meta);
        }

        case 'bp8_return_subunit_member_play_same_cost': {
            $sub = (string)($ab['subunit'] ?? '');
            $hand = array_values(array_filter($p['hand'] ?? [], fn($c) => is_array($c) && bp7IsMemberCard($c) && cardMatchesSubunit($c, $sub)));
            // The returned card itself is a same-cost Member card once it is in hand, so every one qualifies.
            $cands = bp8StageCandidates($p, fn($m) => cardMatchesSubunit($m, $sub));
            unset($hand);
            if ($cands === []) {
                return $state;
            }
            return bp7StartStagePick($state, $pid, $source, $ab, $ctx, 'bp8:return_member', $cands,
                "You may return 1 $sub Member on your Stage to your hand.", array_merge($meta, ['choices' => ['skip']]));
        }

        case 'bp8_optional_opp_draw_grant_heart': {
            $cands = bp8StageCandidates($p, fn($m) => cardMatchesSubunit($m, (string)($ab['subunit'] ?? ''))
                && getEffectiveStageMemberCost($state, $pid, $m) >= intval($ab['min_cost'] ?? 10));
            if ($cands === []) {
                return $state;
            }
            return bp7StartConfirm($state, $pid, $source, $ab, $ctx, 'bp8:opp_draw_heart',
                'Have your opponent draw 1 card?', $meta);
        }

        case 'bp8_score_hearts_per_success_subunit': {
            $n = 0;
            foreach ($p['success_lives'] ?? [] as $c) {
                if (is_array($c) && cardMatchesSubunit($c, (string)($ab['subunit'] ?? ''))) $n++;
            }
            if ($n < 1) {
                return $state;
            }
            bp7BumpSelfScore($state, $pid, $source, $n * intval($ab['score_per'] ?? 3), $name,
                "$n " . (string)($ab['subunit'] ?? '') . ' card(s) in Success Live storage');
            foreach ($state['players'][$pid]['live_zone'] as &$lc) {
                if (!is_array($lc) || ($lc['instance_id'] ?? '') !== $iid) continue;
                if (!isset($lc['hearts_color_increase']) || !is_array($lc['hearts_color_increase'])) $lc['hearts_color_increase'] = [];
                foreach ($ab['hearts'] ?? [] as $h) {
                    $c = (string)($h['color'] ?? 'any');
                    $lc['hearts_color_increase'][$c] = intval($lc['hearts_color_increase'][$c] ?? 0) + $n * intval($h['count'] ?? 1);
                }
            }
            unset($lc);
            return bp8Log($state, $pid, $source, 'required hearts increased.');
        }

        /* ---------------- crossover ---------------- */

        case 'bp8_look_reveal_member_stack_under':
            if (bp8SelfSlot($state, $pid, $source) === '') {
                return $state;
            }
            return bp8LookPick($state, $pid, $source, $ab, $ctx, intval($ab['look'] ?? 6), ['filter' => 'member'], 'under');
    }
    return $state;
}

/* ------------------------------------------------------------------ *
 * Effect tails shared by direct resolution and prompt answers
 * ------------------------------------------------------------------ */

function bp8ActivateThenWaitOpp(array $state, string $pid, array $source, array $ab, array $ctx, string $slot): array {
    $m = $state['players'][$pid]['stage'][$slot] ?? null;
    if (!$m || !memberIsInWait($m)) {
        return $state;
    }
    activateMemberFully($m);
    $state['players'][$pid]['stage'][$slot] = $m;
    $state = bp8Log($state, $pid, $source, 'activated ' . cardDisplayName($m) . '.');
    return bp8WaitOpp($state, $pid, $source, ['max_cost' => intval($ab['max_cost'] ?? 4)], $ctx);
}

function bp8BladePerOppDistinct(array $state, string $pid, array $source, string $slot): array {
    $m = $state['players'][$pid]['stage'][$slot] ?? null;
    if (!$m) {
        return $state;
    }
    $key = bp8NameKey($m);
    $n = 0;
    foreach ($state['players'][bp8Opp($pid)]['stage'] ?? [] as $o) {
        if ($o && bp8NameKey($o) !== $key) $n++;
    }
    if ($n > 0) {
        $state['players'][$pid]['stage'][$slot]['live_blade_bonus'] = intval($m['live_blade_bonus'] ?? 0) + $n;
    }
    return bp8Log($state, $pid, $source, cardDisplayName($m) . " gains +$n Blade until this Live ends.");
}

function bp8MostStatsScore(array $state, string $pid, array $source, array $ab, string $slot): array {
    $m = $state['players'][$pid]['stage'][$slot] ?? null;
    if (!$m) {
        return $state;
    }
    $mine = [
        'hearts' => stageMemberTotalHeartCount($state, $pid, $m, $slot),
        'blade'  => getMemberBlade($m, $state, $pid, $slot),
        'cost'   => getEffectiveStageMemberCost($state, $pid, $m),
    ];
    $beats = ['hearts' => true, 'blade' => true, 'cost' => true];
    foreach (['p1', 'p2'] as $owner) {
        foreach ($state['players'][$owner]['stage'] ?? [] as $s => $o) {
            if (!$o || ($owner === $pid && (string)$s === $slot)) continue;
            if (stageMemberTotalHeartCount($state, $owner, $o, (string)$s) >= $mine['hearts']) $beats['hearts'] = false;
            if (getMemberBlade($o, $state, $owner, (string)$s) >= $mine['blade']) $beats['blade'] = false;
            if (getEffectiveStageMemberCost($state, $owner, $o) >= $mine['cost']) $beats['cost'] = false;
        }
    }
    $n = count(array_filter($beats));
    if ($n > 0) {
        bp7BumpSelfScore($state, $pid, $source, $n * intval($ab['amount'] ?? 1), bp8Name($source),
            cardDisplayName($m) . ' leads in ' . implode(', ', array_keys(array_filter($beats))));
    } else {
        $state = bp8Log($state, $pid, $source, cardDisplayName($m) . ' does not lead in hearts, Blade, or cost.');
    }
    return $state;
}

function bp8GrantStageHearts(array $state, string $pid, array $source, string $slot, array $hearts, int $times = 1): array {
    if ($slot === '' || empty($state['players'][$pid]['stage'][$slot])) {
        return $state;
    }
    $state = bp7Cl1GrantBonusHearts($state, $pid, $slot, $hearts, $times);
    return bp8Log($state, $pid, $source, cardDisplayName($state['players'][$pid]['stage'][$slot]) .
        ' gains hearts until this Live ends.');
}

function bp8RecolorMemberHearts(array $state, string $pid, array $source, array $ab, string $slot): array {
    $m = $state['players'][$pid]['stage'][$slot] ?? null;
    if (!$m) {
        return $state;
    }
    $from = array_map('strval', $ab['from_colors'] ?? ['red', 'blue']);
    $to = (string)($ab['to_color'] ?? 'purple');
    $n = 0;
    foreach (memberPerformanceHeartsFlat($m) as $c) {
        if (in_array(normalizeHeartColor((string)$c), $from, true)) $n++;
    }
    $map = is_array($m['bp8_heart_recolor'] ?? null) ? $m['bp8_heart_recolor'] : [];
    foreach ($from as $c) {
        $map[$c] = $to;
    }
    $state['players'][$pid]['stage'][$slot]['bp8_heart_recolor'] = $map;
    $state = bp8Log($state, $pid, $source, cardDisplayName($m) . "'s " . implode(' and ', array_map('ucfirst', $from)) .
        " hearts become $to ($n heart(s)).");
    if ($n >= intval($ab['min_converted'] ?? 5)) {
        bp7BumpSelfScore($state, $pid, $source, intval($ab['amount'] ?? 1), bp8Name($source), "$n hearts became $to");
    }
    return $state;
}

function bp8SubunitCostPlus(array $state, string $pid, array $source, array $ab, string $slot): array {
    if ($slot !== '' && !empty($state['players'][$pid]['stage'][$slot])) {
        $state['players'][$pid]['stage'][$slot]['live_cost_bonus'] =
            intval($state['players'][$pid]['stage'][$slot]['live_cost_bonus'] ?? 0) + intval($ab['cost_plus'] ?? 5);
        $state = bp8Log($state, $pid, $source, cardDisplayName($state['players'][$pid]['stage'][$slot]) .
            "'s cost +" . intval($ab['cost_plus'] ?? 5) . ' until this Live ends.');
    }
    return bp8SubunitCostScoreCheck($state, $pid, $source, $ab);
}

function bp8SubunitCostScoreCheck(array $state, string $pid, array $source, array $ab): array {
    $total = bp8StageGroupCostTotal($state, $pid, (string)($ab['group'] ?? ''));
    if ($total >= intval($ab['min_total_cost'] ?? 30)) {
        bp7BumpSelfScore($state, $pid, $source, intval($ab['amount'] ?? 1), bp8Name($source), "Stage cost total $total");
    } else {
        $state = bp8Log($state, $pid, $source, "Stage cost total is $total (needs " . intval($ab['min_total_cost'] ?? 30) . ').');
    }
    return $state;
}

/** Hand trim for PL!HS-bp8-003: the active seat first, then the opponent (each picks their own discards). */
function bp8StartTrim(array $state, string $pid, array $source, array $ab, array $ctx, string $seat, bool $first): array {
    $target = intval($ab['target'] ?? 6);
    $hand = array_values(array_filter($state['players'][$seat]['hand'] ?? [], 'is_array'));
    $over = count($hand) - $target;
    if ($over > 0) {
        $state = bp7StartCardPick($state, $pid, $source, $ab, $ctx, 'bp8:trim_hand', $hand, $over, $over,
            "Choose $over card(s) from your hand to put into the Waiting Room (down to $target).",
            array_merge(bp8PromptMeta($source, $ab), ['responder' => $seat, 'bp8_seat' => $seat, 'bp8_first' => $first, 'bp8_discard' => true]));
        return $state;
    }
    if ($first) {
        return bp8StartTrim($state, $pid, $source, $ab, $ctx, bp8Opp($pid), false);
    }
    return $state;
}

/* ------------------------------------------------------------------ *
 * Prompt resolution
 * ------------------------------------------------------------------ */

/**
 * @return array|null new state, or null when the prompt is not a BP08 prompt
 */
function bp8ResolvePrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $action = (string)($prompt['bp7_action'] ?? '');
    if (!str_starts_with($action, 'bp8:')) {
        return null;
    }
    $action = substr($action, 4);
    $ab = is_array($prompt['ability'] ?? null) ? $prompt['ability'] : [];
    $source = bp7PromptSource($state, $owner, $prompt);
    mergeCardCatalogFields($source);
    $name = (string)($prompt['source_name'] ?? bp8Name($source));
    $ids = bp7PickedIds($prompt, $choice, $data);
    $yes = $choice === 'yes';
    $ctx = ['phase' => !empty($prompt['live_start']) ? 'live_start' : 'prompt', 'live_start' => !empty($prompt['live_start'])];
    $slot = (string)($data['slot'] ?? '');
    if ($slot === '' && ($prompt['type'] ?? '') === 'bp7_pick_stage_member') {
        $want = (string)($data['card_id'] ?? ($ids[0] ?? ''));
        foreach ($prompt['candidates'] ?? [] as $cand) {
            if (($cand['instance_id'] ?? '') === $want) { $slot = (string)($cand['slot'] ?? ''); break; }
        }
    }
    $pickMin = intval($prompt['pick_min'] ?? 0);
    if (($prompt['type'] ?? '') === 'bp7_pick_cards' && count($ids) < $pickMin && $choice !== 'skip') {
        throw new Exception("Choose $pickMin card(s).");
    }
    $validIds = array_column($prompt['candidates'] ?? [], 'instance_id');
    foreach ($ids as $id) {
        if ($validIds && !in_array($id, $validIds, true)) {
            throw new Exception('Invalid card choice.');
        }
    }
    unset($state['pending_prompt']);
    $p = $state['players'][$owner];

    switch ($action) {

        case 'leave_self_bottom': {
            if (!$yes) {
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] kept this card in the Waiting Room.");
                break;
            }
            $moved = bp8TakeFromZone($state['players'][$owner], 'waiting_room', [(string)($prompt['source_id'] ?? '')]);
            if (!$moved) break;
            putCardsOnMainDeckBottom($state, $owner, $moved);
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] put this card on the bottom of the deck.");
            $then = $ab['then'] ?? null;
            if (is_array($then)) {
                $then['trigger'] = $ab['trigger'] ?? 'on_leave_stage';
                $state = bp8ResolveEffect($state, $owner, $source, $then, $ctx);
                $state = bp7QueueChain($state);
            }
            break;
        }

        case 'discard_done':
        case 'trim_hand': {
            $seat = (string)($prompt['bp8_seat'] ?? $prompt['responder'] ?? $owner);
            $moved = takeDiscardedHandCards($state['players'][$seat], $ids, $state, $seat);
            $state = addLog($state, bp8PlayerName($state, $seat) . " — [$name] put " . count($moved) .
                ' card(s) from hand into the Waiting Room.');
            if ($action === 'trim_hand' && !empty($prompt['bp8_first'])) {
                $state = bp8StartTrim($state, $owner, $source, $ab, $ctx, bp8Opp($owner), false);
                $state = bp7QueueChain($state);
            }
            break;
        }

        case 'cost_then_look': {
            takeDiscardedHandCards($state['players'][$owner], $ids, $state, $owner);
            $looked = takeFromMainDeckTop($state, $owner, intval($ab['look'] ?? 2));
            if ($looked === []) break;
            $state['_bp8_look_stash'] = $looked;
            $state = bp7StartCardPick($state, $owner, $source, $ab, $ctx, 'bp8:look_pick', $looked, 1, 1,
                'Choose 1 card to add to your hand. The rest go to the Waiting Room.',
                array_merge(bp8PromptMeta($source, $ab), ['bp8_dest' => 'hand']));
            $state = bp7QueueChain($state);
            break;
        }

        case 'cost_then_add_wr': {
            takeDiscardedHandCards($state['players'][$owner], $ids, $state, $owner);
            $cands = bp8WrCandidates($state['players'][$owner], ['group' => $ab['group'] ?? '', 'filter' => $ab['filter'] ?? 'live']);
            if ($cands === []) break;
            $state = bp7StartCardPick($state, $owner, $source, $ab, $ctx, 'bp8:wr_to_hand', $cands, 1, 1,
                'Choose 1 card from your Waiting Room to add to your hand.', bp8PromptMeta($source, $ab));
            $state = bp7QueueChain($state);
            break;
        }

        case 'look_pick': {
            $looked = $state['_bp8_look_stash'] ?? [];
            unset($state['_bp8_look_stash']);
            $dest = (string)($prompt['bp8_dest'] ?? 'hand');
            $keep = [];
            $rest = [];
            foreach ($looked as $c) {
                if (count($keep) < 1 && in_array($c['instance_id'] ?? '', $ids, true)) $keep[] = $c; else $rest[] = $c;
            }
            if ($keep) {
                if ($dest === 'under') {
                    $slot2 = bp7FindSlotByInstance($state['players'][$owner], (string)($prompt['source_id'] ?? ''));
                    if ($slot2 !== '') {
                        $state['players'][$owner]['stage'][$slot2]['stacked_members'][] = $keep[0];
                        $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] revealed " . cardDisplayName($keep[0]) .
                            ' and put it under this Member.');
                    } else {
                        $rest[] = $keep[0];
                    }
                } else {
                    $state['players'][$owner]['hand'][] = $keep[0];
                    $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] added " . cardDisplayName($keep[0]) . ' to hand.');
                }
            }
            if ($rest) {
                $state = appendDeckCardsToWaitingRoom($state, $owner, $rest);
                refreshMainDeckFromWaitingRoom($state, $owner);
                $state = bp7QueueChain($state);
            }
            break;
        }

        case 'bottom_then_cost_plus':
        case 'bottom_then_draw':
        case 'bottom_done': {
            $picked = bp8TakeFromZone($state['players'][$owner], 'waiting_room', $ids);
            if ($action !== 'bottom_done' && count($picked) < intval($ab['count'] ?? 2)) {
                if ($picked) $state['players'][$owner]['waiting_room'] = array_merge($state['players'][$owner]['waiting_room'], $picked);
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] did not put the cards on the deck bottom.");
                break;
            }
            putCardsOnMainDeckBottom($state, $owner, $picked);
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] put " . count($picked) .
                ' card(s) from the Waiting Room on the bottom of the deck.');
            if ($action === 'bottom_then_cost_plus') {
                $s = bp7FindSlotByInstance($state['players'][$owner], (string)($prompt['source_id'] ?? ''));
                if ($s !== '') {
                    $state['players'][$owner]['stage'][$s]['live_cost_bonus'] =
                        intval($state['players'][$owner]['stage'][$s]['live_cost_bonus'] ?? 0) + intval($ab['cost_plus'] ?? 2);
                    $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] cost +" . intval($ab['cost_plus'] ?? 2) .
                        ' until this Live ends.');
                }
            } elseif ($action === 'bottom_then_draw') {
                drawCardsForPlayerWithEffectLog($state, $owner, $name, intval($ab['draw'] ?? 1));
            }
            break;
        }

        case 'wr_to_hand_then_discard': {
            $picked = bp8TakeFromZone($state['players'][$owner], 'waiting_room', array_slice($ids, 0, 1));
            foreach ($picked as $c) {
                $state['players'][$owner]['hand'][] = $c;
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] added " . cardDisplayName($c) . ' to hand.');
            }
            $state = bp8StartDiscard($state, $owner, $source, $ab, $ctx, intval($ab['discard'] ?? 1), 'discard_done');
            $state = bp7QueueChain($state);
            break;
        }

        case 'wr_to_hand': {
            $picked = bp8TakeFromZone($state['players'][$owner], 'waiting_room', array_slice($ids, 0, 1));
            foreach ($picked as $c) {
                $state['players'][$owner]['hand'][] = $c;
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] added " . cardDisplayName($c) .
                    ' from the Waiting Room to hand.');
            }
            break;
        }

        case 'wr_to_top': {
            $picked = bp8TakeFromZone($state['players'][$owner], 'waiting_room', array_slice($ids, 0, 1));
            foreach ($picked as $c) {
                array_unshift($state['players'][$owner]['main_deck'], $c);
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] put " . cardDisplayName($c) .
                    ' from the Waiting Room on top of the deck.');
            }
            break;
        }

        case 'discard_then_yell_pick': {
            if (!$yes) break;
            $hand = array_values(array_filter($state['players'][$owner]['hand'] ?? [], 'is_array'));
            if ($hand === []) break;
            $state = bp7StartCardPick($state, $owner, $source, $ab, $ctx, 'bp8:yell_pick_after_discard', $hand, 1, 1,
                'Choose 1 card from your hand to put into the Waiting Room.', array_merge(bp8PromptMeta($source, $ab), ['bp8_discard' => true]));
            $state = bp7QueueChain($state);
            break;
        }

        case 'yell_pick_after_discard': {
            takeDiscardedHandCards($state['players'][$owner], $ids, $state, $owner);
            $pool = array_values(array_filter(bp7Cl1YellPool($state, $owner, []),
                fn($c) => bp8CardMatches($c, ['group' => $ab['group'] ?? '', 'filter' => $ab['filter'] ?? ''])));
            if ($pool === []) break;
            $state = bp7StartCardPick($state, $owner, $source, $ab, $ctx, 'bp8:yell_to_hand', $pool, 1, 1,
                'Choose 1 card revealed by Yell to add to your hand.', bp8PromptMeta($source, $ab));
            $state = bp7QueueChain($state);
            break;
        }

        case 'yell_to_hand': {
            $card = bp7Cl1TakeFromYellPool($state, $owner, (string)($ids[0] ?? ''));
            if ($card) {
                $state['players'][$owner]['hand'][] = $card;
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] added " . cardDisplayName($card) . ' from Yell to hand.');
                bp8NotifyYellCardMoved($state, $owner);
            }
            break;
        }

        case 'hand_member_under_self': {
            $picked = bp8TakeFromZone($state['players'][$owner], 'hand', array_slice($ids, 0, 1));
            $s = bp7FindSlotByInstance($state['players'][$owner], (string)($prompt['source_id'] ?? ''));
            if ($picked && $s !== '') {
                $state['players'][$owner]['stage'][$s]['stacked_members'][] = $picked[0];
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] put " . cardDisplayName($picked[0]) .
                    ' from hand under this Member.');
            } elseif ($picked) {
                $state['players'][$owner]['hand'][] = $picked[0];
            }
            break;
        }

        case 'reveal_bottom_then_look': {
            if ($ids === []) break;
            $picked = bp8TakeFromZone($state['players'][$owner], 'hand', array_slice($ids, 0, 1));
            if (!$picked) break;
            putCardsOnMainDeckBottom($state, $owner, $picked);
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] revealed " . cardDisplayName($picked[0]) .
                ' and put it on the bottom of the deck.');
            $state = bp8LookPick($state, $owner, $source, $ab, $ctx, intval($ab['look'] ?? 5),
                ['group' => $ab['group'] ?? '', 'filter' => $ab['filter'] ?? ''], 'hand');
            $state = bp7QueueChain($state);
            break;
        }

        case 'activate_then_wait_opp':
            $state = bp8ActivateThenWaitOpp($state, $owner, $source, $ab, $ctx, $slot);
            break;

        case 'blade_per_opp_distinct':
            $state = bp8BladePerOppDistinct($state, $owner, $source, $slot);
            break;

        case 'most_stats_score':
            $state = bp8MostStatsScore($state, $owner, $source, $ab, $slot);
            break;

        case 'energy_under_self': {
            $selfSlot = bp7FindSlotByInstance($state['players'][$owner], (string)($prompt['source_id'] ?? ''));
            if ($yes && $selfSlot !== '' && bp8ZoneEnergyUnderMember($state, $owner, $selfSlot, 1) > 0) {
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] put 1 Energy under this Member.");
            }
            if (empty($state['players'][$owner]['energy_zone'])) break;
            $cands = bp8StageCandidates($state['players'][$owner], fn($m) => ($m['instance_id'] ?? '') !== ($prompt['source_id'] ?? '')
                && cardInGroup($m, (string)($ab['group'] ?? '')));
            if ($cands === []) break;
            $state = bp7StartStagePick($state, $owner, $source, $ab, $ctx, 'bp8:energy_under_other', $cands,
                'You may choose another ' . groupPromptLabel((string)($ab['group'] ?? '')) . ' Member to put 1 Energy under.',
                array_merge(bp8PromptMeta($source, $ab), ['choices' => ['skip']]));
            $state = bp7QueueChain($state);
            break;
        }

        case 'energy_under_other':
            if ($slot !== '' && bp8ZoneEnergyUnderMember($state, $owner, $slot, 1) > 0) {
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] put 1 Energy under " .
                    cardDisplayName($state['players'][$owner]['stage'][$slot]) . '.');
            }
            break;

        case 'unstack_energy_draw': {
            if ($slot === '' || empty($state['players'][$owner]['stage'][$slot])) break;
            $m = $state['players'][$owner]['stage'][$slot];
            $energy = array_values(array_filter($m['stacked_energy'] ?? [], 'is_array'));
            if ($energy === []) break;
            $e = array_shift($energy);
            $m['stacked_energy'] = $energy;
            if (isset($m['stacked_energy_ids']) && is_array($m['stacked_energy_ids'])) {
                $m['stacked_energy_ids'] = array_values(array_filter($m['stacked_energy_ids'],
                    fn($id) => $id !== ($e['instance_id'] ?? '')));
            }
            $state['players'][$owner]['stage'][$slot] = $m;
            $e['active'] = false;
            $state['players'][$owner]['energy_zone'][] = $e;
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] put 1 Energy from under " . cardDisplayName($m) .
                ' into the Energy storage in Wait.');
            $state = bp7ResolveAutoOnEnergyPlaced($state, $owner, 1);
            $state = bp8DrawThenDiscard($state, $owner, $source, $ab, $ctx, intval($ab['draw'] ?? 1), intval($ab['discard'] ?? 1));
            $state = bp7QueueChain($state);
            break;
        }

        case 'grant_hearts':
            $state = bp8GrantStageHearts($state, $owner, $source, $slot, $ab['hearts'] ?? []);
            break;

        case 'reveal_top_then_heart': {
            if ($ids === []) break;
            $picked = bp8TakeFromZone($state['players'][$owner], 'hand', array_slice($ids, 0, 1));
            if (!$picked) break;
            array_unshift($state['players'][$owner]['main_deck'], $picked[0]);
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] revealed " . cardDisplayName($picked[0]) .
                ' and put it on top of the deck.');
            $state = bp8StartHeartChoice($state, $owner, $source, $ab, $ctx, 'heart_times',
                ['pink', 'red', 'yellow', 'green', 'blue', 'purple'], ['bp8_times' => 1]);
            $state = bp7QueueChain($state);
            break;
        }

        case 'heart_times': {
            $color = strtolower($choice);
            if (!in_array($color, ['pink', 'red', 'yellow', 'green', 'blue', 'purple'], true)) {
                throw new Exception('Choose a heart color.');
            }
            $times = max(1, intval($prompt['bp8_times'] ?? 1));
            $state = bp8GrantSourceHearts($state, $owner, $source, [['color' => $color, 'count' => 1]], $times);
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] gained $times " . bp8HeartLabel($color) .
                '(s) until this Live ends.');
            break;
        }

        case 'heart_if_lacking': {
            $color = strtolower($choice);
            if (!in_array($color, ['pink', 'red', 'yellow', 'green', 'blue', 'purple'], true)) {
                throw new Exception('Choose a heart color.');
            }
            $members = array_filter($state['players'][$owner]['stage'] ?? [], fn($m) => $m && cardInGroup($m, (string)($ab['group'] ?? '')));
            $has = false;
            foreach ($members as $m) {
                if (in_array($color, bp8MemberHeartColors($m), true)) { $has = true; break; }
            }
            if (count($members) >= intval($ab['min_members'] ?? 3) && !$has) {
                $state = bp8GrantSourceHearts($state, $owner, $source, [['color' => $color, 'count' => 1]]);
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] gained 1 " . bp8HeartLabel($color) . ' until this Live ends.');
            } else {
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] " . bp8HeartLabel($color) .
                    ' is already on your Stage; no heart gained.');
            }
            break;
        }

        case 'hand_bottom_then_draw': {
            $picked = bp8TakeFromZone($state['players'][$owner], 'hand', $ids);
            putCardsOnMainDeckBottom($state, $owner, $picked);
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] put " . count($picked) . ' card(s) from hand on the bottom of the deck.');
            drawCardsForPlayerWithEffectLog($state, $owner, $name, intval($ab['draw'] ?? 2));
            break;
        }

        case 'mill_bottom_swap': {
            if (!$yes) break;
            $milled = takeFromMainDeckBottom($state, $owner, 1);
            if ($milled === []) break;
            $state = appendDeckCardsToWaitingRoom($state, $owner, $milled);
            refreshMainDeckFromWaitingRoom($state, $owner);
            $card = $milled[0];
            mergeCardCatalogFields($card);
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] put " . cardDisplayName($card) .
                ' from the deck bottom into the Waiting Room.');
            if (!bp7IsLiveCard($card)) break;
            $cands = [];
            foreach ($state['players'][$owner]['live_zone'] ?? [] as $lc) {
                if (is_array($lc) && bp8CardMatches($lc, ['filter' => 'live', 'group' => $ab['group'] ?? '',
                    'max_score' => intval($ab['max_score'] ?? 2)])) {
                    $cands[] = $lc;
                }
            }
            if ($cands === []) {
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] no matching Live card in Live storage.");
                break;
            }
            $state = bp7StartCardPick($state, $owner, $source, $ab, $ctx, 'bp8:zone_live_to_top', $cands, 1, 1,
                'Choose 1 Live card in your Live storage to put on top of your deck.', bp8PromptMeta($source, $ab));
            $state = bp7QueueChain($state);
            break;
        }

        case 'zone_live_to_top': {
            $picked = bp8TakeFromZone($state['players'][$owner], 'live_zone', array_slice($ids, 0, 1));
            if (!$picked) break;
            array_unshift($state['players'][$owner]['main_deck'], liveCardRestorePrintedScore($picked[0]));
            bp8NoteLiveZoneDeparture($state, $owner);
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] put " . cardDisplayName($picked[0]) .
                ' from Live storage on top of the deck.');
            $cands = bp8WrCandidates($state['players'][$owner], ['filter' => 'live']);
            if ($cands === []) break;
            $state = bp7StartCardPick($state, $owner, $source, $ab, $ctx, 'bp8:wr_live_to_zone', $cands, 1, 1,
                'Choose 1 Live card from your Waiting Room to place face up in your Live storage.', bp8PromptMeta($source, $ab));
            $state = bp7QueueChain($state);
            break;
        }

        case 'wr_live_to_zone': {
            $id = (string)($ids[0] ?? '');
            if ($id === '') break;
            if (addLiveFromWrToZone($state['players'][$owner], ['filter' => 'live', 'group' => $ab['zone_group'] ?? ''], $id) > 0) {
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] placed a Live card from the Waiting Room face up in Live storage.");
                $pen = intval($ab['cap_penalty'] ?? 0);
                if ($pen > 0) {
                    $state['players'][$owner]['live_set_cap_penalty'] = intval($state['players'][$owner]['live_set_cap_penalty'] ?? 0) + $pen;
                }
            }
            break;
        }

        case 'mill_top':
            if (!$yes) break;
            [$state, $milled] = bp8MillTop($state, $owner, intval($ab['count'] ?? 5));
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] put " . count($milled) .
                ' card(s) from the deck top into the Waiting Room.');
            break;

        case 'wr_bottom_then_blade': {
            if ($ids === []) break;
            $picked = bp8TakeFromZone($state['players'][$owner], 'waiting_room', array_slice($ids, 0, 1));
            if (!$picked) break;
            putCardsOnMainDeckBottom($state, $owner, $picked);
            $state = bp7Cl1GrantSourceBlade($state, $owner, (string)($prompt['source_id'] ?? ''), intval($ab['amount'] ?? 2));
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] put " . cardDisplayName($picked[0]) .
                ' on the deck bottom: +' . intval($ab['amount'] ?? 2) . ' Blade until this Live ends.');
            break;
        }

        case 'reveal_bottom_then_wait': {
            if ($ids === []) break;
            $picked = bp8TakeFromZone($state['players'][$owner], 'hand', array_slice($ids, 0, 1));
            if (!$picked) break;
            putCardsOnMainDeckBottom($state, $owner, $picked);
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] revealed " . cardDisplayName($picked[0]) .
                ' and put it on the bottom of the deck.');
            $state = bp8WaitOpp($state, $owner, $source, ['max_cost' => intval($ab['max_opp_cost'] ?? 11)], $ctx);
            break;
        }

        case 'cost_then_live_to_zone': {
            takeDiscardedHandCards($state['players'][$owner], $ids, $state, $owner);
            $zoneCount = count(array_filter($state['players'][$owner]['live_zone'] ?? [], 'is_array'));
            if ($zoneCount > 0) {
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] Live storage is not empty; no Live card placed.");
                break;
            }
            $cands = bp8WrCandidates($state['players'][$owner], ['filter' => 'live', 'group' => $ab['group'] ?? '']);
            if ($cands === []) break;
            $ab2 = array_merge($ab, ['zone_group' => $ab['group'] ?? '']);
            $state = bp7StartCardPick($state, $owner, $source, $ab2, $ctx, 'bp8:wr_live_to_zone', $cands, 1, 1,
                'Choose 1 ' . groupPromptLabel((string)($ab['group'] ?? '')) . ' Live card from your Waiting Room to place face up in your Live storage.',
                bp8PromptMeta($source, $ab));
            $state = bp7QueueChain($state);
            break;
        }

        case 'discard_then_hearts': {
            if ($ids === []) break;
            takeDiscardedHandCards($state['players'][$owner], array_slice($ids, 0, 1), $state, $owner);
            $state = bp8GrantSourceHearts($state, $owner, $source, $ab['hearts'] ?? []);
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] gained hearts until this Live ends.");
            break;
        }

        case 'recolor_hearts':
            $state = bp8RecolorMemberHearts($state, $owner, $source, $ab, $slot);
            break;

        case 'discard_then_cost_plus': {
            if ($ids === []) break;
            takeDiscardedHandCards($state['players'][$owner], array_slice($ids, 0, 1), $state, $owner);
            $s = bp7FindSlotByInstance($state['players'][$owner], (string)($prompt['source_id'] ?? ''));
            if ($s === '') break;
            $state['players'][$owner]['stage'][$s]['live_cost_bonus'] =
                intval($state['players'][$owner]['stage'][$s]['live_cost_bonus'] ?? 0) + intval($ab['cost_plus'] ?? 5);
            $m = $state['players'][$owner]['stage'][$s];
            $cost = getEffectiveStageMemberCost($state, $owner, $m);
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] cost +" . intval($ab['cost_plus'] ?? 5) . " (now $cost).");
            if ($cost >= intval($ab['min_cost'] ?? 20)) {
                activateMemberFully($m);
                $state['players'][$owner]['stage'][$s] = $m;
                drawCardsForPlayerWithEffectLog($state, $owner, $name, intval($ab['draw'] ?? 1));
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] activated this Member and drew 1 card.");
            }
            break;
        }

        case 'cost_then_copy_on_enter': {
            $moved = takeDiscardedHandCards($state['players'][$owner], $ids, $state, $owner);
            $card = $moved[0] ?? null;
            if (!is_array($card)) break;
            mergeCardCatalogFields($card);
            if (!bp8CardMatches($card, ['filter' => 'member', 'group' => $ab['group'] ?? '', 'max_cost' => intval($ab['max_cost'] ?? 13)])) {
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] " . cardDisplayName($card) .
                    ' is not a matching Member card; no ability gained.');
                break;
            }
            $onEnter = array_values(array_filter($card['abilities'] ?? [], fn($a) => in_array($a['trigger'] ?? '', ['on_enter', 'on_enter_or_live_start'], true)));
            if ($onEnter === []) {
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] " . cardDisplayName($card) . ' has no [On Enter] ability.');
                break;
            }
            if (count($onEnter) === 1) {
                $state = bp8RunCopiedOnEnter($state, $owner, $source, $card, $onEnter[0], $ctx);
                $state = bp7QueueChain($state);
                break;
            }
            $labels = [];
            foreach ($onEnter as $i => $a) {
                $labels[] = bp8PrintedLine($card, $a) ?: ('[On Enter] #' . ($i + 1));
            }
            $state = bp7StartChoices($state, $owner, $source, $ab, $ctx, 'bp8:copy_on_enter_pick',
                array_map('strval', array_keys($onEnter)), $labels, 'Choose 1 [On Enter] ability to gain and trigger.',
                array_merge(bp8PromptMeta($source, $ab), ['bp8_copy_card' => cardPromptSummary($card), 'bp8_copy_no' => $card['card_no'] ?? '']));
            $state = bp7QueueChain($state);
            break;
        }

        case 'copy_on_enter_pick': {
            $card = tcgCatalogCardByNo((string)($prompt['bp8_copy_no'] ?? ''));
            if (!is_array($card)) break;
            $onEnter = array_values(array_filter($card['abilities'] ?? [], fn($a) => in_array($a['trigger'] ?? '', ['on_enter', 'on_enter_or_live_start'], true)));
            $pick = $onEnter[intval($choice)] ?? null;
            if (!is_array($pick)) throw new Exception('Choose an ability.');
            $state = bp8RunCopiedOnEnter($state, $owner, $source, $card, $pick, $ctx);
            $state = bp7QueueChain($state);
            break;
        }

        case 'discard_then_choose': {
            if ($ids === []) break;
            takeDiscardedHandCards($state['players'][$owner], array_slice($ids, 0, 1), $state, $owner);
            $state = bp7StartChoices($state, $owner, $source, $ab, $ctx, 'bp8:heart_or_surveil', ['heart', 'surveil'],
                ['Choose a heart color to gain until this Live ends.',
                 'Look at the top ' . intval($ab['look'] ?? 3) . ' cards of your deck and arrange them.'],
                'Choose one:', bp8PromptMeta($source, $ab));
            $state = bp7QueueChain($state);
            break;
        }

        case 'heart_or_surveil': {
            if ($choice === 'heart') {
                $state = bp8StartHeartChoice($state, $owner, $source, $ab, $ctx, 'heart_times',
                    $ab['heart_choices'] ?? ['pink', 'green', 'blue', 'purple'], ['bp8_times' => 1]);
            } else {
                $state = resolveAbilityEffect($state, $owner, $source,
                    ['type' => 'deck_surveil', 'trigger' => $ab['trigger'] ?? 'on_enter', 'look' => intval($ab['look'] ?? 3)], $ctx);
            }
            $state = bp7QueueChain($state);
            break;
        }

        case 'cost_then_reenter': {
            takeDiscardedHandCards($state['players'][$owner], $ids, $state, $owner);
            $selfId = (string)($prompt['source_id'] ?? '');
            $cands = bp8StageCandidates($state['players'][$owner], fn($m) => ($m['instance_id'] ?? '') !== $selfId
                && cardMatchesSubunit($m, (string)($ab['subunit'] ?? '')));
            if ($cands === []) break;
            if (count($cands) === 1) {
                $state = bp8Reenter($state, $owner, $name, $cands[0]['slot']);
            } else {
                $state = bp7StartStagePick($state, $owner, $source, $ab, $ctx, 'bp8:reenter', $cands,
                    'Choose 1 ' . (string)($ab['subunit'] ?? '') . ' Member to put into the Waiting Room and play again.',
                    bp8PromptMeta($source, $ab));
            }
            $state = bp7QueueChain($state);
            break;
        }

        case 'reenter':
            $state = bp8Reenter($state, $owner, $name, $slot);
            $state = bp7QueueChain($state);
            break;

        case 'discard_then_add_if_cost': {
            if ($ids === []) break;
            takeDiscardedHandCards($state['players'][$owner], array_slice($ids, 0, 1), $state, $owner);
            $total = bp8StageGroupCostTotal($state, $owner, (string)($ab['group'] ?? ''));
            if ($total < intval($ab['min_total_cost'] ?? 25)) {
                $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] Stage cost total is $total (needs " .
                    intval($ab['min_total_cost'] ?? 25) . '); nothing added.');
                break;
            }
            $cands = bp8WrCandidates($state['players'][$owner], ['subunit' => (string)($ab['subunit'] ?? ''), 'filter' => $ab['filter'] ?? 'live']);
            if ($cands === []) break;
            $state = bp7StartCardPick($state, $owner, $source, $ab, $ctx, 'bp8:wr_to_hand', $cands, 1, 1,
                'Choose 1 ' . (string)($ab['subunit'] ?? '') . ' Live card from your Waiting Room to add to your hand.', bp8PromptMeta($source, $ab));
            $state = bp7QueueChain($state);
            break;
        }

        case 'discard_n_then_add': {
            if (!$yes) break;
            $need = intval($ab['discard'] ?? 2);
            $hand = array_values(array_filter($state['players'][$owner]['hand'] ?? [], 'is_array'));
            if (count($hand) < $need) break;
            $state = bp7StartCardPick($state, $owner, $source, $ab, $ctx, 'bp8:cost_then_add_wr_score', $hand, $need, $need,
                "Choose $need cards from your hand to put into the Waiting Room.", array_merge(bp8PromptMeta($source, $ab), ['bp8_discard' => true]));
            $state = bp7QueueChain($state);
            break;
        }

        case 'cost_then_add_wr_score': {
            takeDiscardedHandCards($state['players'][$owner], $ids, $state, $owner);
            $cands = bp8WrCandidates($state['players'][$owner], ['group' => $ab['group'] ?? '', 'filter' => 'live',
                'max_score' => intval($ab['max_score'] ?? 3)]);
            if ($cands === []) break;
            $state = bp7StartCardPick($state, $owner, $source, $ab, $ctx, 'bp8:wr_to_hand', $cands, 1, 1,
                'Choose 1 Live card from your Waiting Room to add to your hand.', bp8PromptMeta($source, $ab));
            $state = bp7QueueChain($state);
            break;
        }

        case 'discard_then_boost_zone': {
            $moved = takeDiscardedHandCards($state['players'][$owner], $ids, $state, $owner);
            if (!$moved) break;
            $zone = array_values(array_filter($state['players'][$owner]['live_zone'] ?? [], 'is_array'));
            if ($zone === []) break;
            if (count($zone) === 1) {
                $state = bp8BoostZoneLive($state, $owner, $name, $ab, (string)($zone[0]['instance_id'] ?? ''));
                break;
            }
            $state = bp7StartCardPick($state, $owner, $source, $ab, $ctx, 'bp8:boost_zone_live', $zone, 1, 1,
                'Choose 1 card in your Live storage: its score +1 and its Required Hearts increase.', bp8PromptMeta($source, $ab));
            $state = bp7QueueChain($state);
            break;
        }

        case 'boost_zone_live':
            $state = bp8BoostZoneLive($state, $owner, $name, $ab, (string)($ids[0] ?? ''));
            break;

        case 'pay_shuffle_bottom': {
            if (!$yes) break;
            $cost = intval($ab['energy_cost'] ?? 1);
            if (!payEnergyCost($state['players'][$owner], $cost)) {
                throw new Exception("Need $cost active Energy");
            }
            $max = intval($ab['max_cost'] ?? 9);
            $members = [];
            $rest = [];
            foreach ($state['players'][$owner]['waiting_room'] ?? [] as $c) {
                if (is_array($c) && bp8CardMatches($c, ['filter' => 'member', 'max_cost' => $max])) $members[] = $c; else $rest[] = $c;
            }
            $state['players'][$owner]['waiting_room'] = $rest;
            shuffle($members);
            putCardsOnMainDeckBottom($state, $owner, $members);
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] paid $cost Energy and shuffled " . count($members) .
                ' Member card(s) from the Waiting Room to the deck bottom.');
            break;
        }

        case 'wait_members_reduce': {
            $slots = is_array($data['slots'] ?? null) ? array_values(array_filter($data['slots'], 'is_string')) : [];
            if ($slots === [] && $ids) {
                foreach ($prompt['candidates'] ?? [] as $cand) {
                    if (in_array($cand['instance_id'] ?? '', $ids, true)) $slots[] = (string)$cand['slot'];
                }
            }
            $n = intval($ab['count'] ?? 2);
            if (count(array_unique($slots)) !== $n) {
                if ($slots === [] || $choice === 'skip') break;
                throw new Exception("Choose $n Members.");
            }
            foreach (array_unique($slots) as $s) {
                $m = $state['players'][$owner]['stage'][$s] ?? null;
                if (!$m || memberIsInWait($m)) throw new Exception('Choose active Members on your Stage.');
            }
            foreach (array_unique($slots) as $s) {
                $m = $state['players'][$owner]['stage'][$s];
                waitMember($m, $state);
                $m['skip_activate_next_turn'] = true;
                $state['players'][$owner]['stage'][$s] = $m;
            }
            bumpLiveCardColorReduction($state, $owner, (string)($prompt['source_id'] ?? ''), 'any', intval($ab['amount'] ?? 3));
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] put $n Members into Wait (they do not activate next Active Phase): required hearts reduced by " .
                intval($ab['amount'] ?? 3) . '.');
            break;
        }

        case 'subunit_cost_plus':
            $state = bp8SubunitCostPlus($state, $owner, $source, $ab, $slot);
            break;

        case 'return_member': {
            if ($slot === '' || $choice === 'skip') break;
            $sub = (string)($ab['subunit'] ?? '');
            [$state, $card] = bp8StageMemberToHand($state, $owner, $slot);
            if (!$card) break;
            $cost = intval($card['cost'] ?? 0);
            $state = addLog($state, bp8PlayerName($state, $owner) . " — [$name] returned " . cardDisplayName($card) . ' to hand.');
            $cands = bp8HandCandidates($state['players'][$owner], ['filter' => 'member', 'subunit' => $sub]);
            $cands = array_values(array_filter($cands, fn($c) => intval($c['cost'] ?? 0) === $cost));
            $empty = array_values(array_filter(['left', 'center', 'right'], fn($s) => empty($state['players'][$owner]['stage'][$s])));
            if ($cands === [] || $empty === []) break;
            $state = bp7StartCardPick($state, $owner, $source, $ab, $ctx, 'bp8:play_same_cost', $cands, 1, 1,
                "Choose 1 $sub Member card with cost $cost from your hand to play to an empty area (its [On Enter] does not trigger).",
                bp8PromptMeta($source, $ab));
            $state = bp7QueueChain($state);
            break;
        }

        case 'play_same_cost': {
            $id = (string)($ids[0] ?? '');
            $empty = array_values(array_filter(['left', 'center', 'right'], fn($s) => empty($state['players'][$owner]['stage'][$s])));
            if ($id === '' || $empty === []) break;
            if (count($empty) === 1) {
                $state = bp8PlayHandSilent($state, $owner, $id, $empty[0], $name);
                break;
            }
            $state = bp7StartChoices($state, $owner, $source, $ab, $ctx, 'bp8:play_same_cost_slot', $empty,
                array_map('ucfirst', $empty), 'Choose an empty area to play the Member to.',
                array_merge(bp8PromptMeta($source, $ab), ['bp8_card_id' => $id]));
            $state = bp7QueueChain($state);
            break;
        }

        case 'play_same_cost_slot': {
            $s = (string)($data['slot'] ?? $choice);
            if (!in_array($s, ['left', 'center', 'right'], true) || !empty($state['players'][$owner]['stage'][$s])) {
                throw new Exception('Choose an empty area.');
            }
            $state = bp8PlayHandSilent($state, $owner, (string)($prompt['bp8_card_id'] ?? ''), $s, $name);
            break;
        }

        case 'opp_draw_heart': {
            if (!$yes) break;
            $opp = bp8Opp($owner);
            drawCardsForPlayer($state, $opp, 1);
            $state = addLog($state, bp8PlayerName($state, $opp) . " drew 1 card ([$name]).");
            $cands = bp8StageCandidates($state['players'][$owner], fn($m) => cardMatchesSubunit($m, (string)($ab['subunit'] ?? ''))
                && getEffectiveStageMemberCost($state, $owner, $m) >= intval($ab['min_cost'] ?? 10));
            if ($cands === []) break;
            if (count($cands) === 1) {
                $state = bp8GrantStageHearts($state, $owner, $source, $cands[0]['slot'], $ab['hearts'] ?? []);
                break;
            }
            $state = bp7StartStagePick($state, $owner, $source, $ab, $ctx, 'bp8:grant_hearts', $cands,
                'Choose 1 Member to gain the heart.', bp8PromptMeta($source, $ab));
            $state = bp7QueueChain($state);
            break;
        }

        default:
            return null;
    }

    if (!empty($state['pending_prompt'])) {
        $state = bp7QueueChain($state);
    }
    return bp7FinishPrompt($state, $prompt);
}

function bp8BoostZoneLive(array $state, string $pid, string $name, array $ab, string $iid): array {
    if ($iid === '') {
        return $state;
    }
    bumpLiveCardScore($state, $pid, $iid, intval($ab['score'] ?? 1));
    foreach ($state['players'][$pid]['live_zone'] as &$lc) {
        if (!is_array($lc) || ($lc['instance_id'] ?? '') !== $iid) continue;
        if (!isset($lc['hearts_color_increase']) || !is_array($lc['hearts_color_increase'])) $lc['hearts_color_increase'] = [];
        $lc['hearts_color_increase']['any'] = intval($lc['hearts_color_increase']['any'] ?? 0) + intval($ab['hearts_increase'] ?? 2);
        $state = addLog($state, bp8PlayerName($state, $pid) . " — [$name] " . cardDisplayName($lc) . ': score +' .
            intval($ab['score'] ?? 1) . ', Required Hearts +' . intval($ab['hearts_increase'] ?? 2) . '.');
        break;
    }
    unset($lc);
    return $state;
}

function bp8Reenter(array $state, string $pid, string $name, string $slot): array {
    $m = $state['players'][$pid]['stage'][$slot] ?? null;
    if (!$m) {
        return $state;
    }
    $iid = (string)($m['instance_id'] ?? '');
    [$state, $left] = bp8StageMemberToWaitingRoom($state, $pid, $slot);
    if (!$left) {
        return $state;
    }
    $state = addLog($state, bp8PlayerName($state, $pid) . " — [$name] put " . cardDisplayName($left) . ' into the Waiting Room.');
    if (!empty($state['players'][$pid]['stage'][$slot])) {
        return $state;
    }
    return bp8PlayFromWaitingRoom($state, $pid, $iid, $slot, $name);
}

function bp8PlayHandSilent(array $state, string $pid, string $cardId, string $slot, string $name): array {
    $picked = bp8TakeFromZone($state['players'][$pid], 'hand', [$cardId]);
    if (!$picked) {
        return $state;
    }
    $member = stripStageWaitState($picked[0]);
    mergeCardCatalogFields($member);
    clearMemberWait($member);
    $member['active'] = true;
    $member['entered_turn'] = intval($state['turn'] ?? 1);
    $member['entered_from_hand'] = true;
    unset($member['entered_from_wr'], $member['entered_via_baton']);
    $state['players'][$pid]['stage'][$slot] = $member;
    if (function_exists('notifyMemberEnteredStage')) {
        notifyMemberEnteredStage($state, $pid, $member);
    }
    return addLog($state, bp8PlayerName($state, $pid) . " — [$name] played " . cardDisplayName($member) .
        " from hand to the $slot area ([On Enter] does not trigger).");
}

/** PL!HS-bp8-004 Ginko: gain the discarded card's [On Enter] until this Live ends and trigger it for free. */
function bp8RunCopiedOnEnter(array $state, string $pid, array $source, array $card, array $copied, array $ctx): array {
    $slot = bp8SelfSlot($state, $pid, $source);
    if ($slot === '') {
        return $state;
    }
    $copied['trigger'] = 'on_enter';
    $copied['bp8_copied_from'] = $card['card_no'] ?? '';
    $copied['free_cost'] = true;
    $state['players'][$pid]['stage'][$slot]['bp8_gained_abilities'][] = $copied;
    $member = $state['players'][$pid]['stage'][$slot];
    $state = addLog($state, bp8PlayerName($state, $pid) . ' — [' . bp8Name($source) . '] gained an [On Enter] ability of ' .
        cardDisplayName($card) . ' and triggers it.');
    return resolveAbilityEffect($state, $pid, $member, $copied, [
        'slot'      => $slot,
        'phase'     => 'on_enter',
        'free_cost' => true,
    ]);
}

/** Flag Yell cards on display (yell_reveal) that carry PL!-bp8-023's orange heart, for the client. */
function bp8MarkYellRevealOrange(array &$state, string $pid): void {
    foreach (['yell_reveal'] as $key) {
        if (empty($state[$key][$pid]) || !is_array($state[$key][$pid])) continue;
        foreach ($state[$key][$pid] as $i => $c) {
            if (is_array($c) && bp8YellCardHasOrange($state, $pid, $c)) {
                $state[$key][$pid][$i]['bp8_orange'] = true;
            }
        }
    }
    foreach ($state['players'][$pid]['yell_cards'] ?? [] as $i => $c) {
        if (is_array($c) && bp8YellCardHasOrange($state, $pid, $c)) {
            $state['players'][$pid]['yell_cards'][$i]['bp8_orange'] = true;
        }
    }
}
