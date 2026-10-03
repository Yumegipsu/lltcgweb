<?php
/**
 * μ's Premium Booster DUO (PL!-pb2-*) effect types and handlers.
 * Included by effects.php.
 */

function plMusePb2EffectTypes(): array {
    return [
        'if_double_baton_add_wr_live_score_if_cost_sum',
        'success_pile_icon_bonuses',
        'opp_enter_opposite_wait_max_printed_hearts',
        'negate_opp_member_live_success_gain_heart',
        'blade_per_success_score_icon_group',
        'auto_yell_extra_per_score_icon_group',
        'grant_stage_group_blade_if_success_score_icon',
        'optional_wait_self_discard_wait_opp_max_printed_hearts',
        'leave_stage_add_live_activate_per_success_group',
        'auto_on_leave_stage_if_baton_min_cost_energy',
        'blade_per_activated_from_wait_by_subunit_effect',
        'blade_per_stacked_subunit_member',
        'auto_stack_wr_subunit_under_on_opp_wait',
        'play_cost_reduce_if_wait_distinct_subunit',
        'activated_wait_printemps_live_from_wr',
        'reveal_top_all_subunit_add_live',
        'optional_reveal_hand_live_swap_success',
        'auto_on_opp_wait_by_subunit_choose',
        'per_success_subunit_choose',
        'stack_wr_subunit_members_under',
        'optional_unstack_toggle_subunit_members',
        'optional_activate_opp_wait_draw_each',
        'optional_discard_distinct_subunit_wait_opp',
        'optional_wait_self_discard_center_group_blade',
        'auto_on_self_wait_by_own_effect_active_blade',
        'blade_if_no_success_lives',
        'blade_per_success_subunit',
        'optional_wait_self_discard_look_reveal',
        'wait_opp_max_printed_blade_if_stage_only_group',
        'blade_per_success_score_chunk',
        'wait_opponent_stage_max_printed_hearts',
        'live_success_pick_yell_member_if_all_same_subunit',
        'live_score_if_exactly_group_members_on_stage',
        'increase_yell_reveal_if_success_group',
        'score_per_distinct_group_name_stage_and_yell',
        'reduce_hearts_per_activated_from_wait_by_subunit',
        'success_count_as_two_for_subunit_effects',
        'score_if_success_subunit_min',
        'auto_yell_wait_opp_if_named_members_and_center',
        // Continuations after effect_discard_hand / multi-step prompts
        'pb2_apply_center_group_blade',
        'pb2_begin_wait_opp_printed_hearts',
        'pb2_add_subunit_live_from_wr',
        'pb2_resume_per_success_choose',
    ];
}

function plMusePb2IsEffectType(string $type): bool {
    return in_array($type, plMusePb2EffectTypes(), true);
}

function plMusePb2SetPendingPrompt(array $state, array $prompt): array {
    $owner = (string)($prompt['owner'] ?? $prompt['player_id'] ?? '');
    if ($owner !== '') {
        $prompt['owner'] = $owner;
        $prompt['player_id'] = $owner;
        $prompt['responder'] = $prompt['responder'] ?? $owner;
    }
    $state['pending_prompt'] = $prompt;
    return $state;
}

function plMusePb2WaitSelfByInstance(array &$state, string $pid, string $instanceId): bool {
    $p = &$state['players'][$pid];
    foreach ($p['stage'] as &$mbr) {
        if ($mbr && ($mbr['instance_id'] ?? '') === $instanceId) {
            waitMember($mbr, $state);
            return true;
        }
    }
    unset($mbr);
    return false;
}

function plMusePb2FinishPrompt(array $state, array $prompt): array {
    unset($state['pending_prompt']);
    $state['seq'] = intval($state['seq'] ?? 0) + 1;
    if (function_exists('finishAfterBranchChoicePrompt')) {
        return finishAfterBranchChoicePrompt($state, $prompt);
    }
    if (($state['phase'] ?? '') === 'live_start_effects' || !empty($prompt['live_start'])) {
        return finishLiveStartEffects($state);
    }
    return finishPromptEffects($state);
}

function plMusePb2DiscardIds(array &$state, string $pid, array $ids, string $srcName): void {
    $p = &$state['players'][$pid];
    if (function_exists('discardFromHandByIds')) {
        discardFromHandByIds($p, $ids, $state, $pid);
        return;
    }
    if (function_exists('discardHandCardsByIds')) {
        discardHandCardsByIds($p, $ids, $state, $pid);
    }
}

function plMusePb2ActivateEnergy(array $state, string $pid, int $count): array {
    $p = &$state['players'][$pid];
    $n = activateEnergyForPlayer($p, $count);
    if ($n > 0) {
        $state = addLog($state, $state['players'][$pid]['name'] . " activated $n Energy.");
    }
    return $state;
}

function plMusePb2PrintedHeartCount(array $member): int {
    $n = 0;
    foreach ($member['hearts'] ?? [] as $h) {
        $n += intval(is_array($h) ? ($h['count'] ?? 0) : 0);
    }
    return $n;
}

function plMusePb2SuccessGroupCards(array $p, string $group): array {
    $out = [];
    foreach ($p['success_lives'] ?? [] as $c) {
        if ($c && cardMatchesGroup($c, $group, '')) {
            $out[] = $c;
        }
    }
    return $out;
}

/** True when a card is lily white (subunit field or inferred). */
function plMusePb2IsLilyWhiteCard(array $card): bool {
    return cardMatchesSubunit($card, 'lily white');
}

/**
 * Love Marginal / Honoka pb2-010: count Members put Wait→Active this turn by a
 * Printemps (etc.) card effect. Refs #233.
 */
function plMusePb2ActivatedFromWaitKey(string $subunit): string {
    return '_pb2_activated_from_wait_' . $subunit;
}

function plMusePb2NoteActivatedFromWait(array &$state, string $pid, ?array $effectSource, int $count = 1): void {
    if ($count < 1 || !is_array($effectSource)) {
        return;
    }
    $p = &$state['players'][$pid];
    foreach (['Printemps', 'lily white', 'BiBi'] as $sub) {
        if (!cardMatchesSubunit($effectSource, $sub)) {
            continue;
        }
        $key = plMusePb2ActivatedFromWaitKey($sub);
        $p[$key] = intval($p[$key] ?? 0) + $count;
    }
}

/** Mark a Stage Member as Wait→Active this turn via a subunit card effect (#236). */
function plMusePb2MarkMemberActivatedFromWait(array &$member, ?array $effectSource, int $turn): void {
    if (!is_array($effectSource)) {
        return;
    }
    $bys = is_array($member['_pb2_from_wait_by'] ?? null) ? $member['_pb2_from_wait_by'] : [];
    foreach (['Printemps', 'lily white', 'BiBi'] as $sub) {
        if (!cardMatchesSubunit($effectSource, $sub)) {
            continue;
        }
        if (!in_array($sub, $bys, true)) {
            $bys[] = $sub;
        }
    }
    if ($bys === []) {
        return;
    }
    $member['_pb2_from_wait_turn'] = $turn;
    $member['_pb2_from_wait_by'] = $bys;
}

/** Clear Wait and, if the member was Waiting, attribute the Activate to $effectSource. */
function plMusePb2ActivateFromWait(
    array &$state,
    string $pid,
    array &$member,
    ?array $effectSource = null
): bool {
    if (!memberIsInWait($member)) {
        return false;
    }
    $src = $effectSource ?? ($state['_mod_source'] ?? null);
    clearMemberWait($member);
    plMusePb2MarkMemberActivatedFromWait($member, is_array($src) ? $src : null, intval($state['turn'] ?? 1));
    plMusePb2NoteActivatedFromWait($state, $pid, is_array($src) ? $src : null, 1);
    return true;
}

function plMusePb2ClearActivatedFromWaitCounters(array &$p): void {
    foreach (array_keys($p) as $k) {
        if (is_string($k) && str_starts_with($k, '_pb2_activated_from_wait_')) {
            unset($p[$k]);
        }
    }
    unset($p['_pb2_defer_blade_from_wait']);
    unset($p['_pb2_defer_hearts_from_wait']);
    foreach ($p['stage'] ?? [] as &$m) {
        if ($m) {
            unset($m['_pb2_from_wait_turn'], $m['_pb2_from_wait_by']);
        }
    }
    unset($m);
}

/** Members on Stage put Wait→Active this turn by a $subunit card effect (#236 Honoka). */
function plMusePb2CountStageActivatedFromWait(array $p, string $subunit, int $turn): int {
    $n = 0;
    foreach ($p['stage'] ?? [] as $m) {
        if (!$m) {
            continue;
        }
        if (intval($m['_pb2_from_wait_turn'] ?? 0) !== $turn) {
            continue;
        }
        $bys = $m['_pb2_from_wait_by'] ?? [];
        if (!is_array($bys)) {
            continue;
        }
        if (in_array($subunit, $bys, true)) {
            $n++;
        }
    }
    return $n;
}

/** Apply one deferred Honoka-style Live Start blade grant. */
function plMusePb2ApplyActivatedFromWaitBladeEntry(array $state, string $pid, array $entry): array {
    $subunit = (string)($entry['subunit'] ?? 'Printemps');
    $amt = max(1, intval($entry['amount'] ?? 1));
    $turn = intval($state['turn'] ?? 1);
    $n = plMusePb2CountStageActivatedFromWait($state['players'][$pid] ?? [], $subunit, $turn);
    if ($n < 1) {
        // Fallback: turn counter (Love Marginal path) when marks are missing.
        $n = intval($state['players'][$pid][plMusePb2ActivatedFromWaitKey($subunit)] ?? 0);
    }
    if ($n < 1) {
        return $state;
    }
    $blade = $n * $amt;
    $srcId = (string)($entry['source_id'] ?? '');
    $srcName = (string)($entry['source_name'] ?? 'Member');
    $src = [];
    if ($srcId !== '' && function_exists('findSourceCard')) {
        $found = findSourceCard($state, $pid, $srcId);
        if (is_array($found)) {
            $src = $found;
        } else {
            $src = ['instance_id' => $srcId];
        }
    }
    $prev = $state['_mod_source'] ?? null;
    if ($src !== []) {
        $state['_mod_source'] = $src;
    }
    $state = applyModifierEffect($state, $pid, [
        'type' => 'blade_bonus',
        'amount' => $blade,
    ], $src);
    if ($prev === null) {
        unset($state['_mod_source']);
    } else {
        $state['_mod_source'] = $prev;
    }
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$srcName] +$blade Blade (Wait→Active via $subunit effects this turn).");
    return $state;
}

/**
 * Honoka / Love Marginal: apply deferred Wait→Active Live Start grants after all
 * Live Starts (so WAO-WAO etc. can Activate first). Refs #236 / #237.
 */
function plMusePb2FlushDeferredActivatedFromWaitEffects(array $state): array {
    $state = plMusePb2FlushDeferredActivatedFromWaitBlade($state);
    $state = plMusePb2FlushDeferredActivatedFromWaitHearts($state);
    return $state;
}

/** @deprecated Use plMusePb2FlushDeferredActivatedFromWaitEffects */
function plMusePb2FlushDeferredActivatedFromWaitBlade(array $state): array {
    foreach (['p1', 'p2'] as $pid) {
        $list = $state['players'][$pid]['_pb2_defer_blade_from_wait'] ?? null;
        if (!is_array($list) || $list === []) {
            continue;
        }
        unset($state['players'][$pid]['_pb2_defer_blade_from_wait']);
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $state = plMusePb2ApplyActivatedFromWaitBladeEntry($state, $pid, $entry);
        }
    }
    return $state;
}

/** Apply Love Marginal-style deferred heart reductions (#237). */
function plMusePb2ApplyActivatedFromWaitHeartsEntry(array $state, string $pid, array $entry): array {
    $subunit = (string)($entry['subunit'] ?? 'Printemps');
    $tiers = $entry['tiers'] ?? [];
    if (!is_array($tiers)) {
        $tiers = [];
    }
    $turn = intval($state['turn'] ?? 1);
    $n = plMusePb2CountStageActivatedFromWait($state['players'][$pid] ?? [], $subunit, $turn);
    if ($n < 1) {
        $n = intval($state['players'][$pid][plMusePb2ActivatedFromWaitKey($subunit)] ?? 0);
    }
    if ($n < 1 || $tiers === []) {
        return $state;
    }
    $reduce = 0;
    $color = 'any';
    foreach ($tiers as $tier) {
        if (!is_array($tier)) {
            continue;
        }
        if ($n >= intval($tier['min'] ?? 0)) {
            $reduce += intval($tier['reduce'] ?? 0);
            if (!empty($tier['color'])) {
                $color = (string)$tier['color'];
            }
        }
    }
    if ($reduce < 1) {
        return $state;
    }
    $srcId = (string)($entry['source_id'] ?? '');
    $srcName = (string)($entry['source_name'] ?? 'Live');
    if ($srcId !== '') {
        bumpLiveCardColorReduction($state, $pid, $srcId, $color, $reduce);
    }
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$srcName] required $color hearts −$reduce ($n Wait→Active via $subunit this turn).");
    return $state;
}

function plMusePb2FlushDeferredActivatedFromWaitHearts(array $state): array {
    foreach (['p1', 'p2'] as $pid) {
        $list = $state['players'][$pid]['_pb2_defer_hearts_from_wait'] ?? null;
        if (!is_array($list) || $list === []) {
            continue;
        }
        unset($state['players'][$pid]['_pb2_defer_hearts_from_wait']);
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $state = plMusePb2ApplyActivatedFromWaitHeartsEntry($state, $pid, $entry);
        }
    }
    return $state;
}

/**
 * PL!-pb2-041 Shunjou Romantic [Always]: while in Success Live, lily white card effects
 * that count cards in Success Live treat this card as 2. Refs #227.
 */
function plMusePb2SuccessCardCountWeight(array $card, ?array $effectSource = null): int {
    if ($effectSource !== null && !plMusePb2IsLilyWhiteCard($effectSource)) {
        return 1;
    }
    foreach ($card['abilities'] ?? [] as $ab) {
        if (($ab['type'] ?? '') !== 'success_count_as_two_for_subunit_effects') {
            continue;
        }
        $need = (string)($ab['subunit'] ?? 'lily white');
        if ($need === '' || strcasecmp($need, 'lily white') === 0) {
            // When effectSource is omitted, only weight for lily-white-scoped Always.
            if ($effectSource === null || plMusePb2IsLilyWhiteCard($effectSource)) {
                return 2;
            }
        }
    }
    return 1;
}

/** Count Success Live cards matching group, applying lily white count-as-two weights. */
function plMusePb2CountSuccessGroup(array $p, string $group, ?array $effectSource = null): int {
    $n = 0;
    foreach (plMusePb2SuccessGroupCards($p, $group) as $c) {
        $n += plMusePb2SuccessCardCountWeight($c, $effectSource);
    }
    return $n;
}

function plMusePb2CardHasScoreIcon(array $c): bool {
    return !empty($c['yell_score_icon']) || ($c['special_heart'] ?? '') === 'icon_score.png';
}

function plMusePb2CardHasDrawIcon(array $c): bool {
    return !empty($c['yell_draw_icon']) || ($c['special_heart'] ?? '') === 'icon_draw.png';
}

function plMusePb2CardHasAllBlade(array $c): bool {
    foreach ($c['blade_hearts'] ?? [] as $bh) {
        $t = is_array($bh) ? strval($bh['type'] ?? $bh['color'] ?? '') : strval($bh);
        if (in_array(strtolower($t), ['all', 'all_blades', 'all1'], true)) {
            return true;
        }
    }
    return false;
}

function plMusePb2SumSuccessScores(array $p): int {
    $sum = 0;
    foreach ($p['success_lives'] ?? [] as $c) {
        if ($c) {
            $sum += intval($c['score'] ?? 0);
        }
    }
    return $sum;
}

function plMusePb2CountSuccessSubunit(array $p, string $subunit, ?array $effectSource = null): int {
    $n = 0;
    foreach ($p['success_lives'] ?? [] as $c) {
        if ($c && cardMatchesSubunit($c, $subunit)) {
            $n += plMusePb2SuccessCardCountWeight($c, $effectSource);
        }
    }
    return $n;
}

function plMusePb2StageOnlySubunit(array $p, string $subunit): bool {
    $any = false;
    foreach ($p['stage'] ?? [] as $m) {
        if (!$m) {
            continue;
        }
        $any = true;
        if (!cardMatchesSubunit($m, $subunit)) {
            return false;
        }
    }
    return $any;
}

function plMusePb2StageOnlyGroup(array $p, string $group): bool {
    $any = false;
    foreach ($p['stage'] ?? [] as $m) {
        if (!$m) {
            continue;
        }
        $any = true;
        if (!cardMatchesGroup($m, $group, 'member')) {
            return false;
        }
    }
    return $any;
}

/** Continuous blade bonuses for μ's DUO Always abilities. */
function plMusePb2ApplyContinuousBlade(int $blade, array $member, array $state, string $pid, array $ab): int {
    $type = $ab['type'] ?? '';
    $p = $state['players'][$pid] ?? [];
    if ($type === 'blade_per_success_score_icon_group') {
        $group = $ab['group'] ?? "μ's";
        $n = 0;
        foreach (plMusePb2SuccessGroupCards($p, $group) as $c) {
            if (plMusePb2CardHasScoreIcon($c)) {
                $n += plMusePb2SuccessCardCountWeight($c, $member);
            }
        }
        return $blade + $n * intval($ab['amount'] ?? 1);
    }
    if ($type === 'blade_per_stacked_subunit_member') {
        $subunit = $ab['subunit'] ?? '';
        $n = 0;
        foreach ($member['stacked_members'] ?? [] as $s) {
            if ($s && cardMatchesSubunit($s, $subunit)
                && isMemberCard($s)) {
                $n++;
            }
        }
        return $blade + $n * intval($ab['amount'] ?? 1);
    }
    if ($type === 'blade_if_no_success_lives') {
        if (empty($p['success_lives'])) {
            return $blade + intval($ab['amount'] ?? 1);
        }
        return $blade;
    }
    if ($type === 'blade_per_success_subunit') {
        return $blade + plMusePb2CountSuccessSubunit($p, $ab['subunit'] ?? '', $member)
            * intval($ab['amount'] ?? 1);
    }
    if ($type === 'blade_per_success_score_chunk') {
        $chunk = max(1, intval($ab['chunk'] ?? 5));
        $sum = plMusePb2SumSuccessScores($p);
        return $blade + intdiv($sum, $chunk) * intval($ab['amount'] ?? 1);
    }
    return $blade;
}

function plMusePb2ApplyContinuousHearts(array $state, string $pid, array $member, array $ab, array $hearts): array {
    $type = $ab['type'] ?? '';
    // hearts_if_combined_success_score_min is handled elsewhere; keep hook for future.
    return $hearts;
}

function plMusePb2ApplyHandCostReduction(array $state, string $pid, array $card, int $base): int {
    foreach ($card['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') {
            continue;
        }
        if (($ab['type'] ?? '') !== 'play_cost_reduce_if_wait_distinct_subunit') {
            continue;
        }
        // Actual reduction applied when paying / confirming wait choice via prompt flag.
        if (!empty($card['_pb2_cost_reduced'])) {
            $base = max(0, $base - intval($ab['reduce'] ?? 2));
        }
    }
    return $base;
}

/**
 * Optional play-cost: Wait N distinct-named subunit Members on Stage for −reduce.
 * Client opts in with pb2_wait_slots (Refs #232 Kotori).
 *
 * @return array{0:int,1:?array} [adjusted cost, ability or null]
 */
function plMusePb2AdjustHandPlayCost(array $state, string $pid, array $card, int $cost, array $opts = []): array {
    $slots = $opts['wait_slots'] ?? [];
    if (!is_array($slots) || $slots === []) {
        return [$cost, null];
    }
    foreach ($card['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') {
            continue;
        }
        if (($ab['type'] ?? '') !== 'play_cost_reduce_if_wait_distinct_subunit') {
            continue;
        }
        $need = max(1, intval($ab['wait_count'] ?? 2));
        if (count($slots) < $need) {
            continue;
        }
        if (!plMusePb2ValidateDistinctSubunitWaitSlots($state, $pid, array_slice($slots, 0, $need), $ab)) {
            continue;
        }
        return [max(0, $cost - intval($ab['reduce'] ?? 2)), $ab];
    }
    return [$cost, null];
}

/** @param list<string> $slots */
function plMusePb2ValidateDistinctSubunitWaitSlots(array $state, string $pid, array $slots, array $ab): bool {
    $p = $state['players'][$pid] ?? [];
    $subunit = (string)($ab['subunit'] ?? 'Printemps');
    $need = count($slots);
    if ($need < 1) {
        return false;
    }
    $names = [];
    foreach ($slots as $slot) {
        $m = $p['stage'][$slot] ?? null;
        if (!$m || memberIsInWait($m) || !cardMatchesSubunit($m, $subunit)) {
            return false;
        }
        $nm = strtolower(trim((string)($m['name_en'] ?? $m['name'] ?? '')));
        if ($nm === '' || isset($names[$nm])) {
            return false;
        }
        $names[$nm] = true;
    }
    return count($names) === $need;
}

function plMusePb2ApplyHandPlayCostOption(array $state, string $pid, array $card, array $ab, array $opts): array {
    $slots = $opts['wait_slots'] ?? [];
    if (!is_array($slots)) {
        $slots = [];
    }
    $need = max(1, intval($ab['wait_count'] ?? 2));
    $slots = array_slice(array_values($slots), 0, $need);
    if (!plMusePb2ValidateDistinctSubunitWaitSlots($state, $pid, $slots, $ab)) {
        throw new Exception('Choose ' . $need . ' Active Printemps Members with different names to Wait');
    }
    $p = &$state['players'][$pid];
    foreach ($slots as $slot) {
        if (!empty($p['stage'][$slot])) {
            waitMember($p['stage'][$slot], $state);
        }
    }
    $name = $card['name_en'] ?? $card['name'] ?? 'Card';
    $state = addLog($state, $state['players'][$pid]['name'] .
        ' — [' . $name . '] Waited ' . $need . ' Printemps Members; play cost reduced by ' .
        intval($ab['reduce'] ?? 2) . '.');
    return $state;
}

/** True if Kotori activated extra cost can be paid (hand discard 2 OR Wait 2 other Printemps). */
function plMusePb2KotoriCanPayExtraCost(array $p, array $ab, string $srcId): bool {
    if (count($p['hand'] ?? []) >= 2) {
        return true;
    }
    $subunit = (string)($ab['subunit'] ?? 'Printemps');
    $n = 0;
    foreach ($p['stage'] ?? [] as $m) {
        if ($m && ($m['instance_id'] ?? '') !== $srcId
            && cardMatchesSubunit($m, $subunit)
            && !memberIsInWait($m)) {
            $n++;
        }
    }
    return $n >= 2;
}

function plMusePb2ResolveEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';
    $p = &$state['players'][$pid];
    $opp = ($pid === 'p1') ? 'p2' : 'p1';

    switch ($type) {
        case 'if_double_baton_add_wr_live_score_if_cost_sum': {
            if (intval($source['baton_count'] ?? 0) < intval($ab['min_baton'] ?? 2)) {
                break;
            }
            $group = $ab['group'] ?? "μ's";
            $batonGroups = $source['baton_member_groups'] ?? [];
            $groupCount = count(array_filter($batonGroups, fn($g) => $g === $group));
            if ($groupCount < intval($ab['min_baton'] ?? 2)) {
                break;
            }
            $cands = array_values(array_filter(
                $p['waiting_room'] ?? [],
                fn($c) => cardMatchesWrPick($c, [
                    'group' => $group,
                    'filter' => $ab['filter'] ?? 'live',
                ])
            ));
            if ($cands) {
                $state = plMusePb2SetPendingPrompt($state, [
                    'type' => 'add_from_wr',
                    'owner' => $pid,
                    'player_id' => $pid,
                    'source_instance_id' => $source['instance_id'] ?? '',
                    'source_name' => $name,
                    'filter' => $ab['filter'] ?? 'live',
                    'group' => $group,
                    'count' => intval($ab['count'] ?? 1),
                    'candidates' => $cands,
                    'min' => 1,
                    'max' => 1,
                    'prompt' => "Add 1 $group Live from Waiting Room to hand?",
                ]);
            }
            $costSum = 0;
            foreach ($source['baton_member_costs'] ?? [] as $c) {
                $costSum += intval($c);
            }
            if ($costSum === 0) {
                foreach ($source['baton_sources'] ?? [] as $bs) {
                    $costSum += intval($bs['cost'] ?? 0);
                }
            }
            if ($costSum >= intval($ab['cost_sum'] ?? 15) || $costSum === intval($ab['cost_sum'] ?? 15)) {
                if ($costSum == intval($ab['cost_sum'] ?? 15)) {
                    $state = applyModifierEffect($state, $pid, [
                        'type' => 'live_score_bonus',
                        'amount' => intval($ab['score_amount'] ?? 1),
                        'source' => $name,
                    ]);
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] +1 total Live score (double Baton cost sum 15).");
                }
            }
            break;
        }

        case 'success_pile_icon_bonuses': {
            $group = $ab['group'] ?? "μ's";
            $cards = plMusePb2SuccessGroupCards($p, $group);
            $hasScore = $hasAll = $hasDraw = false;
            foreach ($cards as $c) {
                if (plMusePb2CardHasScoreIcon($c)) {
                    $hasScore = true;
                }
                if (plMusePb2CardHasAllBlade($c)) {
                    $hasAll = true;
                }
                if (plMusePb2CardHasDrawIcon($c)) {
                    $hasDraw = true;
                }
            }
            if ($hasScore) {
                $state = applyModifierEffect($state, $pid, [
                    'type' => 'live_score_bonus',
                    'amount' => intval($ab['score_icon_live_score'] ?? 1),
                    'source' => $name,
                ]);
            }
            if ($hasAll) {
                $state = applyModifierEffect($state, $pid, [
                    'type' => 'grant_bonus_hearts',
                    'hearts' => [['color' => 'any', 'count' => intval($ab['all_blade_icon_wild_heart'] ?? 1)]],
                    'source' => $name,
                ]);
            }
            if ($hasDraw) {
                $add = $ab['draw_icon_add_from_wr'] ?? ['group' => $group, 'count' => 1];
                $cands = array_values(array_filter(
                    $p['waiting_room'] ?? [],
                    fn($c) => cardMatchesWrPick($c, ['group' => $add['group'] ?? $group])
                ));
                if ($cands) {
                    $state = plMusePb2SetPendingPrompt($state, [
                        'type' => 'add_from_wr',
                        'owner' => $pid,
                        'player_id' => $pid,
                        'source_instance_id' => $source['instance_id'] ?? '',
                        'source_name' => $name,
                        'group' => $add['group'] ?? $group,
                        'count' => intval($add['count'] ?? 1),
                        'candidates' => $cands,
                        'min' => 1,
                        'max' => 1,
                    ]);
                }
            }
            break;
        }

        case 'negate_opp_member_live_success_gain_heart': {
            $cands = [];
            foreach ($state['players'][$opp]['stage'] ?? [] as $slot => $m) {
                if ($m) {
                    $cands[] = ['slot' => $slot, 'card' => $m];
                }
            }
            if (!$cands) {
                break;
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'negate_opp_member_live_success',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'candidates' => $cands,
                'then_heart' => $ab['heart'] ?? ['color' => 'yellow', 'count' => 1],
                'min' => 1,
                'max' => 1,
                'prompt' => 'Choose 1 opponent Member: negate their [Live Success] until this Live ends.',
            ]);
            break;
        }

        case 'grant_stage_group_blade_if_success_score_icon': {
            $group = $ab['group'] ?? "μ's";
            $ok = false;
            foreach (plMusePb2SuccessGroupCards($p, $group) as $c) {
                if (plMusePb2CardHasScoreIcon($c)) {
                    $ok = true;
                    break;
                }
            }
            if ($ok) {
                // Until-Live Always: μ's Stage Members gain +Blade (Rin PL!-pb2-005, #218).
                $state = initLiveModifiers($state);
                if (!isset($state['live_modifiers'][$pid]['stage_group_blade'])
                    || !is_array($state['live_modifiers'][$pid]['stage_group_blade'])) {
                    $state['live_modifiers'][$pid]['stage_group_blade'] = [];
                }
                $state['live_modifiers'][$pid]['stage_group_blade'][] = [
                    'group' => $group,
                    'blade' => intval($ab['blade'] ?? 1),
                    'source' => $name,
                    'source_instance_id' => $source['instance_id'] ?? '',
                ];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] μ's Stage Members gain +1 Blade until Live ends.");
            }
            break;
        }

        case 'optional_wait_self_discard_wait_opp_max_printed_hearts': {
            $maxH = intval($ab['max_printed_hearts'] ?? 1);
            $oppCands = [];
            foreach ($state['players'][$opp]['stage'] ?? [] as $slot => $m) {
                if ($m && !memberIsInWait($m) && plMusePb2PrintedHeartCount($m) <= $maxH) {
                    $oppCands[] = ['slot' => $slot, 'card' => $m];
                }
            }
            if (!$oppCands) {
                break;
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'optional_wait_self_discard_wait_opp',
                'owner' => $pid,
                'player_id' => $pid,
                'source_id' => $source['instance_id'] ?? '',
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'discard' => intval($ab['discard'] ?? 1),
                'max_printed_hearts' => $maxH,
                'candidates' => $oppCands,
                'choices' => ['yes', 'no'],
                'optional' => (($ab['trigger'] ?? '') === 'live_start'),
                'prompt' => 'Put this Member into Wait and discard 1: Wait an opponent Member with ≤' . $maxH . ' printed hearts?',
            ]);
            break;
        }

        case 'leave_stage_add_live_activate_per_success_group': {
            // Prefer ActivateAbility's leave-stage WR pick path. Keep this for
            // resolveAbilityEffect callers so the effect still opens the same prompt.
            $group = $ab['group'] ?? "μ's";
            $slot = (string)($ctx['slot'] ?? findMemberSlot($p, (string)($source['instance_id'] ?? '')));
            if ($slot === '' || empty($p['stage'][$slot])) {
                break;
            }
            $cfg = [
                'group' => $group,
                'filter' => $ab['filter'] ?? 'live',
            ];
            $abilityIdx = intval($ctx['ability_index'] ?? $ctx['ability_idx'] ?? 0);
            $member = $p['stage'][$slot];
            $ab['then_activate_energy'] = plMusePb2CountSuccessGroup($p, $group, $source);
            startPickWrToHandPrompt($state, $pid, $member, $slot, $abilityIdx, $ab, $cfg, true);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] choose a card from Waiting Room.");
            break;
        }

        case 'auto_on_leave_stage_if_baton_min_cost_energy': {
            // Leaving Member (e.g. PL!-pb2-009): check the Member that Baton Touched
            // over this one via ctx, not this card's own baton_member_costs (those are
            // set on the incoming card). Refs #221.
            $minCost = intval($ab['min_baton_cost'] ?? 15);
            $group = $ab['group'] ?? "μ's";
            $ok = false;
            $incoming = $ctx['baton_incoming'] ?? null;
            if (is_array($incoming)) {
                mergeCardCatalogFields($incoming);
                if (isMemberCard($incoming)
                    && intval($incoming['cost'] ?? 0) >= $minCost
                    && ($group === '' || cardMatchesGroup($incoming, $group, 'member'))) {
                    $ok = true;
                }
            }
            if (!$ok) {
                foreach ($source['baton_sources'] ?? [] as $bs) {
                    if (!is_array($bs)) {
                        continue;
                    }
                    if (intval($bs['cost'] ?? 0) >= $minCost
                        && ($group === '' || cardMatchesGroup($bs, $group, 'member'))) {
                        $ok = true;
                        break;
                    }
                }
            }
            if ($ok) {
                $want = intval($ab['energy'] ?? 2);
                $state = plMusePb2ActivateEnergy($state, $pid, $want);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated Energy (Baton with high-cost μ's).");
            }
            break;
        }

        case 'blade_per_activated_from_wait_by_subunit_effect': {
            // Defer during Live Start so Stage Members (Honoka) don't resolve before
            // Live-zone Activators (WAO-WAO) under default L→R then Lives order (#236).
            $entry = [
                'source_id' => (string)($source['instance_id'] ?? ''),
                'source_name' => $name,
                'subunit' => (string)($ab['subunit'] ?? 'Printemps'),
                'amount' => intval($ab['amount'] ?? 1),
            ];
            $phase = (string)($state['phase'] ?? '');
            $defer = str_contains($phase, 'live_start')
                || !empty($GLOBALS['_lltcg_in_live_start_resolve']);
            if ($defer) {
                $list = $p['_pb2_defer_blade_from_wait'] ?? [];
                if (!is_array($list)) {
                    $list = [];
                }
                $list[] = $entry;
                $p['_pb2_defer_blade_from_wait'] = $list;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Live Start Blade (after Wait→Active counts this turn).");
                break;
            }
            $state = plMusePb2ApplyActivatedFromWaitBladeEntry($state, $pid, $entry);
            break;
        }

        case 'reveal_top_all_subunit_add_live': {
            // Umi PL!-pb2-013 — reveal top N; if all match subunit, add 1 Live to hand (#226).
            $look = max(1, intval($ab['look'] ?? 4));
            $subunit = $ab['subunit'] ?? 'lily white';
            $drawn = [];
            for ($i = 0; $i < $look; $i++) {
                if (empty($p['main_deck'])) {
                    refreshMainDeckFromWaitingRoom($state, $pid);
                }
                if (empty($p['main_deck'])) {
                    break;
                }
                $card = array_shift($p['main_deck']);
                if (!is_array($card)) {
                    continue;
                }
                mergeCardCatalogFields($card);
                $drawn[] = $card;
            }
            if ($drawn === []) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] On Enter: deck is empty.");
                break;
            }
            $state = queuePublicSkillReveal($state, $pid, $drawn, $name, 'deck_to_wr');
            $p = &$state['players'][$pid];

            $allMatch = true;
            foreach ($drawn as $c) {
                if (!cardMatchesSubunit($c, $subunit)) {
                    $allMatch = false;
                    break;
                }
            }
            if ($allMatch) {
                $lives = array_values(array_filter(
                    $drawn,
                    static fn($c) => isLiveTypeCard($c) && cardMatchesSubunit($c, $subunit)
                ));
                if ($lives) {
                    if (count($lives) > 1 && empty($state['pending_prompt'])) {
                        // Hold the whole reveal pile until the player picks a Live.
                        $state = plMusePb2SetPendingPrompt($state, [
                            'type' => 'pb2_pick_revealed_subunit_live',
                            'owner' => $pid,
                            'player_id' => $pid,
                            'responder' => $pid,
                            'source_instance_id' => $source['instance_id'] ?? '',
                            'source_name' => $name,
                            'subunit' => $subunit,
                            'revealed' => $drawn,
                            'candidates' => array_map('cardPromptSummary', $lives),
                            'min' => 1,
                            'max' => 1,
                            'prompt' => "Choose 1 $subunit Live card to add to your hand. The rest go to the Waiting Room.",
                        ]);
                        break;
                    }
                    $pick = $lives[0];
                    $p['hand'][] = $pick;
                    $drawn = array_values(array_filter(
                        $drawn,
                        static fn($c) => ($c['instance_id'] ?? '') !== ($pick['instance_id'] ?? '')
                    ));
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added " . cardDisplayName($pick) . " to hand.");
                    $p = &$state['players'][$pid];
                } else {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] all $subunit, but no Live among them.");
                }
            } else {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] revealed cards are not all $subunit.");
                $p = &$state['players'][$pid];
            }
            foreach ($drawn as $c) {
                $p['waiting_room'][] = $c;
            }
            break;
        }

        case 'optional_reveal_hand_live_swap_success': {
            $subunit = $ab['subunit'] ?? 'lily white';
            $handLives = array_values(array_filter(
                $p['hand'] ?? [],
                fn($c) => isLiveTypeCard($c) && cardMatchesSubunit($c, $subunit)
            ));
            if (!$handLives || empty($p['success_lives'])) {
                break;
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'optional_reveal_hand_live_swap_success',
                'owner' => $pid,
                'player_id' => $pid,
                'source_id' => $source['instance_id'] ?? '',
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'subunit' => $subunit,
                'hand_candidates' => $handLives,
                'success_candidates' => $p['success_lives'],
                'choices' => ['yes', 'no'],
                'optional' => true,
                'prompt' => "Reveal 1 $subunit Live from hand: swap with a Success Live card?",
            ]);
            break;
        }

        case 'stack_wr_subunit_members_under': {
            $subunit = $ab['subunit'] ?? 'Printemps';
            $need = intval($ab['count'] ?? 4);
            $cands = array_values(array_filter(
                $p['waiting_room'] ?? [],
                fn($c) => isMemberCard($c) && cardMatchesSubunit($c, $subunit)
            ));
            if (count($cands) < $need) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] not enough $subunit Members in WR to stack.");
                break;
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'stack_wr_under',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'subunit' => $subunit,
                'count' => $need,
                'candidates' => $cands,
                'min' => $need,
                'max' => $need,
                'prompt' => "Choose $need $subunit Members from your Waiting Room to put under this Member.",
            ]);
            break;
        }

        case 'optional_unstack_toggle_subunit_members': {
            $under = $source['stacked_members'] ?? [];
            if (!$under) {
                break;
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'optional_unstack_toggle_subunit',
                'owner' => $pid,
                'player_id' => $pid,
                'source_id' => $source['instance_id'] ?? '',
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'subunit' => $ab['subunit'] ?? 'Printemps',
                'max' => intval($ab['max'] ?? 3),
                'stacked' => array_map('cardPromptSummary', $under),
                'choices' => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'optional' => true,
                'live_start' => true,
                'prompt' => 'Put up to 3 cards from under this Member into the Waiting Room to toggle Printemps Members?',
            ]);
            break;
        }

        case 'optional_activate_opp_wait_draw_each': {
            $max = intval($ab['max'] ?? 3);
            $cands = [];
            foreach ($state['players'][$opp]['stage'] ?? [] as $slot => $m) {
                if ($m && memberIsInWait($m)) {
                    $cands[] = ['slot' => $slot, 'card' => $m];
                }
            }
            if (!$cands) {
                break;
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'optional_activate_opp_wait_draw_each',
                'owner' => $pid,
                'player_id' => $pid,
                'source_id' => $source['instance_id'] ?? '',
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'candidates' => $cands,
                'max' => $max,
                'choices' => ['yes', 'no'],
                'optional' => true,
                'prompt' => "Active up to $max opponent Wait Members and draw 1 each?",
            ]);
            break;
        }

        case 'optional_discard_distinct_subunit_wait_opp': {
            $subunit = $ab['subunit'] ?? 'BiBi';
            if (!empty($ab['require_stage_only_subunit']) && !plMusePb2StageOnlySubunit($p, $subunit)) {
                break;
            }
            $need = intval($ab['discard'] ?? 3);
            $hand = array_values(array_filter(
                $p['hand'] ?? [],
                fn($c) => isMemberCard($c) && cardMatchesSubunit($c, $subunit)
            ));
            $names = [];
            foreach ($hand as $c) {
                $names[cardDisplayName($c)] = true;
            }
            if (count($names) < $need) {
                break;
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'optional_discard_distinct_subunit_wait_opp',
                'owner' => $pid,
                'player_id' => $pid,
                'source_id' => $source['instance_id'] ?? '',
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'subunit' => $subunit,
                'discard' => $need,
                'choices' => ['yes', 'no'],
                'optional' => true,
                'prompt' => "Discard $need differently named $subunit Members to Wait 1 opponent Member?",
            ]);
            break;
        }

        case 'optional_wait_self_discard_center_group_blade': {
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'optional_wait_self_discard_center_blade',
                'owner' => $pid,
                'player_id' => $pid,
                'source_id' => $source['instance_id'] ?? '',
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'discard' => intval($ab['discard'] ?? 1),
                'group' => $ab['group'] ?? "μ's",
                'blade' => intval($ab['blade'] ?? 2),
                'choices' => ['yes', 'no'],
                'optional' => true,
                'live_start' => true,
                'prompt' => 'Wait this Member and discard 1: Center μ\'s Member gains +2 Blade?',
            ]);
            break;
        }

        case 'auto_on_self_wait_by_own_effect_active_blade': {
            // Resolved from wait hooks; here grant Active + blade if flagged
            if (empty($ctx['self_wait_by_own'])) {
                break;
            }
            $slot = $ctx['slot'] ?? findMemberSlot($p, (string)($source['instance_id'] ?? ''));
            if ($slot === '') {
                break;
            }
            clearMemberWait($p['stage'][$slot]);
            $state = applyModifierEffect($state, $pid, [
                'type' => 'blade_bonus',
                'amount' => intval($ab['blade'] ?? 1),
                'target_instance_id' => $source['instance_id'] ?? '',
                'source' => $name,
            ]);
            break;
        }

        case 'wait_opp_max_printed_blade_if_stage_only_group': {
            $group = $ab['group'] ?? "μ's";
            if (!plMusePb2StageOnlyGroup($p, $group)) {
                break;
            }
            $maxB = intval($ab['max_printed_blade'] ?? 2);
            $cands = [];
            foreach ($state['players'][$opp]['stage'] ?? [] as $slot => $m) {
                if ($m && !memberIsInWait($m)
                    && intval($m['blade'] ?? 0) <= $maxB) {
                    $cands[] = ['slot' => $slot, 'card' => $m];
                }
            }
            if (!$cands) {
                break;
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'wait_opponent_stage',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'candidates' => $cands,
                'min' => 1,
                'max' => intval($ab['pick_count'] ?? 1),
            ]);
            break;
        }

        case 'wait_opponent_stage_max_printed_hearts': {
            $maxH = intval($ab['max_printed_hearts'] ?? 3);
            $cands = [];
            foreach ($state['players'][$opp]['stage'] ?? [] as $slot => $m) {
                if ($m && !memberIsInWait($m) && plMusePb2PrintedHeartCount($m) <= $maxH) {
                    $cands[] = ['slot' => $slot, 'card' => $m];
                }
            }
            if (!$cands) {
                break;
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'wait_opponent_stage',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'candidates' => $cands,
                'min' => 1,
                'max' => intval($ab['pick_count'] ?? 1),
            ]);
            break;
        }

        case 'live_success_pick_yell_member_if_all_same_subunit': {
            $yell = $state['_last_yell_cards_' . $pid] ?? $state['_last_yell_cards'] ?? [];
            $members = array_values(array_filter($yell, 'isMemberCard'));
            if (!$members) {
                break;
            }
            $subunits = $ab['subunits'] ?? ['Printemps', 'lily white', 'BiBi'];
            $su = (string)($members[0]['subunit'] ?? '');
            $ok = in_array($su, $subunits, true);
            foreach ($members as $m) {
                if (!cardMatchesSubunit($m, $su)) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                break;
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'pick_yell_card_to_hand',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'candidates' => $members,
                'filter' => 'member',
                'min' => 1,
                'max' => 1,
            ]);
            break;
        }

        case 'increase_yell_reveal_if_success_group': {
            $group = $ab['group'] ?? "μ's";
            $min = intval($ab['min_success'] ?? 2);
            if (plMusePb2CountSuccessGroup($p, $group, $source) >= $min) {
                $extra = intval($ab['extra_yell'] ?? 10);
                $state['live_modifiers'][$pid]['extra_yell_reveal'] =
                    intval($state['live_modifiers'][$pid]['extra_yell_reveal'] ?? 0) + $extra;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Yell reveal +$extra.");
            }
            break;
        }

        case 'score_per_distinct_group_name_stage_and_yell': {
            $group = $ab['group'] ?? "μ's";
            $names = [];
            foreach ($p['stage'] ?? [] as $m) {
                if ($m && cardMatchesGroup($m, $group, 'member')) {
                    $names[cardDisplayName($m)] = true;
                }
            }
            $yell = $state['_last_yell_cards_' . $pid] ?? $state['_last_yell_cards'] ?? [];
            foreach ($yell as $c) {
                if ($c && isMemberCard($c) && cardMatchesGroup($c, $group, 'member')) {
                    $names[cardDisplayName($c)] = true;
                }
            }
            $amt = count($names) * intval($ab['amount'] ?? 1);
            if ($amt > 0) {
                $state = applyModifierEffect($state, $pid, [
                    'type' => 'live_score_bonus',
                    'amount' => $amt,
                    'source' => $name,
                    'target_instance_id' => $source['instance_id'] ?? '',
                ]);
            }
            break;
        }

        case 'reduce_hearts_per_activated_from_wait_by_subunit': {
            // Defer during Live Start so Love Marginal can see WAO-WAO Activates (#237).
            $entry = [
                'source_id' => (string)($source['instance_id'] ?? ''),
                'source_name' => $name,
                'subunit' => (string)($ab['subunit'] ?? 'Printemps'),
                'tiers' => $ab['tiers'] ?? [],
            ];
            $phase = (string)($state['phase'] ?? '');
            $defer = str_contains($phase, 'live_start')
                || !empty($GLOBALS['_lltcg_in_live_start_resolve']);
            if ($defer) {
                $list = $p['_pb2_defer_hearts_from_wait'] ?? [];
                if (!is_array($list)) {
                    $list = [];
                }
                $list[] = $entry;
                $p['_pb2_defer_hearts_from_wait'] = $list;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Live Start heart reduce (after Wait→Active counts this turn).");
                break;
            }
            $state = plMusePb2ApplyActivatedFromWaitHeartsEntry($state, $pid, $entry);
            break;
        }

        case 'score_if_success_subunit_min': {
            $n = plMusePb2CountSuccessSubunit($p, $ab['subunit'] ?? '', $source);
            if ($n >= intval($ab['min_count'] ?? 2)) {
                $state = applyModifierEffect($state, $pid, [
                    'type' => 'live_score_bonus',
                    'amount' => intval($ab['amount'] ?? 1),
                    'source' => $name,
                    'target_instance_id' => $source['instance_id'] ?? '',
                ]);
            }
            break;
        }

        case 'per_success_subunit_choose': {
            $n = plMusePb2CountSuccessSubunit($p, $ab['subunit'] ?? '', $source);
            if ($n <= 0) {
                break;
            }
            $defs = $ab['choices'] ?? [];
            $keys = [];
            $labels = [];
            foreach ($defs as $i => $def) {
                $keys[] = (string)$i;
                $labels[] = match ($def['type'] ?? '') {
                    'center_blade_bonus' => 'Center +' . intval($def['amount'] ?? 1) . ' Blade',
                    'activate_stage_member' => 'Activate 1 Stage Member',
                    'draw_and_discard' => 'Draw 1, discard 1',
                    default => $def['type'] ?? "choice $i",
                };
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'per_success_subunit_choose',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'remaining' => $n,
                'choices' => $keys,
                'choice_labels' => $labels,
                'choice_defs' => $defs,
                'prompt' => "Choose an effect ($n remaining).",
                'step' => 'choose',
                'live_start' => true,
            ]);
            break;
        }

        case 'auto_on_opp_wait_by_subunit_choose': {
            if (empty($ctx['opp_wait_by_subunit']) || ($ctx['subunit'] ?? '') !== ($ab['subunit'] ?? '')) {
                break;
            }
            $defs = $ab['choices'] ?? [];
            $keys = [];
            $labels = [];
            foreach ($defs as $i => $ch) {
                $keys[] = (string)$i;
                $labels[] = match ($ch['type'] ?? '') {
                    'activate_stage_subunit_member' => 'Activate 1 ' . ($ch['subunit'] ?? 'BiBi') . ' Member',
                    'activate_energy' => 'Activate ' . intval($ch['count'] ?? 2) . ' Energy',
                    default => $ch['type'] ?? "choice $i",
                };
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'auto_on_opp_wait_by_subunit_choose',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'choices' => $keys,
                'choice_labels' => $labels,
                'choice_defs' => $defs,
                'prompt' => 'Choose 1 effect.',
                'step' => 'choose',
            ]);
            break;
        }

        case 'auto_yell_extra_per_score_icon_group': {
            // Handled in resolveAutoYellAbilities (effects.php) — Umi PL!-pb2-004 (#219).
            break;
        }

        case 'auto_yell_wait_opp_if_named_members_and_center': {
            // Handled in resolveAutoYellAbilities → resolvePsychicFireAutoYellWait (#220).
            break;
        }

        case 'auto_stack_wr_subunit_under_on_opp_wait': {
            if (empty($ctx['opp_wait_by_effect'])) {
                break;
            }
            $under = count($source['stacked_members'] ?? []);
            if ($under > intval($ab['max_under'] ?? 2)) {
                break;
            }
            $subunit = $ab['subunit'] ?? 'BiBi';
            $cands = array_values(array_filter(
                $p['waiting_room'] ?? [],
                fn($c) => isMemberCard($c) && cardMatchesSubunit($c, $subunit)
            ));
            if (!$cands) {
                break;
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'stack_wr_under',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'subunit' => $subunit,
                'count' => 1,
                'candidates' => $cands,
                'min' => 1,
                'max' => 1,
                'prompt' => "Choose 1 $subunit Member from your Waiting Room to put under this Member.",
            ]);
            break;
        }

        case 'activated_wait_printemps_live_from_wr': {
            // Wait self immediately, then choose additional cost (Refs #232).
            $srcId = (string)($source['instance_id'] ?? '');
            plMusePb2WaitSelfByInstance($state, $pid, $srcId);
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'pb2_printemps_cost_mode',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $srcId,
                'source_id' => $srcId,
                'source_name' => $name,
                'subunit' => $ab['subunit'] ?? 'Printemps',
                'choices' => ['discard2', 'wait2'],
                'choice_labels' => ['Discard 2 from hand', 'Wait 2 Printemps Members'],
                'prompt' => 'Pay the additional cost.',
            ]);
            break;
        }

        case 'optional_wait_self_discard_look_reveal': {
            // Reuse the shared optional_wait_self_look_reveal prompt (UI + PromptResolver).
            $discardNeed = intval($ab['discard'] ?? 1);
            $look = intval($ab['look'] ?? 3);
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'optional_wait_self_look_reveal',
                'owner' => $pid,
                'player_id' => $pid,
                'source_id' => $source['instance_id'] ?? '',
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'prompt' => "Put this Member into Wait and discard $discardNeed: look at the top $look cards?",
                'choices' => ['yes', 'no'],
                'ability' => $ab,
                'discard_count' => $discardNeed,
                'optional' => empty($ab['once_per_turn']) ? true : false,
            ]);
            break;
        }

        case 'opp_enter_opposite_wait_max_printed_hearts':
        case 'play_cost_reduce_if_wait_distinct_subunit':
        case 'blade_per_success_score_icon_group':
        case 'blade_per_stacked_subunit_member':
        case 'blade_if_no_success_lives':
        case 'blade_per_success_subunit':
        case 'blade_per_success_score_chunk':
        case 'live_score_if_exactly_group_members_on_stage':
        case 'success_count_as_two_for_subunit_effects':
            // Continuous / reactive — applied via hooks
            break;

        case 'pb2_apply_center_group_blade': {
            $group = $ab['group'] ?? "μ's";
            $blade = intval($ab['blade'] ?? $ab['amount'] ?? 2);
            if (function_exists('applyCenterGroupBladeBonus')) {
                applyCenterGroupBladeBonus($state, $pid, $group, $blade);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Center $group Member gained +$blade Blade.");
            break;
        }

        case 'pb2_begin_wait_opp_printed_hearts': {
            unset($state['pending_prompt']);
            return beginWaitOpponentStagePick(
                $state,
                $pid,
                $name,
                [
                    'max_original_hearts' => intval($ab['max_printed_hearts'] ?? 1),
                    'pick_count' => intval($ab['pick_count'] ?? 1),
                ],
                (string)($source['instance_id'] ?? ''),
                ($state['phase'] ?? '') === 'live_start_effects'
            );
        }

        case 'pb2_add_subunit_live_from_wr': {
            $subunit = $ab['subunit'] ?? 'Printemps';
            $cands = array_values(array_filter(
                $p['waiting_room'] ?? [],
                fn($c) => isLiveTypeCard($c) && cardMatchesSubunit($c, $subunit)
            ));
            if (!$cands) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no $subunit Live in Waiting Room.");
                break;
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'add_from_wr',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'candidates' => $cands,
                'filter' => 'live',
                'subunit' => $subunit,
                'count' => 1,
                'min' => 1,
                'max' => 1,
            ]);
            break;
        }

        case 'pb2_resume_per_success_choose': {
            $remaining = intval($ab['remaining'] ?? 0);
            if ($remaining <= 0) {
                break;
            }
            $defs = $ab['choice_defs'] ?? $ab['choices'] ?? [];
            $keys = [];
            $labels = [];
            foreach ($defs as $i => $def) {
                $keys[] = (string)$i;
                $labels[] = match ($def['type'] ?? '') {
                    'center_blade_bonus' => 'Center +' . intval($def['amount'] ?? 1) . ' Blade',
                    'activate_stage_member' => 'Activate 1 Stage Member',
                    'draw_and_discard' => 'Draw 1, discard 1',
                    default => $def['type'] ?? "choice $i",
                };
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'per_success_subunit_choose',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'remaining' => $remaining,
                'choices' => $keys,
                'choice_labels' => $labels,
                'choice_defs' => $defs,
                'prompt' => "Choose an effect ($remaining remaining).",
                'step' => 'choose',
                'live_start' => true,
            ]);
            break;
        }

        default:
            $state = addLog($state, "Unhandled μ's DUO effect type: $type");
            break;
    }

    return $state;
}

/**
 * Prompt resolver for μ's DUO-specific prompts. Returns null if not handled.
 */
function plMusePb2ResolvePrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $type = $prompt['type'] ?? '';
    if (!in_array($type, [
        'negate_opp_member_live_success',
        'optional_wait_self_discard_wait_opp',
        'optional_reveal_hand_live_swap_success',
        'optional_activate_opp_wait_draw_each',
        'optional_discard_distinct_subunit_wait_opp',
        'optional_wait_self_discard_center_blade',
        'optional_unstack_toggle_subunit',
        'per_success_subunit_choose',
        'auto_on_opp_wait_by_subunit_choose',
        'activated_wait_printemps_live_from_wr',
        'stack_wr_under',
        'pb2_pick_opp_wait_activate',
        'pb2_pick_hand_success_swap',
        'pb2_pick_revealed_subunit_live',
        'pb2_pick_distinct_discard_wait_opp',
        'pb2_pick_unstack_toggle',
        'pb2_pick_toggle_printemps',
        'pb2_per_success_pick_member',
        'pb2_printemps_cost_mode',
        'pb2_printemps_wait_members',
    ], true)) {
        return null;
    }

    $pid = $owner;
    $p = &$state['players'][$pid];
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    $name = $prompt['source_name'] ?? 'Card';
    $srcId = (string)($prompt['source_instance_id'] ?? $prompt['source_id'] ?? '');
    $step = (string)($prompt['step'] ?? '');

    if (in_array($choice, ['skip', 'cancel', 'no'], true)
        && !in_array($type, [
            'per_success_subunit_choose',
            'pb2_per_success_pick_member',
            'pb2_pick_revealed_subunit_live',
            'pb2_printemps_cost_mode',
            'pb2_printemps_wait_members',
            'activated_wait_printemps_live_from_wr',
        ], true)
        && $step === '') {
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] skipped optional DUO effect.");
        return plMusePb2FinishPrompt($state, $prompt);
    }

    if ($type === 'negate_opp_member_live_success') {
        $slot = (string)($data['slot'] ?? $choice);
        $m = $state['players'][$opp]['stage'][$slot] ?? null;
        if (!$m) {
            throw new Exception('Choose an opponent Stage Member');
        }
        $state['players'][$opp]['stage'][$slot]['_negate_live_success_until_live_end'] = true;
        $heart = $prompt['then_heart'] ?? ['color' => 'yellow', 'count' => 1];
        $state = applyModifierEffect($state, $pid, [
            'type' => 'grant_bonus_hearts',
            'hearts' => [$heart],
            'source' => $name,
        ]);
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] negated [Live Success] on " . cardDisplayName($m) . '.');
        return plMusePb2FinishPrompt($state, $prompt);
    }

    if ($type === 'stack_wr_under') {
        $ids = $data['instance_ids'] ?? $data['ids'] ?? [];
        if (!$ids && !empty($data['instance_id'])) {
            $ids = [$data['instance_id']];
        }
        if (!$ids && $choice !== '' && $choice !== 'yes') {
            $ids = [$choice];
        }
        $slot = findMemberSlot($p, $srcId);
        if ($slot === '') {
            return plMusePb2FinishPrompt($state, $prompt);
        }
        $need = intval($prompt['count'] ?? 1);
        $moved = 0;
        foreach ($ids as $iid) {
            if ($moved >= $need) {
                break;
            }
            foreach ($p['waiting_room'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $iid) {
                    $card = $c;
                    array_splice($p['waiting_room'], $i, 1);
                    if (!isset($p['stage'][$slot]['stacked_members']) || !is_array($p['stage'][$slot]['stacked_members'])) {
                        $p['stage'][$slot]['stacked_members'] = [];
                    }
                    $p['stage'][$slot]['stacked_members'][] = $card;
                    $moved++;
                    break;
                }
            }
        }
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] stacked $moved card(s) underneath.");
        return plMusePb2FinishPrompt($state, $prompt);
    }

    // —— Wait self + discard → Wait opp (≤N printed hearts) ——
    if ($type === 'optional_wait_self_discard_wait_opp') {
        if ($choice !== 'yes' && $step === '') {
            return plMusePb2FinishPrompt($state, $prompt);
        }
        $need = intval($prompt['discard'] ?? 1);
        $ids = $data['discard_ids'] ?? [];
        if ($srcId !== '') {
            plMusePb2WaitSelfByInstance($state, $pid, $srcId);
        }
        if ($need > 0 && count($ids) !== $need) {
            unset($state['pending_prompt']);
            return startEffectDiscardHandPrompt(
                $state,
                $pid,
                $name,
                $need,
                "Discard $need card(s) from your hand.",
                [
                    'source_id' => $srcId,
                    'source_instance_id' => $srcId,
                    'then' => [
                        'type' => 'pb2_begin_wait_opp_printed_hearts',
                        'max_printed_hearts' => intval($prompt['max_printed_hearts'] ?? 1),
                        'pick_count' => 1,
                    ],
                ]
            );
        }
        if ($need > 0) {
            plMusePb2DiscardIds($state, $pid, $ids, $name);
        }
        unset($state['pending_prompt']);
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] Waited self; discarded $need; choose opponent Member to Wait.");
        return beginWaitOpponentStagePick(
            $state,
            $pid,
            $name,
            [
                'max_original_hearts' => intval($prompt['max_printed_hearts'] ?? 1),
                'pick_count' => 1,
            ],
            $srcId,
            ($state['phase'] ?? '') === 'live_start_effects'
        );
    }

    // —— Reveal top-all-subunit: pick 1 Live from the revealed pile ——
    if ($type === 'pb2_pick_revealed_subunit_live') {
        $pickId = (string)($data['instance_id'] ?? $data['card_id'] ?? $choice);
        $revealed = $prompt['revealed'] ?? [];
        $pick = null;
        foreach ($revealed as $c) {
            if (($c['instance_id'] ?? '') === $pickId && isLiveTypeCard($c)) {
                $pick = $c;
                break;
            }
        }
        if (!$pick) {
            throw new Exception('Choose 1 Live card from the revealed cards');
        }
        $p['hand'][] = $pick;
        foreach ($revealed as $c) {
            if (($c['instance_id'] ?? '') === ($pick['instance_id'] ?? '')) {
                continue;
            }
            $p['waiting_room'][] = $c;
        }
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] added " . cardDisplayName($pick) . " to hand.");
        return plMusePb2FinishPrompt($state, $prompt);
    }

    // —— Reveal hand Live ↔ Success Live swap ——
    if ($type === 'optional_reveal_hand_live_swap_success' || $type === 'pb2_pick_hand_success_swap') {
        if ($type === 'optional_reveal_hand_live_swap_success' && $choice === 'yes' && $step === '') {
            $handLives = $prompt['hand_candidates'] ?? [];
            if (!$handLives) {
                return plMusePb2FinishPrompt($state, $prompt);
            }
            $state['pending_prompt'] = [
                'type' => 'pb2_pick_hand_success_swap',
                'owner' => $pid,
                'responder' => $pid,
                'source_name' => $name,
                'source_instance_id' => $srcId,
                'step' => 'pick_hand',
                'subunit' => $prompt['subunit'] ?? 'lily white',
                'candidates' => array_map('cardPromptSummary', $handLives),
                'success_candidates' => array_map('cardPromptSummary', $prompt['success_candidates'] ?? $p['success_lives'] ?? []),
                'prompt' => 'Choose 1 Live from your hand to reveal.',
                'min' => 1,
                'max' => 1,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_hand') {
            $handId = (string)($data['instance_id'] ?? $data['card_id'] ?? $choice);
            $handCard = null;
            foreach ($p['hand'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $handId) {
                    $handCard = $c;
                    break;
                }
            }
            if (!$handCard) {
                throw new Exception('Choose a Live card from your hand');
            }
            $state['pending_prompt'] = [
                'type' => 'pb2_pick_hand_success_swap',
                'owner' => $pid,
                'responder' => $pid,
                'source_name' => $name,
                'source_instance_id' => $srcId,
                'step' => 'pick_success',
                'hand_instance_id' => $handId,
                'candidates' => array_map('cardPromptSummary', $p['success_lives'] ?? []),
                'prompt' => 'Choose 1 Success Live card to add to your hand.',
                'min' => 1,
                'max' => 1,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_success') {
            $handId = (string)($prompt['hand_instance_id'] ?? '');
            $succId = (string)($data['instance_id'] ?? $data['card_id'] ?? $choice);
            $handCard = null;
            $handIdx = -1;
            foreach ($p['hand'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $handId) {
                    $handCard = $c;
                    $handIdx = $i;
                    break;
                }
            }
            $succCard = null;
            $succIdx = -1;
            foreach ($p['success_lives'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $succId) {
                    $succCard = $c;
                    $succIdx = $i;
                    break;
                }
            }
            if (!$handCard || !$succCard) {
                throw new Exception('Invalid swap targets');
            }
            array_splice($p['hand'], $handIdx, 1);
            array_splice($p['success_lives'], $succIdx, 1);
            $p['hand'][] = $succCard;
            $p['success_lives'][] = $handCard;
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] swapped " . cardDisplayName($handCard) . ' with Success Live ' .
                cardDisplayName($succCard) . '.');
            return plMusePb2FinishPrompt($state, $prompt);
        }
        return plMusePb2FinishPrompt($state, $prompt);
    }

    // —— Activate up to N opp Wait Members; draw 1 each ——
    if ($type === 'optional_activate_opp_wait_draw_each' || $type === 'pb2_pick_opp_wait_activate') {
        if ($type === 'optional_activate_opp_wait_draw_each' && $choice === 'yes' && $step === '') {
            $cands = $prompt['candidates'] ?? [];
            if (!$cands) {
                return plMusePb2FinishPrompt($state, $prompt);
            }
            $state['pending_prompt'] = [
                'type' => 'pb2_pick_opp_wait_activate',
                'owner' => $pid,
                'responder' => $pid,
                'source_name' => $name,
                'source_instance_id' => $srcId,
                'candidates' => $cands,
                'max' => intval($prompt['max'] ?? 3),
                'min' => 1,
                'up_to' => true,
                'optional' => true,
                'prompt' => 'Choose up to ' . intval($prompt['max'] ?? 3) . ' opponent Wait Member(s) to Activate.',
                'step' => 'pick',
            ];
            $state['seq']++;
            return $state;
        }
        $slots = $data['slots'] ?? $data['slot_ids'] ?? [];
        if (!$slots && !empty($data['slot'])) {
            $slots = [$data['slot']];
        }
        if (!$slots && $choice !== '' && $choice !== 'yes') {
            $slots = [$choice];
        }
        $max = intval($prompt['max'] ?? 3);
        $slots = array_slice(array_values(array_unique(array_map('strval', $slots))), 0, $max);
        $activated = 0;
        foreach ($slots as $slot) {
            $m = &$state['players'][$opp]['stage'][$slot];
            if ($m && memberIsInWait($m)) {
                clearMemberWait($m);
                $activated++;
            }
            unset($m);
        }
        if ($activated > 0) {
            $drawn = drawCardInstances($p, $activated);
            foreach ($drawn as $c) {
                $state = logEffectDraw($state, $pid, $name, $c,
                    [animSpec($c['instance_id'], 'main_deck', 'hand', $pid)]);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Activated $activated opponent Wait Member(s); drew $activated.");
        }
        return plMusePb2FinishPrompt($state, $prompt);
    }

    // —— Discard 3 differently named subunit Members → Wait opp ——
    if ($type === 'optional_discard_distinct_subunit_wait_opp' || $type === 'pb2_pick_distinct_discard_wait_opp') {
        if ($type === 'optional_discard_distinct_subunit_wait_opp' && $choice === 'yes' && $step === '') {
            $subunit = $prompt['subunit'] ?? 'BiBi';
            $need = intval($prompt['discard'] ?? 3);
            $hand = array_values(array_filter(
                $p['hand'] ?? [],
                fn($c) => isMemberCard($c) && cardMatchesSubunit($c, $subunit)
            ));
            $state['pending_prompt'] = [
                'type' => 'pb2_pick_distinct_discard_wait_opp',
                'owner' => $pid,
                'responder' => $pid,
                'source_name' => $name,
                'source_instance_id' => $srcId,
                'subunit' => $subunit,
                'discard' => $need,
                'candidates' => array_map('cardPromptSummary', $hand),
                'prompt' => "Choose $need differently named $subunit Member cards from your hand to discard.",
                'min' => $need,
                'max' => $need,
                'step' => 'discard',
                'pick_mode' => 'hand_discard',
                'count' => $need,
            ];
            $state['seq']++;
            return $state;
        }
        $ids = $data['discard_ids'] ?? $data['instance_ids'] ?? [];
        if (!$ids && !empty($data['instance_id'])) {
            $ids = [$data['instance_id']];
        }
        $need = intval($prompt['discard'] ?? 3);
        if (count($ids) !== $need) {
            throw new Exception("Must discard exactly $need differently named Members");
        }
        $names = [];
        foreach ($ids as $iid) {
            foreach ($p['hand'] as $c) {
                if (($c['instance_id'] ?? '') === $iid) {
                    $nm = cardDisplayName($c);
                    if (isset($names[$nm])) {
                        throw new Exception('Chosen Members must have different names');
                    }
                    $names[$nm] = true;
                    break;
                }
            }
        }
        if (count($names) !== $need) {
            throw new Exception('Chosen Members must have different names');
        }
        plMusePb2DiscardIds($state, $pid, $ids, $name);
        unset($state['pending_prompt']);
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] discarded $need distinct Members; choose opponent Member to Wait.");
        return beginWaitOpponentStagePick(
            $state,
            $pid,
            $name,
            ['pick_count' => 1, 'max_cost' => 99],
            $srcId,
            ($state['phase'] ?? '') === 'live_start_effects'
        );
    }

    // —— Wait self + discard → Center group Blade ——
    if ($type === 'optional_wait_self_discard_center_blade') {
        if ($choice !== 'yes') {
            return plMusePb2FinishPrompt($state, $prompt);
        }
        $need = intval($prompt['discard'] ?? 1);
        $ids = $data['discard_ids'] ?? [];
        $group = $prompt['group'] ?? "μ's";
        $blade = intval($prompt['blade'] ?? 2);
        plMusePb2WaitSelfByInstance($state, $pid, $srcId);
        if ($need > 0 && count($ids) !== $need) {
            unset($state['pending_prompt']);
            return startEffectDiscardHandPrompt(
                $state,
                $pid,
                $name,
                $need,
                "Discard $need card(s) from your hand.",
                [
                    'source_id' => $srcId,
                    'then' => [
                        'type' => 'pb2_apply_center_group_blade',
                        'group' => $group,
                        'blade' => $blade,
                    ],
                ]
            );
        }
        if ($need > 0) {
            plMusePb2DiscardIds($state, $pid, $ids, $name);
        }
        if (function_exists('applyCenterGroupBladeBonus')) {
            applyCenterGroupBladeBonus($state, $pid, $group, $blade);
        }
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] Waited self; Center $group Member gained +$blade Blade.");
        return plMusePb2FinishPrompt($state, $prompt);
    }

    // —— Unstack under → toggle Printemps Wait/Active ——
    if ($type === 'optional_unstack_toggle_subunit' || $type === 'pb2_pick_unstack_toggle'
        || $type === 'pb2_pick_toggle_printemps') {
        if ($type === 'optional_unstack_toggle_subunit' && $choice === 'yes' && $step === '') {
            $stacked = $prompt['stacked'] ?? [];
            $max = intval($prompt['max'] ?? 3);
            $state['pending_prompt'] = [
                'type' => 'pb2_pick_unstack_toggle',
                'owner' => $pid,
                'responder' => $pid,
                'source_name' => $name,
                'source_instance_id' => $srcId,
                'subunit' => $prompt['subunit'] ?? 'Printemps',
                // stacked is already cardPromptSummary()'d when the optional was created.
                'candidates' => array_values(array_filter($stacked, static fn($c) => is_array($c) && ($c['instance_id'] ?? '') !== '')),
                'max' => $max,
                'min' => 1,
                'up_to' => true,
                'prompt' => "Choose up to $max card(s) under this Member to put into the Waiting Room.",
                'step' => 'unstack',
                'live_start' => true,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'unstack' || $type === 'pb2_pick_unstack_toggle') {
            $ids = $data['instance_ids'] ?? $data['ids'] ?? [];
            if (!$ids && !empty($data['instance_id'])) {
                $ids = [$data['instance_id']];
            }
            $max = intval($prompt['max'] ?? 3);
            $ids = array_slice(array_values($ids), 0, $max);
            $slot = findMemberSlot($p, $srcId);
            $moved = [];
            if ($slot !== '' && !empty($p['stage'][$slot]['stacked_members'])) {
                $keep = [];
                foreach ($p['stage'][$slot]['stacked_members'] as $c) {
                    if (in_array($c['instance_id'] ?? '', $ids, true)) {
                        $moved[] = $c;
                        $p['waiting_room'][] = $c;
                    } else {
                        $keep[] = $c;
                    }
                }
                $p['stage'][$slot]['stacked_members'] = $keep;
            }
            $n = count($moved);
            if ($n < 1) {
                return plMusePb2FinishPrompt($state, $prompt);
            }
            $subunit = $prompt['subunit'] ?? 'Printemps';
            $stageCands = [];
            foreach ($p['stage'] as $s => $m) {
                if ($m && cardMatchesSubunit($m, $subunit)) {
                    $stageCands[] = array_merge(cardPromptSummary($m), ['slot' => $s]);
                }
            }
            if ($stageCands === []) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] unstacked cards; no $subunit Members on Stage to toggle.");
                return plMusePb2FinishPrompt($state, $prompt);
            }
            $state['pending_prompt'] = [
                'type' => 'pb2_pick_toggle_printemps',
                'owner' => $pid,
                'responder' => $pid,
                'source_name' => $name,
                'source_instance_id' => $srcId,
                'subunit' => $subunit,
                'remaining' => $n,
                'candidates' => $stageCands,
                'prompt' => "Choose a $subunit Member to toggle Active/Wait ($n remaining).",
                'step' => 'toggle',
                'min' => 1,
                'max' => 1,
                'live_start' => true,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'toggle' || $type === 'pb2_pick_toggle_printemps') {
            if (in_array($choice, ['skip', 'cancel', 'no'], true) && ($data['slot'] ?? '') === '') {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] unstacked and toggled Printemps Members.");
                return plMusePb2FinishPrompt($state, $prompt);
            }
            $slot = (string)($data['slot'] ?? $choice);
            $m = &$p['stage'][$slot];
            if (!$m) {
                throw new Exception('Choose a Stage Member to toggle');
            }
            if (memberIsInWait($m)) {
                // Hanayo unstack toggle — Printemps card effect Wait→Active (#233).
                plMusePb2ActivateFromWait($state, $pid, $m, [
                    'subunit' => $prompt['subunit'] ?? 'Printemps',
                    'name_en' => $name,
                ]);
            } else {
                waitMember($m, $state);
            }
            unset($m);
            $remaining = intval($prompt['remaining'] ?? 1) - 1;
            if ($remaining > 0) {
                $subunit = $prompt['subunit'] ?? 'Printemps';
                $stageCands = [];
                foreach ($p['stage'] as $s => $m) {
                    if ($m && cardMatchesSubunit($m, $subunit)) {
                        $stageCands[] = array_merge(cardPromptSummary($m), ['slot' => $s]);
                    }
                }
                if ($stageCands === []) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] unstacked and toggled Printemps Members.");
                    return plMusePb2FinishPrompt($state, $prompt);
                }
                $state['pending_prompt'] = array_merge($prompt, [
                    'remaining' => $remaining,
                    'candidates' => $stageCands,
                    'prompt' => "Choose a $subunit Member to toggle Active/Wait ($remaining remaining).",
                    'live_start' => true,
                ]);
                $state['seq']++;
                return $state;
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] unstacked and toggled Printemps Members.");
            return plMusePb2FinishPrompt($state, $prompt);
        }
        return plMusePb2FinishPrompt($state, $prompt);
    }

    // —— Per Success subunit: choose effect N times ——
    if ($type === 'per_success_subunit_choose' || $type === 'pb2_per_success_pick_member') {
        $remaining = intval($prompt['remaining'] ?? 0);
        $choices = $prompt['choices'] ?? [];
        if ($type === 'pb2_per_success_pick_member') {
            $slot = (string)($data['slot'] ?? $choice);
            if ($slot === '' || empty($p['stage'][$slot])) {
                throw new Exception('Choose a Stage Member to Activate');
            }
            // Source ability is on a lily white / subunit Member — track Wait→Active (#233).
            $srcCard = ['name_en' => $name, 'subunit' => ''];
            // Prefer catalog subunit from the effect source instance if still on Stage.
            foreach ($p['stage'] as $sm) {
                if ($sm && ($sm['instance_id'] ?? '') === $srcId) {
                    $srcCard = $sm;
                    break;
                }
            }
            plMusePb2ActivateFromWait($state, $pid, $p['stage'][$slot], $srcCard);
            $remaining = intval($prompt['remaining'] ?? 1) - 1;
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Activated " . cardDisplayName($p['stage'][$slot]) . '.');
            if ($remaining <= 0) {
                return plMusePb2FinishPrompt($state, $prompt);
            }
            $labels = [];
            $keys = [];
            foreach ($choices as $i => $ch) {
                $keys[] = (string)$i;
                $labels[] = match ($ch['type'] ?? '') {
                    'center_blade_bonus' => 'Center +' . intval($ch['amount'] ?? 1) . ' Blade',
                    'activate_stage_member' => 'Activate 1 Stage Member',
                    'draw_and_discard' => 'Draw 1, discard 1',
                    default => $ch['type'] ?? "choice $i",
                };
            }
            $state['pending_prompt'] = [
                'type' => 'per_success_subunit_choose',
                'owner' => $pid,
                'responder' => $pid,
                'source_name' => $name,
                'source_instance_id' => $srcId,
                'remaining' => $remaining,
                'choices' => $choices,
                'choice_keys' => $keys,
                'choice_labels' => $labels,
                'prompt' => "Choose an effect ($remaining remaining).",
            ];
            $state['pending_prompt']['choices'] = $keys;
            $state['seq']++;
            return $state;
        }
        if ($remaining <= 0) {
            return plMusePb2FinishPrompt($state, $prompt);
        }
        // First entry: ensure choice UI keys
        if ($step === '' && ($choice === '' || $choice === 'yes') && !isset($data['choice_index'])
            && !ctype_digit((string)$choice)) {
            $keys = [];
            $labels = [];
            foreach ($choices as $i => $ch) {
                $keys[] = (string)$i;
                $labels[] = match ($ch['type'] ?? '') {
                    'center_blade_bonus' => 'Center +' . intval($ch['amount'] ?? 1) . ' Blade',
                    'activate_stage_member' => 'Activate 1 Stage Member',
                    'draw_and_discard' => 'Draw 1, discard 1',
                    default => $ch['type'] ?? "choice $i",
                };
            }
            $state['pending_prompt'] = array_merge($prompt, [
                'choices' => $keys,
                'choice_labels' => $labels,
                'choice_defs' => $choices,
                'prompt' => $prompt['prompt'] ?? "Choose an effect ($remaining remaining).",
                'step' => 'choose',
            ]);
            $state['seq']++;
            return $state;
        }
        $idx = intval($data['choice_index'] ?? (ctype_digit((string)$choice) ? $choice : -1));
        $defs = $prompt['choice_defs'] ?? $choices;
        $ch = $defs[$idx] ?? null;
        if (!$ch) {
            throw new Exception('Invalid effect choice');
        }
        $ct = $ch['type'] ?? '';
        if ($ct === 'center_blade_bonus') {
            $amt = intval($ch['amount'] ?? 1);
            if (function_exists('applyCenterGroupBladeBonus')) {
                applyCenterGroupBladeBonus($state, $pid, "μ's", $amt);
            } else {
                $center = $p['stage']['center'] ?? null;
                if ($center) {
                    $p['stage']['center']['live_blade_bonus'] =
                        intval($p['stage']['center']['live_blade_bonus'] ?? 0) + $amt;
                }
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Center gained +$amt Blade.");
            $remaining--;
        } elseif ($ct === 'activate_stage_member') {
            $cands = [];
            foreach ($p['stage'] as $s => $m) {
                if ($m && memberIsInWait($m)) {
                    $cands[] = ['slot' => $s, 'card' => $m];
                }
            }
            if (!$cands) {
                $remaining--;
            } else {
                $state['pending_prompt'] = [
                    'type' => 'pb2_per_success_pick_member',
                    'owner' => $pid,
                    'responder' => $pid,
                    'source_name' => $name,
                    'source_instance_id' => $srcId,
                    'remaining' => $remaining,
                    'choices' => $defs,
                    'candidates' => $cands,
                    'prompt' => 'Choose 1 Stage Member to Activate.',
                    'min' => 1,
                    'max' => 1,
                ];
                $state['seq']++;
                return $state;
            }
        } elseif ($ct === 'draw_and_discard') {
            $drawn = drawCardInstances($p, intval($ch['draw'] ?? 1));
            foreach ($drawn as $c) {
                $state = logEffectDraw($state, $pid, $name, $c,
                    [animSpec($c['instance_id'], 'main_deck', 'hand', $pid)]);
            }
            $remaining--;
            unset($state['pending_prompt']);
            if (!empty($p['hand'])) {
                return startEffectDiscardHandPrompt(
                    $state,
                    $pid,
                    $name,
                    intval($ch['discard'] ?? 1),
                    'Choose a card to discard.',
                    [
                        'source_id' => $srcId,
                        'then' => [
                            'type' => 'pb2_resume_per_success_choose',
                            'remaining' => $remaining,
                            'choice_defs' => $defs,
                            'choices' => $defs,
                        ],
                    ]
                );
            }
        } else {
            $remaining--;
        }
        if ($remaining <= 0) {
            return plMusePb2FinishPrompt($state, $prompt);
        }
        $keys = [];
        $labels = [];
        foreach ($defs as $i => $def) {
            $keys[] = (string)$i;
            $labels[] = match ($def['type'] ?? '') {
                'center_blade_bonus' => 'Center +' . intval($def['amount'] ?? 1) . ' Blade',
                'activate_stage_member' => 'Activate 1 Stage Member',
                'draw_and_discard' => 'Draw 1, discard 1',
                default => $def['type'] ?? "choice $i",
            };
        }
        $state['pending_prompt'] = [
            'type' => 'per_success_subunit_choose',
            'owner' => $pid,
            'responder' => $pid,
            'source_name' => $name,
            'source_instance_id' => $srcId,
            'remaining' => $remaining,
            'choices' => $keys,
            'choice_labels' => $labels,
            'choice_defs' => $defs,
            'prompt' => "Choose an effect ($remaining remaining).",
            'step' => 'choose',
            'live_start' => true,
        ];
        $state['seq']++;
        return $state;
    }

    // —— Auto: on opp Wait by BiBi → choose Activate BiBi or Energy ——
    if ($type === 'auto_on_opp_wait_by_subunit_choose') {
        $defs = $prompt['choices'] ?? [];
        if ($step === '' && !ctype_digit((string)$choice) && !isset($data['choice_index'])) {
            $keys = [];
            $labels = [];
            foreach ($defs as $i => $ch) {
                $keys[] = (string)$i;
                $labels[] = match ($ch['type'] ?? '') {
                    'activate_stage_subunit_member' => 'Activate 1 ' . ($ch['subunit'] ?? 'BiBi') . ' Member',
                    'activate_energy' => 'Activate ' . intval($ch['count'] ?? 2) . ' Energy',
                    default => $ch['type'] ?? "choice $i",
                };
            }
            $state['pending_prompt'] = array_merge($prompt, [
                'choices' => $keys,
                'choice_labels' => $labels,
                'choice_defs' => $defs,
                'step' => 'choose',
                'prompt' => 'Choose 1 effect.',
            ]);
            $state['seq']++;
            return $state;
        }
        $idx = intval($data['choice_index'] ?? (ctype_digit((string)$choice) ? $choice : -1));
        $ch = ($prompt['choice_defs'] ?? $defs)[$idx] ?? null;
        if (!$ch) {
            throw new Exception('Invalid choice');
        }
        if (($ch['type'] ?? '') === 'activate_energy') {
            $state = plMusePb2ActivateEnergy($state, $pid, intval($ch['count'] ?? 2));
            return plMusePb2FinishPrompt($state, $prompt);
        }
        if (($ch['type'] ?? '') === 'activate_stage_subunit_member') {
            $subunit = $ch['subunit'] ?? 'BiBi';
            $cands = [];
            foreach ($p['stage'] as $s => $m) {
                if ($m && memberIsInWait($m) && cardMatchesSubunit($m, $subunit)) {
                    $cands[] = ['slot' => $s, 'card' => $m];
                }
            }
            if (count($cands) === 1) {
                clearMemberWait($p['stage'][$cands[0]['slot']]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Activated " . cardDisplayName($p['stage'][$cands[0]['slot']]) . '.');
                return plMusePb2FinishPrompt($state, $prompt);
            }
            if (!$cands) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no Wait $subunit Members to Activate.");
                return plMusePb2FinishPrompt($state, $prompt);
            }
            $state['pending_prompt'] = [
                'type' => 'pb2_per_success_pick_member',
                'owner' => $pid,
                'responder' => $pid,
                'source_name' => $name,
                'source_instance_id' => $srcId,
                'remaining' => 1,
                'choices' => [],
                'candidates' => $cands,
                'prompt' => "Choose 1 $subunit Member to Activate.",
                'min' => 1,
                'max' => 1,
            ];
            $state['seq']++;
            return $state;
        }
        return plMusePb2FinishPrompt($state, $prompt);
    }

    // —— Kotori: Wait self + (discard 2 OR Wait 2 Printemps) → add Printemps Live from WR ——
    if ($type === 'activated_wait_printemps_live_from_wr' || $type === 'pb2_printemps_cost_mode'
        || $type === 'pb2_printemps_wait_members') {
        if ($type === 'activated_wait_printemps_live_from_wr') {
            plMusePb2WaitSelfByInstance($state, $pid, $srcId);
            $state['pending_prompt'] = [
                'type' => 'pb2_printemps_cost_mode',
                'owner' => $pid,
                'responder' => $pid,
                'source_name' => $name,
                'source_instance_id' => $srcId,
                'subunit' => $prompt['subunit'] ?? 'Printemps',
                'choices' => ['discard2', 'wait2'],
                'choice_labels' => ['Discard 2 from hand', 'Wait 2 Printemps Members'],
                'prompt' => 'Pay the additional cost.',
            ];
            $state['seq']++;
            return $state;
        }
        if ($type === 'pb2_printemps_cost_mode') {
            if ($choice === 'discard2') {
                $ids = $data['discard_ids'] ?? [];
                if (count($ids) !== 2) {
                    unset($state['pending_prompt']);
                    return startEffectDiscardHandPrompt(
                        $state,
                        $pid,
                        $name,
                        2,
                        'Discard 2 cards from your hand.',
                        [
                            'source_id' => $srcId,
                            'then' => [
                                'type' => 'pb2_add_subunit_live_from_wr',
                                'subunit' => $prompt['subunit'] ?? 'Printemps',
                            ],
                        ]
                    );
                }
                plMusePb2DiscardIds($state, $pid, $ids, $name);
            } elseif ($choice === 'wait2') {
                $cands = [];
                foreach ($p['stage'] as $s => $m) {
                    if ($m && ($m['instance_id'] ?? '') !== $srcId
                        && cardMatchesSubunit($m, (string)($prompt['subunit'] ?? 'Printemps'))
                        && !memberIsInWait($m)) {
                        $cands[] = array_merge(cardPromptSummary($m), ['slot' => $s]);
                    }
                }
                if (count($cands) < 2) {
                    throw new Exception('Need 2 Active Printemps Members to Wait');
                }
                $state['pending_prompt'] = [
                    'type' => 'pb2_printemps_wait_members',
                    'owner' => $pid,
                    'responder' => $pid,
                    'source_name' => $name,
                    'source_instance_id' => $srcId,
                    'subunit' => $prompt['subunit'] ?? 'Printemps',
                    'candidates' => $cands,
                    'min' => 2,
                    'max' => 2,
                    'pick_count' => 2,
                    'up_to' => false,
                    'prompt' => 'Choose 2 Printemps Members to put into Wait.',
                ];
                $state['seq']++;
                return $state;
            } else {
                throw new Exception('Choose an additional cost');
            }
            // After discard2 with ids present → add from WR
            $subunit = $prompt['subunit'] ?? 'Printemps';
            $cands = array_values(array_filter(
                $p['waiting_room'] ?? [],
                fn($c) => isLiveTypeCard($c) && cardMatchesSubunit($c, $subunit)
            ));
            unset($state['pending_prompt']);
            if (!$cands) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no $subunit Live in Waiting Room.");
                return plMusePb2FinishPrompt($state, $prompt);
            }
            $state['pending_prompt'] = [
                'type' => 'add_from_wr',
                'owner' => $pid,
                'responder' => $pid,
                'source_name' => $name,
                'source_instance_id' => $srcId,
                'candidates' => $cands,
                'filter' => 'live',
                'subunit' => $subunit,
                'count' => 1,
                'min' => 1,
                'max' => 1,
            ];
            $state['seq']++;
            return $state;
        }
        if ($type === 'pb2_printemps_wait_members') {
            $slots = $data['slots'] ?? [];
            if (!$slots && !empty($data['slot'])) {
                $slots = [$data['slot']];
            }
            if (count($slots) !== 2) {
                throw new Exception('Choose exactly 2 Printemps Members');
            }
            foreach ($slots as $slot) {
                if (!empty($p['stage'][$slot])) {
                    waitMember($p['stage'][$slot], $state);
                }
            }
            $subunit = $prompt['subunit'] ?? 'Printemps';
            $cands = array_values(array_filter(
                $p['waiting_room'] ?? [],
                fn($c) => isLiveTypeCard($c) && cardMatchesSubunit($c, $subunit)
            ));
            unset($state['pending_prompt']);
            if (!$cands) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no $subunit Live in Waiting Room.");
                return plMusePb2FinishPrompt($state, $prompt);
            }
            $state['pending_prompt'] = [
                'type' => 'add_from_wr',
                'owner' => $pid,
                'responder' => $pid,
                'source_name' => $name,
                'source_instance_id' => $srcId,
                'candidates' => $cands,
                'filter' => 'live',
                'subunit' => $subunit,
                'count' => 1,
                'min' => 1,
                'max' => 1,
            ];
            $state['seq']++;
            return $state;
        }
    }

    return null;
}
