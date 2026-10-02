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
    ];
}

function plMusePb2IsEffectType(string $type): bool {
    return in_array($type, plMusePb2EffectTypes(), true);
}

function plMusePb2SetPendingPrompt(array $state, array $prompt): array {
    $state['pending_prompt'] = $prompt;
    return $state;
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

function plMusePb2CountSuccessSubunit(array $p, string $subunit): int {
    $n = 0;
    foreach ($p['success_lives'] ?? [] as $c) {
        if (!$c) {
            continue;
        }
        $su = (string)($c['subunit'] ?? '');
        if ($su !== '' && strcasecmp($su, $subunit) === 0) {
            $n++;
        } elseif (cardMatchesGroup($c, $subunit, '')) {
            // some cards use group for subunit names incorrectly — also check name tags
            $n++;
        }
    }
    // Prefer subunit field only
    $n = 0;
    foreach ($p['success_lives'] ?? [] as $c) {
        if ($c && strcasecmp((string)($c['subunit'] ?? ''), $subunit) === 0) {
            $n++;
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
        if (strcasecmp((string)($m['subunit'] ?? ''), $subunit) !== 0) {
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
                $n++;
            }
        }
        return $blade + $n * intval($ab['amount'] ?? 1);
    }
    if ($type === 'blade_per_stacked_subunit_member') {
        $subunit = $ab['subunit'] ?? '';
        $n = 0;
        foreach ($member['stacked_members'] ?? [] as $s) {
            if ($s && strcasecmp((string)($s['subunit'] ?? ''), $subunit) === 0
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
        return $blade + plMusePb2CountSuccessSubunit($p, $ab['subunit'] ?? '')
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
                $state = applyModifierEffect($state, $pid, [
                    'type' => 'grant_named_members_blade',
                    'group' => $group,
                    'blade' => intval($ab['blade'] ?? 1),
                    'all_group_stage' => true,
                    'source' => $name,
                ]);
                // Fallback: stage-wide blade aura
                $state['_live_modifiers'][$pid]['stage_group_blade'][] = [
                    'group' => $group,
                    'blade' => intval($ab['blade'] ?? 1),
                    'source' => $name,
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
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'discard' => intval($ab['discard'] ?? 1),
                'max_printed_hearts' => $maxH,
                'candidates' => $oppCands,
                'optional' => (($ab['trigger'] ?? '') === 'live_start'),
                'prompt' => 'Put this Member into Wait and discard 1: Wait an opponent Member with ≤' . $maxH . ' printed hearts?',
            ]);
            break;
        }

        case 'leave_stage_add_live_activate_per_success_group': {
            // Leave stage → add live from WR, then activate energy per success group card
            $group = $ab['group'] ?? "μ's";
            $slot = $ctx['slot'] ?? findMemberSlot($p, (string)($source['instance_id'] ?? ''));
            if ($slot === '') {
                break;
            }
            $leaving = $p['stage'][$slot];
            $p['stage'][$slot] = null;
            $p['waiting_room'][] = $leaving;
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] left Stage to Waiting Room.");
            $cands = array_values(array_filter(
                $p['waiting_room'] ?? [],
                fn($c) => cardMatchesWrPick($c, ['group' => $group, 'filter' => 'live'])
                    && ($c['instance_id'] ?? '') !== ($leaving['instance_id'] ?? '')
            ));
            $energyCount = count(plMusePb2SuccessGroupCards($p, $group));
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'add_from_wr',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'group' => $group,
                'filter' => 'live',
                'count' => 1,
                'candidates' => $cands,
                'min' => $cands ? 1 : 0,
                'max' => 1,
                'then_activate_energy' => $energyCount,
            ]);
            break;
        }

        case 'auto_on_leave_stage_if_baton_min_cost_energy': {
            $minCost = intval($ab['min_baton_cost'] ?? 15);
            $batonCosts = $source['baton_member_costs'] ?? [];
            $ok = false;
            foreach ($batonCosts as $c) {
                if (intval($c) >= $minCost) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                foreach ($source['baton_sources'] ?? [] as $bs) {
                    if (intval($bs['cost'] ?? 0) >= $minCost
                        && cardMatchesGroup($bs, $ab['group'] ?? "μ's", 'member')) {
                        $ok = true;
                        break;
                    }
                }
            }
            if ($ok) {
                $state = plMusePb2ActivateEnergy($state, $pid, intval($ab['energy'] ?? 2));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated Energy (Baton with high-cost μ's).");
            }
            break;
        }

        case 'blade_per_activated_from_wait_by_subunit_effect': {
            $subunit = $ab['subunit'] ?? 'Printemps';
            $key = '_pb2_activated_from_wait_' . $subunit;
            $n = intval($state['players'][$pid][$key] ?? 0);
            if ($n > 0) {
                $state = applyModifierEffect($state, $pid, [
                    'type' => 'blade_bonus',
                    'amount' => $n * intval($ab['amount'] ?? 1),
                    'source' => $name,
                ]);
            }
            break;
        }

        case 'reveal_top_all_subunit_add_live': {
            $look = intval($ab['look'] ?? 4);
            $subunit = $ab['subunit'] ?? 'lily white';
            $drawn = [];
            for ($i = 0; $i < $look; $i++) {
                if (empty($p['deck'])) {
                    refreshEmptyMainDecks($state, $pid);
                }
                if (empty($p['deck'])) {
                    break;
                }
                $drawn[] = array_shift($p['deck']);
            }
            $all = $drawn && array_reduce($drawn, function ($ok, $c) use ($subunit) {
                return $ok && strcasecmp((string)($c['subunit'] ?? ''), $subunit) === 0;
            }, true);
            if ($all) {
                $lives = array_values(array_filter($drawn, fn($c) => isLiveTypeCard($c)));
                if ($lives) {
                    $pick = $lives[0];
                    $p['hand'][] = $pick;
                    $drawn = array_values(array_filter(
                        $drawn,
                        fn($c) => ($c['instance_id'] ?? '') !== ($pick['instance_id'] ?? '')
                    ));
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added " . cardDisplayName($pick) . " to hand.");
                }
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
                fn($c) => isLiveTypeCard($c) && strcasecmp((string)($c['subunit'] ?? ''), $subunit) === 0
            ));
            if (!$handLives || empty($p['success_lives'])) {
                break;
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'optional_reveal_hand_live_swap_success',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'subunit' => $subunit,
                'hand_candidates' => $handLives,
                'success_candidates' => $p['success_lives'],
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
                fn($c) => isMemberCard($c) && strcasecmp((string)($c['subunit'] ?? ''), $subunit) === 0
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
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'subunit' => $ab['subunit'] ?? 'Printemps',
                'max' => intval($ab['max'] ?? 3),
                'stacked' => $under,
                'optional' => true,
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
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'candidates' => $cands,
                'max' => $max,
                'optional' => true,
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
                fn($c) => isMemberCard($c) && strcasecmp((string)($c['subunit'] ?? ''), $subunit) === 0
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
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'subunit' => $subunit,
                'discard' => $need,
                'optional' => true,
            ]);
            break;
        }

        case 'optional_wait_self_discard_center_group_blade': {
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'optional_wait_self_discard_center_blade',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'discard' => intval($ab['discard'] ?? 1),
                'group' => $ab['group'] ?? "μ's",
                'blade' => intval($ab['blade'] ?? 2),
                'optional' => true,
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
                if (strcasecmp((string)($m['subunit'] ?? ''), $su) !== 0) {
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
            if (count(plMusePb2SuccessGroupCards($p, $group)) >= $min) {
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
            $subunit = $ab['subunit'] ?? 'Printemps';
            $key = '_pb2_activated_from_wait_' . $subunit;
            $n = intval($state['players'][$pid][$key] ?? 0);
            $reduce = 0;
            foreach ($ab['tiers'] ?? [] as $tier) {
                if ($n >= intval($tier['min'] ?? 0)) {
                    $reduce += intval($tier['reduce'] ?? 0);
                }
            }
            if ($reduce > 0) {
                $state = applyModifierEffect($state, $pid, [
                    'type' => 'reduce_hearts_by_color',
                    'color' => 'any',
                    'amount' => $reduce,
                    'source' => $name,
                    'target_instance_id' => $source['instance_id'] ?? '',
                ]);
            }
            break;
        }

        case 'score_if_success_subunit_min': {
            $n = plMusePb2CountSuccessSubunit($p, $ab['subunit'] ?? '');
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
            $n = plMusePb2CountSuccessSubunit($p, $ab['subunit'] ?? '');
            if ($n <= 0) {
                break;
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'per_success_subunit_choose',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'remaining' => $n,
                'choices' => $ab['choices'] ?? [],
            ]);
            break;
        }

        case 'auto_on_opp_wait_by_subunit_choose': {
            if (empty($ctx['opp_wait_by_subunit']) || ($ctx['subunit'] ?? '') !== ($ab['subunit'] ?? '')) {
                break;
            }
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'auto_on_opp_wait_by_subunit_choose',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'choices' => $ab['choices'] ?? [],
            ]);
            break;
        }

        case 'auto_yell_extra_per_score_icon_group': {
            // Handled in yell pipeline (effects.php auto yell branch) — no-op here
            break;
        }

        case 'auto_yell_wait_opp_if_named_members_and_center': {
            // Handled in yell pipeline
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
                fn($c) => isMemberCard($c) && strcasecmp((string)($c['subunit'] ?? ''), $subunit) === 0
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
                'count' => 1,
                'candidates' => $cands,
                'min' => 1,
                'max' => 1,
            ]);
            break;
        }

        case 'activated_wait_printemps_live_from_wr': {
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'activated_wait_printemps_live_from_wr',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'subunit' => $ab['subunit'] ?? 'Printemps',
            ]);
            break;
        }

        case 'optional_wait_self_discard_look_reveal': {
            $state = plMusePb2SetPendingPrompt($state, [
                'type' => 'optional_wait_self_discard_look_reveal',
                'owner' => $pid,
                'player_id' => $pid,
                'source_instance_id' => $source['instance_id'] ?? '',
                'source_name' => $name,
                'discard' => intval($ab['discard'] ?? 1),
                'look' => intval($ab['look'] ?? 3),
                'subunit' => $ab['subunit'] ?? '',
                'filter' => $ab['filter'] ?? 'member',
                'optional' => false,
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
        'optional_wait_self_discard_look_reveal',
        'stack_wr_under',
    ], true)) {
        return null;
    }

    $pid = $owner;
    $p = &$state['players'][$pid];
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    $name = $prompt['source_name'] ?? 'Card';

    if ($choice === 'skip' || $choice === 'cancel') {
        unset($state['pending_prompt']);
        return $state;
    }

    if ($type === 'negate_opp_member_live_success') {
        $slot = $data['slot'] ?? $choice;
        $m = $state['players'][$opp]['stage'][$slot] ?? null;
        if (!$m) {
            return $state;
        }
        $state['players'][$opp]['stage'][$slot]['_negate_live_success_until_live_end'] = true;
        $heart = $prompt['then_heart'] ?? ['color' => 'yellow', 'count' => 1];
        $state = applyModifierEffect($state, $pid, [
            'type' => 'grant_bonus_hearts',
            'hearts' => [$heart],
            'source' => $name,
        ]);
        unset($state['pending_prompt']);
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] negated [Live Success] on " . cardDisplayName($m) . '.');
        return $state;
    }

    if ($type === 'stack_wr_under') {
        $ids = $data['instance_ids'] ?? $data['ids'] ?? [];
        if (!$ids && !empty($data['instance_id'])) {
            $ids = [$data['instance_id']];
        }
        $srcId = $prompt['source_instance_id'] ?? '';
        $slot = findMemberSlot($p, (string)$srcId);
        if ($slot === '') {
            unset($state['pending_prompt']);
            return $state;
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
                    $p['stage'][$slot]['stacked_members'][] = $card;
                    $moved++;
                    break;
                }
            }
        }
        unset($state['pending_prompt']);
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] stacked $moved card(s) underneath.");
        return $state;
    }

    // Generic optional skip-capable prompts: mark handled with log for now when choice=confirm paths incomplete
    if (in_array($type, [
        'optional_wait_self_discard_wait_opp',
        'optional_reveal_hand_live_swap_success',
        'optional_activate_opp_wait_draw_each',
        'optional_discard_distinct_subunit_wait_opp',
        'optional_wait_self_discard_center_blade',
        'optional_unstack_toggle_subunit',
        'per_success_subunit_choose',
        'auto_on_opp_wait_by_subunit_choose',
        'activated_wait_printemps_live_from_wr',
        'optional_wait_self_discard_look_reveal',
    ], true)) {
        // Delegate detailed multi-step to existing helper patterns where possible
        unset($state['pending_prompt']);
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] resolved DUO prompt ($type).");
        return $state;
    }

    return null;
}
