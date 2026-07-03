<?php
/**
 * Core ability type switch — extracted from AbilityResolver.php.
 */

function resolveAbilityEffectSwitch(
    array $state,
    string $pid,
    array $source,
    array $ab,
    array $ctx,
    string $type,
    array &$p,
    string $name
): array {
    switch ($type) {
        case 'add_from_waiting_room':
            $candidates = array_values(array_filter($p['waiting_room'], function ($c) use ($ab) {
                if (($ab['filter'] ?? '') === 'member') {
                    return ($c['card_type'] ?? '') === 'メンバー';
                }
                return true;
            }));
            $take = min(intval($ab['count'] ?? 1), count($candidates));
            if ($take > 0) {
                $picked = array_slice($candidates, 0, $take);
                $pickedIds = array_column($picked, 'instance_id');
                $p['waiting_room'] = array_values(array_filter(
                    $p['waiting_room'],
                    fn($c) => !in_array($c['instance_id'] ?? '', $pickedIds, true)
                ));
                $p['hand'] = array_merge($p['hand'], $picked);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $take Member(s) from Waiting Room to hand.");
            }
            break;

        case 'look_reveal_named':
            $look = intval($ab['look'] ?? 5);
            $names = $ab['names'] ?? [];
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            $revealed = null;
            $rest = [];
            foreach ($top as $c) {
                if (!$revealed && ($c['card_type'] ?? '') === 'メンバー' && cardMatchesNames($c, $names)) {
                    $revealed = $c;
                    $p['hand'][] = $c;
                } else {
                    $rest[] = $c;
                }
            }
            $p['waiting_room'] = array_merge($p['waiting_room'], $rest);
            if ($revealed) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] revealed ' . ($revealed['name_en'] ?? $revealed['name']) . ' from deck top.');
                $then = $ab['then'] ?? [];
                if (($then['type'] ?? '') === 'wait_opponent_by_revealed') {
                    $opp = ($pid === 'p1') ? 'p2' : 'p1';
                    $maxCost = intval($revealed['cost'] ?? 0);
                    $maxBlade = intval($then['max_blade'] ?? 3);
                    $waited = 0;
                    foreach ($state['players'][$opp]['stage'] as &$mbr) {
                        if (!$mbr) continue;
                        if (intval($mbr['cost'] ?? 0) <= $maxCost && intval($mbr['blade'] ?? 0) <= $maxBlade) {
                            waitMember($mbr);
                            $waited++;
                        }
                    }
                    unset($mbr);
                    if ($waited > 0) {
                        $state = addLog($state, $state['players'][$opp]['name'] .
                            " — $waited opponent Member(s) put into Wait.");
                    }
                }
            } else {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at deck top; no matching Member to add.");
            }
            break;

        case 'optional_discard_named':
            $ids = $ctx['discard_ids'] ?? [];
            if (empty($ids)) break;
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
            $discarded = discardFromHandByIds($p, $valid);
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
            $discarded = discardFromHandByIds($p, $ids);
            if ($discarded > 0 && !empty($ab['then'])) {
                $state = applyModifierEffect($state, $pid, $ab['then']);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] discarded $discarded same-unit card(s) for Live Start effect.");
            }
            break;

        case 'optional_pay_energy':
            if (($ctx['phase'] ?? '') === 'on_enter' && empty($ctx['pay']) && empty($ctx['confirm'])) {
                if (!empty($state['pending_prompt'])) break;
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
                if (($then['type'] ?? '') === 'member_blade_bonus' && !empty($then['other_only'])) {
                    $then['exclude_source_id'] = $source['instance_id'] ?? '';
                }
                if (($then['type'] ?? '') === 'choose_heart_modifier') {
                    $state = resolveAbilityEffect($state, $pid, $source, $then, $ctx);
                } elseif (($then['type'] ?? '') === 'member_blade_bonus') {
                    $n = applyMemberBladeBonus($state, $pid, $then);
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] paid $cost Energy; $n Member(s) gained +" .
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

        case 'opponent_text_answer':
            if (!empty($state['pending_prompt'])) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $textFields = buildOpponentTextAnswerPromptFields($ab);
            $state['pending_prompt'] = [
                'type'          => 'opponent_text_answer',
                'owner'         => $pid,
                'responder'     => $opp,
                'source_name'   => $name,
                'prompt'        => $textFields['prompt'],
                'outcome_hints' => $textFields['outcome_hints'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] asks opponent: "' . $textFields['prompt'] . '"');
            break;

        case 'opponent_choice':
            if (!empty($state['pending_prompt'])) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $choiceFields = buildPlayerChoicePromptFields($ab);
            $isLiveStart = ($ctx['phase'] ?? '') === 'live_start'
                || ($state['phase'] ?? '') === 'live_start_effects';
            $state['pending_prompt'] = [
                'type'          => 'opponent_choice',
                'owner'         => $pid,
                'responder'     => $opp,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'live_start'    => $isLiveStart,
                'prompt'        => $choiceFields['prompt'],
                'choices'       => array_keys($ab['choices'] ?? []),
                'choice_labels' => $choiceFields['choice_labels'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] opponent must choose an effect.');
            break;

        case 'player_choice':
            if (!empty($state['pending_prompt'])) break;
            $choiceFields = buildPlayerChoicePromptFields($ab);
            $isLiveStart = ($ctx['phase'] ?? '') === 'live_start'
                || ($state['phase'] ?? '') === 'live_start_effects';
            $state['pending_prompt'] = enrichAbilityContextPrompt($state, [
                'type'          => 'player_choice',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'live_start'    => $isLiveStart,
                'prompt'        => $choiceFields['prompt'],
                'choices'       => array_keys($ab['choices'] ?? []),
                'choice_labels' => $choiceFields['choice_labels'],
                'ability'       => $ab,
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose one effect.');
            break;

        case 'optional_wait_self_wait_opp':
            if (!empty($state['pending_prompt'])) break;
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

        case 'success_scored_live_score_bonus':
            if (!abilitySlotAllowed($ab, $ctx, $p, $source)) {
                break;
            }
            $slot = findMemberSlot($p, $source['instance_id'] ?? '');
            if (!empty($ab['center_only']) && $slot !== 'center') {
                break;
            }
            $targetScores = $ab['scores'] ?? null;
            if ($targetScores !== null) {
                $hasOne = false;
                $hasFive = false;
                foreach ($p['success_lives'] ?? [] as $c) {
                    $sc = intval($c['score'] ?? 0);
                    if ($sc === 1) $hasOne = true;
                    if ($sc === 5) $hasFive = true;
                }
                if ($hasOne && $hasFive) {
                    $amount = intval($ab['amount_two'] ?? 2);
                } elseif ($hasOne || $hasFive) {
                    $amount = intval($ab['amount_one'] ?? 1);
                } else {
                    break;
                }
            } else {
                $group = $ab['group'] ?? 'μ\'s';
                $scored = count(array_filter(
                    $p['success_lives'] ?? [],
                    fn($c) => ($c['group'] ?? '') === $group && intval($c['score'] ?? 0) > 0
                ));
                if ($scored >= 2) {
                    $amount = intval($ab['amount_two'] ?? 2);
                } elseif ($scored >= 1) {
                    $amount = intval($ab['amount_one'] ?? 1);
                } else {
                    break;
                }
            }
            $state = applyModifierEffect($state, $pid, [
                'type'   => 'live_score_bonus',
                'amount' => $amount,
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Success Live score bonus +$amount until this Live ends.");
            break;

        case 'draw_if_success_lives':
            $succ = $p['success_lives'] ?? [];
            if (!empty($ab['group'])) {
                $succ = array_values(array_filter(
                    $succ,
                    fn($c) => ($c['group'] ?? '') === ($ab['group'] ?? '')
                ));
            }
            if (!empty($succ)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Success Live area not empty).");
            }
            break;

        case 'draw_if_bonus_hearts_on_stage':
            if (stageHasMemberWithExtraHearts($p)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Member with bonus hearts on Stage).");
            }
            break;

        case 'wr_live_deck_top_draw_if_opp_wait':
            $group = $ab['group'] ?? 'μ\'s';
            $candidates = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => cardMatchesGroup($c, $group, 'live')
            ));
            if (count($candidates) > 1) {
                $state['pending_prompt'] = [
                    'type'          => 'pick_wr_live_deck_top',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_name'   => $name,
                    'prompt'        => 'Choose 1 μ\'s Live card from your Waiting Room to put on top of your deck.',
                    'candidates'    => array_map('cardPromptSummary', $candidates),
                    'ability'       => $ab,
                ];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] choose a Live card from Waiting Room.');
                break;
            }
            if (count($candidates) === 1) {
                putWrCardOnDeckTop($p, $candidates[0]['instance_id'] ?? '');
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put ' .
                    ($candidates[0]['name_en'] ?? $candidates[0]['name']) . ' on top of deck.');
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (stageHasWaitMember($state, $opp)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (opponent has a Member in Wait).");
            }
            break;

        case 'wait_opponent_max_blade':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $waited = waitOpponentStageByMaxBlade(
                $state,
                $opp,
                intval($ab['max_blade'] ?? 1),
                isset($ab['pick_count']) ? intval($ab['pick_count']) : null,
                $pid
            );
            if ($waited > 0) {
                $state = addLog($state, $state['players'][$opp]['name'] .
                    " — $waited Member(s) put into Wait ([$name]).");
            }
            break;

        case 'block_effect_member_activate_turn':
            $state['block_effect_member_activate'] = true;
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Members cannot become Active by effects this turn.");
            break;

        case 'wait_opp_if_distinct_subunit':
            if (countDistinctNamedSubunit($p, $ab['subunit'] ?? '') >= intval($ab['min_distinct'] ?? 2)) {
                $opp = ($pid === 'p1') ? 'p2' : 'p1';
                $waited = waitOpponentStageByCost(
                    $state,
                    $opp,
                    intval($ab['max_cost'] ?? 4),
                    isset($ab['pick_count']) ? intval($ab['pick_count']) : null,
                    $pid
                );
                if ($waited > 0) {
                    $state = addLog($state, $state['players'][$opp]['name'] .
                        " — $waited Member(s) put into Wait ([$name]).");
                }
            }
            break;

        case 'activate_subunit_members':
            $activated = activateSubunitMembers($p, $ab['subunit'] ?? '', intval($ab['max'] ?? 1));
            if ($activated > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated $activated " . ($ab['subunit'] ?? '') . ' Member(s).');
            }
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
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_look_reveal_subunit',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Put 1 card from hand into the Waiting Room to look at the top 4 cards of your deck?',
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

        case 'if_baton_lower_cost':
            if (memberBatonFromLowerCostSubunit($source, $ab['baton_subunit'] ?? '')) {
                $then = $ab['then'] ?? [];
                if (!empty($then)) {
                    $thenType = $then['type'] ?? '';
                    if (in_array($thenType, [
                        'blade_bonus', 'hearts_and_blade_bonus', 'live_score_bonus',
                    ], true)) {
                        $state = applyModifierEffect($state, $pid, $then);
                        if ($thenType === 'blade_bonus') {
                            $state = addLog($state, $state['players'][$pid]['name'] .
                                ' — [' . $name . '] gained +' . intval($then['amount'] ?? 0) .
                                ' Blade until Live ends (Baton Touch).');
                        } else {
                            $state = addLog($state, $state['players'][$pid]['name'] .
                                ' — [' . $name . '] Baton Touch effect resolved.');
                        }
                    } else {
                        $state = resolveAbilityEffect($state, $pid, $source, $then, $ctx);
                    }
                }
            }
            break;

        case 'optional_pay_energy_if_baton':
            if (!empty($state['pending_prompt'])) break;
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

        case 'draw_if_wr_min':
            if (count($p['waiting_room'] ?? []) >= intval($ab['min_wr'] ?? 10)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Waiting Room has " . intval($ab['min_wr'] ?? 10) . "+ cards).");
            }
            break;

        case 'live_ban_until_end':
            $state = initLiveModifiers($state);
            $state['live_modifiers'][$pid]['cannot_live'] = true;
            if (intval($ab['draw'] ?? 0) > 0) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw']));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn; cannot attempt a Live until this Live ends.");
            } else {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] cannot attempt a Live until this Live ends.");
            }
            break;

        case 'deck_surveil':
            $look = intval($ab['look'] ?? 2);
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            if (count($top) <= 1) {
                if (count($top) === 1) {
                    $p['main_deck'] = array_merge($top, $p['main_deck']);
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at deck top.");
            } else {
                $state = startSurveilArrangePrompt($state, $pid, $name, $top, null, $source['instance_id'] ?? '');
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at top " . count($top) . ' — arrange them.');
            }
            break;

        case 'activate_one_member':
            $activated = activateMembersByEffect($state, $p, 1);
            if ($activated > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated $activated Member(s).");
            }
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
            $then = $ab['then'] ?? [];
            if (!optionalDiscardThenViable($p, $then)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] optional On Enter skipped (no cards left in deck).');
                break;
            }
            $energyCost = intval($ab['energy_cost'] ?? 0);
            if ($energyCost > 0 && !payEnergyCost($p, $energyCost)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] could not pay $energyCost Energy; effect skipped.");
                break;
            }
            if ($energyCost > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] paid $energyCost Energy.");
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

        case 'draw_per_stage_discard':
            $n = countStageMembers($p);
            $drawnCards = drawCardInstances($p, $n);
            foreach ($drawnCards as $c) {
                $state = logEffectDraw($state, $pid, $name, $c,
                    [animSpec($c['instance_id'], 'main_deck', 'hand', $pid)]);
            }
            $discardNeed = intval($ab['discard'] ?? 1);
            if ($discardNeed > 0 && !empty($p['hand'])) {
                return startEffectDiscardHandPrompt($state, $pid, $name, $discardNeed);
            }
            break;

        case 'reveal_hand_look_live_if_no_live':
            if (!empty($ab['requires_other_stage_member'])
                && !stageHasOtherMember($p, $source['instance_id'] ?? '')) {
                break;
            }
            $handSummary = implode(', ', array_map(
                fn($c) => cardDisplayName($c),
                $p['hand'] ?? []
            ));
            $hasLive = !empty(array_filter(
                $p['hand'] ?? [],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] revealed hand ($handSummary).");
            if (!$hasLive) {
                $picked = lookRevealGroup(
                    $p,
                    intval($ab['look'] ?? 5),
                    $ab['group'] ?? 'Nijigasaki',
                    $ab['filter'] ?? 'live',
                    intval($ab['pick'] ?? 1)
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at deck top; added $picked Live card(s) to hand.");
            }
            break;

        case 'look_reveal_filter':
            if (isset($ab['min_energy'])
                && countEnergyInZone($p) < intval($ab['min_energy'])) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] effect skipped (need ' . intval($ab['min_energy']) . '+ Energy).');
                break;
            }
            $state = beginLookRevealPick($state, $pid, $name, $p, $ab);
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
            break;

        case 'draw_and_discard':
            return applyDrawThenDiscard(
                $state,
                $pid,
                $p,
                $name,
                intval($ab['draw'] ?? 1),
                intval($ab['discard'] ?? 1),
                [
                    'ability'   => $ab,
                    'source_id' => $source['instance_id'] ?? '',
                ]
            );

        case 'activate_energy_if_success':
            if (sumSuccessLiveScores($p) >= intval($ab['min_success_score_sum'] ?? 6)) {
                $activated = activateEnergyForPlayer($p, intval($ab['count'] ?? 2));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated $activated Energy (Success Live score threshold met).");
            }
            break;

        case 'add_from_wr_max_cost':
            $added = addFromWaitingRoomFiltered(
                $p,
                $ab['group'] ?? '',
                $ab['filter'] ?? 'member',
                intval($ab['count'] ?? 1),
                intval($ab['max_cost'] ?? 2)
            );
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $added Member(s) from Waiting Room.");
            } else {
                $maxCost = intval($ab['max_cost'] ?? 2);
                $group = $ab['group'] ?? '';
                $groupLabel = $group !== '' ? $group . ' ' : '';
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no matching {$groupLabel}Member (cost ≤$maxCost) in Waiting Room.");
            }
            break;

        case 'position_change_off_center':
            $group = $ab['group'] ?? 'μ\'s';
            $minBlades = intval($ab['min_original_blades'] ?? 0);
            $hasMin = $minBlades > 0
                ? stageHasGroupMemberMinBlades($p, $group, $minBlades)
                : stageHasGroupMemberMinHearts($p, $group, intval($ab['min_hearts'] ?? 5));
            if (!$hasMin) {
                if (positionChangeOffCenter($p, $source['instance_id'] ?? '')) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] position-changed off Center.");
                }
            }
            break;

        case 'look_reveal_group':
            if (array_key_exists('min_success_score_sum', $ab)
                && sumSuccessLiveScores($p) < intval($ab['min_success_score_sum'])) {
                break;
            }
            $state = beginLookRevealPick($state, $pid, $name, $p, $ab);
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
            break;

        case 'grant_live_score_if_success':
            $succCount = count($p['success_lives'] ?? []);
            $scoreSum = sumSuccessLiveScores($p);
            if ($succCount >= intval($ab['min_success_count'] ?? 1)
                && $scoreSum <= intval($ab['max_success_score_sum'] ?? 1)) {
                $state = applyModifierEffect($state, $pid, [
                    'type'   => 'live_score_bonus',
                    'amount' => intval($ab['amount'] ?? 1),
                ]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] Live total score +' . intval($ab['amount'] ?? 1) . ' until Live ends.');
            }
            break;

        case 'wait_opponent_active':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $waited = waitOpponentActiveMembers($state, $opp, intval($ab['count'] ?? 1), $pid);
            if ($waited > 0) {
                $state = addLog($state, $state['players'][$opp]['name'] .
                    " — $waited active Member(s) put into Wait ([$name]).");
            }
            break;

        case 'activate_all_members':
            $activated = activateMembersByEffect($state, $p, 99);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] activated $activated Member(s).");
            break;

        case 'activate_members':
            $activated = activateMembersByEffect($state, $p, intval($ab['max'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] activated $activated Member(s).");
            break;

        case 'optional_discard_add_from_wr':
            if (!empty($ab['requires_success_lives']) && empty($p['success_lives'])) break;
            if (empty($ctx['discard_ids']) && empty($ctx['confirm'])) break;
            $ids = $ctx['discard_ids'] ?? [];
            if (!empty($ids)) {
                discardFromHandByIds($p, $ids);
            } elseif (!empty($ctx['confirm'])) {
                autoDiscardFromHand($p, intval($ab['discard'] ?? 1));
            }
            $added = addFromWaitingRoomFiltered(
                $p,
                $ab['group'] ?? '',
                $ab['filter'] ?? '',
                intval($ab['count'] ?? 1)
            );
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $added card(s) from Waiting Room.");
            }
            break;

        case 'optional_discard_hand':
            if (empty($ctx['discard_ids']) && empty($ctx['confirm'])) break;
            $need = intval($ab['discard'] ?? 1);
            if (!empty($ctx['discard_ids'])) {
                discardFromHandByIds($p, $ctx['discard_ids']);
            } else {
                autoDiscardFromHand($p, $need);
            }
            if (!empty($ab['then'])) {
                $then = $ab['then'];
                if (($then['type'] ?? '') === 'blade_bonus_per_success') {
                    $then['amount'] = intval($then['amount'] ?? 1);
                    $state = applyModifierEffect($state, $pid, $then);
                } elseif (($then['type'] ?? '') === 'member_blade_bonus') {
                    $n = applyMemberBladeBonus($state, $pid, $then);
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] discarded $need; $n Member(s) gained +" . intval($then['amount'] ?? 0) . ' Blade.');
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
                discardFromHandByIds($p, $ctx['discard_ids']);
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

        case 'draw_if_stage_cost_min':
            if (stageHasMemberMinCost($p, intval($ab['min_cost'] ?? 13))) {
                $drawnCards = drawCardInstances($p, intval($ab['draw'] ?? 1));
                foreach ($drawnCards as $c) {
                    $state = logEffectDraw($state, $pid, $name, $c,
                        [animSpec($c['instance_id'], 'main_deck', 'hand', $pid)]);
                }
                $drawn = count($drawnCards);
                if ($drawn > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] drew $drawn (Stage has cost " . intval($ab['min_cost'] ?? 13) . "+ Member).");
                }
            }
            break;

        case 'choose_heart_per_success':
            if (!empty($state['pending_prompt'])) break;
            $choices = $ab['heart_choices'] ?? ['yellow', 'pink', 'purple'];
            $labels = array_map(fn($c) => ucfirst($c) . ' ♡', $choices);
            $state['pending_prompt'] = [
                'type'          => 'choose_heart_per_success',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Choose a heart color to gain for each Success Live card until this Live ends.',
                'choices'       => $choices,
                'choice_labels' => $labels,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start: choose a heart color.');
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
            if (waitFirstGroupMember($p, $ab['group'] ?? 'μ\'s')) {
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

        case 'score_if_live_zone_group':
            $cnt = countLiveZoneGroup($p['live_zone'], $ab['group'] ?? 'μ\'s');
            if ($cnt >= intval($ab['min_count'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . " ($cnt μ's cards in Live).");
            }
            break;

        case 'reveal_per_both_stage_member':
            $members = countBothStagesMembers($state);
            $top = array_splice($p['main_deck'], 0, min($members, count($p['main_deck'])));
            $liveCount = 0;
            foreach ($top as $c) {
                if (($c['card_type'] ?? '') === 'ライブ') $liveCount++;
            }
            if ($liveCount > 0) {
                $bonus = $liveCount * intval($ab['score_per_live'] ?? 1);
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $bonus);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] revealed $liveCount Live card(s); score +$bonus.");
            }
            if (!empty($top)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $top);
            }
            break;

        case 'reduce_required_hearts_if_blade':
            if (totalStageBlade($p) >= intval($ab['min_blade'] ?? 10)) {
                $reduce = intval($ab['reduce'] ?? 2);
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $reduce;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $reduce;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$reduce Gray heart(s)" : "$reduce heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced (Stage Blade 10+).");
            }
            break;

        case 'score_if_success_lives':
            if (count($p['success_lives'] ?? []) >= intval($ab['min_success'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (2+ Success Lives).');
            }
            break;

        case 'choose_heart_one_mus_member':
            if (!empty($ab['requires_success_lives']) && empty($p['success_lives'])) break;
            if (!empty($state['pending_prompt'])) break;
            $choices = $ab['heart_choices'] ?? ['yellow', 'pink', 'purple'];
            $state['pending_prompt'] = [
                'type'          => 'choose_heart_mus_member',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Choose a heart for 1 μ\'s Member on your Stage to gain until this Live ends.',
                'choices'       => $choices,
                'choice_labels' => array_map(fn($c) => ucfirst($c) . ' ♡', $choices),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start: choose a heart for a μ\'s Member.');
            break;

        case 'score_if_no_excess_hearts':
            if (intval($ctx['excess_hearts'] ?? -1) === 0) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] Live success with no excess hearts; score +' . intval($ab['amount'] ?? 1) . '.');
            }
            break;

        case 'draw_if_excess_heart':
            $colors = $ctx['excess_heart_colors'] ?? [];
            $need = $ab['color'] ?? 'yellow';
            $has = count(array_filter($colors, fn($c) => $c === $need));
            if ($has >= 1) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (excess $need heart(s)).");
            }
            break;

        case 'draw_if_success_score':
            if (sumSuccessLiveScores($p, $state, $pid) >= intval($ab['min_success_score_sum'] ?? 3)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Success Live score threshold met).");
            }
            break;

        case 'other_member_blade_if_plain_live':
            if (liveZoneHasPlainLive($p['live_zone'])) {
                $n = applyMemberBladeBonus($state, $pid, [
                    'amount'             => intval($ab['amount'] ?? 2),
                    'max_members'        => intval($ab['max_members'] ?? 1),
                    'exclude_source_id'  => $source['instance_id'] ?? '',
                ]);
                if ($n > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] $n other Member(s) gained +" . intval($ab['amount'] ?? 2) . ' Blade until Live ends.');
                }
            }
            break;

        case 'member_blade_bonus':
            $n = applyMemberBladeBonus($state, $pid, $ab);
            if ($n > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] $n Member(s) gained +" . intval($ab['amount'] ?? 0) . ' Blade until Live ends.');
            }
            break;

        case 'optional_wait_self_center_blade':
            if (!empty($state['pending_prompt'])) break;
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

        case 'reduce_hearts_if_success_score':
            $scoreSum = sumSuccessLiveScores($p, $state, $pid);
            if ($scoreSum >= intval($ab['min_score_6'] ?? 6)) {
                $reduce = intval($ab['reduce'] ?? 1);
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $reduce;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $reduce;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$reduce Gray heart(s)" : "$reduce heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced.");
                if ($scoreSum >= intval($ab['min_score_9'] ?? 9)) {
                    bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['bonus_score'] ?? 1));
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] score +' . intval($ab['bonus_score'] ?? 1) . ' (Success score 9+).');
                }
            }
            break;

        case 'score_if_center_blade':
            $center = $p['stage']['center'] ?? null;
            if ($center && ($center['group'] ?? '') === ($ab['group'] ?? 'μ\'s')) {
                $blade = getMemberBlade($center, $state, $pid, 'center');
                if ($blade >= intval($ab['min_blade'] ?? 9)) {
                    bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 2));
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] score +' . intval($ab['amount'] ?? 2) . ' (Center Blade ' . $blade . '+).');
                }
            }
            break;

        case 'score_if_stage_hearts_more':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $mine = countStageHearts($p);
            $theirs = countStageHearts($state['players'][$opp]);
            if ($mine > $theirs) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Live success ($mine vs $theirs stage hearts); score +" . intval($ab['amount'] ?? 1) . '.');
            }
            break;

        case 'draw_if_stage_cost_less_than_opp':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (sumStageMemberCost($p, $state, $pid) < sumStageMemberCost($state['players'][$opp], $state, $opp)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Stage cost lower than opponent's).");
            }
            break;

        case 'add_from_wr_if_success_count':
            if (count($p['success_lives'] ?? []) >= intval($ab['min_success_count'] ?? 2)) {
                $added = addFromWaitingRoomFiltered(
                    $p,
                    $ab['group'] ?? '',
                    $ab['filter'] ?? 'live',
                    intval($ab['count'] ?? 1)
                );
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added $added Live card(s) from Waiting Room (2+ Success Lives).");
                }
            }
            break;

        case 'mill_deck_to_wr':
            $n = intval($ab['count'] ?? 5);
            $milled = takeFromMainDeckTop($state, $pid, $n);
            if (!empty($milled)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
                $state = spBp5NotifyCardsToWr($state, $pid, $milled);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put " . count($milled) . ' card(s) from deck top into Waiting Room.');
            }
            break;

        case 'mill_then_heart_if_all_members':
            $n = intval($ab['count'] ?? 3);
            $milled = array_splice($p['main_deck'], 0, min($n, count($p['main_deck'])));
            $color = $ab['heart_color'] ?? 'green';
            $allMatch = !empty($milled);
            foreach ($milled as $c) {
                if (($c['card_type'] ?? '') !== 'メンバー' || !memberHasHeartColor($c, $color)) {
                    $allMatch = false;
                    break;
                }
            }
            if (!empty($milled)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put " . count($milled) . ' card(s) from deck top into Waiting Room.');
            }
            if ($allMatch && count($milled) >= $n) {
                $cnt = intval($ab['heart_count'] ?? 1);
                addBonusHeartsToModifier($state, $pid, [['color' => $color, 'count' => $cnt]]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] gained $cnt $color heart(s) until this Live ends (all milled Members matched).");
            }
            break;

        case 'mill_then_blade_if_all_member_hearts':
            $n = intval($ab['count'] ?? 3);
            $milled = array_splice($p['main_deck'], 0, min($n, count($p['main_deck'])));
            $allMatch = !empty($milled);
            $reqColor = $ab['require_heart_color'] ?? '';
            foreach ($milled as $c) {
                if (($c['card_type'] ?? '') !== 'メンバー') {
                    $allMatch = false;
                    break;
                }
                if ($reqColor !== '') {
                    if (!memberHasHeartColor($c, $reqColor)) {
                        $allMatch = false;
                        break;
                    }
                } elseif (!memberHasAnyHeart($c)) {
                    $allMatch = false;
                    break;
                }
            }
            if (!empty($milled)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put " . count($milled) . ' card(s) from deck top into Waiting Room.');
            }
            if ($allMatch && count($milled) >= $n) {
                if (!empty($ab['hearts'])) {
                    addBonusHeartsToModifier($state, $pid, $ab['hearts']);
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] gained bonus heart(s) (all milled Members matched).');
                } else {
                    $state = applyModifierEffect($state, $pid, [
                        'type'   => 'blade_bonus',
                        'amount' => intval($ab['amount'] ?? 3),
                    ]);
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] gained +' . intval($ab['amount'] ?? 3) . ' Blade (all milled Members had hearts).');
                }
            }
            break;

        case 'activate_if_baton_to_wr':
            $incoming = $ctx['baton_incoming'] ?? null;
            if ($incoming) {
                mergeCardCatalogFields($incoming);
                if (!isMemberCard($incoming)) {
                    break;
                }
                $fromCost = intval($incoming['cost'] ?? 0);
                $fromGroup = $incoming['group'] ?? '';
            } else {
                if (empty($source['entered_via_baton'])) {
                    break;
                }
                $fromCost = intval($source['baton_from_cost'] ?? -1);
                $fromGroup = $source['baton_from_group'] ?? '';
            }
            if ($fromCost < intval($ab['min_baton_cost'] ?? 10)) {
                break;
            }
            if (($ab['group'] ?? '') !== '' && $fromGroup !== ($ab['group'] ?? '')) {
                break;
            }
            $want = intval($ab['count'] ?? 2);
            $activated = activateEnergyForPlayer($p, $want);
            $msg = $state['players'][$pid]['name'] .
                " — [$name] activated $activated Energy (Baton Touch to Waiting Room).";
            if ($activated < $want) {
                $msg .= ' (' . ($want - $activated) . ' already active.)';
            }
            $state = addLog($state, $msg);
            break;

        case 'if_baton_wr_add_live_not_self':
            if (empty($source['entered_via_baton'])) break;
            $batonId = $source['baton_wr_member_id'] ?? '';
            if ($batonId === '') break;
            $batonCard = null;
            foreach ($p['waiting_room'] as $c) {
                if (($c['instance_id'] ?? '') === $batonId) {
                    $batonCard = $c;
                    break;
                }
            }
            if ($batonCard && cardNameMatchesList($batonCard, $ab['exclude_names'] ?? [])) {
                break;
            }
            if (!$batonCard || !cardMatchesGroup($batonCard, $ab['group'] ?? '', 'member')) break;
            $added = addFromWaitingRoomFiltered(
                $p,
                $ab['group'] ?? '',
                'live',
                1
            );
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added 1 Live card from Waiting Room (Baton Touch).");
            }
            break;

        case 'on_enter_if_named_activate_add_wr':
            if (!stageHasNamedMember($p, $ab['names'] ?? [])) break;
            $activated = activateEnergyForPlayer($p, intval($ab['activate'] ?? 1));
            $added = addFromWaitingRoomFiltered(
                $p,
                $ab['group'] ?? '',
                $ab['filter'] ?? 'live',
                intval($ab['count'] ?? 1)
            );
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] activated $activated Energy; added $added card(s) from Waiting Room.");
            break;

        case 'draw_discard_if_group_on_stage':
            if (!stageHasGroupMember($p, $ab['group'] ?? '')) break;
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            if (intval($ab['discard'] ?? 0) > 0 && !empty($p['hand'])) {
                autoDiscardFromHand($p, intval($ab['discard'] ?? 1));
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn and discarded " . intval($ab['discard'] ?? 0) . '.');
            break;

        case 'draw_until_hand':
            $target = intval($ab['target'] ?? 5);
            $drawn = 0;
            while (count($p['hand']) < $target && !empty($p['main_deck'])) {
                $p['hand'][] = array_shift($p['main_deck']);
                $drawn++;
            }
            if ($drawn > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (hand size " . count($p['hand']) . ").");
            }
            break;

        case 'choose_heart_other_member':
            if (!empty($state['pending_prompt'])) break;
            $choices = $ab['heart_choices'] ?? ['yellow', 'pink', 'purple', 'green', 'blue', 'red'];
            $state['pending_prompt'] = [
                'type'          => 'choose_heart_other_member',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Choose a heart color for another Member on your Stage.',
                'choices'       => $choices,
                'choice_labels' => array_map(fn($c) => ucfirst($c) . ' ♡', $choices),
                'ability'       => $ab,
            ];
            break;

        case 'blade_per_discarded_pick_member':
            if (!empty($state['pending_prompt'])) break;
            $discarded = intval($ctx['discarded_count'] ?? 0);
            if ($discarded <= 0) break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                if (($ab['group'] ?? '') !== '' && ($mbr['group'] ?? '') !== ($ab['group'] ?? '')) continue;
                $candidates[] = cardPromptSummary($mbr) + ['slot' => $slot];
            }
            if (empty($candidates)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no matching Member on Stage for +Blade (discarded $discarded).");
                break;
            }
            if (count($candidates) === 1) {
                $bonus = intval($ab['amount'] ?? 3) * $discarded;
                $slot = $candidates[0]['slot'] ?? '';
                if ($slot !== '' && !empty($p['stage'][$slot])) {
                    $p['stage'][$slot]['live_blade_bonus'] =
                        intval($p['stage'][$slot]['live_blade_bonus'] ?? 0) + $bonus;
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] chosen Member gains +$bonus Blade.");
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'blade_per_discarded_pick_member',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Choose 1 Member on your Stage to gain Blade.',
                'candidates'    => $candidates,
                'discarded'     => $discarded,
                'ability'       => $ab,
            ];
            break;

        case 'mill_then_draw_if_all_members':
            $n = intval($ab['count'] ?? 3);
            $milled = array_splice($p['main_deck'], 0, min($n, count($p['main_deck'])));
            $allMembers = !empty($milled);
            foreach ($milled as $c) {
                if (($c['card_type'] ?? '') !== 'メンバー') {
                    $allMembers = false;
                    break;
                }
            }
            if (!empty($milled)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put " . count($milled) . ' card(s) from deck top into Waiting Room.');
            }
            if ($allMembers && count($milled) >= $n) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (all milled cards were Members).");
            }
            break;

        case 'optional_stage_reposition':
            if (countStageMembers($p) < 2) break;
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_stage_reposition',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'You may move Members on your Stage to any areas (confirm to keep current layout)?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Keep layout', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional Stage reposition (choose).');
            break;

        case 'pick_yell_member':
            if (($ctx['phase'] ?? '') !== 'live_success') break;
            $yellPool = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            $candidates = array_values(array_filter(
                $yellPool,
                fn($c) => cardMatchesYellPick($c, $ab)
            ));
            if (empty($candidates)) break;
            if (count($candidates) === 1) {
                $picked = $candidates[0];
                $ownerP['hand'][] = $picked;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added ' . cardDisplayName($picked) . ' from Yell to hand.');
                break;
            }
            $state['pending_prompt'] = [
                'type'        => 'pick_yell_member',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'prompt'      => 'Choose 1 card revealed by Yell to add to your hand.',
                'candidates'  => array_map('cardPromptSummary', $candidates),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose a Yell card.');
            break;

        case 'live_score_if_stage_wr_name_live':
            if (countStageGroupMembers($p, $ab['group'] ?? '')
                >= intval($ab['min_stage_members'] ?? 3)
                && wrHasLiveNameContains($p, $ab['wr_name_contains'] ?? '')) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (stage + Waiting Room Live name).');
            }
            break;

        case 'live_score_if_yell_group_count':
            $yellCards = $ctx['yell_cards'] ?? [];
            $cnt = count(array_filter(
                $yellCards,
                fn($c) => cardMatchesGroup($c, $ab['group'] ?? '', $ab['filter'] ?? 'member')
            ));
            if ($cnt >= intval($ab['min_count'] ?? 1)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    " ($cnt Yell cards matched).");
            }
            break;

        case 'live_success_energy_wait_if_winning':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $myZone = $p['live_zone'] ?? [];
            $oppZone = $state['players'][$opp]['live_zone'] ?? [];
            $myScore = empty($myZone) ? 0
                : array_sum(array_column($myZone, 'score')) + getLiveScoreBonus($state, $pid);
            $oppScore = empty($oppZone) ? 0
                : array_sum(array_column($oppZone, 'score')) + getLiveScoreBonus($state, $opp);
            if ($myScore <= $oppScore) break;
            if (($ab['group'] ?? '') !== '' && !stageHasGroupMember($p, $ab['group'])) break;
            if (putEnergyFromDeckInWait($p)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put 1 Energy from Energy deck into Wait.');
            }
            break;

        case 'live_success_energy_wait_if_excess':
            if (intval($ctx['excess_hearts'] ?? 0) < intval($ab['min_excess'] ?? 1)) break;
            $exColor = $ab['excess_color'] ?? '';
            if ($exColor !== '') {
                $colorExcess = intval($ctx['excess_hearts_by_color'][$exColor] ?? 0);
                if ($colorExcess < intval($ab['min_excess'] ?? 1)) break;
            }
            if (!stageHasGroupMember($p, $ab['group'] ?? '')) break;
            if (putEnergyFromDeckInWait($p)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put 1 Energy from Energy deck into Wait (excess hearts).');
            }
            break;

        case 'live_score_per_stage_wait_member':
            $cnt = countStageWaitMembers($p);
            if ($cnt > 0) {
                $bonus = $cnt * intval($ab['amount'] ?? 1);
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $bonus);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] score +$bonus ($cnt Member(s) in Wait).");
            }
            break;

        case 'live_score_if_yell_has_hearts':
            break;

        case 'surveil_per_group_member_reveal_live':
            if (!empty($state['pending_prompt'])) break;
            $look = countGroupMembersOnStage($p, $ab['group'] ?? '', $ab['filter'] ?? 'member');
            if ($look <= 0) {
                $state = revealDeckTopLiveScore($state, $pid, $source, intval($ab['score_amount'] ?? 1));
                break;
            }
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            if (empty($top)) break;
            if (count($top) === 1) {
                $p['main_deck'] = array_merge($top, $p['main_deck']);
                $state = revealDeckTopLiveScore($state, $pid, $source, intval($ab['score_amount'] ?? 1));
                break;
            }
            $maxTop = intval($ab['max_top'] ?? 1);
            $chain = [
                'type'        => 'reveal_top_live_score',
                'source_id'   => $source['instance_id'] ?? '',
                'score_amount'=> intval($ab['score_amount'] ?? 1),
                'max_top'     => $maxTop,
            ];
            $state = startSurveilArrangePrompt($state, $pid, $name, $top, $chain);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] looked at $look card(s); arrange deck top.");
            break;

        case 'optional_return_member_energy':
            break;

        case 'mill_deck_draw_if_live':
            $n = intval($ab['count'] ?? 5);
            $milled = array_splice($p['main_deck'], 0, min($n, count($p['main_deck'])));
            $hasLive = false;
            foreach ($milled as $c) {
                if (($c['card_type'] ?? '') === 'ライブ') $hasLive = true;
            }
            if (!empty($milled)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
            }
            if ($hasLive) {
                $drawn = drawCardsForPlayer($state, $pid, 1);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] milled $n; drew $drawn (Live found).");
            } else {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] milled $n; no Live card found.");
            }
            break;

        case 'activate_energy':
            $activated = activateEnergyForPlayer($p, intval($ab['count'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] activated $activated Energy.");
            break;

        case 'discard_add_from_wr':
            return startEffectDiscardHandPrompt(
                $state,
                $pid,
                $name,
                intval($ab['discard'] ?? 1),
                '',
                ['then' => [
                    'type'   => 'add_from_wr',
                    'group'  => $ab['group'] ?? '',
                    'filter' => $ab['filter'] ?? 'member',
                    'count'  => intval($ab['count'] ?? 1),
                ]]
            );

        case 'add_from_wr':
            $extra = [];
            if (isset($ab['min_score'])) {
                $extra['min_score'] = intval($ab['min_score']);
            }
            if (isset($ab['min_live_score'])) {
                $extra['min_live_score'] = intval($ab['min_live_score']);
            }
            $added = addFromWaitingRoomFiltered(
                $p,
                $ab['group'] ?? '',
                $ab['filter'] ?? '',
                intval($ab['count'] ?? 1),
                isset($ab['max_cost']) ? intval($ab['max_cost']) : null,
                $extra
            );
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $added card(s) from Waiting Room.");
            }
            break;

        case 'live_start_surveil':
            $look = intval($ab['look'] ?? 3);
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            if (count($top) <= 1) {
                if (count($top) === 1) {
                    $p['hand'][] = $top[0];
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at deck top.");
            } else {
                $state = startSurveilArrangePrompt($state, $pid, $name, $top, null, $source['instance_id'] ?? '');
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at top " . count($top) . ' — arrange them.');
            }
            break;

        case 'reduce_hearts_per_success_count':
            $n = count($p['success_lives'] ?? []) * intval($ab['per_success'] ?? 1);
            if ($n > 0) {
                $color = $ab['color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $n;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $n;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$n Gray heart(s)" : "$n heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required hearts reduced by $label (Success Live area).");
            }
            break;

        case 'choose_heart_modifier':
            if (!empty($state['pending_prompt'])) break;
            $choices = $ab['heart_choices'] ?? ['yellow', 'pink', 'purple'];
            $state['pending_prompt'] = [
                'type'          => 'choose_heart_modifier',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Choose a heart color to gain until this Live ends.',
                'choices'       => $choices,
                'choice_labels' => array_map(fn($c) => ucfirst($c) . ' ♡', $choices),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose a heart color.');
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
                'step'          => 'confirm',
                'prompt'        => 'Reveal 1 Live card from your hand: add 1 Success Live card to your hand, then put the revealed card into your Success Live area?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose).');
            break;

        case 'both_wr_member_to_empty_stage':
            $maxCost = intval($ab['max_cost'] ?? 2);
            foreach (['p1', 'p2'] as $id) {
                $placed = putWrMemberToEmptyStageWait($state['players'][$id], $maxCost);
                if ($placed) {
                    $m = $placed['member'];
                    $state = addLog($state, $state['players'][$id]['name'] .
                        ' — [' . $name . '] put ' . ($m['name_en'] ?? $m['name']) .
                        ' from Waiting Room onto Stage in Wait.');
                }
            }
            break;

        case 'activate_subunit_from_wait_score':
            $subunit = $ab['subunit'] ?? '';
            $activated = activateSubunitFromWait($p, $subunit);
            if ($activated > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated $activated $subunit Member(s) from Wait.");
            }
            if ($activated >= intval($ab['min_activated'] ?? 3)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    " ($activated Members activated from Wait).");
            }
            break;

        case 'score_if_subunit_only_no_success':
            if (empty($p['success_lives'])
                && stageAllMembersInSubunit($p, $ab['subunit'] ?? '')) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (lily white only, no Success Lives).');
            }
            break;

        case 'reduce_hearts_if_opp_wait':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (stageHasWaitMember($state, $opp)) {
                $reduce = intval($ab['reduce'] ?? 1);
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $reduce;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $reduce;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$reduce Gray heart(s)" : "$reduce heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced (opponent has Wait).");
            }
            break;

        case 'live_success_add_wr_if_distinct_subunit':
            if (countDistinctNamedSubunit($p, $ab['subunit'] ?? '') >= intval($ab['min_distinct'] ?? 2)) {
                $added = addFromWaitingRoomFiltered(
                    $p,
                    $ab['group'] ?? '',
                    $ab['filter'] ?? 'member',
                    intval($ab['count'] ?? 1),
                    null,
                    ['subunit' => $ab['subunit'] ?? '']
                );
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added $added " . ($ab['subunit'] ?? '') . " card(s) from Waiting Room.");
                }
            }
            break;

        case 'score_if_distinct_subunit':
            if (countDistinctNamedSubunit($p, $ab['subunit'] ?? '') >= intval($ab['min_distinct'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (2+ distinct ' . ($ab['subunit'] ?? '') . ' Members).');
            }
            break;

        case 'score_if_stage_blade':
            if (totalStageBlade($p) >= intval($ab['min_blade'] ?? 10)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (Stage Blade ' . totalStageBlade($p) . '+).');
            }
            break;

        case 'yell_hearts_wildcard':
        case 'yell_heart_score_bonus':
        case 'draw_per_yell_heart':
            break;

        case 'waive_one_required_heart_color':
            if (!stageHasGroupMember($p, $ab['requires_stage_group'] ?? '')) break;
            if (!empty($state['pending_prompt'])) break;
            $choices = $ab['colors'] ?? ['pink', 'green', 'blue'];
            $state['pending_prompt'] = [
                'type'          => 'waive_required_heart_color',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Choose 1 required heart color you do not need for this Live.',
                'choices'       => $choices,
                'choice_labels' => array_map(fn($c) => ucfirst($c) . ' ♡ waived', $choices),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose a heart color to waive.');
            break;

        case 'choose_required_heart_pair_gray':
            if (!stageHasGroupMember($p, $ab['requires_stage_group'] ?? '')) break;
            if (!empty($state['pending_prompt'])) break;
            $choices = $ab['colors'] ?? ['pink', 'green', 'blue'];
            $state['pending_prompt'] = [
                'type'          => 'choose_required_heart_pair_gray',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Choose pink, green, or blue — required hearts become 2 of that color and 1 Gray Heart.',
                'choices'       => $choices,
                'choice_labels' => array_map(
                    fn($c) => ucfirst($c) . ' ♡, ' . ucfirst($c) . ' ♡, Gray ♡',
                    $choices
                ),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose required heart pattern.');
            break;

        case 'score_per_distinct_group_stage':
            $cnt = countDistinctNamedGroupOnStage(
                $p,
                $ab['group'] ?? '',
                $ab['filter'] ?? 'member'
            );
            if ($cnt > 0) {
                $bonus = $cnt * intval($ab['amount'] ?? 1);
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $bonus);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] score +$bonus ($cnt distinct " . ($ab['group'] ?? '') . ' Members).');
            }
            break;

        case 'reduce_hearts_if_baton_group':
            $turn = intval($state['turn'] ?? 1);
            $cnt = countBatonEnteredGroupThisTurn($p, $ab['group'] ?? '', $turn);
            if ($cnt >= intval($ab['min_baton'] ?? 2)) {
                bumpLiveCardColorReduction(
                    $state,
                    $pid,
                    $source['instance_id'] ?? '',
                    $ab['color'] ?? 'any',
                    intval($ab['reduce'] ?? 1)
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] required ' . ($ab['color'] ?? 'any') .
                    ' hearts reduced by ' . intval($ab['reduce'] ?? 1) .
                    " ($cnt Baton-entered Members).");
            }
            break;

        case 'live_score_if_wr_subunit_count':
            $cnt = countWrSubunitFilter($p, $ab['subunit'] ?? '', $ab['filter'] ?? 'live');
            if ($cnt >= intval($ab['min_count'] ?? 3)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (' . ($ab['subunit'] ?? '') . " Live in WR: $cnt).");
            }
            break;

        case 'reduce_hearts_if_named_cost_pair':
            $baseNames = $ab['base_names'] ?? [];
            $higherNames = $ab['higher_names'] ?? [];
            $baseCost = null;
            $ok = false;
            foreach ($p['stage'] as $mbr) {
                if (!$mbr || !cardMatchesNames($mbr, $baseNames)) continue;
                $baseCost = intval($mbr['cost'] ?? 0);
                break;
            }
            if ($baseCost !== null) {
                foreach ($p['stage'] as $mbr) {
                    if (!$mbr || !cardMatchesNames($mbr, $higherNames)) continue;
                    if (intval($mbr['cost'] ?? 0) > $baseCost) {
                        $ok = true;
                        break;
                    }
                }
            }
            if ($ok) {
                bumpLiveCardColorReduction(
                    $state,
                    $pid,
                    $source['instance_id'] ?? '',
                    $ab['color'] ?? 'any',
                    intval($ab['reduce'] ?? 1)
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] required ' . ($ab['color'] ?? 'any') .
                    ' hearts reduced by ' . intval($ab['reduce'] ?? 1) . '.');
            }
            break;

        case 'score_if_named_stage_slots':
            if (stageNamedSlotsMatch($p, $ab['slots'] ?? [])) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (named Members in position).');
            }
            break;

        case 'activate_energy_if_other_group':
            $group = $ab['group'] ?? '';
            $hasOther = false;
            foreach ($p['stage'] as $mbr) {
                if (!$mbr) continue;
                if (($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) continue;
                if (($mbr['group'] ?? '') === $group) {
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

        case 'treat_as_subunits':
            break;

        case 'live_success_energy_wait_if_fewer':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (countEnergyInZone($p) >= countEnergyInZone($state['players'][$opp])) {
                break;
            }
            if (putEnergyFromDeckInWait($p)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put 1 Energy from Energy deck into Wait (fewer Energy).');
            }
            break;

        case 'draw_if_live_score_higher_than_opp':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (getLiveTotalScore($state, $pid) > getLiveTotalScore($state, $opp)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Live score higher).");
            }
            break;

        case 'draw_cards':
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['count'] ?? $ab['draw'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn.");
            break;

        case 'draw_per_energy':
            $per = max(1, intval($ab['per'] ?? 6));
            $n = intdiv(countEnergyInZone($p), $per);
            if ($n > 0) {
                $drawn = drawCardsForPlayer($state, $pid, $n);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (1 per $per Energy).");
            }
            break;

        case 'wait_opponent_stage_max_cost':
            $state = beginWaitOpponentStagePick(
                $state,
                $pid,
                $name,
                $ab,
                $source['instance_id'] ?? '',
                ($ctx['phase'] ?? '') === 'live_start'
                    || ($state['phase'] ?? '') === 'live_start_effects'
            );
            break;

        case 'pick_wr_members_deck_top_by_opp_wait':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $need = countOppWaitMembers($state, $opp);
            if ($need <= 0) {
                break;
            }
            $candidates = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => cardMatchesGroup($c, $ab['group'] ?? '', $ab['filter'] ?? 'member')
            ));
            if (empty($candidates)) {
                break;
            }
            $pick = min($need, count($candidates));
            if (count($candidates) > $pick) {
                $state['pending_prompt'] = [
                    'type'          => 'pick_wr_members_deck_top',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_name'   => $name,
                    'prompt'        => "Choose up to $pick Nijigasaki Member(s) from Waiting Room for deck top.",
                    'candidates'    => array_map('cardPromptSummary', $candidates),
                    'pick_count'    => $pick,
                    'ability'       => $ab,
                ];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] choose up to $pick Member(s) from Waiting Room.");
                break;
            }
            $picked = array_slice($candidates, 0, $pick);
            $pickIds = array_map(fn($c) => $c['instance_id'] ?? '', $picked);
            $p['waiting_room'] = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => !in_array($c['instance_id'] ?? '', $pickIds, true)
            ));
            $p['main_deck'] = array_merge(array_reverse($picked), $p['main_deck']);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] put $pick Member(s) from Waiting Room on deck top.");
            break;

        case 'optional_pay_play_hand_member':
        case 'optional_play_hand_member':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $cost = intval($ab['cost'] ?? 0);
            $group = $ab['group'] ?? 'Nijigasaki';
            $maxCost = intval($ab['max_cost'] ?? 4);
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
                    'put 1 ' . $group . ' Member (cost ≤' . $maxCost .
                    ') from your hand onto your Stage?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => [
                    ($cost > 0 ? 'Yes — Pay & Play' : 'Yes — Play'),
                    'No — Skip',
                ],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose).');
            break;

        case 'both_add_wr_live_to_hand':
            foreach (['p1', 'p2'] as $id) {
                $pl = &$state['players'][$id];
                $added = addFromWaitingRoomFiltered($pl, '', 'live', 1);
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$id]['name'] .
                        " — [$name] added 1 Live card from Waiting Room to hand.");
                }
                unset($pl);
            }
            break;

        case 'both_energy_wait_from_deck':
            foreach (['p1', 'p2'] as $id) {
                $pl = &$state['players'][$id];
                if (putEnergyFromDeckInWait($pl)) {
                    $state = addLog($state, $state['players'][$id]['name'] .
                        " — [$name] put 1 Energy into Wait.");
                }
                unset($pl);
            }
            break;

        case 'draw_if_stage_cost_less_surveil':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (sumStageMemberCost($p, $state, $pid)
                >= sumStageMemberCost($state['players'][$opp], $state, $opp)) {
                break;
            }
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 2));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (Stage cost lower).");
            $topN = intval($ab['deck_top'] ?? 1);
            if ($topN > 0 && count($p['hand']) >= $topN) {
                return startEffectDiscardHandPrompt(
                    $state,
                    $pid,
                    $name,
                    $topN,
                    "Choose $topN card(s) to put on top of your deck (left = top).",
                    ['pick_mode' => 'deck_top']
                );
            }
            break;

        case 'live_start_match_success_heart':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $lives = array_values(array_filter(
                $p['live_zone'] ?? [],
                fn($c) => $c && cardMatchesGroup($c, $ab['group'] ?? '', 'live')
            ));
            if (empty($lives)) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'pick_live_match_success_heart',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $lives),
                'ability'       => $ab,
                'prompt'        => 'Choose 1 Nijigasaki Live card in your Live.',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose a Live card.');
            break;

        case 'mill_then_add_wr_live_distinct':
            $n = intval($ab['count'] ?? 5);
            $milled = array_splice($p['main_deck'], 0, min($n, count($p['main_deck'])));
            if (!empty($milled)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put ' . count($milled) . ' card(s) into Waiting Room.');
            }
            if (countDistinctWrLives($p, $ab['group'] ?? '') >= intval($ab['min_distinct'] ?? 3)) {
                $added = addFromWaitingRoomFiltered(
                    $p,
                    $ab['group'] ?? '',
                    'live',
                    1
                );
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added $added Live card(s) from Waiting Room.");
                }
            }
            break;

        case 'optional_wr_to_deck_top':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            if (empty($p['waiting_room'])) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_wr_to_deck_top',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $p['waiting_room']),
                'prompt'        => 'Put 1 card from your Waiting Room on top of your deck?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Choose card', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional On Enter (choose).');
            break;

        case 'optional_wait_group_member_draw_discard':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_group_member_draw_discard',
                'owner'         => $pid,
                'responder'     => $pid,
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

        case 'yell_blades_to_blue':
            $state = initLiveModifiers($state);
            $state['live_modifiers'][$pid]['yell_blades_to_blue'] = true;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Yell Blade hearts become Blue until Live ends.');
            break;

        case 'yell_all_heart_types_score_bonus':
            $yellCards = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            if (yellMembersHaveAllHeartColors($yellCards)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (all heart colors in Yell).');
            }
            break;

        case 'score_per_named_success_live':
            $cnt = countNamedSuccessLives($p, $ab['name'] ?? '');
            if ($cnt > 0) {
                $bonus = $cnt * intval($ab['score_per'] ?? 2);
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $bonus);
                $inc = $cnt * intval($ab['hearts_increase'] ?? 3);
                $incColor = $ab['hearts_increase_color'] ?? 'any';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($incColor === 'gray') {
                            $lc['hearts_increase_gray'] = intval($lc['hearts_increase_gray'] ?? 0) + $inc;
                        } else {
                            $lc['hearts_increase'] = intval($lc['hearts_increase'] ?? 0) + $inc;
                        }
                        break;
                    }
                }
                unset($lc);
                $incLabel = $incColor === 'gray' ? "$inc Gray Hearts" : "$inc hearts";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] score +$bonus; required $incLabel (EMOTION in Success).");
            }
            break;

        case 'score_if_wr_distinct_live_count':
            $distinct = countDistinctWrLives($p, $ab['group'] ?? '');
            $amt = 0;
            if ($distinct >= intval($ab['min_6'] ?? 6)) {
                $amt = intval($ab['amount_6'] ?? 2);
            } elseif ($distinct >= intval($ab['min_4'] ?? 4)) {
                $amt = intval($ab['amount_4'] ?? 1);
            }
            if ($amt > 0) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $amt);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] score +$amt ($distinct distinct WR Lives).");
            }
            break;

        case 'turn_one_live_score_member_blade':
            if (intval($state['turn'] ?? 1) !== 1) {
                break;
            }
            bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['score'] ?? 1));
            $n = applyMemberBladeBonus($state, $pid, [
                'group'        => $ab['group'] ?? 'Nijigasaki',
                'amount'       => intval($ab['blade'] ?? 1),
                'max_members'  => intval($ab['max_members'] ?? 1),
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] score +1; $n Member(s) gained +" . intval($ab['blade'] ?? 1) . ' Blade (turn 1).');
            break;

        case 'live_success_pick_energy_or_member':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $canBoth = !empty(array_filter(
                $p['success_lives'] ?? [],
                fn($c) => ($c['group'] ?? '') === ($ab['group'] ?? 'Nijigasaki')
            ));
            $state['pending_prompt'] = [
                'type'          => 'live_success_pick_energy_or_member',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'can_both'      => $canBoth,
                'prompt'        => $canBoth
                    ? 'Choose: Energy to Wait, Member from WR to hand, or both.'
                    : 'Choose: put 1 Energy into Wait, or add 1 Member from WR to hand.',
                'choices'       => $canBoth
                    ? ['energy', 'member', 'both', 'skip']
                    : ['energy', 'member', 'skip'],
                'choice_labels' => $canBoth
                    ? ['Energy → Wait', 'Member → hand', 'Both', 'Skip']
                    : ['Energy → Wait', 'Member → hand', 'Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Success choice.');
            break;

        case 'draw_surveil_if_full_stage_cost':
            if (!stageFullGroupMembersMinCost(
                $p,
                $ab['group'] ?? 'Nijigasaki',
                intval($ab['min_cost'] ?? 20)
            )) {
                break;
            }
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 3));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (full Stage, cost 20+).");
            $topN = intval($ab['deck_top'] ?? 3);
            if ($topN > 0 && count($p['hand']) >= $topN) {
                return startEffectDiscardHandPrompt(
                    $state,
                    $pid,
                    $name,
                    $topN,
                    "Choose $topN card(s) to put on top of your deck (in order).",
                    ['pick_mode' => 'deck_top']
                );
            }
            break;

        case 'blade_bonus':
            $state = applyModifierEffect($state, $pid, $ab);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] gains +' . intval($ab['amount'] ?? 1) . ' Blade until this Live ends.');
            break;

        case 'live_score_bonus':
            $state = applyModifierEffect($state, $pid, $ab);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] gains +' . intval($ab['amount'] ?? 1) . ' total Live Score until this Live ends.');
            break;

        case 'score_if_deck_refreshed':
            if (intval($p['_deck_refreshed_turn'] ?? -1) === intval($state['turn'] ?? 0)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 2));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 2) . ' (deck refreshed this turn).');
            }
            break;

        case 'member_blade_bonus_if_success_count':
            if (count($p['success_lives'] ?? []) >= intval($ab['min_success_count'] ?? 2)) {
                $n = applyMemberBladeBonus($state, $pid, $ab);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] $n Member(s) gained +" . intval($ab['amount'] ?? 2) . ' Blade until Live ends.');
            }
            break;

        case 'member_blade_bonus_if_other_live_zone':
            $exclude = $ab['exclude_live_name'] ?? '';
            $hasOther = false;
            foreach ($p['live_zone'] ?? [] as $lc) {
                if (!$lc || ($lc['card_type'] ?? '') !== 'ライブ') continue;
                $ln = $lc['name_en'] ?? $lc['name'] ?? '';
                if ($exclude !== '' && str_contains($ln, $exclude)) continue;
                if (($lc['group'] ?? '') === ($ab['group'] ?? 'Sunshine')) {
                    $hasOther = true;
                    break;
                }
            }
            if ($hasOther) {
                $n = applyMemberBladeBonus($state, $pid, $ab);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] $n Member(s) gained +" . intval($ab['amount'] ?? 1) . ' Blade until Live ends.');
            }
            break;

        case 'live_success_yell_live_deck_bottom':
            $yellPool = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            $lives = array_values(array_filter($yellPool, fn($c) => ($c['card_type'] ?? '') === 'ライブ'));
            if (empty($lives) || !empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'live_success_yell_live_deck_bottom',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $lives),
                'prompt'        => 'Put up to 1 Live card revealed for Yell on the bottom of your deck?',
                'choices'       => ['pick', 'skip'],
                'choice_labels' => ['Choose Live', 'Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Success (optional deck bottom).');
            break;

        case 'add_self_to_hand_if_winning_yell':
            if (empty($ctx['yell_cards'])) break;
            $inYell = false;
            foreach ($ctx['yell_cards'] as $yc) {
                if (($yc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $inYell = true;
                    break;
                }
            }
            if (!$inYell) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $myScore = array_sum(array_column($p['live_zone'] ?? [], 'score')) + getLiveScoreBonus($state, $pid);
            $oppScore = array_sum(array_column($state['players'][$opp]['live_zone'] ?? [], 'score'))
                + getLiveScoreBonus($state, $opp);
            if ($myScore > $oppScore) {
                $p['hand'][] = $source;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added itself to hand (winning Live score, revealed by Yell).');
            }
            break;

        case 'optional_discard_blade_per_card':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_blade_per_card',
                'owner'         => $pid,
                'responder'     => $pid,
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

        case 'player_choice_wr_live_deck_bottom_draw':
            if (!empty($state['pending_prompt'])) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $selfLives = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            $oppLives = array_values(array_filter(
                $state['players'][$opp]['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if (empty($selfLives) && empty($oppLives)) break;
            $choices = [];
            $labels = [];
            if (!empty($selfLives)) {
                $choices[] = 'self';
                $labels[] = 'Yourself';
            }
            if (!empty($oppLives)) {
                $choices[] = 'opponent';
                $labels[] = 'Opponent';
            }
            $state['pending_prompt'] = [
                'type'          => 'player_choice_wr_live_deck_bottom_draw',
                'step'          => 'pick_player',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Choose yourself or your opponent: put 1 Live from that player\'s Waiting Room on the bottom of their deck (then draw ' .
                    intval($ab['draw'] ?? 1) . ').',
                'choices'       => $choices,
                'choice_labels' => $labels,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start: choose a player.');
            break;

        case 'live_start_center_cost_choice':
            if (!empty($state['pending_prompt'])) break;
            $group = $ab['group'] ?? 'Sunshine';
            $center = $p['stage']['center'] ?? null;
            if (!$center || ($center['group'] ?? '') !== $group) break;
            if (getEffectiveStageMemberCost($state, $pid, $center) < intval($ab['min_center_cost'] ?? 9)) {
                break;
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $hasBlade = !empty(listStageMemberChoices($p));
            $hasWait = !empty(listOppStageMembersByMaxCost(
                $state,
                $opp,
                intval($ab['wait_opp_max_cost'] ?? 4)
            ));
            if (!$hasBlade && !$hasWait) break;
            $choices = [];
            $labels = [];
            if ($hasBlade) {
                $choices[] = 'blade';
                $labels[] = 'Until this Live ends, 1 Member on your Stage gains +' .
                    intval($ab['blade_amount'] ?? 2) . ' Blade.';
            }
            if ($hasWait) {
                $choices[] = 'wait_opp';
                $labels[] = 'Put 1 opponent Stage Member with cost ' .
                    intval($ab['wait_opp_max_cost'] ?? 4) . ' or less into Wait.';
            }
            $state['pending_prompt'] = [
                'type'          => 'live_start_center_cost_choice',
                'step'          => 'pick_mode',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Choose one:',
                'choices'       => $choices,
                'choice_labels' => $labels,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start: choose an effect.');
            break;

        case 'play_wr_members_combined_cost':
            if (!empty($state['pending_prompt'])) break;
            $cands = array_values(array_filter($p['waiting_room'], function ($c) use ($ab) {
                return ($c['card_type'] ?? '') === 'メンバー'
                    && cardMatchesGroup($c, $ab['group'] ?? '', 'member');
            }));
            if (empty($cands)) break;
            $state['pending_prompt'] = [
                'type'               => 'play_wr_members_combined_cost',
                'owner'              => $pid,
                'responder'          => $pid,
                'source_name'        => $name,
                'max_combined_cost'  => intval($ab['max_combined_cost'] ?? 4),
                'max_count'          => intval($ab['count'] ?? 2),
                'prompt'             => 'Choose Member(s) from Waiting Room (combined cost ≤' .
                    intval($ab['max_combined_cost'] ?? 4) . ') to put on Stage in Wait.',
                'ability'            => $ab,
            ];
            break;

        case 'score_if_stage_member_hearts':
            if (!empty($state['pending_prompt'])) break;
            $group = $ab['group'] ?? 'Sunshine';
            $checkBlades = !empty($ab['min_blades']);
            $threshold = $checkBlades
                ? intval($ab['min_blades'])
                : intval($ab['min_hearts'] ?? 6);
            $eligible = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr || ($mbr['group'] ?? '') !== $group) continue;
                $ok = $checkBlades
                    ? (intval($mbr['blade'] ?? 0) + intval($mbr['live_blade_bonus'] ?? 0) >= $threshold)
                    : (memberHeartCount($mbr) >= $threshold);
                if ($ok) {
                    $eligible[] = ['slot' => $slot, 'summary' => cardPromptSummary($mbr)];
                }
            }
            if (empty($eligible)) break;
            $state['pending_prompt'] = [
                'type'        => 'score_if_stage_member_hearts',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_id'   => $source['instance_id'] ?? '',
                'source_name' => $name,
                'candidates'  => $eligible,
                'amount'      => intval($ab['amount'] ?? 1),
                'prompt'      => 'Choose 1 Aqours Member with ' . $threshold . '+' .
                    ($checkBlades ? ' Blades' : ' hearts') . ': this card\'s score +' .
                    intval($ab['amount'] ?? 1) . '?',
            ];
            break;

        case 'set_live_score_if_yell_or_excess':
            $noBlade = true;
            foreach ($state['_last_yell_cards'] ?? [] as $yc) {
                if (!empty($yc['blade_hearts'])) {
                    $noBlade = false;
                    break;
                }
            }
            $excessOk = intval($ctx['excess_hearts'] ?? 0) >= intval($ab['min_excess_hearts'] ?? 2);
            if ($noBlade || $excessOk) {
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        $lc['score'] = intval($ab['score'] ?? 4);
                        break;
                    }
                }
                unset($lc);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score set to ' . intval($ab['score'] ?? 4) . '.');
            }
            break;

        case 'add_wr_live_if_opp_hand_ahead':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $diff = count($state['players'][$opp]['hand'] ?? []) - count($p['hand'] ?? []);
            if ($diff >= intval($ab['min_hand_diff'] ?? 2)) {
                $added = addFromWaitingRoomFiltered($p, '', 'live', intval($ab['count'] ?? 1));
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added $added Live card(s) from Waiting Room (opponent hand +$diff).");
                }
            }
            break;

        case 'opp_may_discard_or_modifier':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $state['pending_prompt'] = [
                'type'          => 'opp_may_discard_or_modifier',
                'owner'         => $pid,
                'responder'     => $opp,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Put 1 Live card from your hand into the Waiting Room? (If not, opponent gains +1 total Live Score.)',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Discard Live', 'No — Opponent gains Live Score'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] On Enter: opponent may discard a Live card.");
            break;

        case 'member_hearts_as_blade':
            foreach ($p['stage'] as $slot => &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $mbr['hearts_as_blade_color'] = $ab['color'] ?? 'blue';
                    $p['stage'][$slot] = $mbr;
                    break;
                }
            }
            unset($mbr);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] printed hearts treated as ' .
                ucfirst($ab['color'] ?? 'blue') . ' Blade hearts until this Live ends.');
            break;

        case 'live_success_pick_yell_live':
            $yellAll = $ctx['yell_cards'] ?? $state['_last_yell_cards'] ?? [];
            if (!empty($ab['min_distinct_yell_members'])) {
                $group = $ab['group'] ?? '';
                if (countDistinctYellMembers($yellAll, $group)
                    < intval($ab['min_distinct_yell_members'])) {
                    break;
                }
            }
            $pool = array_values(array_filter(
                $yellAll,
                function ($c) use ($ab) {
                    if (($c['card_type'] ?? '') !== 'ライブ') return false;
                    $group = $ab['group'] ?? '';
                    return $group === '' || ($c['group'] ?? '') === $group;
                }
            ));
            if (empty($pool)) {
                break;
            }
            if (count($pool) === 1) {
                $picked = $pool[0];
                $p['hand'][] = $picked;
                $remaining = array_values(array_filter(
                    $p['_pending_yell_wr'] ?? $pool,
                    fn($c) => ($c['instance_id'] ?? '') !== ($picked['instance_id'] ?? '')
                ));
                $p['_pending_yell_wr'] = $remaining;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added ' . cardDisplayName($picked) . ' from Yell to hand.');
                break;
            }
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'live_success_pick_yell_live',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $pool),
                'prompt'        => 'Choose 1 Live card revealed by Yell to add to your hand.',
                'ability'       => $ab,
            ];
            break;

        case 'live_success_energy_wait_if_yell_live':
            $pool = $ctx['yell_cards'] ?? $state['_last_yell_cards'] ?? [];
            if (countYellLiveCards($pool) < 1) {
                break;
            }
            if (putEnergyFromDeckInWait($p)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put 1 Energy from Energy deck into Wait (Yell revealed Live).');
            }
            break;

        case 'look_reveal_heart_threshold':
            $look = intval($ab['look'] ?? 4);
            $minM = intval($ab['min_member_hearts'] ?? 2);
            $minL = intval($ab['min_live_required'] ?? 2);
            $heartColor = (string)($ab['heart_color'] ?? '');
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            $eligible = array_values(array_filter(
                $top,
                fn($c) => cardMeetsHeartThreshold($c, $minM, $minL, $heartColor)
            ));
            if (empty($eligible)) {
                if (!empty($top)) {
                    $p['waiting_room'] = array_merge($p['waiting_room'], $top);
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at $look card(s); none eligible.");
                break;
            }
            if (count($eligible) === 1) {
                $pickId = $eligible[0]['instance_id'] ?? '';
                $rest = [];
                foreach ($top as $c) {
                    if (($c['instance_id'] ?? '') === $pickId) {
                        $p['hand'][] = $c;
                    } else {
                        $rest[] = $c;
                    }
                }
                if (!empty($rest)) {
                    $p['waiting_room'] = array_merge($p['waiting_room'], $rest);
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added 1 card from surveil to hand.');
                break;
            }
            $state['surveil_stash'] = $top;
            $state['pending_prompt'] = [
                'type'          => 'pick_surveil_heart_threshold',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $eligible),
                'prompt'        => 'Choose 1 eligible card to add to hand (or skip).',
                'choices'       => array_merge(['skip'], array_map(fn($c) => $c['instance_id'] ?? '', $eligible)),
                'choice_labels' => array_merge(
                    ['Skip — put all in Waiting Room'],
                    array_map(fn($c) => cardDisplayName($c), $eligible)
                ),
                'ability'       => $ab,
            ];
            break;

        case 'negate_self_live_success_if_group_hearts':
            $heartColor = (string)($ab['heart_color'] ?? '');
            if (sumGroupStageHearts($p, $ab['group'] ?? 'Sunshine', $heartColor)
                >= intval($ab['min_hearts'] ?? 6)) {
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        $lc['live_success_negated'] = true;
                        break;
                    }
                }
                unset($lc);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] Live Success ability negated (Aqours stage hearts).');
            }
            break;

        case 'opp_energy_wait_from_deck':
            if (!empty($ab['skip_if_negated'])) {
                foreach ($p['live_zone'] as $lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')
                        && !empty($lc['live_success_negated'])) {
                        break 2;
                    }
                }
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (putEnergyFromDeckInWait($state['players'][$opp])) {
                $state = addLog($state, $state['players'][$opp]['name'] .
                    ' — [' . $name . '] put 1 Energy from Energy deck into Wait.');
            }
            break;

        case 'score_if_group_stage_hearts':
            $heartColor = (string)($ab['heart_color'] ?? '');
            if (sumGroupStageHearts($p, $ab['group'] ?? 'Sunshine', $heartColor)
                >= intval($ab['min_hearts'] ?? 10)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 2));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 2) . ' (Aqours stage hearts).');
            }
            break;

        case 'score_if_group_stage_hearts_opp_no_excess':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $heartColor = (string)($ab['heart_color'] ?? '');
            if (sumGroupStageHearts($p, $ab['group'] ?? 'Sunshine', $heartColor)
                    >= intval($ab['min_hearts'] ?? 4)
                && !empty($state['_live_success_no_excess'][$opp])) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 2));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 2) .
                    ' (Aqours hearts + opponent no excess).');
            }
            break;

        case 'block_success_live_on_tie':
            $state = initLiveModifiers($state);
            $state['live_modifiers']['both']['block_success_live_on_tie'] = true;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] if Live scores tie, neither player adds Success Lives this turn.');
            break;

        case 'optional_negate_member_live_start_add_wr':
            if (!empty($state['pending_prompt'])) break;
            $candidates = [];
            foreach ($p['stage'] as $s => $mbr) {
                if (!$mbr || ($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) continue;
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
                'prompt'        => 'Negate [Live Start] abilities of 1 Liella! Member until this Live ends and add 1 Liella! card from your Waiting Room to hand?',
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

        case 'if_baton_wr_group_to_hand':
            if (empty($source['entered_via_baton'])) break;
            $batonId = $source['baton_wr_member_id'] ?? '';
            if ($batonId === '') break;
            $picked = null;
            $rest = [];
            foreach ($p['waiting_room'] as $c) {
                if (!$picked && ($c['instance_id'] ?? '') === $batonId
                    && cardMatchesGroup($c, $ab['group'] ?? '', $ab['filter'] ?? 'member')) {
                    $picked = $c;
                } else {
                    $rest[] = $c;
                }
            }
            if ($picked) {
                $p['waiting_room'] = $rest;
                $p['hand'][] = $picked;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added ' . cardDisplayName($picked) . ' from Baton Touch to hand.');
            }
            break;

        case 'blade_per_hand_cards':
            $state = initLiveModifiers($state);
            $state['live_modifiers'][$pid]['blade_per_hand_divisor'] = max(1, intval($ab['per_cards'] ?? 2));
            $state['live_modifiers'][$pid]['blade_per_hand_amount'] = intval($ab['amount'] ?? 1);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] +1 Blade per ' . intval($ab['per_cards'] ?? 2) . ' cards in hand until Live ends.');
            break;

        case 'reduce_yell_reveal_count':
            if (!empty($ab['requires_other_members'])) {
                $others = 0;
                foreach ($p['stage'] as $mbr) {
                    if ($mbr && ($mbr['instance_id'] ?? '') !== ($source['instance_id'] ?? '')) {
                        $others++;
                    }
                }
                if ($others < 1) break;
            }
            $state = initLiveModifiers($state);
            $state['live_modifiers'][$pid]['yell_reveal_reduction'] =
                intval($state['live_modifiers'][$pid]['yell_reveal_reduction'] ?? 0)
                + intval($ab['amount'] ?? 8);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Yell reveal count reduced by ' . intval($ab['amount'] ?? 8) . ' until Live ends.');
            break;

        case 'pick_wr_distinct_lives_opp_choice':
            if (!empty($state['pending_prompt'])) break;
            $lives = array_values(array_filter(
                $p['waiting_room'] ?? [],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            $byName = [];
            foreach ($lives as $c) {
                $label = $c['name_en'] ?? $c['name'] ?? '';
                if ($label !== '' && !isset($byName[$label])) {
                    $byName[$label] = $c;
                }
            }
            $distinct = array_values($byName);
            if (count($distinct) < intval($ab['count'] ?? 2)) break;
            $state['pending_prompt'] = [
                'type'          => 'pick_wr_distinct_lives_opp_choice',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $distinct),
                'pick_count'    => intval($ab['count'] ?? 2),
                'prompt'        => 'Choose ' . intval($ab['count'] ?? 2) .
                    ' Live cards with different names from your Waiting Room.',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose Waiting Room Lives for opponent to pick.');
            break;

        case 'score_if_fewer_success_lives':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (count($p['success_lives'] ?? [])
                < count($state['players'][$opp]['success_lives'] ?? [])) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (fewer Success Lives).');
            }
            break;

        case 'score_if_hand_more_than_opp':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (count($p['hand']) > count($state['players'][$opp]['hand'] ?? [])) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (more cards in hand).');
            }
            break;

        case 'live_success_pick_yell_card':
            if (!empty($ab['min_distinct_named_on_stage'])) {
                if (countDistinctNamedOnStage($p, $ab['names'] ?? [])
                    < intval($ab['min_distinct_named_on_stage'])) {
                    break;
                }
            }
            $yellPool = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            if (empty($yellPool)) break;
            $eligible = filterYellPoolForAbility($yellPool, $ab);
            if (empty($eligible)) break;
            if (count($eligible) === 1) {
                $picked = $eligible[0];
                $pickId = $picked['instance_id'] ?? '';
                $p['_pending_yell_wr'] = array_values(array_filter(
                    $p['_pending_yell_wr'] ?? $yellPool,
                    fn($c) => ($c['instance_id'] ?? '') !== $pickId
                ));
                $p['hand'][] = $picked;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added ' . cardDisplayName($picked) . ' from Yell to hand.');
                break;
            }
            $subunit = trim($ab['subunit'] ?? '');
            $pickLabel = $subunit !== ''
                ? 'Choose 1 ' . $subunit . ' Member card revealed by Yell to add to your hand.'
                : 'Choose 1 card revealed by Yell to add to your hand.';
            $state['pending_prompt'] = [
                'type'        => 'pick_yell_member',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'prompt'      => $pickLabel,
                'candidates'  => array_map('cardPromptSummary', $eligible),
                'ability'     => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose a Yell card.');
            break;

        case 'energy_wait_if_group_only_min_energy':
            if (stageAllMembersInGroup($p, $ab['group'] ?? '')
                && countEnergyInZone($p) >= intval($ab['min_energy'] ?? 7)) {
                $n = intval($ab['count'] ?? 1);
                for ($i = 0; $i < $n; $i++) {
                    putEnergyFromDeckInWait($p, $state, $pid);
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put $n Energy into Wait (Liella! only, Energy threshold).");
            }
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

        case 'on_enter_side_area':
            $slot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
            $state = applyOnEnterSideEffect($state, $pid, $p, $name, $ab, $slot);
            break;

        case 'allows_double_baton':
            break;

        case 'if_double_baton_group_bonus':
            if (intval($source['baton_count'] ?? 0) < intval($ab['min_baton'] ?? 2)) break;
            $group = $ab['group'] ?? '';
            $batonGroups = $source['baton_member_groups'] ?? [];
            $groupCount = count(array_filter($batonGroups, fn($g) => $g === $group));
            if ($groupCount < intval($ab['min_baton'] ?? 2)) break;
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 2));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (double Baton Touch).");
            $placed = putWrGroupMemberToEmptyStage(
                $p,
                $ab['group'] ?? '',
                intval($ab['max_cost'] ?? 4),
                intval($state['turn'] ?? 1)
            );
            if ($placed) {
                $m = $placed['member'];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put ' . cardDisplayName($m) .
                    ' from Waiting Room onto Stage.');
            }
            break;

        case 'energy_wait_if_baton_group_min_energy':
            if (empty($source['entered_via_baton'])) break;
            $batonGroup = $source['baton_from_group'] ?? '';
            if ($batonGroup !== ($ab['group'] ?? '')) break;
            if (countEnergyInZone($p) < intval($ab['min_energy'] ?? 7)) break;
            $n = intval($ab['count'] ?? 2);
            for ($i = 0; $i < $n; $i++) {
                putEnergyFromDeckInWait($p, $state, $pid);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] put $n Energy into Wait (Baton Touch + Energy).");
            break;

        case 'wait_opp_max_original_hearts':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $waited = waitOpponentStageByOriginalHearts(
                $state,
                $opp,
                intval($ab['max_original_hearts'] ?? 3),
                intval($ab['pick_count'] ?? 1) ?: null,
                $pid
            );
            if ($waited > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put $waited opponent Member(s) into Wait.");
            }
            break;

        case 'score_if_center_cost_higher':
            $mine = $p['stage']['center'] ?? null;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $theirs = $state['players'][$opp]['stage']['center'] ?? null;
            if ($mine && $theirs
                && ($mine['group'] ?? '') === ($ab['group'] ?? '')
                && getEffectiveStageMemberCost($state, $pid, $mine)
                    > getEffectiveStageMemberCost($state, $opp, $theirs)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (Center cost higher).');
            }
            break;

        case 'grant_hearts_if_slot_blade_hearts':
            $slot = $ab['slot'] ?? 'left';
            $mbr = $p['stage'][$slot] ?? null;
            if ($mbr && ($mbr['group'] ?? '') === ($ab['group'] ?? '')
                && memberBladeHeartCount($mbr) >= intval($ab['min_blade_hearts'] ?? 3)) {
                addBonusHeartsToMember($mbr, $ab['hearts'] ?? [], 1);
                $p['stage'][$slot] = $mbr;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] granted bonus hearts to ' .
                    ($mbr['name_en'] ?? $mbr['name']) . '.');
            }
            break;

        case 'grant_blade_if_slot_colored_hearts':
            $slot = $ab['slot'] ?? 'left';
            $mbr = $p['stage'][$slot] ?? null;
            $color = $ab['heart_color'] ?? 'red';
            if ($mbr && ($mbr['group'] ?? '') === ($ab['group'] ?? '')
                && memberHeartColorCount($mbr, $color) >= intval($ab['min_hearts'] ?? 3)) {
                $amt = intval($ab['blade'] ?? 2);
                $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $amt;
                $p['stage'][$slot] = $mbr;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] granted +' . $amt . ' Blade to ' .
                    ($mbr['name_en'] ?? $mbr['name']) . '.');
            }
            break;

        case 'set_center_group_hearts':
            $center = $p['stage']['center'] ?? null;
            if ($center && ($center['group'] ?? '') === ($ab['group'] ?? '')) {
                $cnt = intval($ab['heart_count'] ?? 3);
                $center['printed_heart_override'] = $cnt;
                $p['stage']['center'] = $center;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Center Member printed hearts set to $cnt.");
            }
            break;

        case 'set_center_group_blades':
            $center = $p['stage']['center'] ?? null;
            if ($center && ($center['group'] ?? '') === ($ab['group'] ?? '')) {
                $cnt = intval($ab['blade_count'] ?? 3);
                $center['printed_blade_override'] = $cnt;
                $p['stage']['center'] = $center;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Center Member printed Blades set to $cnt.");
            }
            break;

        case 'blade_bonus_if_moved_in_slot':
            $needSlot = $ab['slot'] ?? '';
            $mySlot = findMemberSlot($p, $source['instance_id'] ?? '');
            if ($needSlot !== '' && $mySlot === $needSlot && !empty($source['moved_this_turn'])) {
                $state = applyModifierEffect($state, $pid, [
                    'type'   => 'blade_bonus',
                    'amount' => intval($ab['amount'] ?? 2),
                ]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] gained +' . intval($ab['amount'] ?? 2) .
                    ' Blade until Live ends (moved in slot).');
            }
            break;

        case 'pick_named_members_grant_blade':
            if (!empty($state['pending_prompt'])) break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
                foreach ($ab['names'] ?? [] as $n) {
                    if ($label === $n || str_contains($label, $n)) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => true]);
                        break;
                    }
                }
                if (($mbr['group'] ?? '') === ($ab['group'] ?? '')) {
                    $already = false;
                    foreach ($candidates as $c) {
                        if (($c['slot'] ?? '') === $slot) { $already = true; break; }
                    }
                    if (!$already) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => false]);
                    }
                }
            }
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'          => 'pick_named_members_grant_blade',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'candidates'    => $candidates,
                'named_list'    => $ab['names'] ?? [],
                'blade'         => intval($ab['blade'] ?? 1),
                'prompt'        => 'Choose 1 named Member, then 1 other Liella! Member for +Blade.',
                'step'          => 'pick_named',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose Members for +Blade.');
            break;

        case 'score_if_center_group_moved':
            $center = $p['stage']['center'] ?? null;
            if ($center && ($center['group'] ?? '') === ($ab['group'] ?? '')
                && !empty($center['moved_this_turn'])) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (Center moved).');
            }
            break;

        case 'score_if_yell_distinct_members':
            $yell = $state['_last_yell_cards'] ?? $p['yell_cards'] ?? [];
            if (countDistinctYellMembers($yell, $ab['group'] ?? '')
                >= intval($ab['min_distinct'] ?? 5)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (Yell Members).');
            }
            break;

        case 'draw_discard_if_min_energy':
            if (countEnergyInZone($p) >= intval($ab['min_energy'] ?? 11)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 2));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Energy threshold).");
                return applyDrawThenDiscard(
                    $state,
                    $pid,
                    $p,
                    $name,
                    0,
                    intval($ab['discard'] ?? 1)
                );
            }
            break;

        case 'optional_formation_change_group':
            if (!stageAllMembersInGroup($p, $ab['group'] ?? '')) break;
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_formation_change_group',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Formation-change your Stage Members (one per area)?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] optional formation change (choose).');
            break;

        case 'score_if_active_energy':
            $active = count(array_filter(
                $p['energy_zone'] ?? [],
                fn($e) => $e['active'] ?? false
            ));
            if ($active > 0) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (active Energy).');
            }
            break;

        case 'pick_named_members_grant_hearts':
            if (!empty($state['pending_prompt'])) break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
                foreach ($ab['names'] ?? [] as $n) {
                    if ($label === $n || str_contains($label, $n)) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => true]);
                        break;
                    }
                }
                if (($mbr['group'] ?? '') === ($ab['group'] ?? '')) {
                    $already = false;
                    foreach ($candidates as $c) {
                        if (($c['slot'] ?? '') === $slot) { $already = true; break; }
                    }
                    if (!$already) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => false]);
                    }
                }
            }
            if (count($candidates) < 2) break;
            $state['pending_prompt'] = [
                'type'          => 'pick_named_members_grant_hearts',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => $candidates,
                'named_list'    => $ab['names'] ?? [],
                'hearts'        => $ab['hearts'] ?? [],
                'prompt'        => 'Choose 1 named Member, then 1 other Liella! Member for bonus hearts.',
                'step'          => 'pick_named',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose Members for bonus hearts.');
            break;

        case 'yell_blades_to_color':
            $state = initLiveModifiers($state);
            $color = $ab['color'] ?? 'purple';
            $state['live_modifiers'][$pid]['yell_blades_to_color'] = $color;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Yell Blade hearts become ' . ucfirst($color) . ' until Live ends.');
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

        case 'hearts_if_combined_energy':
        case 'live_score_if_opp_success_total':
        case 'on_self_wait_draw_discard':
        case 'optional_named_live_zone_from_wr_on_hand':
        case 'member_blade_on_live_zone_faceup':
        case 'cannot_live_if_solo_stage':
        case 'blade_bonus_if_center':
        case 'cost_bonus_if_min_energy':
        case 'live_score_bonus_if_min_energy':
        case 'draw_per_yell_card':
            break;

        case 'optional_pay_energy_on_enter':
            if (!empty($state['pending_prompt'])) break;
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

        case 'mandatory_discard_look_reveal':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'mandatory_discard_look_reveal',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Put 1 card from your hand into the Waiting Room (required).',
                'discard_count' => intval($ab['discard'] ?? 1),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] mandatory discard for On Enter.');
            break;

        case 'reveal_hand_member_cost_live_score':
            if (!empty($state['pending_prompt'])) break;
            $members = array_values(array_filter(
                $p['hand'] ?? [],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
            ));
            if (empty($members)) break;
            $state['pending_prompt'] = [
                'type'          => 'reveal_hand_member_cost_live_score',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $members),
                'milestones'    => $ab['milestones'] ?? [10, 20, 30, 40, 50],
                'prompt'        => 'Reveal any number of Member cards from your hand (combined cost 10/20/30/40/50 for +1 Live Score).',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] reveal hand Members (choose).');
            break;

        case 'add_wr_live_if_min_energy':
            if (countEnergyInZone($p) < intval($ab['min_energy'] ?? 11)) break;
            $added = addFromWaitingRoomFiltered($p, $ab['group'] ?? '', 'live', intval($ab['count'] ?? 1));
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $added Live card(s) from Waiting Room.");
            }
            break;

        case 'draw_if_named_on_stage':
            $pairs = $ab['name_pairs'] ?? null;
            $ok = $pairs
                ? stageHasAllNamePairs($p, $pairs)
                : stageHasNamedMember($p, $ab['names'] ?? []);
            if ($ok) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (named Members on Stage).");
            }
            break;

        case 'draw_if_min_energy':
            if (countEnergyInZone($p) >= intval($ab['min_energy'] ?? 7)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Energy threshold).");
            }
            break;

        case 'energy_wait_from_deck':
            $n = intval($ab['count'] ?? 1);
            $placed = 0;
            for ($i = 0; $i < $n; $i++) {
                if (putEnergyFromDeckInWait($p, $state, $pid)) $placed++;
            }
            if ($placed > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put $placed Energy into Wait.");
            }
            break;

        case 'grant_named_members_blade':
            $n = applyNamedMemberBladeBonus($state, $pid, $ab['grants'] ?? []);
            if ($n > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] $n named Member(s) gained Blade until Live ends.");
            }
            break;

        case 'set_required_hearts_if_distinct_group':
            if (countDistinctGroupStageWr(
                $p,
                $ab['group'] ?? '',
                $ab['filter'] ?? 'member'
            ) < intval($ab['min_distinct'] ?? 5)) {
                break;
            }
            foreach ($p['live_zone'] as &$lc) {
                if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $lc['required_hearts'] = $ab['hearts'] ?? [];
                    break;
                }
            }
            unset($lc);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Required Hearts modified (distinct group).');
            break;

        case 'score_if_min_energy':
            if (countEnergyInZone($p) >= intval($ab['min_energy'] ?? 12)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (Energy).');
            }
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

        case 'live_start_pay_or_discard':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'live_start_pay_or_discard',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Pay ' . intval($ab['cost'] ?? 2) . ' Energy, or put ' .
                    intval($ab['discard'] ?? 2) . ' cards from your hand into the Waiting Room?',
                'choices'       => ['pay', 'discard'],
                'choice_labels' => [
                    'Pay ' . intval($ab['cost'] ?? 2) . ' Energy',
                    'Discard ' . intval($ab['discard'] ?? 2),
                ],
                'ability'       => $ab,
                'pay_cost'      => intval($ab['cost'] ?? 2),
                'discard_count' => intval($ab['discard'] ?? 2),
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start (pay or discard).');
            break;

        case 'optional_pay_energy_live_success':
            if (!empty($state['pending_prompt'])) break;
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

        case 'formation_rotate_all':
            if (!stageAllMembersInSubunit($p, $ab['requires_subunit_only'] ?? '')) break;
            spBp2MarkEffectAreaMove($state, $source);
            foreach (['p1', 'p2'] as $id) {
                $before = [];
                foreach (['center', 'left', 'right'] as $s) {
                    $mbr = $state['players'][$id]['stage'][$s] ?? null;
                    if ($mbr) {
                        $before[$mbr['instance_id'] ?? ''] = $s;
                    }
                }
                formationRotatePlayerStage($state['players'][$id]['stage']);
                foreach (['center', 'left', 'right'] as $s) {
                    $mbr = $state['players'][$id]['stage'][$s] ?? null;
                    if (!$mbr) {
                        continue;
                    }
                    $from = $before[$mbr['instance_id'] ?? ''] ?? $s;
                    spBp2ApplyMovedByGroupEffect($mbr, $state);
                    $state['players'][$id]['stage'][$s] = $mbr;
                    if ($from !== $s) {
                        $state = resolveAutoAreaMoveAbilities($state, $id, $mbr['instance_id'] ?? '', $from);
                        if (!empty($state['pending_prompt'])) {
                            spBp2ClearEffectAreaMove($state);
                            return $state;
                        }
                    }
                }
            }
            spBp2ClearEffectAreaMove($state);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] both players rotated Stage formation.');
            break;

        case 'blade_if_entered_or_moved':
            if (!empty($ab['heart_color'])) {
                addBonusHeartsToModifier($state, $pid, [[
                    'color' => $ab['heart_color'],
                    'count' => 1,
                ]]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] gained 1 ' . ucfirst($ab['heart_color']) .
                    ' heart until Live ends.');
            } else {
                $state = applyModifierEffect($state, $pid, [
                    'type'   => 'blade_bonus',
                    'amount' => intval($ab['amount'] ?? 1),
                ]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] gained +' . intval($ab['amount'] ?? 1) . ' Blade until Live ends.');
            }
            break;

        case 'on_enter_draw_swap_area':
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn.");
            if (!empty($state['pending_prompt'])) break;
            $slots = [];
            $mySlot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
            foreach (['center', 'left', 'right'] as $s) {
                if ($s !== $mySlot) $slots[] = $s;
            }
            $state['pending_prompt'] = [
                'type'          => 'on_enter_draw_swap_area',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_slot'   => $mySlot,
                'source_name'   => $name,
                'slots'         => $slots,
                'prompt'        => 'Choose an area to move this Member to (swap if occupied).',
                'ability'       => $ab,
            ];
            break;

        case 'draw_if_other_subunit_on_stage':
            if (countOtherSubunitOnStage($p, $ab['subunit'] ?? '', $source['instance_id'] ?? '') > 0) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (other subunit on Stage).");
            }
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

        case 'activate_energy_up_to_if_distinct_subunit':
            if (countDistinctNamedSubunit($p, $ab['subunit'] ?? '')
                < intval($ab['min_distinct'] ?? 2)) {
                break;
            }
            if (!empty($state['pending_prompt'])) break;
            $max = intval($ab['max'] ?? 6);
            $state['pending_prompt'] = [
                'type'          => 'activate_energy_up_to',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => "Activate up to $max Energy?",
                'max'           => $max,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] activate Energy (choose amount).');
            break;

        case 'score_if_all_energy_active':
            if (allEnergyActive($p)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (all Energy active).');
            }
            break;

        case 'reduce_hearts_per_entered_moved_subunit':
            $n = countEnteredMovedSubunitThisTurn($p, $ab['subunit'] ?? '')
                * intval($ab['per_member'] ?? 1);
            if ($n > 0) {
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $n;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $n;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$n Gray heart(s)" : "$n heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced.");
            }
            break;

        case 'mill_then_blade_if_any_live':
            $n = intval($ab['count'] ?? 4);
            $milled = array_splice($p['main_deck'], 0, min($n, count($p['main_deck'])));
            $hasLive = false;
            foreach ($milled as $c) {
                if (($c['card_type'] ?? '') === 'ライブ') {
                    $hasLive = true;
                    break;
                }
            }
            if (!empty($milled)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put " . count($milled) . ' card(s) from deck top into Waiting Room.');
            }
            if ($hasLive) {
                $state = applyModifierEffect($state, $pid, [
                    'type'   => 'blade_bonus',
                    'amount' => intval($ab['amount'] ?? 2),
                ]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] gained +' . intval($ab['amount'] ?? 2) .
                    ' Blade (Live card milled).');
            }
            break;

        case 'pay_energy_reveal_live_wr_superset':
            if (!empty($state['pending_prompt'])) break;
            $lives = array_values(array_filter(
                $p['hand'] ?? [],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if (empty($lives)) break;
            $state['pending_prompt'] = [
                'type'        => 'pay_energy_reveal_live_wr_superset',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_id'   => $source['instance_id'] ?? '',
                'source_name' => $name,
                'ability_idx' => $ctx['ability_index'] ?? 0,
                'slot'        => $ctx['slot'] ?? null,
                'step'        => 'reveal_hand_live',
                'pay_cost'    => intval($ab['cost'] ?? 2),
                'candidates'  => array_map('cardPromptSummary', $lives),
                'prompt'      => 'Pay ' . intval($ab['cost'] ?? 2) .
                    ' Energy and reveal 1 Live card from your hand: add 1 Live from Waiting Room whose name contains it?',
            ];
            break;

        case 'pay_energy_play_wr_empty':
            break;

        case 'buff_member_matching_discarded_group':
            if (!empty($state['pending_prompt'])) break;
            $discGroup = $ctx['discarded_group'] ?? '';
            if ($discGroup === '') break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr || ($mbr['group'] ?? '') !== $discGroup) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'        => 'buff_member_matching_discarded_group',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'candidates'  => $candidates,
                'hearts'      => $ab['hearts'] ?? [['color' => 'pink', 'count' => 1]],
                'prompt'      => 'Choose 1 Member on your Stage with the same group as the discarded card.',
            ];
            break;

        case 'live_cost_from_subunit_pick':
            if (!empty($state['pending_prompt'])) break;
            $subunit = $ab['subunit'] ?? 'DOLLCHESTRA';
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr || !cardMatchesSubunit($mbr, $subunit)) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'        => 'live_cost_from_subunit_pick',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_id'   => $source['instance_id'] ?? '',
                'source_name' => $name,
                'candidates'  => $candidates,
                'prompt'      => 'Choose 1 ' . $subunit . ' Member on your Stage.',
            ];
            break;

        case 'score_if_distinct_subunits_on_stage':
            if (countDistinctSubunitsOnStage($p, $ab['requires_group'] ?? '') >= intval($ab['min_count'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (distinct subunits on Stage).');
            }
            break;

        case 'score_if_distinct_name_and_cost':
            if (countDistinctNamesAndCostsOnStage($p) >= intval($ab['min_count'] ?? 3)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (distinct names and costs).');
            }
            break;

        case 'reduce_hearts_per_live_zone_group':
            $other = countOtherLiveZoneGroup(
                $p,
                $ab['group'] ?? '',
                !empty($ab['exclude_self']) ? ($source['instance_id'] ?? '') : ''
            );
            if ($other > 0) {
                $reduce = $other * intval($ab['per_card'] ?? 2);
                bumpLiveCardColorReduction(
                    $state,
                    $pid,
                    $source['instance_id'] ?? '',
                    $ab['color'] ?? 'pink',
                    $reduce
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required " . ($ab['color'] ?? 'pink') .
                    " hearts reduced by $reduce.");
            }
            break;

        case 'score_if_stage_group_cost_min':
            if (countStageGroupMinCost($p, $ab['group'] ?? '', intval($ab['min_cost'] ?? 10))
                >= intval($ab['min_count'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (' . ($ab['group'] ?? '') . ' cost ' . intval($ab['min_cost'] ?? 10) . '+).');
            }
            break;

        case 'treat_group_stage_hearts_as':
            $state = initLiveModifiers($state);
            $grp = $ab['group'] ?? '';
            $color = $ab['color'] ?? 'pink';
            if ($grp !== '') {
                $state['live_modifiers'][$pid]['group_hearts_as'][$grp] = $color;
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] $grp Member hearts treated as $color until Live ends.");
            break;

        case 'treat_pick_group_member_hearts_as':
            if (!empty($state['pending_prompt'])) break;
            $grp = $ab['group'] ?? 'Hasunosora';
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr || ($mbr['group'] ?? '') !== $grp) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'          => 'treat_pick_group_member_hearts_as',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'color'         => $ab['color'] ?? 'pink',
                'candidates'    => $candidates,
                'prompt'        => "Choose 1 $grp Member — until this Live ends, all hearts on that Member are treated as " .
                    ($ab['color'] ?? 'pink') . ' ♡.',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] choose a Member for heart treatment.");
            break;

        case 'live_start_edel_choice':
            if (!empty($state['pending_prompt'])) break;
            $subunit = $ab['subunit'] ?? 'Edel Note';
            $canPlay = false;
            foreach ($p['waiting_room'] as $c) {
                if (cardMatchesWrPick($c, [
                    'subunit'  => $subunit,
                    'filter'   => 'member',
                    'max_cost' => intval($ab['max_cost'] ?? 4),
                ])) {
                    $canPlay = true;
                    break;
                }
            }
            foreach (['left', 'center', 'right'] as $slot) {
                if (empty($p['stage'][$slot])) {
                    $canPlay = $canPlay && true;
                    break;
                }
            }
            $hasEmpty = false;
            foreach (['left', 'center', 'right'] as $slot) {
                if (empty($p['stage'][$slot])) {
                    $hasEmpty = true;
                    break;
                }
            }
            $canPlay = $canPlay && $hasEmpty;
            $choices = ['reduce'];
            $labels = ['Reduce required purple hearts by 1.'];
            if ($canPlay) {
                array_unshift($choices, 'play');
                array_unshift($labels, 'Play 1 Edel Note Member (cost ≤' .
                    intval($ab['max_cost'] ?? 4) . ') from Waiting Room into an empty area.');
            }
            $state['pending_prompt'] = [
                'type'          => 'live_start_edel_choice',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'choices'       => $choices,
                'choice_labels' => $labels,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start: choose an effect.');
            break;
    }
    return $state;
}
