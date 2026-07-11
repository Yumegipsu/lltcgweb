<?php
/**
 * Liella! SD2 starter + batch 98 promo gap effect handlers.
 * Included by effects.php.
 */

function plSpSd2EffectTypes(): array {
    return [
        'continuous_blade_bonus',
        'blade_bonus_if_combined_stage_members',
        'draw_extra_if_moved_on_enter',
        'optional_swap_area_on_enter',
        'live_start_score_wild_if_success',
        'on_enter_blade_self_and_pick_group',
        'live_success_energy_wait_if_yell_group_count',
        'auto_yell_blade_if_group_count',
        'auto_yell_blade_if_group_count',
        'wait_opp_if_stage_hearts',
    ];
}

function plSpSd2IsEffectType(string $type): bool {
    return in_array($type, plSpSd2EffectTypes(), true);
}

function plSpSd2CountCombinedStageMembers(array $state): int {
    return countStageMembers($state['players']['p1'] ?? [])
        + countStageMembers($state['players']['p2'] ?? []);
}

function plSpSd2SumStageHearts(array $p): int {
    $total = 0;
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        foreach ($mbr['hearts'] ?? [] as $h) {
            $total += intval($h['count'] ?? 0);
        }
    }
    return $total;
}

function plSpSd2ApplyContinuousBlade(int $blade, array $member, array $state, string $pid, array $ab): int {
    $type = $ab['type'] ?? '';
    if (($ab['trigger'] ?? '') === 'continuous' && $type === 'continuous_blade_bonus') {
        if (!empty($ab['center_only'])) {
            $slot = findMemberSlot($state['players'][$pid], $member['instance_id'] ?? '');
            if ($slot !== 'center') {
                return $blade;
            }
        }
        $blade += intval($ab['amount'] ?? 1);
    }
    if (($ab['trigger'] ?? '') === 'continuous' && $type === 'blade_bonus_if_combined_stage_members') {
        if (empty($ab['hearts']) && plSpSd2CountCombinedStageMembers($state) >= intval($ab['min_count'] ?? 6)) {
            $blade += intval($ab['amount'] ?? 1);
        }
    }
    return $blade;
}

function plSpSd2ResolveAutoYell(array $state, string $pid, array $yellCards, array $ab): ?array {
    if (($ab['type'] ?? '') !== 'auto_yell_blade_if_group_count') {
        return null;
    }
    $cnt = count(array_filter(
        $yellCards,
        fn($c) => ($c['card_type'] ?? '') === 'メンバー'
            && cardMatchesGroup($c, $ab['group'] ?? '', 'member')
    ));
    if ($cnt < intval($ab['min_count'] ?? 3)) {
        return $state;
    }
    $state = applyModifierEffect($state, $pid, [
        'type'   => 'blade_bonus',
        'amount' => intval($ab['amount'] ?? 1),
    ]);
    $state = addLog($state, $state['players'][$pid]['name'] .
        ' — Yell: gained +' . intval($ab['amount'] ?? 1) . ' Blade until Live ends.');
    return $state;
}

function plSpSd2ResolveEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (!plSpSd2IsEffectType($type)) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'draw_extra_if_moved_on_enter':
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn.");
            if (!empty($source['moved_this_turn'])) {
                $extra = drawCardsForPlayer($state, $pid, intval($ab['bonus_draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $extra (moved this turn).");
            }
            break;

        case 'optional_swap_area_on_enter':
            if (!empty($state['pending_prompt'])) break;
            $mySlot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
            $slots = [];
            foreach (['center', 'left', 'right'] as $s) {
                if ($s !== $mySlot) $slots[] = $s;
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_swap_area_on_enter',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_slot'   => $mySlot,
                'source_name'   => $name,
                'choices'       => array_merge(['skip'], $slots),
                'choice_labels' => array_merge(
                    ['Skip — no move'],
                    array_map(fn($s) => ucfirst($s) . ' area', $slots)
                ),
                'prompt'        => 'Move this Member to another area (swap if occupied)?',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional position change (choose).");
            break;

        case 'live_start_score_wild_if_success':
            if (count($p['success_lives'] ?? []) < intval($ab['min_success'] ?? 2)) break;
            bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['score_bonus'] ?? 5));
            foreach ($p['live_zone'] as &$lc) {
                if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $override = $ab['required_hearts_override'] ?? null;
                    if (is_array($override) && !empty($override)) {
                        $lc['required_hearts'] = $override;
                    } else {
                        $lc['required_hearts'] = [['color' => 'any', 'count' => 1]];
                    }
                    break;
                }
            }
            unset($lc);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] score +' . intval($ab['score_bonus'] ?? 5) .
                '; Required Hearts updated.');
            break;

        case 'on_enter_blade_self_and_pick_group':
            if (countEnergyInZone($p) < intval($ab['min_energy'] ?? 7)) break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                if (($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) continue;
                if (($ab['group'] ?? '') !== '' && ($mbr['group'] ?? '') !== ($ab['group'] ?? '')) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            $amt = intval($ab['amount'] ?? 1);
            $selfSlot = findMemberSlot($p, $source['instance_id'] ?? '');
            if ($selfSlot !== null && !empty($p['stage'][$selfSlot])) {
                $p['stage'][$selfSlot]['live_blade_bonus'] = intval($p['stage'][$selfSlot]['live_blade_bonus'] ?? 0) + $amt;
            }
            if (empty($candidates)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] +$amt Blade on self (7+ Energy).");
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'on_enter_blade_self_and_pick_group',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => $candidates,
                'amount'        => $amt,
                'prompt'        => 'Choose 1 other Liella! Member to gain +' . $amt . ' Blade until this Live ends.',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] +$amt Blade on self; choose another Liella! Member.");
            break;

        case 'live_success_energy_wait_if_yell_group_count':
            $pool = $ctx['yell_cards'] ?? $state['_last_yell_cards'] ?? [];
            $cnt = count(array_filter(
                $pool,
                fn($c) => cardMatchesGroup($c, $ab['group'] ?? 'Superstar', $ab['filter'] ?? '')
            ));
            if ($cnt < intval($ab['min_count'] ?? 7)) break;
            if (putEnergyFromDeckInWait($p)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put 1 Energy from Energy deck into Wait (7+ Liella! in Yell).');
            }
            break;

        case 'wait_opp_if_stage_hearts':
            if (plSpSd2SumStageHearts($p) < intval($ab['min_stage_hearts'] ?? 5)) break;
            $state = resolveAbilityEffect($state, $pid, $source, [
                'trigger'    => 'on_enter',
                'type'       => 'wait_opponent_stage_max_cost',
                'max_cost'   => intval($ab['max_cost'] ?? 2),
                'pick_count' => intval($ab['pick_count'] ?? 1),
            ], $ctx);
            break;
    }

    return $state;
}

function plSpSd2ResolvePrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $type = $prompt['type'] ?? '';
    if ($type === 'optional_swap_area_on_enter') {
        $allowed = $prompt['choices'] ?? ['skip', 'left', 'center', 'right'];
        if ($choice === 'no' || $choice === 'cancel') {
            $choice = 'skip';
        }
        if ($choice === 'skip') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped position change.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        if (!in_array($choice, $allowed, true)) {
            throw new Exception('Choose Skip or a valid Stage area');
        }
        $p = &$state['players'][$owner];
        $fromSlot = $prompt['source_slot'] ?? '';
        $toSlot = $choice;
        if ($fromSlot === '' || !in_array($toSlot, ['left', 'center', 'right'], true) || $fromSlot === $toSlot) {
            throw new Exception('Invalid position change');
        }
        $fromM = $p['stage'][$fromSlot] ?? null;
        if (!$fromM) {
            throw new Exception('Member not on Stage');
        }
        $toM = $p['stage'][$toSlot] ?? null;
        $p['stage'][$toSlot] = $fromM;
        $p['stage'][$fromSlot] = $toM;
        $fromM['moved_this_turn'] = true;
        $fromM['moved_from_slot'] = $fromSlot;
        $p['stage'][$toSlot] = $fromM;
        if ($toM) {
            $toM['moved_this_turn'] = true;
            $toM['moved_from_slot'] = $toSlot;
            $p['stage'][$fromSlot] = $toM;
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] moved $fromSlot → $toSlot.");
        unset($state['pending_prompt']);
        $fromId = $fromM['instance_id'] ?? '';
        if ($fromId !== '') {
            $state = resolveAutoAreaMoveAbilities($state, $owner, $fromId, $fromSlot);
        }
        if (empty($state['pending_prompt']) && $toM) {
            $toId = $toM['instance_id'] ?? '';
            if ($toId !== '') {
                $state = resolveAutoAreaMoveAbilities($state, $owner, $toId, $toSlot);
            }
        }
        $state['seq']++;
        return finishPromptEffects($state);
    }
    if ($type === 'on_enter_blade_self_and_pick_group') {
        $slot = $data['slot'] ?? '';
        $p = &$state['players'][$owner];
        if ($slot !== '' && !empty($p['stage'][$slot])) {
            $amt = intval($prompt['amount'] ?? 1);
            $p['stage'][$slot]['live_blade_bonus'] = intval($p['stage'][$slot]['live_blade_bonus'] ?? 0) + $amt;
            $mName = $p['stage'][$slot]['name_en'] ?? $p['stage'][$slot]['name'] ?? 'Member';
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$mName] gained +$amt Blade until Live ends.");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }
    return null;
}
