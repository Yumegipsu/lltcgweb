<?php
/**
 * Hasunosora bp6 (Royal Holiday) effect handlers.
 * Included by effects.php.
 */

function hsBp6EffectTypes(): array {
    return [
        'surveil_stage_plus_pick_one_top',
        'live_success_pick_yell_deck_top',
        'blade_if_solo_stage',
        'optional_activate_wait_subunit_add_live_wr',
        'optional_discard_subunit_member_heart',
        'optional_discard_blade_named_extra',
        'live_start_cost_plus_stage_cost_blade_hearts',
        'hand_cost_reduction_per_stage_subunit',
        'no_baton_except_subunit',
        'live_success_wait_skip_next_activate',
        'auto_on_subunit_enter_opp_wait_active',
        'mandatory_wait_self_add_wr_live',
        'auto_activate_if_live_zone_score_max',
        'mill_then_blade_if_all_group',
        'optional_discard_subunit_draw_buff_cost',
        'activate_energy_if_other_subunit',
        'wait_opponent_max_blade_excl_subunit',
        'hand_discard_named_blade',
        'draw_and_discard_if_not_from_hand',
        'leave_stage_add_live_and_member_from_wr',
        'leave_stage_discard_member_heart_blade',
        'add_from_wr_if_stage_min_members',
        'auto_yell_mill_extra_yell',
        'live_success_surplus_heart_surveil',
        'live_start_stage_cost_threshold_surveil',
        'optional_shuffle_wr_members_deck_bottom_named_blade',
    ];
}

function hsIsHasunosoraBp6EffectType(string $type): bool {
    return in_array($type, hsBp6EffectTypes(), true);
}

function hsResolveHasunosoraEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (!hsIsHasunosoraBp6EffectType($type)) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'surveil_stage_plus_pick_one_top':
            if (!empty($state['pending_prompt'])) break;
            $look = countStageMembers($p) + intval($ab['plus'] ?? 2);
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            if (empty($top)) break;
            if (count($top) === 1) {
                array_unshift($p['main_deck'], $top[0]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put 1 card on deck top.");
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'surveil_pick_one_deck_top',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $top),
                'look_cards'    => $top,
                'prompt'        => 'Choose 1 looked card to put on top of your deck (rest go to Waiting Room).',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] looked at " . count($top) . ' card(s).');
            break;

        case 'live_success_pick_yell_deck_top':
            $yellPool = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            if (empty($yellPool) || !empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'live_success_pick_yell_deck_top',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'optional'      => true,
                'prompt'        => 'Put 1 card revealed for Yell on top of your deck?',
                'candidates'    => array_map('cardPromptSummary', $yellPool),
                'choices'       => ['pick', 'skip'],
                'choice_labels' => ['Choose card', 'Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Live Success (optional deck top).");
            break;

        case 'blade_if_solo_stage':
            break;

        case 'optional_activate_wait_subunit_add_live_wr':
            if (!empty($state['pending_prompt'])) break;
            $subunit = $ab['subunit'] ?? '';
            $waitCandidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr || !memberIsInWait($mbr)) continue;
                if (!cardMatchesSubunit($mbr, $subunit)) continue;
                $waitCandidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($waitCandidates)) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_activate_wait_subunit_add_live_wr',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_slot'   => (string)($ctx['slot'] ?? ''),
                'source_name'   => $name,
                'subunit'       => $subunit,
                'group'         => $ab['group'] ?? 'Hasunosora',
                'candidates'    => $waitCandidates,
                'prompt'        => 'Activate 1 ' . $subunit . ' Member in Wait and add 1 ' . $subunit . ' Live from WR to hand?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional On Enter (choose).");
            break;

        case 'optional_discard_subunit_member_heart':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_prompt',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => $ab['prompt'] ?? 'Put 1 card from hand into the Waiting Room?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
                'discard'       => intval($ab['discard'] ?? 1),
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Live Start (choose).");
            break;

        case 'optional_discard_blade_named_extra':
            if (!empty($state['pending_prompt'])) break;
            $liveStart = ($ctx['phase'] ?? '') === 'live_start'
                || ($state['phase'] ?? '') === 'live_start_effects';
            $promptAbility = [
                'discard' => 1,
                'then'    => [
                    'type'         => 'blade_bonus_named_extra',
                    'amount'       => intval($ab['amount'] ?? 1),
                    'extra_amount' => intval($ab['extra_amount'] ?? 1),
                    'named'        => $ab['named'] ?? '',
                ],
            ];
            if (!empty($ctx['confirm']) || !empty($ctx['discard_ids'])) {
                return resolveOptionalDiscardPromptChoice($state, $pid, [
                    'ability'     => $promptAbility,
                    'source_name' => $name,
                    'source_id'   => $source['instance_id'] ?? '',
                    'live_start'  => $liveStart,
                ], 'yes', ['discard_ids' => $ctx['discard_ids'] ?? []], true);
            }
            $named = $ab['named'] ?? '';
            $promptText = 'Put 1 card from your hand into the Waiting Room: gain +'
                . intval($ab['amount'] ?? 1) . ' Blade until Live ends'
                . ($named !== '' ? ' (+' . intval($ab['extra_amount'] ?? 1) . " more if $named)" : '') . '?';
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_prompt',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => $promptText,
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $promptAbility,
                'live_start'    => $liveStart,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Live Start (choose).");
            break;

        case 'live_start_cost_plus_stage_cost_blade_hearts':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = enrichSelfActivationPrompt($state, [
                'type'          => 'optional_discard_prompt',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'live_start'    => true,
                'prompt'        => 'Put 1 card from hand into the Waiting Room: this Member\'s cost +6 until Live ends?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => array_merge($ab, [
                    'discard' => 1,
                    'then'    => [
                        'type'        => 'live_start_self_cost_plus_check',
                        'amount'      => intval($ab['cost_plus'] ?? 6),
                        'heart_color' => $ab['heart_color'] ?? 'pink',
                    ],
                ]),
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Live Start (choose).");
            break;

        case 'hand_cost_reduction_per_stage_subunit':
        case 'no_baton_except_subunit':
            break;

        case 'live_success_wait_skip_next_activate':
            waitMember($source, $state);
            $slot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
            if ($slot !== null && $slot !== '') {
                $p['stage'][$slot] = $source;
            }
            $source['skip_activate_next_turn'] = true;
            if ($slot !== null && $slot !== '') {
                $p['stage'][$slot] = $source;
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] put self into Wait; will not stand next Active Phase.");
            break;

        case 'auto_on_subunit_enter_opp_wait_active':
            break;

        case 'mandatory_wait_self_add_wr_live':
            waitMember($source, $state);
            $slot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
            if ($slot !== null && $slot !== '') {
                $p['stage'][$slot] = $source;
            }
            $added = addFromWaitingRoomFiltered(
                $p,
                $ab['group'] ?? 'Hasunosora',
                'live',
                1,
                null,
                ['max_live_score' => intval($ab['max_live_score'] ?? 4)]
            );
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Waited self; added $added Live from Waiting Room.");
            break;

        case 'auto_activate_if_live_zone_score_max':
            // "Activate this Member" must clear Wait (in_wait), not only set active=true.
            foreach ($p['live_zone'] ?? [] as $lc) {
                if (!$lc || !isLiveTypeCard($lc)) {
                    continue;
                }
                if (intval($lc['score'] ?? 99) <= intval($ab['max_live_score'] ?? 2)) {
                    $slot = findMemberSlot($p, $source['instance_id'] ?? '');
                    if ($slot !== null && $slot !== '' && !empty($p['stage'][$slot])) {
                        activateMemberFully($p['stage'][$slot]);
                        $state = addLog($state, $state['players'][$pid]['name'] .
                            " — [$name] activated (low-score Live in zone).");
                    }
                    break;
                }
            }
            break;

        case 'mill_then_blade_if_all_group':
            $n = intval($ab['count'] ?? 4);
            $group = $ab['group'] ?? 'Hasunosora';
            $milled = array_splice($p['main_deck'], 0, min($n, count($p['main_deck'])));
            $allMatch = !empty($milled);
            foreach ($milled as $c) {
                if (($c['group'] ?? '') !== $group) {
                    $allMatch = false;
                    break;
                }
            }
            if (!empty($milled)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] milled " . count($milled) . ' to Waiting Room.');
            }
            if ($allMatch && count($milled) >= $n) {
                $state = applyModifierEffect($state, $pid, [
                    'type'   => 'blade_bonus',
                    'amount' => intval($ab['amount'] ?? 1),
                ]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] gained +' . intval($ab['amount'] ?? 1) . " Blade (all $group).");
            }
            break;

        case 'optional_discard_subunit_draw_buff_cost':
            if (!empty($state['pending_prompt'])) break;
            $sub = $ab['subunit'] ?? 'DOLLCHESTRA';
            $state['pending_prompt'] = enrichSelfActivationPrompt($state, [
                'type'          => 'optional_discard_subunit_draw_buff_cost',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'subunit'       => $sub,
                'cost_bonus'    => intval($ab['cost_bonus'] ?? 5),
                'live_start'    => true,
                'ability'       => array_merge($ab, [
                    'discard' => 1,
                    'discard_subunit' => $sub,
                ]),
                'prompt'        => 'Put 1 ' . $sub . ' card from your hand into the Waiting Room: draw 1 and give +' .
                    intval($ab['cost_bonus'] ?? 5) . ' cost to 1 ' . $sub . ' Member until Live ends?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Live Start (choose).");
            break;

        case 'activate_energy_if_other_subunit':
            $subunit = $ab['subunit'] ?? '';
            $hasOther = false;
            foreach ($p['stage'] as $mbr) {
                if (!$mbr) continue;
                if (($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) continue;
                if (cardMatchesSubunit($mbr, $subunit)) {
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

        case 'wait_opponent_max_blade_excl_subunit':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $waited = hsWaitOpponentByMaxBladeExclSubunit(
                $state,
                $opp,
                intval($ab['max_blade'] ?? 3),
                $ab['exclude_subunit'] ?? 'DOLLCHESTRA',
                intval($ab['pick_count'] ?? 1) ?: null,
                $pid
            );
            if ($waited > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put $waited opponent Member(s) into Wait.");
            }
            break;

        case 'hand_discard_named_blade':
            throw new Exception('Use hand-activated ability from hand UI');

        case 'draw_and_discard_if_not_from_hand':
            if (!empty($source['entered_from_hand'])) break;
            return applyDrawThenDiscard(
                $state,
                $pid,
                $p,
                $name,
                intval($ab['draw'] ?? 2),
                intval($ab['discard'] ?? 2)
            );

        case 'leave_stage_add_live_and_member_from_wr':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_prompt',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Put 1 card from hand into the Waiting Room: add up to 1 Live and 1 Member from WR to hand?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => array_merge($ab, [
                    'discard' => 1,
                    'then'    => ['type' => 'add_live_and_member_from_wr', 'live' => 1, 'member' => 1],
                ]),
            ];
            break;

        case 'leave_stage_discard_member_heart_blade':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_prompt',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Put 1 card from hand into the Waiting Room: 1 Stage Member gains pink heart and +1 Blade?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => array_merge($ab, [
                    'discard' => 1,
                    'then'    => [
                        'type'   => 'pick_member_heart_blade',
                        'hearts' => $ab['hearts'] ?? [['color' => 'pink', 'count' => 1]],
                        'blade'  => intval($ab['blade'] ?? 1),
                    ],
                ]),
            ];
            break;

        case 'add_from_wr_if_stage_min_members':
            if (countStageMembers($p) >= intval($ab['min_stage_members'] ?? 2)) {
                $added = addFromWaitingRoomFiltered(
                    $p,
                    $ab['group'] ?? '',
                    'live',
                    1,
                    null,
                    ['max_live_score' => intval($ab['max_live_score'] ?? 3)]
                );
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added $added Live from Waiting Room.");
                }
            }
            break;

        case 'auto_yell_mill_extra_yell':
            if (!empty($state['pending_prompt'])) break;
            $group = $ab['group'] ?? 'Hasunosora';
            $groupLabel = groupPromptLabel($group);
            // Natsumi (filter=live): discard 1 group Live from hand for fixed extra Yell.
            // Kurage (no filter / mill Yell reveals): different zone and eligibility.
            if (($ab['filter'] ?? '') === 'live') {
                $handCands = [];
                foreach ($p['hand'] ?? [] as $hc) {
                    if ($hc && cardMatchesGroup($hc, $group, 'live')) {
                        $handCands[] = $hc;
                    }
                }
                if (empty($handCands)) {
                    break;
                }
                $extra = max(1, intval($ab['extra_yell'] ?? 2));
                $state['pending_prompt'] = [
                    'type'            => 'auto_yell_mill_extra_yell',
                    'from_hand'       => true,
                    'owner'           => $pid,
                    'responder'       => $pid,
                    'source_name'     => $name,
                    'source_id'       => $source['instance_id'] ?? '',
                    'live_zone_index' => $ctx['live_zone_index'] ?? null,
                    'member_slot'     => $ctx['member_slot'] ?? null,
                    'ability_index'   => $ctx['ability_index'] ?? null,
                    'candidates'      => array_map('cardPromptSummary', $handCands),
                    'max_pick'        => 1,
                    'prompt'          => "Put 1 $groupLabel Live card from your hand into the Waiting Room for $extra additional Yell?",
                    'choices'         => ['yes', 'no'],
                    'choice_labels'   => ['Yes', 'No — Skip'],
                    'ability'         => $ab,
                ];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] optional hand Live discard for extra Yell (choose).");
                break;
            }
            $yellCards = $ctx['yell_cards'] ?? [];
            $candidates = array_values(array_filter(
                $yellCards,
                fn($c) => yellCardEligibleForKurageMill($c, $group)
            ));
            if (empty($candidates)) break;
            // max_pick caps how many you may mill; candidates must list every eligible Yell card.
            $max = min(intval($ab['max_mill'] ?? 3), count($candidates));
            $state['pending_prompt'] = [
                'type'          => 'auto_yell_mill_extra_yell',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'source_id'     => $source['instance_id'] ?? '',
                'live_zone_index' => $ctx['live_zone_index'] ?? null,
                'member_slot'   => $ctx['member_slot'] ?? null,
                'ability_index' => $ctx['ability_index'] ?? null,
                'candidates'    => array_map('cardPromptSummary', $candidates),
                'max_pick'      => $max,
                'prompt'        => "Put up to $max $groupLabel Yell card(s) without Blade hearts or Score icons into the Waiting Room for extra Yell?",
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Yell mill (choose).");
            break;

        case 'live_success_surplus_heart_surveil':
            // Surplus is stored as _live_excess_hearts[pid] / ctx excess_hearts (not _surplus_hearts).
            $surplus = intval($ctx['excess_hearts']
                ?? ($state['_live_excess_hearts'][$pid] ?? 0));
            if ($surplus < intval($ab['min_surplus'] ?? 1)) break;
            $look = intval($ab['look'] ?? 2);
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            if (empty($top)) break;
            $state = startSurveilArrangePrompt($state, $pid, $name, $top, null);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] looked at deck top (surplus hearts).");
            break;

        case 'live_start_stage_cost_threshold_surveil':
            $sum = sumStageMemberCost($p, $state, $pid);
            $group = $ab['group'] ?? 'Hasunosora';
            $groupSum = 0;
            foreach ($p['stage'] as $mbr) {
                if ($mbr && ($mbr['group'] ?? '') === $group) {
                    $groupSum += getEffectiveStageMemberCost($state, $pid, $mbr);
                }
            }
            $t20 = intval($ab['threshold_look'] ?? 20);
            $t30 = intval($ab['threshold_reduce'] ?? 30);
            if ($groupSum < $t20) break;
            $look = intval($ab['look'] ?? 2);
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            if (count($top) === 1) {
                $p['hand'][] = $top[0];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added 1 card to hand (stage cost $groupSum+).");
            } elseif (count($top) >= 2) {
                $state['pending_prompt'] = [
                    'type'          => 'surveil_pick_one_hand_rest_top',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_id'     => $source['instance_id'] ?? '',
                    'source_name'   => $name,
                    'look_cards'    => $top,
                    'candidates'    => array_map('cardPromptSummary', $top),
                    'prompt'        => 'Choose 1 card to add to hand (rest return to deck top).',
                ];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at 2 cards (stage cost $groupSum+).");
            }
            if ($groupSum >= $t30) {
                $reduceColor = $ab['reduce_color'] ?? 'any';
                bumpLiveCardColorReduction(
                    $state,
                    $pid,
                    $source['instance_id'] ?? '',
                    $reduceColor,
                    intval($ab['reduce_hearts'] ?? 2)
                );
                $colorLabel = $reduceColor === 'any' ? 'Gray' : ucfirst($reduceColor);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $colorLabel Hearts reduced by " .
                    intval($ab['reduce_hearts'] ?? 2) . " (cost $groupSum+).");
            }
            break;

        case 'optional_shuffle_wr_members_deck_bottom_named_blade':
            if (!empty($state['pending_prompt'])) break;
            $members = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
            ));
            if (empty($members)) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_shuffle_wr_members_deck_bottom',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'member_count'  => count($members),
                'subunit'       => $ab['subunit'] ?? 'みらくらぱーく!',
                'min_subunit'   => intval($ab['min_subunit_bottom'] ?? 15),
                'named'         => $ab['named'] ?? 'Hime Anyoji',
                'blade'         => intval($ab['blade'] ?? 3),
                'prompt'        => 'Shuffle all Members in your Waiting Room to the bottom of your deck?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional shuffle WR Members to deck bottom.");
            break;
    }

    return $state;
}

function hsWaitOpponentByMaxBladeExclSubunit(
    array &$state,
    string $oppId,
    int $maxBlade,
    string $excludeSubunit,
    ?int $pickCount,
    ?string $effectSourcePid
): int {
    $waited = 0;
    foreach ($state['players'][$oppId]['stage'] as &$mbr) {
        if (!$mbr) continue;
        if (cardMatchesSubunit($mbr, $excludeSubunit)) continue;
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

function yellCardHasBladeHeart(array $card): bool {
    return !empty($card['blade_hearts']);
}

/** Official FAQ Q251: Auto mill may not put [Score]-icon cards into WR. */
function yellCardHasScoreIcon(array $card): bool {
    return cardYellScoreIconCount($card) > 0;
}

/** Eligible for Tsukuyomi Kurage Auto: Hasunosora, no Blade hearts, no Score icon. */
function yellCardEligibleForKurageMill(array $card, string $group = 'Hasunosora'): bool {
    mergeYellCardCatalogFields($card);
    if (($card['group'] ?? '') !== $group) {
        return false;
    }
    if (yellCardHasBladeHeart($card) || yellCardHasScoreIcon($card)) {
        return false;
    }
    return true;
}

function hsResolveAutoOnOtherMemberEnter(array $state, string $pid, array $entered): array {
    if (!cardMatchesSubunit($entered, 'Edel Note')) {
        return $state;
    }
    $enteredId = (string)($entered['instance_id'] ?? '');
    $p = &$state['players'][$pid];
    // Left → center → right so multiple Cerases resolve in a stable stage order (#78).
    // Include the entering Member herself: "When an Edel Note Member enters" covers
    // Ceras's own Stage entry (#104), not only other Edel Notes.
    foreach (['left', 'center', 'right'] as $slot) {
        $member = $p['stage'][$slot] ?? null;
        if (!$member) {
            continue;
        }
        foreach ($member['abilities'] ?? [] as $idx => $ab) {
            if (($ab['type'] ?? '') !== 'auto_on_subunit_enter_opp_wait_active') {
                continue;
            }
            if (($ab['trigger'] ?? '') !== 'auto') {
                continue;
            }
            if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $idx)) {
                continue;
            }
            if (($ab['subunit'] ?? 'Edel Note') !== ''
                && !cardMatchesSubunit($entered, $ab['subunit'] ?? 'Edel Note')) {
                continue;
            }
            // Do not clobber an On Enter (or other) prompt already opened for the
            // entering Member — resume Ceras after that chain finishes (#80).
            if (!empty($state['pending_prompt'])) {
                $state['_resume_hs_auto_on_other_enter'] = [
                    'pid' => $pid,
                    'entered_id' => $enteredId,
                ];
                return $state;
            }
            $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
            // Card text: "your opponent puts 1 of their active Stage Members into Wait"
            $state = beginWaitOpponentStagePick(
                $state,
                $pid,
                $mName,
                [
                    'max_cost' => 99,
                    'pick_count' => 1,
                    'active_only' => true,
                    'opp_chooses' => true,
                ],
                (string)($member['instance_id'] ?? '')
            );
            markAbilityUsed($member, $idx);
            $p['stage'][$slot] = $member;
            if (!empty($state['pending_prompt'])) {
                // Resume remaining Cerases after the opponent answers (#78 two Cerases).
                $state['_resume_hs_auto_on_other_enter'] = [
                    'pid' => $pid,
                    'entered_id' => $enteredId,
                ];
                return $state;
            }
            // Auto-resolved (0–1 legal targets) — keep scanning for more Cerases.
        }
    }
    return $state;
}

function hsBp6ResolveWaitActivateSlot(array $ownerP, array $prompt, string $choice, array $data): string
{
    $slot = (string)($data['slot'] ?? '');
    if ($slot === 'yes' || $slot === 'no' || $slot === 'skip') {
        $slot = '';
    }
    $cid = (string)($data['card_id'] ?? '');
    if ($slot === '' && $cid !== '') {
        foreach ($prompt['candidates'] ?? [] as $c) {
            if (!is_array($c)) {
                continue;
            }
            if (($c['instance_id'] ?? '') === $cid) {
                $slot = (string)($c['slot'] ?? '');
                break;
            }
        }
        if ($slot === '') {
            $slot = findMemberSlot($ownerP, $cid);
        }
    }
    if ($slot === '' && $choice !== '' && !in_array($choice, ['yes', 'no', 'skip'], true)) {
        $slot = $choice;
    }
    return $slot;
}

function hsApplyHandCostPerStageSubunit(array $card, array $p): int {
    $base = intval($card['cost'] ?? 0);
    foreach ($card['abilities'] ?? [] as $ab) {
        if (($ab['type'] ?? '') === 'hand_cost_reduction_per_stage_subunit') {
            $sub = $ab['subunit'] ?? '';
            $n = 0;
            foreach ($p['stage'] as $mbr) {
                if ($mbr && cardMatchesSubunit($mbr, $sub)) $n++;
            }
            $base = max(0, $base - $n * intval($ab['per_member'] ?? 2));
        }
    }
    return $base;
}

function hsMemberBatonRestricted(array $member, ?array $batonFrom): bool {
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['type'] ?? '') === 'no_baton_except_subunit') {
            $allowed = $ab['subunit'] ?? '';
            if (!$batonFrom || !cardMatchesSubunit($batonFrom, $allowed)) {
                return true;
            }
        }
        if (($ab['trigger'] ?? '') === 'continuous' && ($ab['type'] ?? '') === 'no_baton') {
            return true;
        }
    }
    return false;
}

function hsApplySoloStageBlade(array $member, array $state, string $pid, int $blade): int {
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['type'] ?? '') === 'blade_if_solo_stage' && countStageMembers($state['players'][$pid]) <= 1) {
            $blade += intval($ab['amount'] ?? 2);
        }
    }
    return $blade;
}

function hsResolveHandDiscardNamedBlade(
    array $state,
    string $pid,
    array &$p,
    array $ab,
    array $data
): array {
    $handId = $data['hand_card_id'] ?? $data['card_id'] ?? '';
    $found = false;
    foreach ($p['hand'] as $i => $c) {
        if (($c['instance_id'] ?? '') === $handId) {
            $p['waiting_room'][] = $c;
            array_splice($p['hand'], $i, 1);
            $found = true;
            break;
        }
    }
    if (!$found) throw new Exception('Choose this card from your hand');
    $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
    $names = $ab['names'] ?? [];
    $candidates = [];
    foreach ($p['stage'] as $slot => $mbr) {
        if (!$mbr) continue;
        $label = cardNameKey($mbr);
        foreach ($names as $n) {
            if ($label === $n || str_contains($label, $n)) {
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
                break;
            }
        }
    }
    if (empty($candidates)) {
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — discarded from hand; drew $drawn (no named Member on Stage).");
        return $state;
    }
    $state['pending_prompt'] = [
        'type'        => 'pick_named_member_blade',
        'owner'       => $pid,
        'responder'   => $pid,
        'source_name' => 'Hand ability',
        'candidates'  => $candidates,
        'blade'       => intval($ab['blade'] ?? 1),
        'prompt'      => 'Choose 1 named Member for +' . intval($ab['blade'] ?? 1) . ' Blade until Live ends.',
    ];
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — discarded Member from hand; drew $drawn.");
    return $state;
}

function hsStartPickWrLiveAndMemberPrompt(
    array $state,
    string $owner,
    string $sourceName,
    string $sourceId = ''
): array {
    $p = &$state['players'][$owner];
    $lives = wrCandidatesMatching($p, ['filter' => 'live']);
    $members = wrCandidatesMatching($p, ['filter' => 'member']);
    if (empty($lives) && empty($members)) {
        $state = addLog($state, $state['players'][$owner]['name'] .
            " — [$sourceName] no Live or Member in Waiting Room to add.");
        return $state;
    }
    $step = !empty($lives) ? 'pick_live' : 'pick_member';
    $filter = $step === 'pick_live' ? 'live' : 'member';
    $cands = $step === 'pick_live' ? $lives : $members;
    $state['pending_prompt'] = [
        'type'            => 'hsbp6_pick_wr_live_and_member',
        'owner'           => $owner,
        'responder'       => $owner,
        'source_id'       => $sourceId,
        'source_name'     => $sourceName,
        'step'            => $step,
        'allow_skip'      => true,
        'need_member_step'=> !empty($members),
        'candidates'      => array_map('cardPromptSummary', $cands),
        'wr_pick_cfg'     => ['filter' => $filter],
        'pick_count'      => 1,
        'prompt'          => $step === 'pick_live'
            ? 'Choose up to 1 Live from your Waiting Room to add to hand (or skip).'
            : 'Choose up to 1 Member from your Waiting Room to add to hand (or skip).',
    ];
    return $state;
}

function hsAdvancePickWrLiveAndMemberPrompt(array $state, string $owner, array $prompt): array {
    $p = &$state['players'][$owner];
    $sourceName = $prompt['source_name'] ?? 'Member';
    if (empty($prompt['need_member_step'])) {
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }
    $members = wrCandidatesMatching($p, ['filter' => 'member']);
    if (empty($members)) {
        $state = addLog($state, $state['players'][$owner]['name'] .
            " — [$sourceName] no Member in Waiting Room to add.");
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }
    $state['pending_prompt'] = [
        'type'            => 'hsbp6_pick_wr_live_and_member',
        'owner'           => $owner,
        'responder'       => $owner,
        'source_id'       => $prompt['source_id'] ?? '',
        'source_name'     => $sourceName,
        'step'            => 'pick_member',
        'allow_skip'      => true,
        'need_member_step'=> false,
        'candidates'      => array_map('cardPromptSummary', $members),
        'wr_pick_cfg'     => ['filter' => 'member'],
        'pick_count'      => 1,
        'prompt'          => 'Choose up to 1 Member from your Waiting Room to add to hand (or skip).',
    ];
    $state['seq']++;
    return $state;
}

function hsResolveHasunosoraPrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $promptType = $prompt['type'] ?? '';
    $ownerP = &$state['players'][$owner];

    if ($promptType === 'hsbp6_pick_wr_live_and_member') {
        $step = $prompt['step'] ?? 'pick_live';
        $sourceName = $prompt['source_name'] ?? 'Member';
        $skip = in_array($choice, ['skip', 'no', 'cancel'], true);
        if ($skip) {
            if ($step === 'pick_live') {
                return hsAdvancePickWrLiveAndMemberPrompt($state, $owner, $prompt);
            }
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $pickId = $data['card_id'] ?? $choice;
        if ($pickId === '' || $pickId === 'skip' || $pickId === 'no' || $pickId === 'cancel') {
            throw new Exception('Choose a Waiting Room card or skip');
        }
        $wantFilter = ($prompt['wr_pick_cfg']['filter'] ?? '') !== ''
            ? $prompt['wr_pick_cfg']['filter']
            : ($step === 'pick_live' ? 'live' : 'member');
        $picked = null;
        foreach ($ownerP['waiting_room'] as $i => &$c) {
            if (($c['instance_id'] ?? '') !== $pickId) {
                continue;
            }
            hydrateWrCardForPick($c);
            if (!cardMatchesWrPick($c, ['filter' => $wantFilter])) {
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
            ' — [' . $sourceName . '] added ' . cardDisplayName($picked) .
            ' from Waiting Room to hand.');
        if ($step === 'pick_live') {
            return hsAdvancePickWrLiveAndMemberPrompt($state, $owner, $prompt);
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'surveil_pick_one_deck_top') {
        $pickId = $data['card_id'] ?? $choice;
        $looked = $prompt['look_cards'] ?? [];
        $picked = null;
        $rest = [];
        foreach ($looked as $c) {
            if (($c['instance_id'] ?? '') === $pickId) {
                $picked = $c;
            } else {
                $rest[] = $c;
            }
        }
        if (!$picked) throw new Exception('Choose 1 looked card');
        array_unshift($ownerP['main_deck'], $picked);
        if (!empty($rest)) {
            $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $rest);
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] arranged deck top.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'live_success_pick_yell_deck_top') {
        if ($choice === 'skip' || $choice === 'no' || $choice === 'cancel') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveSuccessEffects($state);
        }
        $pickId = trim((string)($data['card_id'] ?? ''));
        // Scored-choice / two-step UI may send choice=pick without card_id yet.
        if ($pickId === '' || $pickId === 'pick') {
            $cands = $prompt['candidates'] ?? [];
            if (count($cands) === 1) {
                $pickId = (string)($cands[0]['instance_id'] ?? '');
            }
        }
        if ($pickId === '' || $pickId === 'pick') {
            // Incomplete human two-step pick (choice=pick, no card yet) — keep prompt.
            // CPU softlock is handled by anti_softlock_skip preferring optional skip.
            return $state;
        }
        $picked = takeFromPendingYellPool($ownerP, $pickId, $prompt, $state, $owner);
        if (!$picked) {
            // Pool may have moved; still clear the optional prompt so play continues.
            unset($state['pending_prompt']);
            $state['seq']++;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped Yell deck-top (card unavailable).');
            return finishLiveSuccessEffects($state);
        }
        array_unshift($ownerP['main_deck'], $picked);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] put ' .
            cardDisplayName($picked) . ' on deck top.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveSuccessEffects($state);
    }

    if ($promptType === 'optional_activate_wait_subunit_add_live_wr') {
        $step = (string)($prompt['step'] ?? '');
        if ($step === '' && in_array($choice, ['no', 'skip'], true)) {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $cands = is_array($prompt['candidates'] ?? null) ? $prompt['candidates'] : [];
        $slot = hsBp6ResolveWaitActivateSlot($ownerP, $prompt, $choice, $data);
        if ($step === '' && $choice === 'yes' && $slot === '') {
            if (count($cands) === 1) {
                $slot = (string)($cands[0]['slot'] ?? '');
            } else {
                $state['pending_prompt'] = array_merge($prompt, [
                    'step'    => 'pick_wait_member',
                    'choices' => [],
                    'prompt'  => 'Choose 1 ' . ($prompt['subunit'] ?? '') .
                        ' Member in Wait to activate.',
                ]);
                $state['seq']++;
                return $state;
            }
        }
        if ($slot === '' || empty($ownerP['stage'][$slot])) {
            throw new Exception('Choose a Member in Wait to activate');
        }
        if (!memberIsInWait($ownerP['stage'][$slot])) {
            throw new Exception('That Member is not in Wait');
        }
        activateMemberFully($ownerP['stage'][$slot]);
        $sub = $prompt['subunit'] ?? '';
        $srcName = $prompt['source_name'] ?? 'Member';
        $cfg = [
            'group'   => $prompt['group'] ?? 'Hasunosora',
            'filter'  => 'live',
            'subunit' => $sub,
        ];
        $wrCands = wrCandidatesMatching($ownerP, $cfg);
        if (empty($wrCands)) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$srcName] activated Wait Member; no matching Live in Waiting Room.");
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        // Always open pick_wr_to_hand — never auto-first-match (#101 / player choice).
        $state['pending_prompt'] = [
            'type'          => 'pick_wr_to_hand',
            'owner'         => $owner,
            'responder'     => $owner,
            'source_id'     => $prompt['source_id'] ?? '',
            'source_slot'   => $prompt['source_slot'] ?? '',
            'source_name'   => $srcName,
            'prompt'        => 'Choose 1 ' . ($sub !== '' ? $sub . ' ' : '') .
                'Live from your Waiting Room to add to your hand.',
            'candidates'    => array_map('cardPromptSummary', $wrCands),
            'wr_pick_cfg'   => $cfg,
            'pick_count'    => 1,
            'up_to'         => false,
        ];
        $state = addLog($state, $state['players'][$owner]['name'] .
            " — [$srcName] activated Wait Member; choose Live from Waiting Room.");
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_discard_subunit_draw_buff_cost') {
        if ($choice !== 'yes') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        $sub = $prompt['subunit'] ?? '';
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== 1) throw new Exception('Discard exactly 1 matching card');
        $discarded = takeDiscardedHandCards($ownerP, $ids, $state, $owner);
        if (empty($discarded) || !cardMatchesSubunit($discarded[0], $sub)) {
            throw new Exception('Must discard a matching subunit card');
        }
        $drawn = drawCardsForPlayer($state, $owner, 1);
        $candidates = [];
        foreach ($ownerP['stage'] as $slot => $mbr) {
            if ($mbr && cardMatchesSubunit($mbr, $sub)) {
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
        }
        if (empty($candidates)) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — drew $drawn (no $sub Member to buff).");
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        $state['pending_prompt'] = [
            'type'        => 'pick_member_cost_bonus',
            'owner'       => $owner,
            'responder'   => $owner,
            'source_name' => $prompt['source_name'] ?? 'Member',
            'candidates'  => $candidates,
            'cost_bonus'  => intval($prompt['cost_bonus'] ?? 5),
            'prompt'      => 'Choose 1 Member for +cost until Live ends.',
        ];
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_member_cost_bonus') {
        $slot = $data['slot'] ?? $choice;
        if ($slot === '' || empty($ownerP['stage'][$slot])) throw new Exception('Choose a Member');
        // This can target a Member with an existing Live Start cost modifier
        // (for example, Fantasy Sayaka's +4 per stacked Member). Bonuses from
        // distinct abilities stack until the Live ends; never overwrite one here.
        $ownerP['stage'][$slot]['live_cost_bonus'] =
            intval($ownerP['stage'][$slot]['live_cost_bonus'] ?? 0)
            + intval($prompt['cost_bonus'] ?? 5);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — Member cost +' . intval($prompt['cost_bonus'] ?? 5) . ' until Live ends.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'pick_named_member_blade') {
        $slot = $data['slot'] ?? $choice;
        $validSlots = array_column($prompt['candidates'] ?? [], 'slot');
        if ($slot === '' || !in_array($slot, $validSlots, true) || empty($ownerP['stage'][$slot])) {
            throw new Exception('Choose an eligible Member');
        }
        $ownerP['stage'][$slot]['live_blade_bonus'] = intval($ownerP['stage'][$slot]['live_blade_bonus'] ?? 0)
            + intval($prompt['blade'] ?? 1);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — named Member gained +' . intval($prompt['blade'] ?? 1) . ' Blade.');
        unset($state['pending_prompt']);
        $state['seq']++;
        if (!empty($prompt['continue_live_start'])) {
            return finishLiveStartEffects($state);
        }
        return $state;
    }

    if ($promptType === 'surveil_pick_one_hand_rest_top') {
        $pickId = $data['card_id'] ?? $choice;
        $looked = $prompt['look_cards'] ?? [];
        $picked = null;
        $rest = [];
        foreach ($looked as $c) {
            if (($c['instance_id'] ?? '') === $pickId) {
                $picked = $c;
            } else {
                $rest[] = $c;
            }
        }
        if (!$picked) throw new Exception('Choose 1 card');
        $ownerP['hand'][] = $picked;
        $ownerP['main_deck'] = array_merge(array_reverse($rest), $ownerP['main_deck']);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . '] added 1 to hand; rest to deck top.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'optional_shuffle_wr_members_deck_bottom') {
        if ($choice !== 'yes') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        $members = array_values(array_filter(
            $ownerP['waiting_room'],
            fn($c) => ($c['card_type'] ?? '') === 'メンバー'
        ));
        $rest = array_values(array_filter(
            $ownerP['waiting_room'],
            fn($c) => ($c['card_type'] ?? '') !== 'メンバー'
        ));
        $ownerP['waiting_room'] = $rest;
        shuffle($members);
        $subCount = count(array_filter($members, fn($c) => cardMatchesSubunit($c, $prompt['subunit'] ?? '')));
        $ownerP['main_deck'] = array_merge($ownerP['main_deck'], $members);
        $named = $prompt['named'] ?? 'Hime Anyoji';
        if ($subCount >= intval($prompt['min_subunit'] ?? 15)) {
            $candidates = [];
            foreach ($ownerP['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                if (cardNameKey($mbr) === $named || str_contains(cardNameKey($mbr), $named)) {
                    $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
                }
            }
            if (!empty($candidates)) {
                $state['pending_prompt'] = [
                    'type' => 'pick_named_member_blade',
                    'owner' => $owner,
                    'responder' => $owner,
                    'source_name' => $prompt['source_name'] ?? 'Live',
                    'candidates' => $candidates,
                    'blade' => intval($prompt['blade'] ?? 3),
                    'prompt' => "Choose 1 $named for +" . intval($prompt['blade'] ?? 3) .
                        ' Blade until this Live ends.',
                    'continue_live_start' => true,
                ];
            }
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — shuffled ' . count($members) . ' WR Member(s) to deck bottom.');
        if (($state['pending_prompt']['type'] ?? '') === 'optional_shuffle_wr_members_deck_bottom') {
            unset($state['pending_prompt']);
        }
        $state['seq']++;
        if (($state['pending_prompt']['type'] ?? '') === 'pick_named_member_blade') {
            return $state;
        }
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'auto_yell_mill_extra_yell') {
        $sourceName = $prompt['source_name'] ?? 'Member';
        $fromHand = !empty($prompt['from_hand'])
            || (($prompt['ability']['filter'] ?? '') === 'live');
        if ($choice !== 'yes') {
            unset($state['pending_prompt']);
            $state['seq']++;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ($fromHand
                    ? " — [$sourceName] declined hand Live discard for extra Yell."
                    : " — [$sourceName] kept Yell cards (declined mill)."));
            return continuePerformanceAfterYellAbilities($state, $owner);
        }
        $ids = $data['card_ids'] ?? ($data['discard_ids'] ?? []);
        if (!is_array($ids)) {
            $ids = $ids !== '' && $ids !== null ? [$ids] : [];
        }
        $group = $prompt['ability']['group'] ?? 'Hasunosora';

        if ($fromHand) {
            $validIds = [];
            foreach ($state['players'][$owner]['hand'] ?? [] as $hc) {
                $iid = $hc['instance_id'] ?? '';
                if ($iid === '' || !in_array($iid, $ids, true)) {
                    continue;
                }
                if (!cardMatchesGroup($hc, $group, 'live')) {
                    continue;
                }
                $validIds[] = $iid;
            }
            $validIds = array_slice($validIds, 0, max(1, intval($prompt['max_pick'] ?? 1)));
            if (empty($validIds)) {
                throw new Exception('Choose 1 eligible Live card from hand');
            }
            $pOwner = &$state['players'][$owner];
            $discarded = discardFromHandByIds($pOwner, $validIds, $state, $owner);
            unset($pOwner);
            if ($discarded < 1) {
                throw new Exception('Choose 1 eligible Live card from hand');
            }
            $extra = max(1, intval($prompt['ability']['extra_yell'] ?? 2));
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$sourceName] put $discarded Live from hand into WR for +$extra extra Yell.");
            unset($state['pending_prompt']);
            $state['seq']++;
            $state = executeExtraYellDraws($state, $owner, $extra, $sourceName);
            if (!empty($state['pending_prompt'])) {
                $state['_performance_continue'] = $owner;
                return $state;
            }
            return continuePerformanceAfterYellAbilities($state, $owner);
        }

        $pool = currentPlayerYellCards($state, $owner);
        $validIds = [];
        foreach ($pool as $c) {
            $iid = $c['instance_id'] ?? '';
            if ($iid === '' || !in_array($iid, $ids, true)) {
                continue;
            }
            if (!yellCardEligibleForKurageMill($c, $group)) {
                continue;
            }
            $validIds[] = $iid;
        }
        $maxPick = intval($prompt['max_pick'] ?? 3);
        $validIds = array_slice($validIds, 0, $maxPick);
        if (empty($validIds)) {
            throw new Exception('Choose at least 1 eligible Yell card');
        }
        $state = millPlayerYellCardsToWr($state, $owner, $validIds);
        $milled = count($validIds);
        // Kurage: extra Yell equals milled count (no fixed extra_yell on ability).
        $extra = intval($prompt['ability']['extra_yell'] ?? 0);
        if ($extra <= 0) {
            $extra = $milled;
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            " — [$sourceName] milled $milled Yell card(s) for +$extra extra Yell.");
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = executeExtraYellDraws($state, $owner, $extra, $sourceName);
        if (!empty($state['pending_prompt'])) {
            $state['_performance_continue'] = $owner;
            return $state;
        }
        return continuePerformanceAfterYellAbilities($state, $owner);
    }

    return null;
}
