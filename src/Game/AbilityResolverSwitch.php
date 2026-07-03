<?php
/**
 * Core ability type switch — extracted from AbilityResolver.php.
 */

require_once __DIR__ . '/AbilityResolverSwitchOptional.php';
require_once __DIR__ . '/AbilityResolverSwitchLiveStart.php';
require_once __DIR__ . '/AbilityResolverSwitchLive.php';
require_once __DIR__ . '/AbilityResolverSwitchDeckLook.php';
require_once __DIR__ . '/AbilityResolverSwitchScore.php';
require_once __DIR__ . '/AbilityResolverSwitchWaitActivate.php';

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
    if (str_starts_with($type, 'optional_')) {
        $result = tryResolveAbilityEffectSwitchOptional($state, $pid, $source, $ab, $ctx, $type, $p, $name);
        if ($result !== null) {
            return $result;
        }
    }

    if (str_starts_with($type, 'live_start_')) {
        return tryResolveAbilityEffectSwitchLiveStart($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'live_') && !str_starts_with($type, 'live_start_')) {
        return tryResolveAbilityEffectSwitchLive($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (preg_match('/^(draw_|look_|deck_|mill_|surveil_)/', $type)) {
        return tryResolveAbilityEffectSwitchDeckLook($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'score_')) {
        return tryResolveAbilityEffectSwitchScore($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'wait_') || str_starts_with($type, 'activate_')) {
        return tryResolveAbilityEffectSwitchWaitActivate($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

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

        case 'block_effect_member_activate_turn':
            $state['block_effect_member_activate'] = true;
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Members cannot become Active by effects this turn.");
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

        case 'yell_hearts_wildcard':
        case 'yell_heart_score_bonus':
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

        case 'treat_as_subunits':
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

        case 'blade_bonus':
            $state = applyModifierEffect($state, $pid, $ab);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] gains +' . intval($ab['amount'] ?? 1) . ' Blade until this Live ends.');
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

        case 'block_success_live_on_tie':
            $state = initLiveModifiers($state);
            $state['live_modifiers']['both']['block_success_live_on_tie'] = true;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] if Live scores tie, neither player adds Success Lives this turn.');
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

        case 'hearts_if_combined_energy':
        case 'live_score_if_opp_success_total':
        case 'on_self_wait_draw_discard':
        case 'optional_named_live_zone_from_wr_on_hand':
        case 'member_blade_on_live_zone_faceup':
        case 'cannot_live_if_solo_stage':
        case 'blade_bonus_if_center':
        case 'cost_bonus_if_min_energy':
        case 'live_score_bonus_if_min_energy':
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

    }
    return $state;
}
