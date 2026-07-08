<?php
/**
 * Liella! (Superstar) pb2 / DUO effect type registry and handlers.
 * Included by effects.php.
 */

function spBp2EffectTypes(): array {
    return [
        'activated_discard_trigger_on_enter',
        'activate_energy_up_to_if_distinct_subunit',
        'allows_double_baton',
        'auto_area_move_energy_wait',
        'auto_on_center_move_choose',
        'auto_on_move_to_center_subunit_heart',
        'auto_stack_wr_group_member_under',
        'auto_yell_mill_extra_yell',
        'auto_yell_no_blade_heart',
        'blade_per_hand_cards',
        'choose_heart_modifier',
        'continuous_hearts_in_slot',
        'continuous_negate_stage_member_abilities',
        'cost_per_stacked_group_member',
        'draw_and_discard',
        'draw_if_live_zone_score_up_or_yell_score_icon',
        'energy_wait_from_deck',
        'formation_rotate_all',
        'grant_bonus_hearts',
        'hearts_if_active_energy',
        'hearts_if_center_highest_cost',
        'hearts_if_min_energy',
        'if_baton_wr_group_to_hand',
        'if_double_baton_group_bonus',
        'inherit_stacked_group_abilities',
        'leave_stage_add_from_wr',
        'live_score_if_stage_has_ability_members',
        'live_success_pick_yell_card',
        'look_reveal_filter',
        'on_enter_side_area',
        'optional_discard_prompt',
        'optional_formation_change_group',
        'optional_pay_energy',
        'optional_swap_area_on_enter',
        'optional_wait_self_opp_heart_gap',
        'optional_wr_to_deck_top',
        'pay_energy_add_from_wr',
        'pick_wr_distinct_lives_opp_choice',
        'activated_discard_liella_choose_energy_or_hearts',
        'reduce_hearts_per_entered_moved_subunit',
        'reduce_yell_reveal_count',
        'score_if_fewer_success_lives',
        'score_if_hand_more_than_opp',
        'score_if_min_energy',
        'score_if_moved_by_group_effect',
        'score_if_stage_member_hearts',
        'score_per_yell_group_no_blade',
        'stack_baton_wr_member_under',
        'wait_opponent_stage_max_cost',
    ];
}

function spBp2IsEffectType(string $type): bool {
    return in_array($type, spBp2EffectTypes(), true);
}

function spBp2HandlerTypes(): array {
    return [
        'auto_on_center_move_choose',
        'auto_on_move_to_center_subunit_heart',
        'auto_stack_wr_group_member_under',
        'continuous_negate_stage_member_abilities',
        'cost_per_stacked_group_member',
        'draw_if_live_zone_score_up_or_yell_score_icon',
        'hearts_if_active_energy',
        'inherit_stacked_group_abilities',
        'live_score_if_stage_has_ability_members',
        'optional_wait_self_opp_heart_gap',
        'score_if_moved_by_group_effect',
        'score_per_yell_group_no_blade',
        'stack_baton_wr_member_under',
        'leave_stage_add_from_wr',
    ];
}

function spBp2IsHandlerType(string $type): bool {
    return in_array($type, spBp2HandlerTypes(), true);
}

function spBp2InheritedAbilitiesForTrigger(array $member, string $trigger): array {
    $group = '';
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') === 'continuous'
            && ($ab['type'] ?? '') === 'inherit_stacked_group_abilities') {
            $group = $ab['group'] ?? 'Superstar';
            break;
        }
    }
    if ($group === '') {
        return [];
    }
    $out = [];
    foreach ($member['stacked_members'] ?? [] as $stacked) {
        if (!$stacked || !cardMatchesGroup($stacked, $group, 'member')) {
            continue;
        }
        mergeCardCatalogFields($stacked);
        foreach (getAbilitiesByTrigger($stacked, $trigger) as $sab) {
            $out[] = $sab;
        }
    }
    return $out;
}

function spBp2MemberLiveSuccessAbilities(array $member): array {
    mergeCardCatalogFields($member);
    $out = getAbilitiesByTrigger($member, 'live_success');
    foreach (batch99MemberLiveSuccessAbilities($member) as $ab) {
        $out[] = $ab;
    }
    return $out;
}

function spBp2StageMemberAbilitiesSuppressed(array $state, string $pid): bool {
    foreach ($state['players'][$pid]['live_zone'] ?? [] as $lc) {
        if (!$lc) {
            continue;
        }
        mergeCardCatalogFields($lc);
        foreach ($lc['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'continuous_negate_stage_member_abilities') {
                return true;
            }
        }
    }
    return false;
}

function spBp2StageHasAbilityMember(array $p): bool {
    foreach ($p['stage'] ?? [] as $mbr) {
        if (!$mbr) {
            continue;
        }
        mergeCardCatalogFields($mbr);
        if (cardHasAbilities($mbr)) {
            return true;
        }
    }
    return false;
}

function spBp2StackMemberUnder(array &$p, string $slot, array $stacked): void {
    if ($slot === '' || empty($p['stage'][$slot])) {
        return;
    }
    if (!isset($p['stage'][$slot]['stacked_members'])) {
        $p['stage'][$slot]['stacked_members'] = [];
    }
    $p['stage'][$slot]['stacked_members'][] = $stacked;
}

function spBp2RemoveWrMemberById(array &$p, string $pickId): ?array {
    $picked = null;
    $p['waiting_room'] = array_values(array_filter(
        $p['waiting_room'],
        function ($c) use ($pickId, &$picked) {
            if (($c['instance_id'] ?? '') === $pickId) {
                $picked = $c;
                return false;
            }
            return true;
        }
    ));
    return $picked;
}

function spBp2LiveZoneHasScoreAbovePrinted(array $p): bool {
    foreach ($p['live_zone'] ?? [] as $lc) {
        if (!$lc || !isLiveTypeCard($lc)) {
            continue;
        }
        $printed = intval($lc['_printed_score'] ?? $lc['score'] ?? 0);
        if (intval($lc['score'] ?? 0) > $printed) {
            return true;
        }
    }
    return false;
}

function spBp2YellHasScoreIconLive(array $yellCards): bool {
    foreach ($yellCards as $yc) {
        if (!isLiveTypeCard($yc)) {
            continue;
        }
        if (!empty($yc['yell_score_icon']) || ($yc['special_heart'] ?? '') === CARD_SPECIAL_HEART_SCORE) {
            return true;
        }
    }
    return false;
}

function spBp2CountYellGroupMembersNoBlade(array $yellCards, string $group): int {
    $n = 0;
    foreach ($yellCards as $yc) {
        if (($yc['card_type'] ?? '') !== 'メンバー') {
            continue;
        }
        if ($group !== '' && ($yc['group'] ?? '') !== $group) {
            continue;
        }
        if (!empty($yc['blade_hearts'])) {
            continue;
        }
        $n++;
    }
    return $n;
}

function spBp2MarkEffectAreaMove(array &$state, array $source): void {
    $grp = $source['group'] ?? '';
    if ($grp !== '') {
        $state['_effect_area_move_group'] = $grp;
    }
}

function spBp2ClearEffectAreaMove(array &$state): void {
    unset($state['_effect_area_move_group']);
}

function spBp2ApplyMovedByGroupEffect(array &$member, array $state): void {
    $grp = $state['_effect_area_move_group'] ?? '';
    if ($grp !== '') {
        $member['moved_by_group_effect'] = $grp;
    }
}

function spBp2RefreshLiveZoneScores(array &$state, string $pid): void {
    $p = &$state['players'][$pid];
    foreach ($p['live_zone'] as &$lc) {
        if (!$lc) {
            continue;
        }
        mergeCardCatalogFields($lc);
        if (!isset($lc['_printed_score'])) {
            $lc['_printed_score'] = intval($lc['score'] ?? 0);
        }
        $bonus = 0;
        foreach ($lc['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') {
                continue;
            }
            if (($ab['type'] ?? '') === 'live_score_if_stage_has_ability_members'
                && spBp2StageHasAbilityMember($p)) {
                $bonus += intval($ab['amount'] ?? 1);
            }
        }
        $lc['score'] = intval($lc['_printed_score']) + $bonus;
    }
    unset($lc);
}

function spBp2ApplyContinuousHearts(array $state, string $pid, array $member, array $ab, array $hearts): array {
    if (($ab['trigger'] ?? '') !== 'continuous') {
        return $hearts;
    }
    if (($ab['type'] ?? '') === 'hearts_if_active_energy') {
        if (countActiveEnergyInZone($state['players'][$pid] ?? []) >= intval($ab['min_active'] ?? 1)) {
            appendContinuousHeartsFromSpec($hearts, $ab['hearts'] ?? []);
        }
    }
    return $hearts;
}

function spBp2ApplyContinuousMemberCost(array $member, int $cost): int {
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') {
            continue;
        }
        if (($ab['type'] ?? '') === 'cost_per_stacked_group_member') {
            $group = $ab['group'] ?? 'Superstar';
            $n = 0;
            foreach ($member['stacked_members'] ?? [] as $stacked) {
                if ($stacked && cardMatchesGroup($stacked, $group, 'member')) {
                    $n++;
                }
            }
            $cost += $n * intval($ab['per_card'] ?? 1);
        }
    }
    return $cost;
}

function spBp2FindMemberSlot(array $p, string $instanceId): ?string {
    foreach (['center', 'left', 'right'] as $slot) {
        $mbr = $p['stage'][$slot] ?? null;
        if ($mbr && ($mbr['instance_id'] ?? '') === $instanceId) {
            return $slot;
        }
    }
    return null;
}

function spBp2SwapMemberSlots(array &$state, string $pid, string $holderId, string $toSlot): array {
    $p = &$state['players'][$pid];
    $fromSlot = spBp2FindMemberSlot($p, $holderId);
    if ($fromSlot === null || $fromSlot === $toSlot) {
        return $state;
    }
    $member = $p['stage'][$fromSlot] ?? null;
    $other = $p['stage'][$toSlot] ?? null;
    if (!$member) {
        return $state;
    }
    spBp2MarkEffectAreaMove($state, $member);
    $p['stage'][$toSlot] = $member;
    $p['stage'][$fromSlot] = $other;
    if ($other) {
        $other['moved_this_turn'] = true;
        $other['moved_from_slot'] = $toSlot;
        spBp2ApplyMovedByGroupEffect($other, $state);
        $p['stage'][$fromSlot] = $other;
        $state = spBp2OnMemberAreaMove($state, $pid, $other['instance_id'] ?? '', $toSlot, $fromSlot);
    }
    $member['moved_this_turn'] = true;
    $member['moved_from_slot'] = $fromSlot;
    $p['stage'][$toSlot] = $member;
    $state = spBp2OnMemberAreaMove($state, $pid, $holderId, $fromSlot, $toSlot);
    spBp2ClearEffectAreaMove($state);
    return $state;
}

function spBp2TriggerCenterMoveChoose(array $state, string $pid, array $movedMember, string $fromSlot): array {
    if ($fromSlot !== 'center' || !empty($state['pending_prompt'])) {
        return $state;
    }
    $p = &$state['players'][$pid];
    foreach ($p['stage'] as $slot => &$observer) {
        if (!$observer || ($observer['instance_id'] ?? '') === ($movedMember['instance_id'] ?? '')) {
            continue;
        }
        mergeCardCatalogFields($observer);
        foreach ($observer['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') {
                continue;
            }
            if (($ab['type'] ?? '') !== 'auto_on_center_move_choose') {
                continue;
            }
            if (!empty($ab['once_per_turn']) && isAbilityUsed($observer, $idx)) {
                continue;
            }
            if (spBp2StageMemberAbilitiesSuppressed($state, $pid)) {
                continue;
            }
            markAbilityUsed($observer, $idx);
            $p['stage'][$slot] = $observer;
            $mName = $observer['name_en'] ?? $observer['name'] ?? 'Member';
            $state['pending_prompt'] = [
                'type'          => 'spbp2_center_move_choose',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $observer['instance_id'] ?? '',
                'source_slot'   => $slot,
                'source_name'   => $mName,
                'moved_name'    => $movedMember['name_en'] ?? $movedMember['name'] ?? 'Member',
                'ability'       => $ab,
                'ability_index' => $idx,
                'prompt'        => 'Center Member moved — choose one effect:',
                'choices'       => ['heart', 'wait_opp', 'draw'],
                'choice_labels' => [
                    'Gain 1 heart until Live ends',
                    'Wait 1 opponent Member (≤2 printed Blade)',
                    'Draw 1 card',
                ],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$mName] Center moved — choose an effect.");
            return $state;
        }
    }
    unset($observer);
    return $state;
}

function spBp2TriggerMoveToCenterHeart(array $state, string $pid, array $movedMember, string $toSlot): array {
    if ($toSlot !== 'center' || !empty($state['pending_prompt'])) {
        return $state;
    }
    $subunit = $movedMember['subunit'] ?? '';
    if ($subunit === '') {
        return $state;
    }
    $p = &$state['players'][$pid];
    foreach ($p['stage'] as $slot => &$observer) {
        if (!$observer || ($observer['instance_id'] ?? '') === ($movedMember['instance_id'] ?? '')) {
            continue;
        }
        mergeCardCatalogFields($observer);
        foreach ($observer['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') {
                continue;
            }
            if (($ab['type'] ?? '') !== 'auto_on_move_to_center_subunit_heart') {
                continue;
            }
            if (($ab['subunit'] ?? '') !== '' && ($ab['subunit'] ?? '') !== $subunit) {
                continue;
            }
            if (spBp2StageMemberAbilitiesSuppressed($state, $pid)) {
                continue;
            }
            addBonusHeartsToModifier($state, $pid, $ab['hearts'] ?? [['color' => 'any', 'count' => 1]]);
            $mName = $observer['name_en'] ?? $observer['name'] ?? 'Member';
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$mName] gained bonus heart ($subunit moved to Center).");
        }
    }
    unset($observer);
    return $state;
}

function spBp2ResolveAutoStackUnder(
    array $state,
    string $pid,
    array &$member,
    string $slot,
    array $ab
): array {
    if (($ab['type'] ?? '') !== 'auto_stack_wr_group_member_under') {
        return $state;
    }
    if (!empty($state['pending_prompt'])) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $group = $ab['group'] ?? 'Superstar';
    $filter = $ab['filter'] ?? 'member';
    $candidates = array_values(array_filter(
        $p['waiting_room'],
        fn($c) => cardMatchesGroup($c, $group, $filter)
    ));
    if (empty($candidates)) {
        $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
        return addLog($state, $state['players'][$pid]['name'] .
            " — [$mName] no eligible $group card in Waiting Room to stack.");
    }
    $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
    $state['pending_prompt'] = [
        'type'        => 'spbp2_stack_wr_member',
        'owner'       => $pid,
        'responder'   => $pid,
        'source_id'   => $member['instance_id'] ?? '',
        'source_slot' => $slot,
        'source_name' => $mName,
        'candidates'  => array_map('cardPromptSummary', $candidates),
        'optional'    => !empty($ab['optional']),
        'prompt'      => "Stack 1 $group Member from your Waiting Room under $mName?",
        'ability'     => $ab,
    ];
    return addLog($state, $state['players'][$pid]['name'] .
        " — [$mName] may stack a WR Member underneath.");
}

function spBp2OnMemberAreaMove(
    array $state,
    string $pid,
    string $memberInstanceId,
    string $fromSlot,
    string $toSlot
): array {
    $p = &$state['players'][$pid];
    $moved = null;
    $movedSlot = null;
    foreach ($p['stage'] as $slot => $mbr) {
        if ($mbr && ($mbr['instance_id'] ?? '') === $memberInstanceId) {
            $moved = $mbr;
            $movedSlot = $slot;
            break;
        }
    }
    if (!$moved) {
        return $state;
    }

    $state = spBp2TriggerMoveToCenterHeart($state, $pid, $moved, $toSlot);
    if (!empty($state['pending_prompt'])) {
        return $state;
    }
    $state = spBp2TriggerCenterMoveChoose($state, $pid, $moved, $fromSlot);
    if (!empty($state['pending_prompt'])) {
        return $state;
    }

    foreach ($p['stage'] as $slot => &$member) {
        if (!$member || ($member['instance_id'] ?? '') !== $memberInstanceId) {
            continue;
        }
        foreach ($member['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') {
                continue;
            }
            if (($ab['type'] ?? '') !== 'auto_stack_wr_group_member_under') {
                continue;
            }
            if (spBp2StageMemberAbilitiesSuppressed($state, $pid)) {
                continue;
            }
            $state = spBp2ResolveAutoStackUnder($state, $pid, $member, $slot, $ab);
            if (!empty($state['pending_prompt'])) {
                $p['stage'][$slot] = $member;
                return $state;
            }
        }
        $p['stage'][$slot] = $member;
        break;
    }
    unset($member);

    spBp2RefreshLiveZoneScores($state, $pid);
    return $state;
}

function spBp2OnLiveSuccess(array $state, string $pid): array {
    if (spBp2StageMemberAbilitiesSuppressed($state, $pid)) {
        return $state;
    }
    $p = &$state['players'][$pid];
    foreach ($p['stage'] as $slot => &$member) {
        if (!$member) {
            continue;
        }
        foreach ($member['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') {
                continue;
            }
            if (($ab['type'] ?? '') !== 'auto_stack_wr_group_member_under') {
                continue;
            }
            $state = spBp2ResolveAutoStackUnder($state, $pid, $member, $slot, $ab);
            $p['stage'][$slot] = $member;
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
        }
    }
    unset($member);
    return $state;
}

function spBp2ResolveEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (!spBp2IsHandlerType($type)) {
        return $state;
    }
    if (isMemberCard($source) && spBp2StageMemberAbilitiesSuppressed($state, $pid)
        && $type !== 'inherit_stacked_group_abilities') {
        return $state;
    }

    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'score_if_moved_by_group_effect':
            $group = $ab['group'] ?? 'Superstar';
            $movedGrp = $source['moved_by_group_effect'] ?? '';
            if ($movedGrp === $group && !empty($source['moved_this_turn'])) {
                $state = initLiveModifiers($state);
                $amt = intval($ab['amount'] ?? 1);
                $state['live_modifiers'][$pid]['score_bonus'] =
                    intval($state['live_modifiers'][$pid]['score_bonus'] ?? 0) + $amt;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Live total score +$amt (moved by $group effect).");
            }
            break;

        case 'draw_if_live_zone_score_up_or_yell_score_icon':
            $yell = $ctx['yell_cards'] ?? $state['_last_yell_cards'] ?? [];
            $ok = spBp2LiveZoneHasScoreAbovePrinted($p)
                || spBp2YellHasScoreIconLive($yell);
            if ($ok) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Live zone / Yell condition).");
            }
            break;

        case 'stack_baton_wr_member_under':
            if (empty($source['entered_via_baton'])) {
                break;
            }
            $batonId = $source['baton_wr_member_id'] ?? '';
            if ($batonId === '') {
                break;
            }
            $slot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
            if ($slot === '' || empty($p['stage'][$slot])) {
                break;
            }
            $group = $ab['group'] ?? 'Superstar';
            $batonCard = null;
            foreach ($p['waiting_room'] as $c) {
                if (($c['instance_id'] ?? '') === $batonId
                    && cardMatchesGroup($c, $group, 'member')) {
                    $batonCard = $c;
                    break;
                }
            }
            if ($batonCard) {
                spBp2StackMemberUnder($p, $slot, $batonCard);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] stacked ' . cardDisplayName($batonCard) .
                    ' from Baton Touch under this Member.');
            }
            break;

        case 'inherit_stacked_group_abilities':
        case 'continuous_negate_stage_member_abilities':
        case 'cost_per_stacked_group_member':
        case 'hearts_if_active_energy':
        case 'live_score_if_stage_has_ability_members':
            spBp2RefreshLiveZoneScores($state, $pid);
            break;

        case 'score_per_yell_group_no_blade':
            $yell = $ctx['yell_cards'] ?? $state['_last_yell_cards'] ?? $p['yell_cards'] ?? [];
            if (empty($yell)) {
                break;
            }
            $cnt = spBp2CountYellGroupMembersNoBlade($yell, $ab['group'] ?? 'Superstar');
            $tiers = intdiv($cnt, intval($ab['per_count'] ?? 2));
            $amt = min($tiers * intval($ab['amount'] ?? 1), intval($ab['max_amount'] ?? 2));
            if ($amt > 0) {
                $state = initLiveModifiers($state);
                $state['live_modifiers'][$pid]['score_bonus'] =
                    intval($state['live_modifiers'][$pid]['score_bonus'] ?? 0) + $amt;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Live total score +$amt (Yell Members without Blade).");
            }
            break;

        case 'optional_wait_self_opp_heart_gap':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $group = $ab['group'] ?? 'Superstar';
            $selfCandidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) {
                    continue;
                }
                if (!cardMatchesGroup($mbr, $group, 'member')) {
                    continue;
                }
                $selfCandidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($selfCandidates)) {
                break;
            }
            $state['pending_prompt'] = [
                'type'            => 'spbp2_wait_self_opp_heart_gap',
                'step'            => 'confirm',
                'owner'           => $pid,
                'responder'       => $pid,
                'source_name'     => $name,
                'self_candidates' => $selfCandidates,
                'heart_gap'       => intval($ab['heart_gap'] ?? 2),
                'group'           => $group,
                'ability'         => $ab,
                'prompt'          => "Put 1 $group Member on your Stage into Wait to Wait an opponent Member with fewer printed hearts?",
                'choices'         => ['yes', 'no'],
                'choice_labels'   => ['Yes — choose Members', 'No — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Wait chain (choose).");
            break;

        case 'leave_stage_add_from_wr':
            $cfg = wrPickCfgForLeaveStageAbility($ab);
            $found = findActivatedAbilitySource($p, $source['instance_id'] ?? '');
            $slot = $found['slot'] ?? null;
            $zone = $found['zone'] ?? 'stage';
            $wrIndex = $found['wr_index'] ?? null;
            if ($zone === 'stage' && $slot !== null && !empty($p['stage'][$slot])) {
                $member2 = &$p['stage'][$slot];
            } elseif ($zone === 'waiting_room' && $wrIndex !== null && isset($p['waiting_room'][$wrIndex])) {
                $member2 = &$p['waiting_room'][$wrIndex];
            } elseif ($zone === 'hand' && isset($found['hand_index'], $p['hand'][$found['hand_index']])) {
                $member2 = &$p['hand'][$found['hand_index']];
            } else {
                $member2 = $found['card'];
            }
            $mName = $member2['name_en'] ?? $member2['name'] ?? 'Member';
            startPickWrToHandPrompt($state, $pid, $member2, $slot, 0, $ab, $cfg, true, $ab['count'] ?? 1);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $mName . '] choose a card from Waiting Room.');
            break;
        case 'auto_on_center_move_choose':
        case 'auto_on_move_to_center_subunit_heart':
        case 'auto_stack_wr_group_member_under':
            break;
    }
    return $state;
}

/** Live Start abilities that reference Yell cards resolve after Yell reveal. */
function spBp2ApplyDeferredYellLiveStartBonuses(array $state, string $pid, array $yellCards): array {
    if (spBp2StageMemberAbilitiesSuppressed($state, $pid)) {
        return $state;
    }
    $p = $state['players'][$pid] ?? [];
    foreach ($p['stage'] ?? [] as $member) {
        if (!$member) {
            continue;
        }
        mergeCardCatalogFields($member);
        foreach (getAbilitiesByTrigger($member, 'live_start') as $ab) {
            if (($ab['type'] ?? '') !== 'score_per_yell_group_no_blade') {
                continue;
            }
            $state = spBp2ResolveEffect($state, $pid, $member, $ab, [
                'phase'      => 'live_start',
                'yell_cards' => $yellCards,
            ]);
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
        }
    }
    foreach ($p['live_zone'] ?? [] as $live) {
        if (!$live) {
            continue;
        }
        mergeCardCatalogFields($live);
        foreach (getAbilitiesByTrigger($live, 'live_start') as $ab) {
            if (($ab['type'] ?? '') !== 'score_per_yell_group_no_blade') {
                continue;
            }
            $state = spBp2ResolveEffect($state, $pid, $live, $ab, [
                'phase'      => 'live_start',
                'yell_cards' => $yellCards,
            ]);
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
        }
    }
    return $state;
}

function spBp2ResolvePrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $type = $prompt['type'] ?? '';
    $ownerP = &$state['players'][$owner];

    if ($type === 'spbp2_stack_wr_member') {
        if ($choice === 'skip' || $choice === 'no') {
            unset($state['pending_prompt']);
            $state['seq']++;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — skipped stacking under ' . ($prompt['source_name'] ?? 'Member') . '.');
            return finishPromptEffects($state);
        }
        $pickId = $data['pick_id'] ?? $data['card_id'] ?? '';
        if ($pickId === '') {
            throw new Exception('Choose a card to stack');
        }
        $slot = $prompt['source_slot'] ?? '';
        $stacked = spBp2RemoveWrMemberById($ownerP, $pickId);
        if (!$stacked || $slot === '' || empty($ownerP['stage'][$slot])) {
            throw new Exception('Invalid stack target');
        }
        spBp2StackMemberUnder($ownerP, $slot, $stacked);
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — stacked ' . ($stacked['name_en'] ?? $stacked['name']) .
            ' under ' . ($prompt['source_name'] ?? 'Member') . '.');
        spBp2RefreshLiveZoneScores($state, $owner);
        return finishPromptEffects($state);
    }

    if ($type === 'spbp2_wait_self_opp_heart_gap') {
        $step = $prompt['step'] ?? 'confirm';
        if ($step === 'confirm') {
            if (!isset(['yes' => true, 'no' => true][$choice])) {
                throw new Exception('Invalid choice');
            }
            if ($choice === 'no') {
                unset($state['pending_prompt']);
                $state['seq']++;
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped Wait chain.');
                return finishPromptEffects($state);
            }
            $state['pending_prompt']['step'] = 'pick_self';
            $state['pending_prompt']['prompt'] = 'Choose 1 Member on your Stage to put into Wait.';
            $state['pending_prompt']['candidates'] = $prompt['self_candidates'] ?? [];
            unset($state['pending_prompt']['choices'], $state['pending_prompt']['choice_labels']);
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_self') {
            $slot = $data['slot'] ?? $choice;
            $mbr = $ownerP['stage'][$slot] ?? null;
            if (!$mbr) {
                throw new Exception('Choose a Member on your Stage');
            }
            $selfHearts = memberHeartCount($mbr);
            $gap = intval($prompt['heart_gap'] ?? 2);
            $maxOppHearts = max(0, $selfHearts - $gap);
            $opp = ($owner === 'p1') ? 'p2' : 'p1';
            $oppCandidates = [];
            foreach ($state['players'][$opp]['stage'] as $oSlot => $om) {
                if (!$om) {
                    continue;
                }
                if (memberHeartCount($om) > $maxOppHearts) {
                    continue;
                }
                $oppCandidates[] = array_merge(cardPromptSummary($om), ['slot' => $oSlot]);
            }
            waitMember($mbr, $state);
            $ownerP['stage'][$slot] = $mbr;
            if (empty($oppCandidates)) {
                unset($state['pending_prompt']);
                $state['seq']++;
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] Waited ' .
                    ($mbr['name_en'] ?? $mbr['name']) . '; no eligible opponent to Wait.');
                return finishPromptEffects($state);
            }
            $state['pending_prompt'] = [
                'type'        => 'spbp2_wait_self_opp_heart_gap',
                'step'        => 'pick_opp',
                'owner'       => $owner,
                'responder'   => $owner,
                'source_name' => $prompt['source_name'] ?? 'Member',
                'self_hearts' => $selfHearts,
                'candidates'  => $oppCandidates,
                'prompt'      => "Choose 1 opponent Member with ≤$maxOppHearts printed hearts to put into Wait.",
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_opp') {
            $slot = $data['slot'] ?? $choice;
            $opp = ($owner === 'p1') ? 'p2' : 'p1';
            $om = $state['players'][$opp]['stage'][$slot] ?? null;
            if (!$om) {
                throw new Exception('Choose an opponent Member');
            }
            waitMember($om, $state);
            $state['players'][$opp]['stage'][$slot] = $om;
            unset($state['pending_prompt']);
            $state['seq']++;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] Waited ' .
                ($om['name_en'] ?? $om['name']) . ' (heart gap).');
            return finishPromptEffects($state);
        }
        return null;
    }

    if ($type === 'spbp2_center_move_choose') {
        $valid = ['heart', 'wait_opp', 'draw'];
        if (!in_array($choice, $valid, true)) {
            throw new Exception('Invalid choice');
        }
        $holderId = $prompt['source_id'] ?? '';
        $holderSlot = $prompt['source_slot'] ?? '';
        $mName = $prompt['source_name'] ?? 'Member';
        if ($choice === 'heart') {
            addBonusHeartsToModifier($state, $owner, [['color' => 'any', 'count' => 1]]);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$mName] gained 1 heart (Center moved).");
        } elseif ($choice === 'wait_opp') {
            $opp = ($owner === 'p1') ? 'p2' : 'p1';
            $waited = waitOpponentStageByOriginalHearts($state, $opp, 2, 1, $owner);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$mName] put $waited opponent Member(s) into Wait (Center moved).");
        } else {
            $drawn = drawCardsForPlayer($state, $owner, 1);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$mName] drew $drawn (Center moved).");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($type === 'spbp2_discard_liella_choice') {
        $step = $prompt['step'] ?? 'pick_hand';
        $group = $prompt['group'] ?? 'Superstar';
        $slot = $prompt['source_slot'] ?? '';
        $abIdx = intval($prompt['ability_index'] ?? 0);
        $srcName = $prompt['source_name'] ?? 'Member';

        if ($step === 'pick_hand') {
            $cardId = $data['card_id'] ?? $choice;
            $discarded = null;
            foreach ($ownerP['hand'] as $i => $c) {
                if (($c['instance_id'] ?? '') !== $cardId) {
                    continue;
                }
                if (!cardMatchesGroup($c, $group, '')) {
                    throw new Exception("Must choose a $group card");
                }
                $discarded = $c;
                array_splice($ownerP['hand'], $i, 1);
                break;
            }
            if (!$discarded) {
                throw new Exception('Choose a card from hand');
            }
            $state = appendCardsToWaitingRoom($state, $owner, [$discarded]);
            $noBladeMember = ($discarded['card_type'] ?? '') === 'メンバー'
                && empty($discarded['blade_hearts']);
            $choices = ['energy', 'hearts'];
            $labels = [
                'Put 1 Energy from your Energy deck into Wait',
                'Grant 2 hearts of your choice to another Liella! Member on Stage',
            ];
            if ($noBladeMember) {
                $choices[] = 'both';
                $labels[] = 'Both — Energy into Wait and grant 2 hearts';
            }
            $state['pending_prompt'] = [
                'type'            => 'spbp2_discard_liella_choice',
                'step'            => 'choose',
                'owner'           => $owner,
                'responder'       => $owner,
                'source_id'       => $prompt['source_id'] ?? '',
                'source_slot'     => $slot,
                'ability_index'   => $abIdx,
                'source_name'     => $srcName,
                'group'           => $group,
                'no_blade_member' => $noBladeMember,
                'choices'         => $choices,
                'choice_labels'   => $labels,
                'prompt'          => 'Choose an effect:',
                'ability'         => $prompt['ability'] ?? [],
            ];
            $state['seq']++;
            return $state;
        }

        if ($step === 'choose') {
            $pick = $choice;
            if (!in_array($pick, $prompt['choices'] ?? [], true)) {
                throw new Exception('Invalid choice');
            }
            $doEnergy = $pick === 'energy' || $pick === 'both';
            $doHearts = $pick === 'hearts' || $pick === 'both';
            if ($doEnergy) {
                putEnergyFromDeckInWait($ownerP);
            }
            if ($doHearts) {
                $candidates = [];
                foreach ($ownerP['stage'] as $s => $mbr) {
                    if (!$mbr || ($mbr['instance_id'] ?? '') === ($prompt['source_id'] ?? '')) {
                        continue;
                    }
                    if (!cardMatchesGroup($mbr, $group, 'member')) {
                        continue;
                    }
                    $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $s]);
                }
                if (empty($candidates)) {
                    $state = addLog($state, $state['players'][$owner]['name'] .
                        " — [$srcName] no other $group Members on Stage for hearts.");
                } else {
                    $state['pending_prompt'] = [
                        'type'          => 'spbp2_discard_liella_choice',
                        'step'          => 'pick_member',
                        'owner'         => $owner,
                        'responder'     => $owner,
                        'source_id'     => $prompt['source_id'] ?? '',
                        'source_slot'   => $slot,
                        'ability_index' => $abIdx,
                        'source_name'   => $srcName,
                        'candidates'    => $candidates,
                        'did_energy'    => $doEnergy,
                        'prompt'        => "Choose 1 other $group Member to grant 2 hearts until this Live ends.",
                        'ability'       => $prompt['ability'] ?? [],
                    ];
                    $state['seq']++;
                    return $state;
                }
            }
            if ($slot !== '' && !empty($ownerP['stage'][$slot])) {
                markAbilityUsed($ownerP['stage'][$slot], $abIdx);
            }
            unset($state['pending_prompt']);
            $state['seq']++;
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$srcName] resolved activated discard effect.");
            return finishPromptEffects($state);
        }

        if ($step === 'pick_member') {
            $memberSlot = $data['slot'] ?? $choice;
            $mbr = $ownerP['stage'][$memberSlot] ?? null;
            if (!$mbr) {
                throw new Exception('Choose a Member on Stage');
            }
            if (!isset($mbr['bonus_hearts'])) {
                $mbr['bonus_hearts'] = [];
            }
            $mbr['bonus_hearts'][] = ['color' => 'any', 'count' => 2];
            $ownerP['stage'][$memberSlot] = $mbr;
            if ($slot !== '' && !empty($ownerP['stage'][$slot])) {
                markAbilityUsed($ownerP['stage'][$slot], $abIdx);
            }
            unset($state['pending_prompt']);
            $state['seq']++;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] granted 2 hearts to ' .
                ($mbr['name_en'] ?? $mbr['name']) . '.');
            return finishPromptEffects($state);
        }
    }

    return null;
}
