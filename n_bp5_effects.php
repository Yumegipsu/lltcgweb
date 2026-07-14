<?php
/**
 * Nijigasaki bp5 effect handlers.
 * Included by effects.php.
 */

function nBp5EffectTypes(): array {
    return [
        'auto_yell_distinct_blade_heart_milestones',
        'live_score_if_most_hearts_member',
        'activated_discard_pay_wr_live_score',
        'optional_wait_self_wait_opp_exact_blade',
        'on_leave_baton_incoming_bonus',
        'skip_active_phase_self',
        'live_success_wait_self_if_other_stage',
        'live_start_if_equal_success_live_count',
        'live_success_draw_discard_if_surplus',
        'activated_stack_energy_activate',
        'optional_wait_discard_look_reveal_group',
        'live_success_surplus_heart_score_swing',
        'on_enter_wr_live_distinct_choice',
        'activated_stack_energy_draw_heart',
        'live_success_energy_wait_stacked_plus',
        'live_start_heart_if_stacked_on_stage',
        'activated_pay_discard_add_wr_live',
        'live_start_blade_if_all_six_hearts_stage',
        'mill_optional_wr_live_deck_fourth',
        'live_start_score_if_all_six_hearts_stage',
        'live_success_add_wr_if_live_score',
        'live_start_score_if_success_zone_and_names',
        'live_start_yellow_heart_member_buff',
        'live_start_reveal_pick_named_hearts',
        'auto_grant_wild_on_member_live_start',
        'auto_draw_on_member_live_success',
    ];
}

function nBp5IsEffectType(string $type): bool {
    return in_array($type, nBp5EffectTypes(), true);
}

function nBp5CountDistinctBladeHeartTypes(array $yellCards): int {
    $types = [];
    foreach ($yellCards as $yc) {
        foreach ($yc['blade_hearts'] ?? [] as $bh) {
            if ($bh === 'draw') continue;
            $types[$bh] = true;
        }
    }
    return count($types);
}

function nBp5StageHasAllSixHeartColors(array $p): bool {
    $colors = ['pink', 'red', 'yellow', 'green', 'blue', 'purple'];
    $found = [];
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        foreach ($mbr['hearts'] ?? [] as $h) {
            $c = $h['color'] ?? '';
            if ($c === 'any' || $c === 'wild') {
                foreach ($colors as $col) {
                    $found[$col] = true;
                }
                continue;
            }
            if (in_array($c, $colors, true)) {
                $found[$c] = true;
            }
        }
        foreach ($mbr['bonus_hearts'] ?? [] as $c) {
            if (in_array($c, $colors, true)) {
                $found[$c] = true;
            }
        }
    }
    return count($found) >= 6;
}

function nBp5MemberHasMostHearts(array $state, string $pid, array $member): bool {
    $selfCount = memberHeartCount($member);
    $selfId = $member['instance_id'] ?? '';
    foreach (['p1', 'p2'] as $checkPid) {
        foreach ($state['players'][$checkPid]['stage'] ?? [] as $mbr) {
            if (!$mbr) continue;
            if (($mbr['instance_id'] ?? '') === $selfId) continue;
            if (memberHeartCount($mbr) > $selfCount) {
                return false;
            }
        }
    }
    foreach (['p1', 'p2'] as $checkPid) {
        foreach ($state['players'][$checkPid]['stage'] ?? [] as $mbr) {
            if (!$mbr || ($mbr['instance_id'] ?? '') === $selfId) continue;
            if (memberHeartCount($mbr) === $selfCount) {
                return false;
            }
        }
    }
    return $selfCount > 0;
}

function nBp5CountSuccessLiveCards(array $p): int {
    return count($p['success_lives'] ?? []);
}

function nBp5StageHasStackedEnergy(array $p): bool {
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        if (countMemberStackedEnergy($p, $mbr) > 0) {
            return true;
        }
    }
    return false;
}

function nBp5CountWrDistinctLiveNames(array $p): int {
    $names = [];
    foreach ($p['waiting_room'] as $c) {
        if (($c['card_type'] ?? '') !== 'ライブ') continue;
        $n = $c['name_en'] ?? $c['name'] ?? '';
        if ($n !== '') $names[$n] = true;
    }
    return count($names);
}

function nBp5CountWrDistinctLiveGroups(array $p): int {
    $groups = [];
    foreach ($p['waiting_room'] as $c) {
        if (($c['card_type'] ?? '') !== 'ライブ') continue;
        $g = $c['group'] ?? '';
        if ($g !== '') $groups[$g] = true;
    }
    return count($groups);
}

function nBp5ApplyContinuousLiveScore(array $state, string $pid, array $member, array $ab): int {
    if (($ab['type'] ?? '') === 'live_score_if_most_hearts_member') {
        if (nBp5MemberHasMostHearts($state, $pid, $member)) {
            return intval($ab['amount'] ?? 1);
        }
    }
    return 0;
}

function nBp5MemberSkipsActivePhase(array $member): bool {
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') === 'continuous' && ($ab['type'] ?? '') === 'skip_active_phase_self') {
            return true;
        }
    }
    return false;
}

function nBp5ResolveEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (!nBp5IsEffectType($type)) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'auto_yell_distinct_blade_heart_milestones':
            $yellCards = $ctx['yell_cards'] ?? [];
            $distinct = nBp5CountDistinctBladeHeartTypes($yellCards);
            if ($distinct >= 3 && !empty($ab['min_types_3']['hearts'])) {
                addBonusHeartsToModifier($state, $pid, $ab['min_types_3']['hearts']);
            }
            if ($distinct >= 6 && !empty($ab['min_types_6'])) {
                $state = resolveAbilityEffect($state, $pid, $source, $ab['min_types_6'], $ctx);
            }
            if ($distinct >= 3) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Yell blade-heart milestones ($distinct types).");
            }
            break;

        case 'optional_wait_self_wait_opp_exact_blade':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'bp5_wait_self_opp_exact_blade',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'exact_blade'   => intval($ab['exact_blade'] ?? 4),
                'pick_count'    => intval($ab['pick_count'] ?? 1),
                'prompt'        => 'You may put this Member into Wait: put 1 opponent Stage Member with exactly ' .
                    intval($ab['exact_blade'] ?? 4) . ' Blade into Wait?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Wait both', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Wait / opponent Wait.");
            break;

        case 'on_leave_baton_incoming_bonus':
            $incoming = $ctx['baton_incoming'] ?? null;
            if (!$incoming || !cardMatchesGroup($incoming, $ab['group'] ?? '', 'member')) break;
            if (intval($incoming['cost'] ?? 0) < intval($ab['min_cost'] ?? 10)) break;
            if (!empty($ab['require_no_blade_heart']) && !empty($incoming['blade_hearts'])) break;
            $activated = activateEnergyForPlayer($p, intval($ab['activate_energy'] ?? 2));
            $drawn = 0;
            if (intval($incoming['cost'] ?? 0) >= intval($ab['draw_if_cost'] ?? 15)) {
                $drawn = drawCardsForPlayer($state, $pid, 1);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Baton leave: activated $activated Energy" .
                ($drawn > 0 ? ", drew $drawn." : '.'));
            break;

        case 'live_success_wait_self_if_other_stage':
            if (!stageHasOtherMember($p, $source['instance_id'] ?? '')) break;
            foreach ($p['stage'] as $slot => &$mbr) {
                if (!$mbr || ($mbr['instance_id'] ?? '') !== ($source['instance_id'] ?? '')) continue;
                waitMember($mbr, $state);
                $p['stage'][$slot] = $mbr;
                break;
            }
            unset($mbr);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] put self into Wait (other Members on Stage).");
            break;

        case 'live_start_if_equal_success_live_count':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (nBp5CountSuccessLiveCards($p) !== nBp5CountSuccessLiveCards($state['players'][$opp])) break;
            if (!empty($ab['hearts'])) {
                addBonusHeartsToModifier($state, $pid, $ab['hearts']);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] equal Success Live counts; bonus hearts until Live ends.");
            break;

        case 'live_success_draw_discard_if_surplus':
            if (intval($ctx['excess_hearts'] ?? 0) < intval($ab['min_surplus_hearts'] ?? 1)) break;
            $state = applyDrawThenDiscard(
                $state,
                $pid,
                $p,
                $name,
                intval($ab['draw'] ?? 2),
                intval($ab['discard'] ?? 1)
            );
            break;

        case 'live_success_surplus_heart_score_swing':
            $excess = intval($ctx['excess_hearts'] ?? 0);
            $bonus = 0;
            if ($excess === 0) {
                $bonus = intval($ab['bonus_no_surplus'] ?? 1);
            } elseif ($excess >= intval($ab['penalty_min_surplus'] ?? 2)) {
                $bonus = -intval($ab['penalty_amount'] ?? 1);
            }
            if ($bonus !== 0) {
                $state = initLiveModifiers($state);
                $state['live_modifiers'][$pid]['score_bonus'] =
                    intval($state['live_modifiers'][$pid]['score_bonus'] ?? 0) + $bonus;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Live total score " . ($bonus > 0 ? '+' : '') . "$bonus (surplus hearts).");
            }
            break;

        case 'live_start_heart_if_stacked_on_stage':
            if (!nBp5StageHasStackedEnergy($p)) break;
            if (!empty($ab['hearts'])) {
                addBonusHeartsToModifier($state, $pid, $ab['hearts']);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] pink heart (stacked Energy on Stage).");
            break;

        case 'live_start_blade_if_all_six_hearts_stage':
        case 'live_start_score_if_all_six_hearts_stage':
            if (!nBp5StageHasAllSixHeartColors($p)) break;
            if ($type === 'live_start_blade_if_all_six_hearts_stage') {
                $state = initLiveModifiers($state);
                $state['live_modifiers'][$pid]['blade_bonus'] =
                    intval($state['live_modifiers'][$pid]['blade_bonus'] ?? 0) + intval($ab['amount'] ?? 2);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] +' . intval($ab['amount'] ?? 2) . ' Blade (all heart colors on Stage).');
            } else {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (all heart colors on Stage).');
            }
            break;

        case 'live_start_score_if_success_zone_and_names':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $successCount = nBp5CountSuccessLiveCards($p) + nBp5CountSuccessLiveCards($state['players'][$opp]);
            if ($successCount < intval($ab['min_success_cards'] ?? 2)) break;
            if (countDistinctNamedGroupOnStage($p, '', 'member') < intval($ab['min_distinct_names'] ?? 3)) break;
            bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . '.');
            break;

        case 'live_start_yellow_heart_member_buff':
            $color = $ab['color'] ?? 'yellow';
            $minHearts = intval($ab['min_hearts'] ?? 4);
            $found = false;
            foreach ($p['stage'] as $mbr) {
                if (!$mbr) continue;
                $cnt = 0;
                foreach ($mbr['hearts'] ?? [] as $h) {
                    if (($h['color'] ?? '') === $color) $cnt += intval($h['count'] ?? 1);
                }
                foreach ($mbr['bonus_hearts'] ?? [] as $c) {
                    if ($c === $color) $cnt++;
                }
                if ($cnt >= $minHearts) {
                    $found = true;
                    break;
                }
            }
            if (!$found) break;
            bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['score_bonus'] ?? 2));
            foreach ($p['live_zone'] as &$lc) {
                if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $lc['required_hearts'] = $ab['required_hearts'] ?? [];
                    break;
                }
            }
            unset($lc);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] score +' . intval($ab['score_bonus'] ?? 2) . ', required hearts modified.');
            break;

        case 'live_success_add_wr_if_live_score':
            $liveScore = 0;
            foreach ($p['live_zone'] as $lc) {
                if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $liveScore = intval($lc['score'] ?? 0) + intval($lc['live_score_bonus'] ?? 0);
                    break;
                }
            }
            if ($liveScore < intval($ab['min_score'] ?? 3)) break;
            $added = addFromWaitingRoomFiltered(
                $p,
                $ab['group'] ?? 'Nijigasaki',
                '',
                intval($ab['count'] ?? 1)
            );
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $added card(s) from Waiting Room.");
            }
            break;

        case 'live_success_energy_wait_stacked_plus':
            if (getLiveTotalScore($state, $pid) <= getLiveTotalScore($state, ($pid === 'p1') ? 'p2' : 'p1')) break;
            $stacked = countMemberStackedEnergy($p, $source);
            $count = $stacked + 1;
            $placed = 0;
            for ($i = 0; $i < $count; $i++) {
                if (putEnergyFromDeckInWait($p, $state, $pid)) $placed++;
            }
            if ($placed > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put $placed Energy into Wait (stacked +1).");
            }
            break;

        case 'mill_optional_wr_live_deck_fourth':
            $mill = intval($ab['mill'] ?? 2);
            for ($i = 0; $i < $mill; $i++) {
                if (empty($p['main_deck'])) break;
                $p['waiting_room'][] = array_shift($p['main_deck']);
            }
            $lives = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if (empty($lives)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] milled $mill; no Live in Waiting Room.");
                break;
            }
            if (count($lives) === 1) {
                $pick = $lives[0];
                $pos = intval($ab['deck_position'] ?? 4);
                $p['waiting_room'] = array_values(array_filter(
                    $p['waiting_room'],
                    fn($c) => ($c['instance_id'] ?? '') !== ($pick['instance_id'] ?? '')
                ));
                $top = array_splice($p['main_deck'], 0, max(0, $pos - 1));
                $p['main_deck'] = array_merge($top, [$pick], $p['main_deck']);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] placed Live at deck position $pos.");
                break;
            }
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'bp5_wr_live_deck_position',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $lives),
                'deck_position' => intval($ab['deck_position'] ?? 4),
                'prompt'        => 'Choose 1 Live from your Waiting Room to place as the 4th card from the top of your deck.',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] milled $mill; choose Live for deck.");
            break;

        case 'on_enter_wr_live_distinct_choice':
            $byName = nBp5CountWrDistinctLiveNames($p) >= intval($ab['min_distinct_names'] ?? 3);
            $byGroup = nBp5CountWrDistinctLiveGroups($p) >= intval($ab['min_distinct_groups'] ?? 3);
            if (!$byName && !$byGroup) break;
            if ($byName && !$byGroup) {
                $added = addFromWaitingRoomFiltered($p, $ab['group'] ?? '', 'live', intval($ab['count_by_name'] ?? 1));
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added $added Live (distinct names).");
                }
                break;
            }
            if ($byGroup && !$byName) {
                $added = addFromWaitingRoomFiltered($p, $ab['group'] ?? '', 'live', intval($ab['count_by_group'] ?? 2));
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added $added Live (distinct groups).");
                }
                break;
            }
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'bp5_wr_live_distinct_choice',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Choose an effect:',
                'choices'       => ['by_name', 'by_group'],
                'choice_labels' => [
                    'Add 1 Live (3+ different names in WR)',
                    'Add 2 Live (3+ different groups in WR)',
                ],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] choose WR Live effect.");
            break;

        case 'optional_wait_discard_look_reveal_group':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'bp5_wait_discard_look_reveal',
                'step'          => 'confirm',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_slot'   => $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? ''),
                'source_name'   => $name,
                'prompt'        => 'Put this Member into Wait and discard 1: look at the top ' .
                    intval($ab['look'] ?? 5) . ' cards; you may reveal 1 ' .
                    ($ab['group'] ?? 'Nijigasaki') . ' Member cost ' . intval($ab['min_cost'] ?? 9) . '+?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Wait / surveil.");
            break;

        case 'live_start_reveal_pick_named_hearts':
            if (!stageHasNamedMember($p, $ab['names'] ?? [])) break;
            $look = intval($ab['look'] ?? 4);
            $revealed = [];
            for ($i = 0; $i < $look; $i++) {
                if (empty($p['main_deck'])) break;
                $revealed[] = array_shift($p['main_deck']);
            }
            $matches = array_values(array_filter(
                $revealed,
                fn($c) => cardMatchesNames($c, $ab['target_names'] ?? [])
            ));
            if (count($matches) === 1) {
                $pick = $matches[0];
                $hearts = $pick['hearts'] ?? [];
                foreach ($p['stage'] as $slot => &$mbr) {
                    if (!$mbr || !cardMatchesNames($mbr, $ab['target_names'] ?? [])) continue;
                    foreach ($hearts as $h) {
                        $c = $h['color'] ?? '';
                        for ($i = 0; $i < intval($h['count'] ?? 1); $i++) {
                            $mbr['bonus_hearts'][] = $c;
                        }
                    }
                    $p['stage'][$slot] = $mbr;
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] Kasumi gained hearts from revealed card.');
                    break;
                }
            } elseif (count($matches) > 1 && !empty($state['pending_prompt'])) {
                break;
            } elseif (count($matches) > 1) {
                $state['pending_prompt'] = [
                    'type'          => 'bp5_pick_kasumi_reveal',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_name'   => $name,
                    'candidates'    => array_map('cardPromptSummary', $matches),
                    'revealed_rest' => array_map('cardPromptSummary', array_values(array_filter(
                        $revealed,
                        fn($c) => !in_array($c['instance_id'] ?? '', array_map(fn($m) => $m['instance_id'] ?? '', $matches), true)
                    ))),
                    'target_names'  => $ab['target_names'] ?? [],
                    'prompt'        => 'Choose 1 Kasumi Nakasu card revealed.',
                    'ability'       => $ab,
                ];
                $p['waiting_room'] = array_merge($p['waiting_room'], $revealed);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] revealed $look; choose Kasumi.");
                break;
            }
            if (count($matches) === 0 && !empty($revealed) && empty($state['pending_prompt'])) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $revealed);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] revealed $look; no matching Kasumi card found.");
            } elseif (!empty($revealed) && empty($state['pending_prompt'])) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $revealed);
            }
            break;
    }
    return $state;
}

function nBp5ResolveActivatedAbility(
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
    $name = $member['name_en'] ?? $member['name'] ?? 'Member';

    if ($type === 'activated_discard_pay_wr_live_score') {
        $liveId = $data['wr_live_id'] ?? '';
        $discardIds = $data['discard_ids'] ?? [];
        if (count($discardIds) !== intval($ab['discard'] ?? 1)) {
            throw new Exception('Must discard exactly ' . intval($ab['discard'] ?? 1) . ' card(s)');
        }
        $liveCard = null;
        foreach ($p['waiting_room'] as $c) {
            if (($c['instance_id'] ?? '') === $liveId && ($c['card_type'] ?? '') === 'ライブ') {
                $liveCard = $c;
                break;
            }
        }
        if (!$liveCard) throw new Exception('Choose a Live from Waiting Room');
        $payScore = intval($liveCard['score'] ?? 0);
        if (!payEnergyCost($p, $payScore)) {
            throw new Exception("Need $payScore active Energy (Live score)");
        }
        discardFromHandByIds($p, $discardIds);
        $p['waiting_room'] = array_values(array_filter(
            $p['waiting_room'],
            fn($c) => ($c['instance_id'] ?? '') !== $liveId
        ));
        $p['hand'][] = $liveCard;
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] paid $payScore Energy, discarded, added Live to hand.");
        return $state;
    }

    if ($type === 'activated_stack_energy_activate') {
        nijiStackEnergyUnderMember($p, $member, intval($ab['energy'] ?? 1));
        $activated = activateEnergyForPlayer($p, intval($ab['activate_count'] ?? 2));
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] stacked Energy; activated $activated Energy.");
        return $state;
    }

    if ($type === 'activated_stack_energy_draw_heart') {
        nijiStackEnergyUnderMember($p, $member, intval($ab['energy'] ?? 1));
        drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
        if (!empty($ab['hearts'])) {
            addBonusHeartsToModifier($state, $pid, $ab['hearts']);
        }
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] stacked Energy, drew 1, gained pink heart.");
        return $state;
    }

    if ($type === 'activated_pay_discard_add_wr_live') {
        $cost = intval($ab['cost'] ?? 2);
        if (!payEnergyCost($p, $cost)) throw new Exception("Need $cost active Energy");
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== intval($ab['discard'] ?? 1)) {
            throw new Exception('Must discard exactly ' . intval($ab['discard'] ?? 1) . ' card(s)');
        }
        discardFromHandByIds($p, $ids);
        startPickWrToHandPrompt(
            $state,
            $pid,
            $member,
            $slot,
            $abilityIdx,
            $ab,
            wrPickCfgFromAbility($ab)
        );
        if (empty($ab['once_per_turn'])) {
            markAbilityUsed($member, $abilityIdx);
        }
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] paid $cost Energy, discarded; choose a Live from Waiting Room.");
        return $state;
    }

    return null;
}

function nBp5ResolveAutoOnMemberAbility(
    array $state,
    string $pid,
    array &$member,
    $slot,
    array $ab,
    int $idx,
    array $ctx = []
): array {
    $type = $ab['type'] ?? '';
    $mName = $member['name_en'] ?? $member['name'] ?? 'Member';

    if ($type === 'auto_grant_wild_on_member_live_start') {
        $resolvedMemberId = $ctx['resolved_member_id'] ?? '';
        if ($resolvedMemberId === '' || $resolvedMemberId === ($member['instance_id'] ?? '')) {
            return $state;
        }
        $p = &$state['players'][$pid];
        $target = null;
        $targetSlot = '';
        foreach ($p['stage'] as $s => $mbr) {
            if ($mbr && ($mbr['instance_id'] ?? '') === $resolvedMemberId) {
                $target = $mbr;
                $targetSlot = $s;
                break;
            }
        }
        if (!$target || memberHasWildHeart($target)) {
            return $state;
        }
        $target['bonus_hearts'][] = 'any';
        $p['stage'][$targetSlot] = $target;
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$mName] granted wild heart to Stage Member (Live Start resolved).");
        return $state;
    }

    if ($type === 'auto_draw_on_member_live_success') {
        $resolvedMemberId = $ctx['resolved_member_id'] ?? '';
        if ($resolvedMemberId === '' || $resolvedMemberId === ($member['instance_id'] ?? '')) {
            return $state;
        }
        $p = &$state['players'][$pid];
        $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$mName] drew $drawn (Stage Member Live Success resolved).");
        return $state;
    }

    return $state;
}

function memberHasWildHeart(array $member): bool {
    foreach ($member['hearts'] ?? [] as $h) {
        $c = $h['color'] ?? '';
        if ($c === 'any' || $c === 'wild') return true;
    }
    foreach ($member['bonus_hearts'] ?? [] as $c) {
        if ($c === 'any' || $c === 'wild') return true;
    }
    return false;
}

function nBp5ResolvePrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $promptType = $prompt['type'] ?? '';
    $ownerP = &$state['players'][$owner];
    $ability = $prompt['ability'] ?? [];

    if ($promptType === 'bp5_wait_self_opp_exact_blade') {
        if ($choice === 'no') {
            unset($state['pending_prompt']);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional Wait effect.');
            $state['seq']++;
            return finishPromptEffects($state);
        }
        if ($choice !== 'yes') {
            throw new Exception('Invalid choice');
        }
        $opp = ($owner === 'p1') ? 'p2' : 'p1';
        $exact = intval($prompt['exact_blade'] ?? 4);
        $pickCount = intval($prompt['pick_count'] ?? 1);
        $oppSlots = [];
        foreach ($state['players'][$opp]['stage'] as $slot => $mbr) {
            if (!$mbr) {
                continue;
            }
            if (intval($mbr['blade'] ?? 0) !== $exact) {
                continue;
            }
            $oppSlots[] = $slot;
            if (count($oppSlots) >= $pickCount) {
                break;
            }
        }
        if ($oppSlots === []) {
            unset($state['pending_prompt']);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') .
                "] no opponent with exactly $exact Blade; effect skipped.");
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $srcId = $prompt['source_id'] ?? '';
        $slot = findMemberSlot($ownerP, $srcId);
        if ($slot !== null && !empty($ownerP['stage'][$slot])) {
            waitMember($ownerP['stage'][$slot], $state);
        }
        $waited = 0;
        foreach ($oppSlots as $oppSlot) {
            $mbr = &$state['players'][$opp]['stage'][$oppSlot];
            if (!$mbr) {
                continue;
            }
            waitMember($mbr, $state);
            $waited++;
        }
        unset($mbr);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] Waited self; $waited opponent Member(s) Waited.");
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'bp5_wr_live_distinct_choice') {
        $ab = $ability;
        if ($choice === 'by_name') {
            addFromWaitingRoomFiltered($ownerP, $ab['group'] ?? '', 'live', intval($ab['count_by_name'] ?? 1));
        } else {
            addFromWaitingRoomFiltered($ownerP, $ab['group'] ?? '', 'live', intval($ab['count_by_group'] ?? 2));
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] added Live from WR.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'bp5_wr_live_deck_position') {
        $cardId = $data['card_id'] ?? '';
        $pos = intval($prompt['deck_position'] ?? 4);
        foreach ($ownerP['waiting_room'] as $i => $c) {
            if (($c['instance_id'] ?? '') !== $cardId) continue;
            $pick = $c;
            array_splice($ownerP['waiting_room'], $i, 1);
            $top = array_splice($ownerP['main_deck'], 0, max(0, $pos - 1));
            $ownerP['main_deck'] = array_merge($top, [$pick], $ownerP['main_deck']);
            break;
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] placed Live at deck position $pos.");
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'bp5_wait_discard_look_reveal') {
        $step = $prompt['step'] ?? 'confirm';
        if ($step === 'confirm') {
            if ($choice === 'no') {
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $slot = $prompt['source_slot'] ?? '';
            if ($slot !== '' && !empty($ownerP['stage'][$slot])) {
                waitMember($ownerP['stage'][$slot], $state);
            }
            $state['pending_prompt'] = [
                'type'          => 'bp5_wait_discard_look_reveal',
                'step'          => 'discard',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'ability'       => $ability,
                'discard_count' => intval($ability['discard'] ?? 1),
                'prompt'        => 'Discard 1 card from your hand.',
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'discard') {
            $need = intval($prompt['discard_count'] ?? $ability['discard'] ?? 1);
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== $need) {
                unset($state['pending_prompt']);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] could not discard; effect skipped.');
                $state['seq']++;
                return finishPromptEffects($state);
            }
            discardFromHandByIds($ownerP, $ids);
            $look = intval($ability['look'] ?? 5);
            $top = array_splice($ownerP['main_deck'], 0, min($look, count($ownerP['main_deck'])));
            $matches = array_values(array_filter(
                $top,
                fn($c) => cardMatchesGroup($c, $ability['group'] ?? 'Nijigasaki', 'member')
                    && intval($c['cost'] ?? 0) >= intval($ability['min_cost'] ?? 9)
            ));
            $srcName = $prompt['source_name'] ?? 'Member';
            if (count($matches) === 1) {
                $pick = $matches[0];
                $ownerP['hand'][] = $pick;
                $pickId = $pick['instance_id'] ?? '';
                $rest = array_values(array_filter($top, fn($c) => ($c['instance_id'] ?? '') !== $pickId));
                $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $rest);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . $srcName . '] looked at top ' . count($top) .
                    '; revealed ' . cardDisplayName($pick) . ' to hand.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            if (count($matches) === 0) {
                $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $top);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . $srcName . '] looked at top ' . count($top) .
                    '; no matching Member to reveal.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $state['surveil_stash'] = $top;
            $state['pending_prompt'] = [
                'type'          => 'bp5_wait_discard_look_reveal',
                'step'          => 'pick',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'top_cards'     => array_map('cardPromptSummary', $top),
                'candidates'    => array_map('cardPromptSummary', $matches),
                'prompt'        => 'Reveal 1 matching Member to add to hand? (Rest go to WR)',
                'choices'       => array_merge(['skip'], array_map(fn($c, $i) => 'pick_' . $i, $matches, array_keys($matches))),
                'choice_labels' => array_merge(['Skip — all to WR'], array_map(
                    fn($c) => cardDisplayName($c),
                    $matches
                )),
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick') {
            $cardId = $data['card_id'] ?? '';
            if ($cardId === '' && preg_match('/^pick_(\d+)$/', $choice, $m)) {
                $idx = intval($m[1]);
                $cands = $prompt['candidates'] ?? [];
                if (isset($cands[$idx])) {
                    $cardId = $cands[$idx]['instance_id'] ?? '';
                }
            }
            $looked = $state['surveil_stash'] ?? [];
            $srcName = $prompt['source_name'] ?? 'Member';
            if ($cardId !== '') {
                applyLookPickHand($ownerP, $looked, [$cardId]);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . $srcName . '] revealed 1 matching Member to hand.');
            } else {
                $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $looked);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . $srcName . '] looked at top ' . count($looked) .
                    '; no Member revealed (all to Waiting Room).');
            }
            unset($state['surveil_stash']);
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
    }

    if ($promptType === 'bp5_pick_kasumi_reveal') {
        $cardId = $data['card_id'] ?? '';
        $targetNames = $prompt['target_names'] ?? [];
        foreach ($prompt['candidates'] ?? [] as $c) {
            if (($c['instance_id'] ?? '') !== $cardId) continue;
            foreach ($ownerP['stage'] as $slot => &$mbr) {
                if (!$mbr || !cardMatchesNames($mbr, $targetNames)) continue;
                $full = null;
                foreach ($ownerP['waiting_room'] as $wr) {
                    if (($wr['instance_id'] ?? '') === $cardId) {
                        $full = $wr;
                        break;
                    }
                }
                if ($full) {
                    foreach ($full['hearts'] ?? [] as $h) {
                        for ($i = 0; $i < intval($h['count'] ?? 1); $i++) {
                            $mbr['bonus_hearts'][] = $h['color'] ?? 'pink';
                        }
                    }
                }
                $ownerP['stage'][$slot] = $mbr;
            }
            unset($mbr);
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if (in_array($promptType, nBp5EffectTypes(), true) && $choice === 'no') {
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    return null;
}

function nBp5NotifyMemberAbilityResolved(array $state, string $pid, array $resolvedMember, string $phase): array {
    $resolvedId = $resolvedMember['instance_id'] ?? '';
    if ($resolvedId === '') {
        return $state;
    }
    $p = &$state['players'][$pid];
    foreach ($p['live_zone'] as $live) {
        if (!$live) continue;
        foreach ($live['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') continue;
            $type = $ab['type'] ?? '';
            if ($phase === 'live_start' && $type !== 'auto_grant_wild_on_member_live_start') continue;
            if ($phase === 'live_success' && $type !== 'auto_draw_on_member_live_success') continue;
            $state = nBp5ResolveAutoOnMemberAbility(
                $state,
                $pid,
                $live,
                null,
                $ab,
                $idx,
                ['resolved_member_id' => $resolvedId]
            );
        }
    }
    return plMuseGapNotifyMemberAbilityResolved($state, $pid, $resolvedMember, $phase);
}
