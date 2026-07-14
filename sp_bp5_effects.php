<?php
/**
 * Liella! (Superstar) bp5 effect handlers.
 * Included by effects.php.
 */

function spBp5EffectTypes(): array {
    return [
        'activated_wait_or_discard_activate',
        'activated_wait_draw_discard_reactivate',
        'hand_cost_reduction_group_target_cost',
        'live_start_activate_all_group_and_energy',
        'auto_move_or_energy_draw_heart',
        'activated_mill_blade_per_group_member',
        'auto_main_wr_pay_energy_add_hand',
        'activated_mill_then_swap',
        'look_reveal_distinct_groups',
        'optional_wait_self_discard_look_reveal_group',
        'live_start_repeat_mill_top_blade',
        'on_enter_both_center_position_change',
        'continuous_hearts_in_slot',
        'continuous_heart_if_live_zone_group_hearts',
        'look_reveal_subunit_or_blade_group',
        'on_enter_draw_if_other_moved',
        'hand_cost_reduction_if_group_moved',
        'activated_pay_energy_draw',
        'leave_stage_energy_wait_if_min_energy',
        'live_success_score_if_success_zones_yell_score',
        'live_start_heart_choice_moved_members',
        'live_success_score_per_energy_paid',
        'live_score_if_stage_group_hearts_total',
        'live_success_energy_wait_opp_draw',
        'live_score_if_exact_energy',
        'activated_return_energy_add_wr_live',
    ];
}

function spBp5IsEffectType(string $type): bool {
    return in_array($type, spBp5EffectTypes(), true);
}

function spBp5CountLiveZoneGroupRequiredHearts(array $p, string $group): int {
    $total = 0;
    foreach ($p['live_zone'] ?? [] as $lc) {
        if (!$lc || ($lc['group'] ?? '') !== $group) continue;
        foreach ($lc['required_hearts'] ?? $lc['hearts'] ?? [] as $h) {
            $total += intval($h['count'] ?? 1);
        }
    }
    return $total;
}

function spBp5CountStageGroupHearts(array $p, string $group): int {
    $total = 0;
    foreach ($p['stage'] as $mbr) {
        if (!$mbr || ($mbr['group'] ?? '') !== $group) continue;
        foreach ($mbr['hearts'] ?? [] as $h) {
            $total += intval($h['count'] ?? 1);
        }
        foreach ($mbr['bonus_hearts'] ?? [] as $c) {
            if ($c !== '') $total++;
        }
    }
    return $total;
}

function spBp5StageGroupMovedThisTurn(array $p, string $group): bool {
    foreach ($p['stage'] as $mbr) {
        if (!$mbr || ($mbr['group'] ?? '') !== $group) continue;
        if (!empty($mbr['moved_this_turn'])) return true;
    }
    return false;
}

function spBp5OtherStageMembersMoved(array $p, string $excludeId): bool {
    foreach ($p['stage'] as $mbr) {
        if (!$mbr || ($mbr['instance_id'] ?? '') === $excludeId) continue;
        if (!empty($mbr['moved_this_turn'])) return true;
    }
    return false;
}

function spBp5SwapCenterWithSide(array &$state, string $pid, string $side = 'left'): void {
    $p = &$state['players'][$pid];
    $center = $p['stage']['center'] ?? null;
    if (!$center) return;
    $dest = $p['stage'][$side] ?? null;
    $p['stage'][$side] = $center;
    $p['stage']['center'] = $dest;
    if ($center) {
        $center['moved_this_turn'] = true;
        $p['stage'][$side] = $center;
        $state = resolveAutoAreaMoveAbilities($state, $pid, $center['instance_id'] ?? '');
    }
    if ($dest) {
        $dest['moved_this_turn'] = true;
        $p['stage']['center'] = $dest;
        $state = resolveAutoAreaMoveAbilities($state, $pid, $dest['instance_id'] ?? '');
    }
}

function spBp5MoveMemberSwap(array &$state, string $pid, string $fromSlot, string $toSlot): void {
    $p = &$state['players'][$pid];
    $member = $p['stage'][$fromSlot] ?? null;
    if (!$member) return;
    $other = $p['stage'][$toSlot] ?? null;
    $p['stage'][$toSlot] = $member;
    $p['stage'][$fromSlot] = $other;
    $member['moved_this_turn'] = true;
    $p['stage'][$toSlot] = $member;
    $state = resolveAutoAreaMoveAbilities($state, $pid, $member['instance_id'] ?? '');
    if ($other) {
        $other['moved_this_turn'] = true;
        $p['stage'][$fromSlot] = $other;
        $state = resolveAutoAreaMoveAbilities($state, $pid, $other['instance_id'] ?? '');
    }
}

function spBp5ApplyContinuousLiveScore(array $state, string $pid, array $member, array $ab): int {
    $type = $ab['type'] ?? '';
    if ($type === 'live_score_if_exact_energy') {
        $p = $state['players'][$pid] ?? [];
        if (countEnergyInZone($p) === intval($ab['exact_energy'] ?? 8)) {
            return intval($ab['amount'] ?? 1);
        }
    }
    return 0;
}

function spBp5ApplyHandCostReduction(array $state, string $pid, array $card, int $base): int {
    $p = $state['players'][$pid] ?? [];
    foreach ($p['stage'] ?? [] as $stageMbr) {
        if (!$stageMbr) continue;
        foreach ($stageMbr['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') continue;
            if (($ab['type'] ?? '') === 'hand_cost_reduction_group_target_cost') {
                $target = intval($ab['target_cost'] ?? 10);
                if (intval($card['cost'] ?? 0) === $target
                    && cardMatchesGroup($card, $ab['group'] ?? 'Superstar', 'member')) {
                    $base = max(0, $base - intval($ab['amount'] ?? 2));
                }
            }
        }
    }
    if (cardHasAbilities($card)) {
        foreach ($card['abilities'] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') continue;
            if (($ab['type'] ?? '') === 'hand_cost_reduction_if_group_moved') {
                if (spBp5StageGroupMovedThisTurn($p, $ab['group'] ?? 'Superstar')) {
                    $base = max(0, $base - intval($ab['amount'] ?? 2));
                }
            }
        }
    }
    return $base;
}

function spBp5ApplyContinuousHearts(array $state, string $pid, array $member, string $slot, array $hearts): array {
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') continue;
        $type = $ab['type'] ?? '';
        if ($type === 'continuous_hearts_in_slot' && ($ab['slot'] ?? '') === $slot) {
            foreach ($ab['hearts'] ?? [] as $h) {
                for ($i = 0; $i < intval($h['count'] ?? 1); $i++) {
                    $hearts[] = $h['color'] ?? 'yellow';
                }
            }
        }
        if ($type === 'continuous_heart_if_live_zone_group_hearts') {
            $p = $state['players'][$pid] ?? [];
            if (spBp5CountLiveZoneGroupRequiredHearts($p, $ab['group'] ?? 'Superstar')
                >= intval($ab['min_required_total'] ?? 8)) {
                foreach ($ab['hearts'] ?? [] as $h) {
                    for ($i = 0; $i < intval($h['count'] ?? 1); $i++) {
                        $hearts[] = $h['color'] ?? 'purple';
                    }
                }
            }
        }
    }
    return $hearts;
}

function spBp5OnEnergyPlaced(array $state, string $pid): array {
    foreach ($state['players'][$pid]['stage'] as $slot => &$member) {
        if (!$member) continue;
        foreach ($member['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') continue;
            if (($ab['type'] ?? '') !== 'auto_move_or_energy_draw_heart') continue;
            if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $idx)) continue;
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $color = $ab['heart_color'] ?? 'purple';
            $cnt = intval($ab['heart_count'] ?? 1);
            $state = initLiveModifiers($state);
            for ($i = 0; $i < $cnt; $i++) {
                $state['live_modifiers'][$pid]['bonus_hearts'][] = $color;
            }
            markAbilityUsed($member, $idx);
            $state['players'][$pid]['stage'][$slot] = $member;
            $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$mName] drew $drawn and gained $cnt $color heart(s) (Energy placed).");
        }
    }
    unset($member);
    return $state;
}

function spBp5OnAutoAreaMove(array $state, string $pid, array &$member, int $idx, array $ab): array {
    if (($ab['type'] ?? '') !== 'auto_move_or_energy_draw_heart') return $state;
    if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $idx)) return $state;
    $p = &$state['players'][$pid];
    $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
    $color = $ab['heart_color'] ?? 'purple';
    $cnt = intval($ab['heart_count'] ?? 1);
    $state = initLiveModifiers($state);
    for ($i = 0; $i < $cnt; $i++) {
        $state['live_modifiers'][$pid]['bonus_hearts'][] = $color;
    }
    markAbilityUsed($member, $idx);
    $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$mName] drew $drawn and gained $cnt $color heart(s) (area move).");
    return $state;
}

function spBp5NotifyCardsToWr(array $state, string $pid, array $cards): array {
    if (empty($cards)) return $state;
    if (!in_array($state['phase'] ?? '', ['main_first', 'main_second'], true)
        || ($state['active_player'] ?? '') !== $pid) {
        return $state;
    }
    foreach ($state['players'][$pid]['stage'] as $slot => &$member) {
        if (!$member) continue;
        foreach ($member['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') continue;
            if (($ab['type'] ?? '') !== 'auto_main_wr_pay_energy_add_hand') continue;
            if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $idx)) continue;
            if (!empty($state['pending_prompt'])) return $state;
            $state['pending_prompt'] = [
                'type'          => 'spbp5_wr_pay_add_hand',
                'step'          => 'confirm',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $member['instance_id'] ?? '',
                'source_slot'   => $slot,
                'ability_index' => $idx,
                'source_name'   => $member['name_en'] ?? $member['name'] ?? 'Member',
                'wr_cards'      => array_map('cardPromptSummary', $cards),
                'prompt'        => 'Pay 1 Energy: add 1 of the cards just put into your Waiting Room to your hand?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Pay 1 Energy', 'No — Skip'],
                'ability'       => $ab,
            ];
            return $state;
        }
    }
    unset($member);
    return $state;
}

function spBp5ResolveEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (!spBp5IsEffectType($type)) return $state;

    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'on_enter_both_center_position_change':
            foreach (['p1', 'p2'] as $pl) {
                spBp5SwapCenterWithSide($state, $pl, 'left');
            }
            $state = addLog($state, 'Both players position-changed their Center Members.');
            break;

        case 'on_enter_draw_if_other_moved':
            if (spBp5OtherStageMembersMoved($p, $source['instance_id'] ?? '')) {
                $n = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $n (other Member moved this turn).");
            }
            break;

        case 'live_start_activate_all_group_and_energy':
            $group = $ab['group'] ?? 'Superstar';
            $reqSlot = $ab['slot'] ?? '';
            if ($reqSlot !== '') {
                $mbr = $p['stage'][$reqSlot] ?? null;
                if (!$mbr) break;
            }
            foreach ($p['stage'] as $slot => &$mbr) {
                if (!$mbr) continue;
                if ($group !== '' && ($mbr['group'] ?? '') !== $group) continue;
                $mbr['active'] = true;
                $p['stage'][$slot] = $mbr;
            }
            unset($mbr);
            $n = activateEnergyForPlayer($p, count($p['energy_zone'] ?? []));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] activated all $group Members and $n Energy.");
            break;

        case 'live_start_repeat_mill_top_blade':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'        => 'spbp5_repeat_mill_blade',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_id'   => $source['instance_id'] ?? '',
                'source_name' => $name,
                'repeat'      => 0,
                'max_repeats' => intval($ab['max_repeats'] ?? 5),
                'blade_per'   => intval($ab['blade_per'] ?? 1),
                'member_id'   => $ctx['member_id'] ?? '',
                'member_slot' => $ctx['slot'] ?? '',
                'prompt'      => 'Put the top card of your deck into the Waiting Room for +1 Blade until this Live ends? (Up to 5 times)',
                'choices'     => ['yes', 'no'],
                'choice_labels' => ['Yes — Mill top', 'No — Stop'],
                'ability'     => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional repeat mill for Blade.");
            break;

        case 'live_start_heart_choice_moved_members':
            if (!empty($state['pending_prompt'])) break;
            $colors = $ab['colors'] ?? ['pink', 'yellow', 'blue'];
            $state['pending_prompt'] = [
                'type'          => 'spbp5_heart_choice_moved',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'colors'        => $colors,
                'prompt'        => 'Choose 1 heart color for Members that moved this turn.',
                'choices'       => $colors,
                'choice_labels' => array_map('ucfirst', $colors),
                'ability'       => $ab,
            ];
            break;

        case 'live_score_if_stage_group_hearts_total':
            $total = spBp5CountStageGroupHearts($p, $ab['group'] ?? 'Superstar');
            if ($total >= intval($ab['min_hearts'] ?? 11)) {
                $state = applyModifierEffect($state, $pid, [
                    'type'   => 'live_score_bonus',
                    'amount' => intval($ab['amount'] ?? 1),
                ]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] score +1 ($total stage hearts).");
            }
            break;

        case 'live_success_score_if_success_zones_yell_score':
            $selfCnt = count($p['success_lives'] ?? []);
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $oppCnt = count($state['players'][$opp]['success_lives'] ?? []);
            $minZone = intval($ab['min_success_zone'] ?? 2);
            if ($selfCnt < $minZone && $oppCnt < $minZone) break;
            $yell = $state['_last_yell_cards'] ?? [];
            $hasScoreLive = false;
            foreach ($yell as $yc) {
                if (($yc['card_type'] ?? '') === 'ライブ' && intval($yc['score'] ?? 0) >= 1) {
                    $hasScoreLive = true;
                    break;
                }
            }
            if ($hasScoreLive) {
                $state = applyModifierEffect($state, $pid, [
                    'type'   => 'live_score_bonus',
                    'amount' => intval($ab['amount'] ?? 2),
                ]);
            }
            break;

        case 'live_success_score_per_energy_paid':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'        => 'spbp5_pay_energy_score',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_id'   => $source['instance_id'] ?? '',
                'source_name' => $name,
                'per_energy'  => intval($ab['per_energy'] ?? 4),
                'amount'      => intval($ab['amount'] ?? 1),
                'prompt'      => 'Pay any amount of Energy: score +1 for every 4 Energy paid.',
                'choices'     => ['pay', 'skip'],
                'choice_labels' => ['Pay Energy', 'Skip'],
                'ability'     => $ab,
            ];
            break;

        case 'live_success_energy_wait_opp_draw':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'        => 'spbp5_energy_wait_opp_draw',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_id'   => $source['instance_id'] ?? '',
                'source_name' => $name,
                'opp_draw'    => intval($ab['opp_draw'] ?? 1),
                'prompt'      => 'Put 1 Energy from your Energy deck into Wait? If you do, your opponent draws 1.',
                'choices'     => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No'],
                'ability'     => $ab,
            ];
            break;

        case 'look_reveal_distinct_groups':
            if (!empty($state['pending_prompt'])) break;
            $look = intval($ab['look'] ?? 5);
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            if (empty($top)) break;
            $state['pending_prompt'] = [
                'type'        => 'spbp5_distinct_groups',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'top_cards'   => array_map('cardPromptSummary', $top),
                'max_pick'    => intval($ab['max_pick'] ?? 3),
                'picked_groups' => [],
                'picked_ids'  => [],
                'prompt'      => 'Reveal up to 1 card per group name (max 3) to add to hand.',
                'ability'     => $ab,
            ];
            $state['_spbp5_surveil_rest'] = $top;
            break;

        case 'look_reveal_subunit_or_blade_group':
            if (!empty($state['pending_prompt'])) break;
            $look = intval($ab['look'] ?? 5);
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            $group = $ab['group'] ?? 'Superstar';
            $sub = $ab['subunit'] ?? 'Sunny Passion';
            $matches = array_values(array_filter($top, function ($c) use ($group, $sub) {
                if (($c['card_type'] ?? '') !== 'メンバー') return false;
                if (cardMatchesSubunit($c, $sub)) return true;
                return ($c['group'] ?? '') === $group && !empty($c['blade_hearts']);
            }));
            if (count($matches) === 1) {
                $pick = $matches[0];
                $p['hand'][] = $pick;
                $rest = array_values(array_filter($top, fn($c) => ($c['instance_id'] ?? '') !== ($pick['instance_id'] ?? '')));
                $p['waiting_room'] = array_merge($p['waiting_room'], $rest);
                break;
            }
            if (empty($matches)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $top);
                break;
            }
            $state['pending_prompt'] = [
                'type'        => 'spbp5_subunit_blade_pick',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'candidates'  => array_map('cardPromptSummary', $matches),
                'rest_ids'    => array_map(fn($c) => $c['instance_id'] ?? '', array_values(array_filter(
                    $top,
                    fn($c) => !in_array($c['instance_id'] ?? '', array_column($matches, 'instance_id'), true)
                ))),
                'prompt'      => 'Reveal 1 Sunny Passion Member or Liella! Member with a Blade heart?',
                'choices'     => array_merge(['skip'], array_map(fn($i) => 'pick_' . $i, array_keys($matches))),
                'choice_labels' => array_merge(['Skip — all to WR'], array_map('cardDisplayName', $matches)),
                'ability'     => $ab,
            ];
            $state['_spbp5_surveil_rest'] = $top;
            break;

        case 'optional_wait_self_discard_look_reveal_group':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'spbp5_wait_discard_surveil',
                'step'          => 'confirm',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_slot'   => $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? ''),
                'source_name'   => $name,
                'prompt'        => 'Put this Member into Wait and discard 1: look at the top ' .
                    intval($ab['look'] ?? 5) . ' cards; reveal 1 Liella! Member cost ' .
                    intval($ab['min_cost'] ?? 9) . '+?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            break;
    }
    return $state;
}

function spBp5ResolveActivatedAbility(
    array $state,
    string $pid,
    array &$p,
    array &$member,
    $slot,
    array $ab,
    int|string $abilityIdx,
    array $data
): ?array {
    $type = $ab['type'] ?? '';
    if (!spBp5IsEffectType($type)) return null;
    $name = $member['name_en'] ?? $member['name'] ?? 'Member';

    if ($type === 'activated_wait_or_discard_activate') {
        if (!empty($data['choice']) && $data['choice'] === 'wait') {
            waitMember($member, $state);
            $p['stage'][$slot] = $member;
        } elseif (!empty($data['discard_ids'])) {
            discardFromHandByIds($p, $data['discard_ids']);
        } else {
            $discardNeed = intval($ab['discard'] ?? 1);
            $choices = ['wait'];
            $labels = ['Wait this Member'];
            if (count($p['hand'] ?? []) >= $discardNeed) {
                $choices[] = 'discard';
                $labels[] = 'Discard 1 from hand';
            }
            $state['pending_prompt'] = [
                'type'          => 'spbp5_wait_or_discard_activate',
                'step'          => 'choose',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $member['instance_id'] ?? '',
                'source_slot'   => $slot,
                'ability_index' => $abilityIdx,
                'source_name'   => $name,
                'prompt'        => 'Put this Member into Wait OR discard 1 card: activate 1 Energy.',
                'choices'       => $choices,
                'choice_labels' => $labels,
                'ability'       => $ab,
            ];
            return $state;
        }
        $n = activateEnergyForPlayer($p, intval($ab['activate_count'] ?? 1));
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] activated $n Energy.");
        return $state;
    }

    if ($type === 'activated_wait_draw_discard_reactivate') {
        $reqSlot = $ab['slot'] ?? 'left';
        if ($slot !== $reqSlot) throw new Exception('Ability requires Left Side');
        waitMember($member, $state);
        $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 3));
        $need = intval($ab['discard'] ?? 2);
        $state['pending_prompt'] = [
            'type'          => 'spbp5_wait_draw_discard',
            'step'          => 'discard',
            'owner'         => $pid,
            'responder'     => $pid,
            'source_id'     => $member['instance_id'] ?? '',
            'source_slot'   => $slot,
            'ability_index' => $abilityIdx,
            'source_name'   => $name,
            'discard_count' => $need,
            'drawn'         => $drawn,
            'blade_amount'  => intval($ab['blade_amount'] ?? 2),
            'prompt'        => "Discard $need card(s) from your hand.",
            'ability'       => $ab,
        ];
        return $state;
    }

    if ($type === 'activated_mill_blade_per_group_member') {
        $mill = intval($ab['mill'] ?? 3);
        $milled = array_splice($p['main_deck'], 0, min($mill, count($p['main_deck'])));
        $group = $ab['group'] ?? 'Superstar';
        $bladeCnt = 0;
        foreach ($milled as $c) {
            if (($c['card_type'] ?? '') === 'メンバー' && ($c['group'] ?? '') === $group) {
                $bladeCnt += intval($ab['blade_per'] ?? 1);
            }
        }
        if (!empty($milled)) {
            $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
            $state = spBp5NotifyCardsToWr($state, $pid, $milled);
        }
        if ($bladeCnt > 0) {
            $state = applyModifierEffect($state, $pid, ['type' => 'blade_bonus', 'amount' => $bladeCnt]);
        }
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] milled " . count($milled) . " and gained +$bladeCnt Blade.");
        return $state;
    }

    if ($type === 'activated_mill_then_swap') {
        $mill = intval($ab['mill'] ?? 3);
        $milled = array_splice($p['main_deck'], 0, min($mill, count($p['main_deck'])));
        if (!empty($milled)) {
            $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
            $state = spBp5NotifyCardsToWr($state, $pid, $milled);
        }
        $slots = array_values(array_filter(['left', 'center', 'right'], fn($s) => $s !== $slot));
        $state['pending_prompt'] = [
            'type'          => 'spbp5_mill_swap_pick',
            'owner'         => $pid,
            'responder'     => $pid,
            'source_id'     => $member['instance_id'] ?? '',
            'source_slot'   => $slot,
            'ability_index' => $abilityIdx,
            'source_name'   => $name,
            'choices'       => $slots,
            'choice_labels' => array_map(fn($s) => ucfirst($s) . ' area', $slots),
            'prompt'        => 'Choose an area to position-change this Member to.',
            'ability'       => $ab,
        ];
        markAbilityUsed($member, $abilityIdx);
        return $state;
    }

    if ($type === 'activated_pay_energy_draw') {
        $cost = intval($ab['cost'] ?? 2);
        if (!payEnergyCost($p, $cost)) throw new Exception("Need $cost active Energy");
        $n = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] paid $cost Energy and drew $n.");
        return $state;
    }

    if ($type === 'leave_stage_energy_wait_if_min_energy') {
        if (countEnergyInZone($p) < intval($ab['min_energy'] ?? 6)) {
            throw new Exception('Need ' . intval($ab['min_energy'] ?? 6) . '+ Energy');
        }
        $p['waiting_room'][] = $member;
        $p['stage'][$slot] = null;
        putEnergyFromDeckInWait($p, $state, $pid);
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] left Stage; put 1 Energy into Wait.");
        return $state;
    }

    if ($type === 'activated_return_energy_add_wr_live') {
        $cost = intval($ab['cost'] ?? 2);
        $returned = 0;
        $toDeck = [];
        foreach ($p['energy_zone'] as $i => $e) {
            if ($returned >= $cost) break;
            if ($e['active'] ?? false) {
                $toDeck[] = $e;
                unset($p['energy_zone'][$i]);
                $returned++;
            }
        }
        $p['energy_zone'] = array_values($p['energy_zone']);
        if ($returned < $cost) throw new Exception("Need $cost active Energy to return to Energy deck");
        $p['energy_deck'] = array_merge($p['energy_deck'], $toDeck);
        $eligible = array_values(array_filter(
            $p['waiting_room'] ?? [],
            fn($c) => ($c['card_type'] ?? '') === 'ライブ'
        ));
        if (empty($eligible)) throw new Exception('No Live in Waiting Room');
        if (count($eligible) === 1) {
            foreach ($p['waiting_room'] as $i => $c) {
                if (($c['instance_id'] ?? '') === ($eligible[0]['instance_id'] ?? '')) {
                    $p['hand'][] = $c;
                    array_splice($p['waiting_room'], $i, 1);
                    break;
                }
            }
        } else {
            $state['pending_prompt'] = [
                'type'          => 'spbp5_pick_wr_live',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $member['instance_id'] ?? '',
                'ability_index' => $abilityIdx,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $eligible),
                'prompt'        => 'Choose 1 Live card from your Waiting Room.',
                'ability'       => $ab,
            ];
            markAbilityUsed($member, $abilityIdx);
            return $state;
        }
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] returned Energy to deck and added WR Live to hand.");
        return $state;
    }

    return null;
}

function spBp5ResolvePrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $promptType = $prompt['type'] ?? '';
    $ownerP = &$state['players'][$owner];
    $ability = $prompt['ability'] ?? [];

    if ($promptType === 'spbp5_wait_or_discard_activate') {
        $markUsed = function () use (&$state, &$ownerP, $prompt): void {
            $slot = $prompt['source_slot'] ?? '';
            $abIdx = intval($prompt['ability_index'] ?? 0);
            if ($slot !== '' && !empty($ownerP['stage'][$slot])) {
                markAbilityUsed($ownerP['stage'][$slot], $abIdx);
            }
        };
        if (($prompt['step'] ?? '') === 'choose') {
            if ($choice === 'wait') {
                $slot = $prompt['source_slot'] ?? 'center';
                if (!empty($ownerP['stage'][$slot])) {
                    waitMember($ownerP['stage'][$slot], $state);
                }
                $n = activateEnergyForPlayer($ownerP, intval($ability['activate_count'] ?? 1));
                $markUsed();
                unset($state['pending_prompt']);
                $state['seq']++;
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — activated $n Energy.");
                return finishPromptEffects($state);
            }
            $handCount = count($ownerP['hand'] ?? []);
            $need = intval($ability['discard'] ?? 1);
            if ($handCount < $need) {
                throw new Exception('Need at least ' . $need . ' card(s) in hand to discard');
            }
            $state['pending_prompt'] = array_merge($prompt, [
                'step'   => 'discard',
                'prompt' => 'Discard 1 card from your hand.',
            ]);
            $state['seq']++;
            return $state;
        }
        if (($prompt['step'] ?? '') === 'discard') {
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== intval($ability['discard'] ?? 1)) {
                throw new Exception('Must discard exactly ' . intval($ability['discard'] ?? 1) . ' card(s)');
            }
            discardFromHandByIds($ownerP, $ids);
            $n = activateEnergyForPlayer($ownerP, intval($ability['activate_count'] ?? 1));
            $markUsed();
            unset($state['pending_prompt']);
            $state['seq']++;
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — discarded and activated $n Energy.");
            return finishPromptEffects($state);
        }
    }

    if ($promptType === 'spbp5_wait_draw_discard') {
        if (($prompt['step'] ?? '') === 'discard') {
            $ids = $data['discard_ids'] ?? [];
            $need = intval($prompt['discard_count'] ?? 2);
            if (count($ids) !== $need) throw new Exception("Must discard exactly $need card(s)");
            $bladeless = 0;
            foreach ($ownerP['hand'] as $c) {
                if (!in_array($c['instance_id'] ?? '', $ids, true)) continue;
                if (($c['card_type'] ?? '') === 'メンバー' && empty($c['blade_hearts'])) {
                    $bladeless++;
                }
            }
            discardFromHandByIds($ownerP, $ids);
            $slot = $prompt['source_slot'] ?? 'left';
            if ($bladeless >= 1 && !empty($ownerP['stage'][$slot])) {
                $ownerP['stage'][$slot]['active'] = true;
            }
            if ($bladeless >= 2) {
                $amt = intval($prompt['blade_amount'] ?? 2);
                $state = applyModifierEffect($state, $owner, ['type' => 'blade_bonus', 'amount' => $amt]);
            }
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
    }

    if ($promptType === 'spbp5_mill_swap_pick') {
        $toSlot = $choice;
        $fromSlot = $prompt['source_slot'] ?? 'center';
        spBp5MoveMemberSwap($state, $owner, $fromSlot, $toSlot);
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'spbp5_repeat_mill_blade') {
        if ($choice === 'no') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        if (empty($ownerP['main_deck'])) {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        $card = array_shift($ownerP['main_deck']);
        $milled = [$card];
        $ownerP['waiting_room'][] = $card;
        $state = applyModifierEffect($state, $owner, [
            'type'   => 'blade_bonus',
            'amount' => intval($prompt['blade_per'] ?? 1),
        ]);
        if (($card['card_type'] ?? '') === 'ライブ') {
            $mSlot = $prompt['member_slot'] ?? '';
            if ($mSlot !== '' && !empty($ownerP['stage'][$mSlot])) {
                waitMember($ownerP['stage'][$mSlot], $state);
            }
        }
        $repeat = intval($prompt['repeat'] ?? 0) + 1;
        if ($repeat >= intval($prompt['max_repeats'] ?? 5) || empty($ownerP['main_deck'])) {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        $state['pending_prompt'] = array_merge($prompt, ['repeat' => $repeat]);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'spbp5_heart_choice_moved') {
        $color = $choice;
        foreach ($ownerP['stage'] as $slot => &$mbr) {
            if (!$mbr || empty($mbr['moved_this_turn'])) continue;
            $mbr['bonus_hearts'][] = $color;
            $ownerP['stage'][$slot] = $mbr;
        }
        unset($mbr);
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'spbp5_pay_energy_score') {
        if ($choice === 'skip') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $paid = intval($data['energy_count'] ?? 0);
        if ($paid < 1 || !payEnergyCost($ownerP, $paid)) {
            throw new Exception('Invalid Energy payment');
        }
        $bonus = intdiv($paid, intval($prompt['per_energy'] ?? 4)) * intval($prompt['amount'] ?? 1);
        if ($bonus > 0) {
            $state = applyModifierEffect($state, $owner, ['type' => 'live_score_bonus', 'amount' => $bonus]);
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'spbp5_energy_wait_opp_draw') {
        if ($choice === 'yes') {
            putEnergyFromDeckInWait($ownerP, $state, $owner);
            $opp = ($owner === 'p1') ? 'p2' : 'p1';
            $n = drawCardsForPlayer($state, $opp, intval($prompt['opp_draw'] ?? 1));
            $state = addLog($state, $state['players'][$opp]['name'] . " drew $n.");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'spbp5_wr_pay_add_hand') {
        if (($prompt['step'] ?? '') === 'confirm') {
            if ($choice === 'no') {
                unset($state['pending_prompt']);
                $state['seq']++;
                return $state;
            }
            if (!payEnergyCost($ownerP, intval($ability['cost'] ?? 1))) {
                throw new Exception('Need 1 active Energy');
            }
            $state['pending_prompt'] = array_merge($prompt, [
                'step'   => 'pick',
                'prompt' => 'Choose 1 card just put into your Waiting Room.',
            ]);
            $state['seq']++;
            return $state;
        }
        if (($prompt['step'] ?? '') === 'pick') {
            $cardId = $data['card_id'] ?? '';
            foreach ($ownerP['waiting_room'] as $i => $c) {
                if (($c['instance_id'] ?? '') !== $cardId) continue;
                $ownerP['hand'][] = $c;
                array_splice($ownerP['waiting_room'], $i, 1);
                break;
            }
            $slot = $prompt['source_slot'] ?? '';
            $idx = intval($prompt['ability_index'] ?? 0);
            if ($slot !== '' && !empty($ownerP['stage'][$slot])) {
                markAbilityUsed($ownerP['stage'][$slot], $idx);
            }
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }
    }

    if ($promptType === 'spbp5_distinct_groups') {
        $cardId = $data['card_id'] ?? '';
        $top = $state['_spbp5_surveil_rest'] ?? [];
        foreach ($top as $c) {
            if (($c['instance_id'] ?? '') !== $cardId) continue;
            $ownerP['hand'][] = $c;
            break;
        }
        unset($state['_spbp5_surveil_rest'], $state['pending_prompt']);
        $rest = array_values(array_filter($top, fn($c) => ($c['instance_id'] ?? '') !== $cardId));
        $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $rest);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'spbp5_subunit_blade_pick') {
        if ($choice === 'skip') {
            $top = $state['_spbp5_surveil_rest'] ?? [];
            $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $top);
        } else {
            $cardId = $data['card_id'] ?? '';
            $top = $state['_spbp5_surveil_rest'] ?? [];
            foreach ($top as $c) {
                if (($c['instance_id'] ?? '') === $cardId) {
                    $ownerP['hand'][] = $c;
                } else {
                    $ownerP['waiting_room'][] = $c;
                }
            }
        }
        unset($state['_spbp5_surveil_rest'], $state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'spbp5_wait_discard_surveil') {
        if (($prompt['step'] ?? '') === 'confirm') {
            if ($choice === 'no') {
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $slot = $prompt['source_slot'] ?? '';
            if ($slot !== '' && !empty($ownerP['stage'][$slot])) {
                waitMember($ownerP['stage'][$slot], $state);
            }
            $state['pending_prompt'] = array_merge($prompt, [
                'step'   => 'discard',
                'prompt' => 'Discard 1 card from your hand.',
            ]);
            $state['seq']++;
            return $state;
        }
        if (($prompt['step'] ?? '') === 'discard') {
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== intval($ability['discard'] ?? 1)) {
                throw new Exception('Must discard 1 card');
            }
            discardFromHandByIds($ownerP, $ids);
            $look = intval($ability['look'] ?? 5);
            $top = array_splice($ownerP['main_deck'], 0, min($look, count($ownerP['main_deck'])));
            $group = $ability['group'] ?? 'Superstar';
            $matches = array_values(array_filter(
                $top,
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
                    && cardMatchesGroup($c, $group, 'member')
                    && intval($c['cost'] ?? 0) >= intval($ability['min_cost'] ?? 9)
            ));
            if (count($matches) === 1) {
                $ownerP['hand'][] = $matches[0];
                $rest = array_values(array_filter($top, fn($c) => ($c['instance_id'] ?? '') !== ($matches[0]['instance_id'] ?? '')));
                $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $rest);
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            if (count($matches) === 0) {
                $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $top);
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $top);
            $state['pending_prompt'] = [
                'type'        => 'spbp5_wait_discard_surveil',
                'step'        => 'pick',
                'owner'       => $owner,
                'responder'   => $owner,
                'source_name' => $prompt['source_name'] ?? '',
                'candidates'  => array_map('cardPromptSummary', $matches),
                'rest'        => array_map('cardPromptSummary', array_values(array_filter(
                    $top,
                    fn($c) => !in_array($c['instance_id'] ?? '', array_column($matches, 'instance_id'), true)
                ))),
                'prompt'      => 'Reveal 1 matching Member?',
                'ability'     => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        if (($prompt['step'] ?? '') === 'pick') {
            $cardId = $data['card_id'] ?? '';
            if ($cardId !== '') {
                foreach ($ownerP['waiting_room'] as $i => $wr) {
                    if (($wr['instance_id'] ?? '') === $cardId) {
                        $ownerP['hand'][] = $wr;
                        array_splice($ownerP['waiting_room'], $i, 1);
                        break;
                    }
                }
            }
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
    }

    if ($promptType === 'spbp5_pick_wr_live') {
        $cardId = $data['card_id'] ?? '';
        foreach ($ownerP['waiting_room'] as $i => $c) {
            if (($c['instance_id'] ?? '') !== $cardId) continue;
            $ownerP['hand'][] = $c;
            array_splice($ownerP['waiting_room'], $i, 1);
            break;
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    return null;
}
