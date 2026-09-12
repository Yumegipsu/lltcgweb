<?php
/**
 * Optional ability type cases — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchOptional(
    array $state,
    string $pid,
    array $source,
    array $ab,
    array $ctx,
    string $type,
    array &$p,
    string $name
): ?array {
    switch ($type) {
        case 'optional_discard_named':
            $ids = $ctx['discard_ids'] ?? [];
            if (empty($ids)) {
                if (!empty($ab['exact_total'])) {
                    throw new Exception('Must discard exactly ' . $ab['exact_total'] . ' matching cards');
                }
                break;
            }
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
            $discarded = discardFromHandByIds($p, $valid, $state, $pid);
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
            $discarded = discardFromHandByIds($p, $ids, $state, $pid);
            if ($discarded > 0 && !empty($ab['then'])) {
                $state = applyModifierEffect($state, $pid, $ab['then'], $source);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] discarded $discarded same-unit card(s) for Live Start effect.");
            }
            break;

        case 'optional_pay_energy':
            if (($ctx['phase'] ?? '') === 'on_enter' && empty($ctx['pay']) && empty($ctx['confirm'])) {
                if (!empty($state['pending_prompt'])) break;
                if (!optionalCostAbilityShouldOpen($state, $pid, $ab)) {
                    if (!optionalAbilityHasAffordableEnergy($p, $ab)) {
                        $need = optionalAbilityEnergyCost($ab);
                        $state = addLog($state, $state['players'][$pid]['name'] .
                            " — [$name] could not pay $need Energy; effect skipped.");
                    }
                    break;
                }
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
                if (($then['type'] ?? '') === 'choose_heart_modifier') {
                    $state = resolveAbilityEffect($state, $pid, $source, $then, $ctx);
                } elseif (($then['type'] ?? '') === 'member_blade_bonus') {
                    if (!empty($then['other_only'])) {
                        $then['exclude_source_id'] = $source['instance_id'] ?? '';
                    }
                    $applied = 0;
                    $state = applyOrPromptMemberBladeBonus(
                        $state,
                        $pid,
                        $then,
                        [
                            'source_name' => $name,
                            'source_id'   => $source['instance_id'] ?? '',
                            'live_start'  => ($ctx['phase'] ?? '') === 'live_start'
                                || ($state['phase'] ?? '') === 'live_start_effects',
                        ],
                        $applied
                    );
                    if ($applied === null) {
                        $state = addLog($state, $state['players'][$pid]['name'] .
                            " — [$name] paid $cost Energy; choose a Stage Member for +" .
                            intval($then['amount'] ?? 0) . ' Blade.');
                        break;
                    }
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] paid $cost Energy; $applied Member(s) gained +" .
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

        case 'optional_wait_self_wait_opp':
            if (!empty($state['pending_prompt'])) break;
            if (!optionalCostAbilityShouldOpen($state, $pid, $ab)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] optional Wait skipped (no legal targets).');
                break;
            }
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
                // So skip / auto-wait resume Live Start instead of softlocking.
                'live_start'    => (($ctx['phase'] ?? '') === 'live_start'),
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
            $look = max(1, intval($ab['look'] ?? 4));
            $subunit = trim((string)($ab['subunit'] ?? ''));
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_look_reveal_subunit',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Put 1 card from hand into the Waiting Room to look at the top ' . $look .
                    ' cards of your deck and add 1 ' . ($subunit !== '' ? $subunit . ' ' : '') . 'card?',
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

        case 'optional_pay_energy_if_baton':
            if (!empty($state['pending_prompt'])) break;
            if (!optionalAbilityHasAffordableEnergy($p, $ab)) {
                $need = optionalAbilityEnergyCost($ab);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] could not pay $need Energy; effect skipped.");
                break;
            }
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
            // Always defer Energy until Yes. Skip opening when the cost cannot be
            // paid or the follow-up has no legal targets (do not discard for nothing).
            if (!optionalCostAbilityShouldOpen($state, $pid, $ab)) {
                $then = $ab['then'] ?? [];
                if (!is_array($then)) {
                    $then = [];
                }
                if (!optionalAbilityHasAffordableEnergy($p, $ab)) {
                    $need = optionalAbilityEnergyCost($ab);
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] could not pay $need Energy; effect skipped.");
                } elseif (!optionalDiscardThenViable($p, $then)) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] optional On Enter skipped (no cards left in deck).');
                } elseif (!optionalDiscardPromptHandViable($p, $ab)
                    || !optionalThenEffectViable($state, $pid, $then)) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] optional On Enter skipped (no legal targets).');
                }
                break;
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

        case 'optional_discard_add_from_wr':
            if (!empty($ab['requires_success_lives']) && empty($p['success_lives'])) break;
            if (empty($ctx['discard_ids']) && empty($ctx['confirm'])) break;
            $ids = $ctx['discard_ids'] ?? [];
            if (!empty($ids)) {
                discardFromHandByIds($p, $ids, $state, $pid);
            } elseif (!empty($ctx['confirm'])) {
                autoDiscardFromHand($p, intval($ab['discard'] ?? 1));
            }
            $added = addFromWaitingRoomWithChoice(
                $state,
                $pid,
                $source,
                $ab,
                $ctx,
                wrPickCfgFromAbility($ab),
                intval($ab['count'] ?? 1)
            );
            if ($added === null) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] choose a card from Waiting Room.");
                break;
            }
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $added card(s) from Waiting Room.");
            }
            break;

        case 'optional_discard_hand':
            if (empty($ctx['discard_ids']) && empty($ctx['confirm'])) break;
            $need = intval($ab['discard'] ?? 1);
            $filter = (string)($ab['filter'] ?? '');
            if (!empty($ctx['discard_ids'])) {
                $ids = $ctx['discard_ids'];
                if ($filter === 'live' || $filter === 'member') {
                    $matched = [];
                    foreach ($p['hand'] as $c) {
                        if (!in_array($c['instance_id'] ?? '', $ids, true)) {
                            continue;
                        }
                        if ($filter === 'live' && !isLiveTypeCard($c)) {
                            throw new Exception('Must discard a Live card');
                        }
                        if ($filter === 'member' && !isMemberCard($c)) {
                            throw new Exception('Must discard a Member card');
                        }
                        $matched[] = $c['instance_id'];
                    }
                    if (count($matched) !== count($ids)) {
                        throw new Exception($filter === 'live'
                            ? 'Must discard a Live card'
                            : 'Must discard a Member card');
                    }
                }
                discardFromHandByIds($p, $ids, $state, $pid);
            } else {
                autoDiscardFromHand($p, $need);
            }
            if (!empty($ab['then'])) {
                $then = $ab['then'];
                if (($then['type'] ?? '') === 'blade_bonus_per_success') {
                    $then['amount'] = intval($then['amount'] ?? 1);
                    $state = applyModifierEffect($state, $pid, $then);
                } elseif (($then['type'] ?? '') === 'member_blade_bonus') {
                    $applied = 0;
                    $state = applyOrPromptMemberBladeBonus(
                        $state,
                        $pid,
                        $then,
                        [
                            'source_name' => $name,
                            'source_id'   => $source['instance_id'] ?? '',
                            'live_start'  => ($ctx['phase'] ?? '') === 'live_start'
                                || ($state['phase'] ?? '') === 'live_start_effects',
                        ],
                        $applied
                    );
                    if ($applied === null) {
                        $state = addLog($state, $state['players'][$pid]['name'] .
                            " — [$name] discarded $need; choose a Stage Member for +" .
                            intval($then['amount'] ?? 0) . ' Blade.');
                        break;
                    }
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] discarded $need; $applied Member(s) gained +" . intval($then['amount'] ?? 0) . ' Blade.');
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
                discardFromHandByIds($p, $ctx['discard_ids'], $state, $pid);
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
            if (waitFirstGroupMember($p, $ab['group'] ?? 'μ\'s', $state)) {
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

        case 'optional_wait_self_center_blade':
            if (!empty($state['pending_prompt'])) break;
            if (empty($ctx['confirm'])) {
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
            }
            $sourceId = $source['instance_id'] ?? '';
            foreach ($p['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    waitMember($mbr, $state);
                    break;
                }
            }
            unset($mbr);
            $group = $ab['group'] ?? 'μ\'s';
            $amount = intval($ab['amount'] ?? 1);
            if (applyCenterGroupBladeBonus($state, $pid, $group, $amount)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Waited self; Center $group Member gained +$amount Blade.");
            }
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

        case 'optional_stage_reposition':
            if (countStageMembers($p) < 1) break;
            if (!empty($state['pending_prompt'])) break;
            $repositionCands = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) {
                    continue;
                }
                $repositionCands[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_stage_reposition',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'You may Position Change 1 Member on your Stage?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'candidates'    => $repositionCands,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional Position Change (choose).');
            break;

        case 'optional_return_member_energy':
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
                'ability'       => $ab,
                'ability_index' => $ctx['ability_index'] ?? null,
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
                'ability'       => $ab,
                'ability_index' => $ctx['ability_index'] ?? null,
                'step'          => 'confirm',
                'prompt'        => 'Reveal 1 Live card from your hand: add 1 Success Live card to your hand, then put the revealed card into your Success Live area?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose).');
            break;

        case 'optional_pay_play_hand_member':
        case 'optional_play_hand_member':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            // Skip when hand has no legal Member (Ayumu pb1-013 etc.) — otherwise the
            // client opens an empty / wrong-filter hand pick and softlocks.
            $handCands = handMembersMatchingPlayAbility($p, $ab);
            if (empty($handCands)) {
                break;
            }
            $allowOverlap = !empty($ab['allow_overlap']);
            $blockEntered = !empty($ab['block_entered_this_turn']);
            $turn = intval($state['turn'] ?? 1);
            $hasSlot = false;
            foreach (['left', 'center', 'right'] as $s) {
                $existing = $p['stage'][$s] ?? null;
                if ($blockEntered && $existing && intval($existing['entered_turn'] ?? 0) === $turn) {
                    continue;
                }
                if (!$allowOverlap && $existing) {
                    continue;
                }
                $hasSlot = true;
                break;
            }
            if (!$hasSlot) {
                break;
            }
            $cost = intval($ab['cost'] ?? 0);
            $group = $ab['group'] ?? 'Nijigasaki';
            $anyGroup = !empty($ab['any_group']);
            $maxCost = intval($ab['max_cost'] ?? 4);
            $names = $ab['names'] ?? [];
            $targetLabel = !empty($names)
                ? implode('/', $names)
                : ($anyGroup ? 'Member' : ($group . ' Member'));
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
                    'put 1 ' . $targetLabel . ' (cost ≤' . $maxCost .
                    ') from your hand onto your Stage?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => [
                    ($cost > 0 ? 'Yes — Pay & Play' : 'Yes — Play'),
                    'No — Skip',
                ],
                'ability'       => $ab,
                'candidates'    => array_map('cardPromptSummary', $handCands),
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose).');
            break;

        case 'optional_wr_to_deck_top':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            if (empty($p['waiting_room'])) {
                break;
            }
            $group = (string)($ab['group'] ?? '');
            $cands = [];
            foreach ($p['waiting_room'] as $wrCard) {
                if ($group !== '' && ($wrCard['group'] ?? '') !== $group) {
                    continue;
                }
                $cands[] = $wrCard;
            }
            if (empty($cands)) {
                break;
            }
            $label = $group !== ''
                ? (groupPromptLabel($group) . ' card')
                : 'card';
            $state['pending_prompt'] = [
                'type'          => 'optional_wr_to_deck_top',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $cands),
                'group'         => $group,
                'prompt'        => "Put 1 $label from your Waiting Room on top of your deck?",
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Choose card', 'No — Skip'],
                'ability'       => $ab,
            ];
            $when = (($ctx['phase'] ?? '') === 'live_success') ? 'Live Success' : 'optional';
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . "] $when (choose).");
            break;

        case 'optional_wait_group_member_draw_discard':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            if (!stageHasGroupMember($p, $ab['group'] ?? 'Nijigasaki')) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_group_member_draw_discard',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
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

        case 'optional_discard_blade_per_card':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_blade_per_card',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
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

        case 'optional_negate_member_live_start_add_wr':
            if (!empty($state['pending_prompt'])) break;
            $candidates = [];
            foreach ($p['stage'] as $s => $mbr) {
                if (!$mbr) continue;
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
                'prompt'        => 'Negate all abilities of 1 Liella! Member until this Live ends, then add 1 Liella! card from your Waiting Room to hand?',
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

        case 'optional_formation_change_group':
            if (!stageAllMembersInGroup($p, $ab['group'] ?? '')) break;
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_formation_change_group',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Formation-change your Stage Members (one per area)?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional formation change (choose).');
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

        case 'optional_pay_energy_on_enter':
            if (!empty($state['pending_prompt'])) break;
            if (!optionalCostAbilityShouldOpen($state, $pid, $ab)) {
                if (!optionalAbilityHasAffordableEnergy($p, $ab)) {
                    $need = optionalAbilityEnergyCost($ab);
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] could not pay $need Energy; effect skipped.");
                }
                break;
            }
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

        case 'optional_pay_energy_live_success':
            if (!empty($state['pending_prompt'])) break;
            if (!optionalAbilityHasAffordableEnergy($p, $ab)) {
                $need = optionalAbilityEnergyCost($ab);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] could not pay $need Energy; effect skipped.");
                break;
            }
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

        case 'optional_wait_group_member_blade':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $group = $ab['group'] ?? 'Nijigasaki';
            if (!stageHasGroupMember($p, $group)) {
                break;
            }
            $amt = intval($ab['amount'] ?? 2);
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_group_member_blade',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'group'         => $group,
                'amount'        => $amt,
                'prompt'        => "Put 1 $group Member into Wait: until this Live ends, gain +$amt Blade?",
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional Live Start (choose).');
            break;

        case 'optional_wait_up_to_group_live_score':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $group = $ab['group'] ?? 'Nijigasaki';
            $maxWait = intval($ab['max_wait'] ?? 3);
            $members = listGroupStageMembersNotWaiting($p, $group);
            if (empty($members)) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_up_to_group_live_score',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'group'         => $group,
                'max_wait'      => $maxWait,
                'score_per'     => intval($ab['score_per'] ?? 1),
                'prompt'        => "Put up to $maxWait $group Member(s) into Wait: this card's score +"
                    . intval($ab['score_per'] ?? 1) . ' for each?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — choose Members', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional Live Start (choose).');
            break;

        default:
            return null;

    }
    return $state;
}
