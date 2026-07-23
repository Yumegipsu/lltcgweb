<?php
/**
 * μ's (Love Live!) bp5/bp6 gap effect handlers.
 * Included by effects.php.
 */

function plMuseGapEffectTypes(): array {
    return [
        'look_reveal_live_score_plus',
        'hearts_if_distinct_stage_names',
        'mandatory_discard_group_branch',
        'activated_wait_opp_reduce_cost_per_group',
        'auto_yell_blade_if_no_blade_count',
        'draw_if_live_zone_count',
        'both_players_trim_then_draw',
        'blade_if_success_score_min',
        'mill_then_add_wr_group',
        'reduce_hearts_center_mus_blade_pairs',
        'live_start_draw_both_grant_blade_score',
        'score_and_increase_hearts_per_success',
        'reduce_hearts_per_non_yellow_stage',
        'live_start_arise_choice',
        'hearts_per_other_group_member',
        'discard_activate_member_add_live_if_opp',
        'hearts_bonus_if_self_wait',
        'continuous_mus_blade_if_live_zone',
        'live_start_mus_blade_if_live_zone',
        'live_start_wr_group_live_score',
        'live_success_mus_draw_if_no_blade',
        'auto_yell_mus_draw_discard',
        'surveil2_mus_ability_choice',
        'reveal_hand_named_stack_under',
        'play_stacked_member_from_under',
        'optional_discard2_add_wr_blade_member_and_heart_live',
        'mandatory_discard_color_threshold_reveal5',
        'reveal_top_draw_live_score_if_no_blade',
        'wait_self_activate_other_member',
        'live_score_if_sides_two_original_blades',
        'leave_stage_wait_opp_max_cost',
        'blade_if_success_subunit',
        'hearts_if_success_score_min',
        'hearts_if_success_subunit',
        'add_wr_live_if_success_score',
        'hand_cost_reduction_if_success_live_group',
        'auto_position_change_center_on_ability',
        'score_if_center_moved_this_turn',
        'optional_leave_mus_score_add_wr_live',
        'reduce_hearts_mus_live_min_score_success',
        'draw_if_success_mus',
        'optional_replace_success_with_wr_live',
        'opp_blind_pick_hand_reveal',
        'if_baton_lower_cost_play_hand_member',
        'leave_stage_add_wr_live_energy_if_success',
        'add_wr_live_min_score',
    ];
}

function plMuseGapIsEffectType(string $type): bool {
    return in_array($type, plMuseGapEffectTypes(), true);
}

function plMuseGapCountDistinctStageNames(array $p): int {
    $names = [];
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        $names[cardNameKey($mbr)] = true;
    }
    return count($names);
}

function plMuseGapMemberHeartsOfColor(array $member, string $color): int {
    $n = 0;
    foreach ($member['hearts'] ?? [] as $hg) {
        if (($hg['color'] ?? '') === $color) {
            $n += intval($hg['count'] ?? 1);
        }
    }
    foreach ($member['bonus_hearts'] ?? [] as $c) {
        if ($c === $color) {
            $n++;
        }
    }
    return $n;
}

function plMuseGapNotifyMemberAbilityResolved(
    array $state,
    string $pid,
    array $resolvedMember,
    string $phase
): array {
    $resolvedId = $resolvedMember['instance_id'] ?? '';
    if ($resolvedId === '') {
        return $state;
    }
    $p = &$state['players'][$pid];
    $center = $p['stage']['center'] ?? null;
    if (!$center || ($center['instance_id'] ?? '') !== $resolvedId) {
        return $state;
    }
    if (($center['group'] ?? '') !== "μ's") {
        return $state;
    }

    foreach ($p['live_zone'] as $live) {
        if (!$live) {
            continue;
        }
        $liveId = $live['instance_id'] ?? '';
        $liveName = $live['name_en'] ?? $live['name'] ?? 'Live';
        foreach ($live['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') {
                continue;
            }
            $type = $ab['type'] ?? '';
            if ($phase === 'live_start' && $type === 'auto_position_change_center_on_ability') {
                $left = $p['stage']['left'];
                $p['stage']['left'] = $p['stage']['center'];
                $p['stage']['center'] = $left;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$liveName] position-changed Center Member (Live Start ability resolved).");
                if ($left) {
                    $state = resolveAutoAreaMoveAbilities($state, $pid, $left['instance_id'] ?? '');
                }
                if ($p['stage']['left']) {
                    $state = resolveAutoAreaMoveAbilities($state, $pid, $p['stage']['left']['instance_id'] ?? '');
                }
            }
            if ($phase === 'live_success' && $type === 'score_if_center_moved_this_turn') {
                $target = null;
                foreach ($p['stage'] as $mbr) {
                    if ($mbr && ($mbr['instance_id'] ?? '') === $resolvedId) {
                        $target = $mbr;
                        break;
                    }
                }
                if ($target && !empty($target['moved_this_turn'])) {
                    bumpLiveCardScore($state, $pid, $liveId, intval($ab['amount'] ?? 1));
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $liveName . '] score +' . intval($ab['amount'] ?? 1) .
                        ' (Center Member moved this turn; Live Success ability resolved).');
                }
            }
        }
    }
    return $state;
}

function plMuseGapCountDistinctGroupsOnStage(array $p): int {
    $groups = [];
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        $g = $mbr['group'] ?? '';
        if ($g !== '') $groups[$g] = true;
    }
    return count($groups);
}

function plMuseGapCardMusSurveilEligible(array $card, string $group): bool {
    if (($card['group'] ?? '') !== $group) return false;
    $abilities = $card['abilities'] ?? [];
    if (empty($abilities)) return true;
    foreach ($abilities as $ab) {
        $trigger = $ab['trigger'] ?? '';
        if ($trigger === 'continuous' || $trigger === 'always') continue;
        return false;
    }
    return true;
}

function plMuseGapApplySuccessLivePassiveReductions(array $state, string $pid, array $liveCard): array {
    $required = $liveCard['required_hearts'] ?? $liveCard['hearts'] ?? [];
    if (($liveCard['card_type'] ?? '') !== 'ライブ') return $required;
    if (($liveCard['group'] ?? '') !== "μ's") return $required;
    if (intval($liveCard['score'] ?? 0) < 5) return $required;

    $p = $state['players'][$pid] ?? [];
    $reduceAmt = 0;
    $heartColor = 'gray';
    foreach ($p['success_lives'] ?? [] as $sl) {
        foreach ($sl['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') continue;
            if (($ab['type'] ?? '') !== 'reduce_hearts_mus_live_min_score_success') continue;
            if (intval($liveCard['score'] ?? 0) < intval($ab['min_score'] ?? 5)) continue;
            $reduceAmt = max($reduceAmt, intval($ab['reduce'] ?? 2));
            $heartColor = (string)($ab['heart_color'] ?? 'gray');
            break 2;
        }
    }
    if ($reduceAmt <= 0) return $required;
    $reduceColor = ($heartColor === 'gray') ? 'any' : $heartColor;
    return reduceHeartRequirementsByColor($required, $reduceColor, $reduceAmt);
}

function plMuseGapApplyContinuousHearts(array $state, string $pid, array $member, array $ab, array $hearts): array {
    $type = $ab['type'] ?? '';
    $p = $state['players'][$pid] ?? [];
    if ($type === 'hearts_if_success_score_min') {
        if (sumSuccessLiveScores($p, $state, $pid) >= intval($ab['min_success_score_sum'] ?? 6)) {
            foreach ($ab['hearts'] ?? [] as $h) {
                for ($i = 0; $i < intval($h['count'] ?? 1); $i++) {
                    $hearts[] = $h['color'] ?? 'yellow';
                }
            }
        }
    }
    if ($type === 'hearts_if_success_subunit') {
        if (successZoneHasSubunit($p, $ab['subunit'] ?? '')) {
            foreach ($ab['hearts'] ?? [] as $h) {
                for ($i = 0; $i < intval($h['count'] ?? 1); $i++) {
                    $hearts[] = $h['color'] ?? 'yellow';
                }
            }
        }
    }
    if ($type === 'hearts_per_other_group_member') {
        $group = $ab['group'] ?? '';
        $selfId = $member['instance_id'] ?? '';
        foreach ($p['stage'] as $mbr) {
            if (!$mbr || ($mbr['instance_id'] ?? '') === $selfId) continue;
            if (($mbr['group'] ?? '') === $group) {
                foreach ($ab['hearts'] ?? [] as $h) {
                    for ($i = 0; $i < intval($h['count'] ?? 1); $i++) {
                        $hearts[] = $h['color'] ?? 'blue';
                    }
                }
            }
        }
    }
    if ($type === 'hearts_bonus_if_self_wait') {
        if (!($member['active'] ?? true)) {
            foreach ($ab['hearts'] ?? [] as $h) {
                for ($i = 0; $i < intval($h['count'] ?? 1); $i++) {
                    $hearts[] = $h['color'] ?? 'blue';
                }
            }
        }
    }
    return $hearts;
}

function plMuseGapSidesHaveExactOriginalBlades(array $p, int $minBlades): bool {
    foreach (['left', 'right'] as $slot) {
        $mbr = $p['stage'][$slot] ?? null;
        if (!$mbr || intval($mbr['blade'] ?? 0) !== $minBlades) {
            return false;
        }
    }
    return true;
}

function plMuseGapApplyContinuousLiveScore(array $state, string $pid, array $member, array $ab, string $slot = ''): int {
    if (($ab['trigger'] ?? '') !== 'continuous') {
        return 0;
    }
    if (($ab['type'] ?? '') !== 'live_score_if_sides_two_original_blades') {
        return 0;
    }
    if (!empty($ab['center_only']) && $slot !== 'center') {
        return 0;
    }
    $p = $state['players'][$pid] ?? [];
    if (plMuseGapSidesHaveExactOriginalBlades($p, intval($ab['min_original_blades'] ?? 2))) {
        return intval($ab['amount'] ?? 1);
    }
    return 0;
}

function plMuseGapApplyContinuousBlade(int $blade, array $member, array $state, string $pid, array $ab): int {
    $type = $ab['type'] ?? '';
    $p = $state['players'][$pid] ?? [];
    if ($type === 'blade_if_success_score_min') {
        if (sumSuccessLiveScores($p, $state, $pid) >= intval($ab['min_success_score_sum'] ?? 6)) {
            $blade += intval($ab['amount'] ?? 2);
        }
    }
    if ($type === 'blade_if_success_subunit') {
        if (successZoneHasSubunit($p, $ab['subunit'] ?? '')) {
            $blade += intval($ab['amount'] ?? 2);
        }
    }
    if ($type === 'blade_per_other_group_member') {
        $group = $ab['group'] ?? '';
        $selfId = $member['instance_id'] ?? '';
        foreach ($p['stage'] as $mbr) {
            if (!$mbr || ($mbr['instance_id'] ?? '') === $selfId) continue;
            if (($mbr['group'] ?? '') === $group) {
                $blade += intval($ab['amount'] ?? 2);
            }
        }
    }
    if ($type === 'blade_bonus_if_self_wait') {
        if (!($member['active'] ?? true)) {
            $blade += intval($ab['amount'] ?? 2);
        }
    }
    if ($type === 'continuous_mus_blade_if_live_zone') {
        $group = $ab['group'] ?? "μ's";
        foreach ($p['live_zone'] ?? [] as $lc) {
            if ($lc && isLiveTypeCard($lc) && ($lc['group'] ?? '') === $group) {
                $blade += intval($ab['amount'] ?? 2);
                break;
            }
        }
    }
    return $blade;
}

function plMuseGapApplyHandCostReduction(array $state, string $pid, array $card, int $base): int {
    if (!cardHasAbilities($card)) return $base;
    $p = $state['players'][$pid] ?? [];
    foreach ($card['abilities'] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') continue;
        if (($ab['type'] ?? '') !== 'hand_cost_reduction_if_success_live_group') continue;
        if (empty($p['success_lives'])) continue;
        if (($card['group'] ?? '') !== ($ab['group'] ?? "μ's")) continue;
        if (intval($card['cost'] ?? 0) < intval($ab['min_original_cost'] ?? 17)) continue;
        $base = max(0, $base - intval($ab['amount'] ?? 2));
    }
    return $base;
}

function plMuseGapResolveEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (!plMuseGapIsEffectType($type)) return $state;

    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'look_reveal_live_score_plus':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'look_reveal_live_score_plus',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'ability'       => $ab,
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Discard 1', 'No — Skip'],
                'prompt'        => 'Put 1 card from your hand into the Waiting Room: look at deck top equal to Live score + ' .
                    intval($ab['bonus'] ?? 2) . ', add 1 to hand?',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] optional look (score+bonus).");
            break;

        case 'mandatory_discard_group_branch':
            if (count($p['hand'] ?? []) < intval($ab['discard'] ?? 1)) break;
            autoDiscardFromHand($p, intval($ab['discard'] ?? 1));
            $discarded = end($p['waiting_room']);
            $isGroup = $discarded && ($discarded['group'] ?? '') === ($ab['group'] ?? "μ's");
            if ($isGroup) {
                $then = ['type' => 'look_reveal_filter', 'look' => intval($ab['look'] ?? 4), 'filter' => '', 'pick' => intval($ab['pick'] ?? 2)];
            } else {
                $then = ['type' => 'add_from_wr', 'filter' => $ab['else_filter'] ?? 'live', 'count' => intval($ab['else_count'] ?? 1)];
            }
            $state = resolveAbilityEffect($state, $pid, $source, $then, $ctx);
            break;

        case 'activated_wait_opp_reduce_cost_per_group':
            $then = [
                'type'       => 'wait_opponent_stage_max_cost',
                'max_cost'   => intval($ab['max_cost'] ?? 10),
                'pick_count' => intval($ab['pick_count'] ?? 1),
            ];
            $state = resolveAbilityEffect($state, $pid, $source, $then, $ctx);
            break;

        case 'draw_if_live_zone_count':
            if (count($p['live_zone'] ?? []) >= intval($ab['min_count'] ?? 2)) {
                $state = resolveAbilityEffect($state, $pid, $source, ['type' => 'draw_cards', 'draw' => intval($ab['draw'] ?? 1)], $ctx);
            }
            break;

        case 'both_players_trim_then_draw':
            foreach (['p1', 'p2'] as $id) {
                $pl = &$state['players'][$id];
                while (count($pl['hand']) > intval($ab['target_hand'] ?? 3) && !empty($pl['hand'])) {
                    $pl['waiting_room'][] = array_pop($pl['hand']);
                }
                drawCardsForPlayer($state, $id, intval($ab['draw'] ?? 3));
            }
            unset($pl);
            $state = addLog($state, 'Both players trimmed to ' . intval($ab['target_hand'] ?? 3) . ' and drew ' . intval($ab['draw'] ?? 3) . '.');
            break;

        case 'mill_then_add_wr_group':
            $mill = intval($ab['mill'] ?? 3);
            for ($i = 0; $i < $mill && !empty($p['main_deck']); $i++) {
                $p['waiting_room'][] = array_shift($p['main_deck']);
            }
            $state = resolveAbilityEffect($state, $pid, $source, [
                'type'   => 'add_from_wr',
                'group'  => $ab['group'] ?? '',
                'filter' => $ab['filter'] ?? 'member',
                'count'  => intval($ab['count'] ?? 1),
            ], $ctx);
            break;

        case 'reduce_hearts_center_mus_blade_pairs':
            $center = $p['stage']['center'] ?? null;
            if ($center && ($center['group'] ?? '') === ($ab['group'] ?? "μ's")) {
                $countColor = $ab['count_heart_color'] ?? null;
                if ($countColor) {
                    $hearts = plMuseGapMemberHeartsOfColor($center, $countColor);
                } else {
                    $hearts = memberHeartCount($center) + memberContinuousHeartCount($center, $state, $pid);
                }
                $pairs = intdiv($hearts, 2);
                $perReduce = $countColor
                    ? intval($ab['per_pair_reduce'] ?? 1)
                    : intval($ab['per_pair'] ?? 2);
                $reduce = min(intval($ab['max_reduce'] ?? 3), $pairs * $perReduce);
                if ($reduce > 0) {
                    $reduceColor = (($ab['reduce_heart_color'] ?? '') === 'gray') ? 'any' : 'any';
                    foreach ($p['live_zone'] as &$lc) {
                        if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                            if (($ab['reduce_heart_color'] ?? '') === 'gray') {
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
                    $label = (($ab['reduce_heart_color'] ?? '') === 'gray')
                        ? "$reduce Gray heart(s)"
                        : "$reduce heart(s)";
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] required $label reduced.");
                }
            }
            break;

        case 'live_start_draw_both_grant_blade_score':
            foreach (['p1', 'p2'] as $id) {
                $pl = &$state['players'][$id];
                if (!empty($pl['main_deck'])) $pl['hand'][] = array_shift($pl['main_deck']);
                if (!empty($pl['hand'])) $pl['waiting_room'][] = array_pop($pl['hand']);
            }
            unset($pl);
            $stageCount = countStageMembers($p);
            if ($stageCount >= 2) {
                if (!empty($ab['heart_color'])) {
                    $group = $ab['group'] ?? '';
                    foreach ($p['stage'] as $s => &$mbr) {
                        if (!$mbr) continue;
                        if ($group !== '' && ($mbr['group'] ?? '') !== $group) continue;
                        addBonusHeartsToMember($mbr, [[
                            'color' => $ab['heart_color'],
                            'count' => intval($ab['heart_count'] ?? 1),
                        ]]);
                        $p['stage'][$s] = $mbr;
                        break;
                    }
                    unset($mbr);
                } else {
                    $state = applyModifierEffect($state, $pid, [
                        'type'         => 'member_blade_bonus',
                        'group'        => $ab['group'] ?? "μ's",
                        'amount'       => intval($ab['blade_amount'] ?? 2),
                        'max_members'  => 1,
                    ]);
                }
            }
            if ($stageCount >= 3 && plMuseGapCountDistinctStageNames($p) >= 3) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', 1);
            }
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] both players drew/discarded.");
            break;

        case 'score_and_increase_hearts_per_success':
            $n = count($p['success_lives'] ?? []);
            if ($n > 0) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $n * intval($ab['score_per_success'] ?? 2));
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if (!empty($ab['hearts_color_increase']) && is_array($ab['hearts_color_increase'])) {
                            if (!isset($lc['hearts_color_increase']) || !is_array($lc['hearts_color_increase'])) {
                                $lc['hearts_color_increase'] = [];
                            }
                            foreach ($ab['hearts_color_increase'] as $color => $per) {
                                $lc['hearts_color_increase'][$color] =
                                    intval($lc['hearts_color_increase'][$color] ?? 0)
                                    + $n * intval($per);
                            }
                        } else {
                            $lc['hearts_penalty'] = intval($lc['hearts_penalty'] ?? 0)
                                + $n * intval($ab['hearts_per_success'] ?? 1);
                        }
                        break;
                    }
                }
                unset($lc);
            }
            break;

        case 'reduce_hearts_per_non_yellow_stage':
            $reduce = 0;
            $exclude = $ab['exclude_colors'] ?? null;
            foreach ($p['stage'] as $mbr) {
                if (!$mbr) continue;
                if (is_array($exclude)) {
                    $qualifies = false;
                    foreach ($mbr['hearts'] ?? [] as $h) {
                        $c = $h['color'] ?? '';
                        if ($c !== '' && !in_array($c, $exclude, true)) {
                            $qualifies = true;
                            break;
                        }
                    }
                    if (!$qualifies) continue;
                } else {
                    $hasNonYellow = false;
                    foreach ($mbr['hearts'] ?? [] as $h) {
                        if (($h['color'] ?? '') !== 'yellow') $hasNonYellow = true;
                    }
                    if (!$hasNonYellow) continue;
                }
                $reduce += intval($ab['per_member'] ?? 1);
            }
            if ($reduce > 0) {
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if (($ab['reduce_heart_color'] ?? '') === 'gray') {
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction']['any'] =
                                intval($lc['hearts_color_reduction']['any'] ?? 0) + $reduce;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $reduce;
                        }
                        break;
                    }
                }
                unset($lc);
            }
            break;

        case 'live_start_arise_choice':
            $hasArise = false;
            foreach ($p['stage'] as $mbr) {
                if ($mbr && ($mbr['group'] ?? '') === ($ab['group'] ?? 'A-RISE')) $hasArise = true;
            }
            if (!$hasArise) break;
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'live_start_arise_choice',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'ability'       => $ab,
                'choices'       => ['activate', 'wait'],
                'choice_labels' => [
                    'Activate Wait Member (+' . intval($ab['blade_amount'] ?? 2) . ' Blade)',
                    'Wait opponent (≤' . intval($ab['max_original_blades'] ?? $ab['max_original_hearts'] ?? 3) .
                    ' original Blade)',
                ],
            ];
            break;

        case 'discard_activate_member_add_live_if_opp':
            if (count($p['hand'] ?? []) < intval($ab['discard'] ?? 1)) break;
            autoDiscardFromHand($p, intval($ab['discard'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] activated a Wait Member.");
            break;

        case 'surveil2_mus_ability_choice':
            $look = intval($ab['look'] ?? 2);
            $top = [];
            for ($i = 0; $i < $look && !empty($p['main_deck']); $i++) {
                $top[] = array_shift($p['main_deck']);
            }
            $group = $ab['group'] ?? "μ's";
            $matches = array_values(array_filter(
                $top,
                fn($c) => plMuseGapCardMusSurveilEligible($c, $group)
            ));
            if (!empty($matches) && empty($state['pending_prompt'])) {
                $state['pending_prompt'] = [
                    'type'        => 'surveil2_mus_ability_choice',
                    'owner'       => $pid,
                    'responder'   => $pid,
                    'source_name' => $name,
                    'prompt'      => 'Look at the top ' . count($top) . ' card(s). You may add 1 '
                        . $group . ' card to your hand; put the rest in the Waiting Room.',
                    'look_cards'  => array_map('cardPromptSummary', $top),
                    'candidates'  => array_map('cardPromptSummary', $matches),
                ];
                $state['seq']++;
                break;
            }
            if (!empty($top)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $top);
            }
            break;

        case 'reveal_top_draw_live_score_if_no_blade':
            if (!empty($p['main_deck'])) {
                $top = array_shift($p['main_deck']);
                $p['hand'][] = $top;
                if (($top['card_type'] ?? '') === 'メンバー' && empty($top['blade_hearts'])) {
                    $state['live_modifiers'][$pid]['live_score_bonus'] =
                        intval($state['live_modifiers'][$pid]['live_score_bonus'] ?? 0) + 1;
                }
            }
            break;

        case 'wait_self_activate_other_member':
            $slot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
            if ($slot !== null && isset($p['stage'][$slot])) {
                waitMember($p['stage'][$slot], $state);
            }
            foreach ($p['stage'] as $s => &$mbr) {
                if (!$mbr || ($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) continue;
                $mbr['active'] = true;
                break;
            }
            unset($mbr);
            break;

        case 'leave_stage_wait_opp_max_cost':
            $state = resolveAbilityEffect($state, $pid, $source, [
                'type'   => 'leave_stage_add_from_wr',
                'filter' => 'member',
                'count'  => 0,
            ], $ctx);
            $state = resolveAbilityEffect($state, $pid, $source, [
                'type'       => 'wait_opponent_stage_max_cost',
                'max_cost'   => intval($ab['max_cost'] ?? 4),
                'pick_count' => intval($ab['pick_count'] ?? 1),
            ], $ctx);
            break;

        case 'add_wr_live_if_success_score':
            if (sumSuccessLiveScores($p, $state, $pid) >= intval($ab['min_success_score_sum'] ?? 6)) {
                $state = resolveAbilityEffect($state, $pid, $source, [
                    'type'   => 'add_from_wr',
                    'group'  => $ab['group'] ?? "μ's",
                    'filter' => 'live',
                    'count'  => intval($ab['count'] ?? 1),
                ], $ctx);
            }
            break;

        case 'draw_if_success_mus':
            foreach ($p['success_lives'] ?? [] as $sl) {
                if (($sl['group'] ?? '') === ($ab['group'] ?? "μ's")) {
                    $state = resolveAbilityEffect($state, $pid, $source, ['type' => 'draw_cards', 'draw' => intval($ab['draw'] ?? 1)], $ctx);
                    break;
                }
            }
            break;

        case 'optional_leave_mus_score_add_wr_live':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_leave_mus_score_add_wr_live',
                'step'          => 'confirm',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'ability'       => $ab,
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Leave μ\'s Member', 'No — Skip'],
                'prompt'        => 'Put 1 μ\'s Member from your Stage into the Waiting Room: this card\'s score +1 and add 1 μ\'s Live from your Waiting Room to your hand?',
            ];
            break;

        case 'opp_blind_pick_hand_reveal':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $oppHand = $state['players'][$opp]['hand'] ?? [];
            $pickCount = intval($ab['pick_count'] ?? 3);
            if (count($oppHand) < $pickCount) break;
            if (!empty($ab['force_random'])) {
                $pool = $oppHand;
                shuffle($pool);
                $picked = array_slice($pool, 0, $pickCount);
                $hasLive = false;
                $names = [];
                foreach ($picked as $c) {
                    $names[] = cardDisplayName($c);
                    if (($c['card_type'] ?? '') === 'ライブ') {
                        $hasLive = true;
                    }
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] revealed 3 random opponent hand cards: ' .
                    implode(', ', $names) . '.');
                if (!$hasLive) {
                    $drawn = drawCardsForPlayer($state, $pid, 1);
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — no Live revealed; drew $drawn.");
                }
                break;
            }
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'        => 'opp_blind_pick_hand_reveal',
                'owner'       => $pid,
                'responder'   => $opp,
                'source_name' => $name,
                'pick_count'  => $pickCount,
                'prompt'      => 'Choose 3 cards from your hand to reveal (opponent cannot see your selection).',
            ];
            break;

        case 'if_baton_lower_cost_play_hand_member':
            $fromCost = intval($source['baton_from_cost'] ?? -1);
            $selfCost = intval($source['cost'] ?? 0);
            if ($fromCost >= 0 && $fromCost < $selfCost) {
                $state = resolveAbilityEffect($state, $pid, $source, [
                    'type'      => 'optional_play_hand_member',
                    'max_cost'  => intval($ab['max_cost'] ?? 4),
                    'max_count' => 1,
                ], $ctx);
            }
            break;

        case 'leave_stage_add_wr_live_energy_if_success':
            $state = resolveAbilityEffect($state, $pid, $source, [
                'type'   => 'leave_stage_add_from_wr',
                'filter' => 'live',
                'group'  => $ab['group'] ?? "μ's",
                'count'  => 1,
            ], $ctx);
            if (sumSuccessLiveScores($p, $state, $pid) >= intval($ab['min_success_score_sum'] ?? 9)) {
                $state = resolveAbilityEffect($state, $pid, $source, ['type' => 'activate_energy', 'count' => 2], $ctx);
            }
            break;

        case 'add_wr_live_min_score':
            foreach ($p['waiting_room'] as $c) {
                if (($c['card_type'] ?? '') === 'ライブ' && intval($c['score'] ?? 0) >= intval($ab['min_score'] ?? 6)) {
                    $p['hand'][] = $c;
                    $p['waiting_room'] = array_values(array_filter(
                        $p['waiting_room'],
                        fn($x) => ($x['instance_id'] ?? '') !== ($c['instance_id'] ?? '')
                    ));
                    break;
                }
            }
            break;

        case 'live_start_mus_blade_if_live_zone':
            $group = $ab['group'] ?? "μ's";
            $hasLive = false;
            foreach ($p['live_zone'] ?? [] as $lc) {
                if ($lc && ($lc['group'] ?? '') === $group) {
                    $hasLive = true;
                    break;
                }
            }
            if (!$hasLive) {
                break;
            }
            if (!empty($ab['center_only'])) {
                $slot = findMemberSlot($p, $source['instance_id'] ?? '');
                if ($slot !== 'center') {
                    break;
                }
            }
            $state = applyModifierEffect($state, $pid, [
                'type'        => 'member_blade_bonus',
                'group'       => $group,
                'amount'      => intval($ab['amount'] ?? 1),
                'max_members' => 99,
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] all $group Members gained +" . intval($ab['amount'] ?? 1) . ' Blade until Live ends.');
            break;

        case 'live_start_wr_group_live_score':
            if (countWrGroup($p, $ab['group'] ?? "μ's") >= intval($ab['min_count'] ?? 25)) {
                $state = initLiveModifiers($state);
                $state['live_modifiers'][$pid]['live_score_bonus'] =
                    intval($state['live_modifiers'][$pid]['live_score_bonus'] ?? 0) +
                    intval($ab['amount'] ?? 1);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] gained +" . intval($ab['amount'] ?? 1) . ' total Live Score until Live ends.');
            }
            break;

        case 'live_success_mus_draw_if_no_blade':
            $group = $ab['group'] ?? "μ's";
            $found = false;
            foreach ($p['stage'] as $mbr) {
                if (!$mbr || ($mbr['group'] ?? '') !== $group) {
                    continue;
                }
                if (empty($mbr['blade_hearts'])) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                break;
            }
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            if ($drawn > 0 && intval($ab['discard'] ?? 0) > 0 && !empty($p['hand'])) {
                return startEffectDiscardHandPrompt(
                    $state,
                    $pid,
                    $name,
                    intval($ab['discard'] ?? 1)
                );
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (μ's Member without Blade heart on Stage).");
            break;

        case 'hearts_if_distinct_stage_names':
        case 'auto_yell_blade_if_no_blade_count':
        case 'auto_yell_mus_draw_discard':
        case 'optional_discard2_add_wr_blade_member_and_heart_live':
        case 'auto_position_change_center_on_ability':
        case 'score_if_center_moved_this_turn':
        case 'reduce_hearts_mus_live_min_score_success':
        case 'optional_replace_success_with_wr_live':
            // Handled at Live Judge placement time (liveJudgePlaceSuccessLive), not via resolveAbilityEffect.
            break;

        case 'reveal_hand_named_stack_under':
            if (!empty($state['pending_prompt'])) break;
            if (!abilitySlotAllowed($ab, $ctx, $p, $source)) break;
            $candidates = array_values(array_filter(
                $p['hand'] ?? [],
                fn($c) => cardMatchesWrPick($c, [
                    'group'    => $ab['group'] ?? '',
                    'filter'   => $ab['filter'] ?? 'member',
                    'max_cost' => intval($ab['max_cost'] ?? 2),
                ])
            ));
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'          => 'reveal_hand_named_stack_under',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_slot'   => $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? ''),
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $candidates),
                'ability'       => $ab,
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Reveal & stack', 'No — Skip'],
                'prompt'        => 'Reveal 1 matching Member from your hand to stack under this Member?',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] reveal hand to stack.");
            break;

        case 'play_stacked_member_from_under':
            if (!empty($state['pending_prompt'])) break;
            $stacked = $source['stacked_members'] ?? [];
            $srcSlot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
            if ($srcSlot !== null && !empty($p['stage'][$srcSlot])) {
                $stacked = $p['stage'][$srcSlot]['stacked_members'] ?? $stacked;
            }
            $group = $ab['group'] ?? '';
            $maxCost = intval($ab['max_cost'] ?? 2);
            $candidates = array_values(array_filter(
                $stacked,
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
                    && cardMatchesGroup($c, $group, 'member')
                    && intval($c['cost'] ?? 0) <= $maxCost
            ));
            if (empty($candidates)) break;
            $emptySlots = [];
            foreach (['left', 'center', 'right'] as $s) {
                if (empty($p['stage'][$s])) $emptySlots[] = $s;
            }
            if (empty($emptySlots)) break;
            $state['pending_prompt'] = [
                'type'          => 'play_stacked_member_from_under',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_slot'   => $srcSlot,
                'source_name'   => $name,
                'stack_cards'   => $candidates,
                'empty_slots'   => $emptySlots,
                'candidates'    => array_map('cardPromptSummary', $candidates),
                'prompt'        => 'Put 1 stacked Member onto an empty Stage area?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Play stacked Member', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] may play stacked Member.");
            break;
    }
    return $state;
}

function plMuseGapLiveReplaceSuccessAbility(array $card): ?array {
    foreach ($card['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') === 'continuous'
            && ($ab['type'] ?? '') === 'optional_replace_success_with_wr_live') {
            return $ab;
        }
    }
    return null;
}

function plMuseGapWrLivesForReplace(array $p, array $ab): array {
    $group = $ab['group'] ?? "μ's";
    $filter = $ab['filter'] ?? 'live';
    return array_values(array_filter(
        $p['waiting_room'] ?? [],
        fn($c) => cardMatchesGroup($c, $group, $filter)
    ));
}

/**
 * If $toAdd has optional_replace_success_with_wr_live and WR has a matching Live,
 * open a yes/no prompt instead of placing immediately. Returns null when no offer.
 */
function plMuseGapTryOfferReplaceSuccess(array $state, string $winnerId, array $toAdd): ?array {
    $ab = plMuseGapLiveReplaceSuccessAbility($toAdd);
    if ($ab === null) {
        return null;
    }
    $cands = plMuseGapWrLivesForReplace($state['players'][$winnerId] ?? [], $ab);
    if ($cands === []) {
        return null;
    }
    $name = $toAdd['name_en'] ?? $toAdd['name'] ?? 'Live';
    $groupLabel = groupPromptLabel($ab['group'] ?? "μ's");
    $state['pending_prompt'] = [
        'type'          => 'replace_success_with_wr_live',
        'step'          => 'confirm',
        'owner'         => $winnerId,
        'responder'     => $winnerId,
        'source_id'     => $toAdd['instance_id'] ?? '',
        'source_name'   => $name,
        'ability'       => $ab,
        'candidates'    => array_map('cardPromptSummary', $cands),
        'choices'       => ['yes', 'no'],
        'choice_labels' => ['Yes — Place from Waiting Room', 'No — Place this Live'],
        'prompt'        => "Place 1 {$groupLabel} Live from your Waiting Room into Success instead of {$name}?",
    ];
    $state = addLog(
        $state,
        ($state['players'][$winnerId]['name'] ?? 'Player') .
        " — [{$name}] may place a {$groupLabel} Live from Waiting Room into Success instead."
    );
    return $state;
}

/** After replace-success prompt resolves: leftover WR dump note + resume Live Judge winners. */
function plMuseGapFinishReplaceSuccessJudge(array $state, string $owner): array {
    $leftInZone = count($state['players'][$owner]['live_zone'] ?? []);
    if ($leftInZone > 0) {
        $state = addLog(
            $state,
            ($state['players'][$owner]['name'] ?? 'Player') .
            " — $leftInZone other successful Live(s) in storage cannot be placed (only 1 Success Live per Judge win); sent to Waiting Room.",
            'action'
        );
    }
    $ctx = $state['_live_judge_ctx'] ?? null;
    if (is_array($ctx)) {
        $ctx['winner_index'] = intval($ctx['winner_index'] ?? 0) + 1;
        if (!in_array($owner, $ctx['success_placed_by'] ?? [], true)) {
            $ctx['success_placed_by'][] = $owner;
        }
        $state['_live_judge_ctx'] = $ctx;
    }
    $state['seq']++;
    return advanceLiveJudgeWinners($state);
}

function plMuseGapResolvePrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $type = $prompt['type'] ?? '';

    if ($type === 'replace_success_with_wr_live') {
        $step = $prompt['step'] ?? 'confirm';
        $srcId = $prompt['source_id'] ?? '';
        $ab = $prompt['ability'] ?? [];
        $srcName = $prompt['source_name'] ?? 'Live';
        $ownerP = $state['players'][$owner];

        if ($step === 'confirm') {
            if ($choice === 'no' || $choice === 'skip') {
                $toAdd = null;
                foreach ($ownerP['live_zone'] ?? [] as $c) {
                    if (($c['instance_id'] ?? '') === $srcId) {
                        $toAdd = $c;
                        break;
                    }
                }
                if ($toAdd === null) {
                    throw new Exception('Live card no longer in storage');
                }
                unset($state['pending_prompt']);
                $state = liveJudgePlaceSuccessLive($state, $owner, $toAdd, false);
                return plMuseGapFinishReplaceSuccessJudge($state, $owner);
            }
            if ($choice !== 'yes') {
                throw new Exception('Invalid choice');
            }
            $cands = plMuseGapWrLivesForReplace($ownerP, $ab);
            if ($cands === []) {
                unset($state['pending_prompt']);
                $toAdd = null;
                foreach ($ownerP['live_zone'] ?? [] as $c) {
                    if (($c['instance_id'] ?? '') === $srcId) {
                        $toAdd = $c;
                        break;
                    }
                }
                if ($toAdd === null) {
                    throw new Exception('Live card no longer in storage');
                }
                $state = liveJudgePlaceSuccessLive($state, $owner, $toAdd, false);
                return plMuseGapFinishReplaceSuccessJudge($state, $owner);
            }
            $groupLabel = groupPromptLabel($ab['group'] ?? "μ's");
            $state['pending_prompt'] = [
                'type'        => 'replace_success_with_wr_live',
                'step'        => 'pick_wr',
                'owner'       => $owner,
                'responder'   => $owner,
                'source_id'   => $srcId,
                'source_name' => $srcName,
                'ability'     => $ab,
                'candidates'  => array_map('cardPromptSummary', $cands),
                'prompt'      => "Choose 1 {$groupLabel} Live from Waiting Room to place in Success.",
            ];
            $state['seq']++;
            return $state;
        }

        if ($step === 'pick_wr') {
            $cardId = $data['card_id'] ?? (($choice !== 'yes' && $choice !== 'no') ? $choice : '');
            $group = $ab['group'] ?? "μ's";
            $filter = $ab['filter'] ?? 'live';
            $wrLive = null;
            $wr = $ownerP['waiting_room'] ?? [];
            foreach ($wr as $i => $c) {
                if (($c['instance_id'] ?? '') !== $cardId) {
                    continue;
                }
                if (!cardMatchesGroup($c, $group, $filter)) {
                    throw new Exception('Choose a matching Live from Waiting Room');
                }
                $wrLive = $c;
                array_splice($wr, $i, 1);
                break;
            }
            if ($wrLive === null) {
                throw new Exception('Choose a Live card from your Waiting Room');
            }
            $liveZone = $ownerP['live_zone'] ?? [];
            $fromIdx = 0;
            foreach ($liveZone as $slotIdx => $c) {
                if (($c['instance_id'] ?? '') === $srcId) {
                    $fromIdx = liveZoneSlotOf($c, $slotIdx);
                    break;
                }
            }
            $removed = liveJudgeRemoveFromZone($liveZone, $srcId);
            if (!$removed) {
                throw new Exception('Live card no longer in storage');
            }
            $wr[] = $removed;
            $success = $ownerP['success_lives'] ?? [];
            $successIdx = count($success);
            $success[] = $wrLive;
            $ownerP['waiting_room'] = $wr;
            $ownerP['live_zone'] = $liveZone;
            $ownerP['success_lives'] = $success;
            $state['players'][$owner] = $ownerP;
            notifyLiveEnteredSuccess($state, $owner, $wrLive);
            $wrName = $wrLive['name_en'] ?? $wrLive['name'] ?? 'Live';
            unset($state['pending_prompt']);
            $state = addLog(
                $state,
                ($state['players'][$owner]['name'] ?? 'Player') .
                " wins this Live! [{$srcName}] placed \"{$wrName}\" from Waiting Room into Success instead.",
                'good',
                [
                    animSpec($srcId, 'live', 'waiting', $owner, ['from_index' => $fromIdx]),
                    animSpec($cardId, 'waiting', 'success', $owner, ['index' => $successIdx]),
                ]
            );
            return plMuseGapFinishReplaceSuccessJudge($state, $owner);
        }
    }

    $p = &$state['players'][$owner];

    if ($type === 'reveal_hand_named_stack_under') {
        if ($choice === 'no' || $choice === 'skip') {
            unset($state['pending_prompt']);
            $state['seq']++;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped stacking from hand.');
            return finishPromptEffects($state);
        }
        $handId = $data['card_id'] ?? (($choice !== 'yes' && $choice !== '') ? $choice : '');
        $ab = $prompt['ability'] ?? [];
        $stacked = null;
        foreach ($p['hand'] as $i => $c) {
            if (($c['instance_id'] ?? '') === $handId) {
                $stacked = $c;
                array_splice($p['hand'], $i, 1);
                break;
            }
        }
        if (!$stacked) throw new Exception('Choose a card from your hand');
        if (!cardMatchesWrPick($stacked, [
            'group'    => $ab['group'] ?? '',
            'filter'   => $ab['filter'] ?? 'member',
            'max_cost' => intval($ab['max_cost'] ?? 2),
        ])) {
            throw new Exception('That card does not match this effect');
        }
        $slot = $prompt['source_slot'] ?? findMemberSlot($p, $prompt['source_id'] ?? '');
        if ($slot !== null && !empty($p['stage'][$slot])) {
            if (!isset($p['stage'][$slot]['stacked_members'])) {
                $p['stage'][$slot]['stacked_members'] = [];
            }
            $p['stage'][$slot]['stacked_members'][] = $stacked;
        }
        if (!empty($ab['grant_heart_choice'])) {
            $heartChoices = ['pink', 'yellow', 'purple', 'green', 'blue', 'red'];
            $state['pending_prompt'] = [
                'type'          => 'pl_muse_stack_heart_choice',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'heart_choices' => $heartChoices,
                'choices'       => $heartChoices,
                'prompt'        => 'Choose a heart color — until this Live ends, you gain 1 of that heart.',
            ];
            $state['seq']++;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — stacked ' . ($stacked['name_en'] ?? $stacked['name']) . ' under Member.');
            return $state;
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — stacked ' . ($stacked['name_en'] ?? $stacked['name']) . ' under Member.');
        return finishPromptEffects($state);
    }

    if ($type === 'pl_muse_stack_heart_choice') {
        $color = $data['heart_choice'] ?? $choice;
        $choices = $prompt['heart_choices'] ?? $prompt['choices'] ?? ['pink', 'yellow', 'purple'];
        if (!in_array($color, $choices, true)) {
            throw new Exception('Choose a heart color: ' . implode(', ', $choices));
        }
        addBonusHeartsToModifier($state, $owner, [['color' => $color, 'count' => 1]]);
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] gained 1 $color heart until Live ends.");
        return finishPromptEffects($state);
    }

    if ($type === 'play_stacked_member_from_under') {
        if ($choice === 'no' || $choice === 'skip') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $pickId = $data['card_id'] ?? '';
        $targetSlot = $data['slot'] ?? '';
        $srcSlot = $prompt['source_slot'] ?? findMemberSlot($p, $prompt['source_id'] ?? '');
        if ($srcSlot === null || empty($p['stage'][$srcSlot])) {
            throw new Exception('Source Member not on Stage');
        }
        if ($targetSlot === '' || !in_array($targetSlot, ['left', 'center', 'right'], true)
            || !empty($p['stage'][$targetSlot])) {
            throw new Exception('Choose an empty Stage area');
        }
        $ab = $prompt['ability'] ?? [];
        $group = $ab['group'] ?? '';
        $maxCost = intval($ab['max_cost'] ?? 2);
        $stacked = $p['stage'][$srcSlot]['stacked_members'] ?? [];
        $played = null;
        $rest = [];
        foreach ($stacked as $c) {
            if (!$played && ($c['instance_id'] ?? '') === $pickId) {
                $played = $c;
            } else {
                $rest[] = $c;
            }
        }
        if (!$played) throw new Exception('Choose 1 stacked Member');
        if (($played['card_type'] ?? '') !== 'メンバー'
            || !cardMatchesGroup($played, $group, 'member')
            || intval($played['cost'] ?? 0) > $maxCost) {
            throw new Exception('That stacked Member does not match this effect');
        }
        $p['stage'][$srcSlot]['stacked_members'] = $rest;
        $played['active'] = true;
        $played['entered_turn'] = intval($state['turn'] ?? 1);
        $p['stage'][$targetSlot] = $played;
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = resolveOnEnterAbilities($state, $owner, $played, $targetSlot);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — played ' . ($played['name_en'] ?? $played['name']) . ' from under Member.');
        return finishPromptEffects($state);
    }

    if ($type === 'surveil_pick_one') {
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
        $p = &$state['players'][$owner];
        $p['hand'][] = $picked;
        if (!empty($rest)) {
            $p['waiting_room'] = array_merge($p['waiting_room'], $rest);
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] added 1 to hand; rest to Waiting Room.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if (!plMuseGapIsEffectType($type)) return null;

    $p = &$state['players'][$owner];
    $name = $prompt['source_name'] ?? 'Card';

    if ($type === 'look_reveal_live_score_plus' && $choice === 'yes') {
        if (!empty($p['hand'])) $p['waiting_room'][] = array_pop($p['hand']);
        $look = getLiveTotalScore($state, $owner) + intval($prompt['ability']['bonus'] ?? 2);
        $top = [];
        for ($i = 0; $i < $look && !empty($p['main_deck']); $i++) {
            $top[] = array_shift($p['main_deck']);
        }
        if (count($top) === 1) {
            $p['hand'][] = $top[0];
        } elseif (count($top) > 1) {
            $state['pending_prompt'] = [
                'type'        => 'surveil_pick_one',
                'owner'       => $owner,
                'responder'   => $owner,
                'source_name' => $name,
                'look_cards'  => $top,
                'candidates'  => array_map('cardPromptSummary', $top),
                'rest_to_wr'  => true,
                'prompt'      => 'Choose 1 card to add to your hand (rest go to Waiting Room).',
            ];
        }
        return $state;
    }

    if ($type === 'live_start_arise_choice') {
        $ab = $prompt['ability'] ?? [];
        $step = $prompt['step'] ?? 'choose';
        if ($step === 'pick_wait_member') {
            $slot = $data['slot'] ?? '';
            if ($slot === '' || empty($p['stage'][$slot])) {
                throw new Exception('Choose a Member in Wait');
            }
            $p['stage'][$slot]['active'] = true;
            $amt = intval($ab['blade_amount'] ?? 2);
            $p['stage'][$slot]['live_blade_bonus'] = intval($p['stage'][$slot]['live_blade_bonus'] ?? 0) + $amt;
            $mName = $p['stage'][$slot]['name_en'] ?? $p['stage'][$slot]['name'] ?? 'Member';
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$mName] activated from Wait and gained +$amt Blade until Live ends.");
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        if (!in_array($choice, ['activate', 'wait'], true)) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'wait') {
            $opp = ($owner === 'p1') ? 'p2' : 'p1';
            $maxBlades = intval($ab['max_original_blades'] ?? 0);
            if ($maxBlades > 0) {
                $waited = waitOpponentStageByOriginalBlades(
                    $state,
                    $opp,
                    $maxBlades,
                    1,
                    $owner
                );
                $waitLabel = "≤$maxBlades original Blade";
            } else {
                $maxHearts = intval($ab['max_original_hearts'] ?? 3);
                $waited = waitOpponentStageByOriginalHearts(
                    $state,
                    $opp,
                    $maxHearts,
                    1,
                    $owner
                );
                $waitLabel = "≤$maxHearts printed hearts";
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $name . "] put $waited opponent Member(s) with $waitLabel into Wait.");
        } else {
            $waitSlots = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if ($mbr && !($mbr['active'] ?? true)) {
                    $waitSlots[] = $slot;
                }
            }
            if (empty($waitSlots)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . $name . '] no Members in Wait to activate.');
            } elseif (count($waitSlots) === 1) {
                $slot = $waitSlots[0];
                $p['stage'][$slot]['active'] = true;
                $amt = intval($ab['blade_amount'] ?? 2);
                $p['stage'][$slot]['live_blade_bonus'] = intval($p['stage'][$slot]['live_blade_bonus'] ?? 0) + $amt;
                $mName = $p['stage'][$slot]['name_en'] ?? $p['stage'][$slot]['name'] ?? 'Member';
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$mName] activated from Wait and gained +$amt Blade until Live ends.");
            } else {
                $candidates = [];
                foreach ($waitSlots as $slot) {
                    $candidates[] = array_merge(cardPromptSummary($p['stage'][$slot]), ['slot' => $slot]);
                }
                $state['pending_prompt'] = [
                    'type'        => 'live_start_arise_choice',
                    'step'        => 'pick_wait_member',
                    'owner'       => $owner,
                    'responder'   => $owner,
                    'source_name' => $name,
                    'ability'       => $ab,
                    'candidates'  => $candidates,
                    'prompt'      => 'Choose 1 Member in Wait to activate (+Blade until Live ends).',
                ];
                $state['seq']++;
                return $state;
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($type === 'surveil2_mus_ability_choice') {
        $looked = $prompt['look_cards'] ?? [];
        if ($choice === 'skip' || $choice === 'no') {
            if (!empty($looked)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $looked);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $name . '] sent looked cards to the Waiting Room.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $pickId = $data['card_id'] ?? $choice;
        $eligibleIds = array_map(
            fn($c) => $c['instance_id'] ?? '',
            $prompt['candidates'] ?? []
        );
        if (!in_array($pickId, $eligibleIds, true)) {
            throw new Exception('Choose a μ\'s card or skip');
        }
        $picked = null;
        $rest = [];
        foreach ($looked as $c) {
            if (($c['instance_id'] ?? '') === $pickId) {
                $picked = $c;
            } else {
                $rest[] = $c;
            }
        }
        if (!$picked) {
            throw new Exception('Choose 1 looked card');
        }
        $p['hand'][] = $picked;
        if (!empty($rest)) {
            $p['waiting_room'] = array_merge($p['waiting_room'], $rest);
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . $name . '] added ' . cardDisplayName($picked) . ' to hand; rest to Waiting Room.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($type === 'opp_blind_pick_hand_reveal') {
        $effectOwner = $prompt['owner'] ?? $owner;
        $responder = $prompt['responder'] ?? $owner;
        $pickCount = intval($prompt['pick_count'] ?? 3);
        $ids = $data['card_ids'] ?? [];
        if (count($ids) !== $pickCount) {
            throw new Exception("Choose exactly $pickCount cards from your hand");
        }
        $respP = &$state['players'][$responder];
        $hasLive = false;
        $names = [];
        foreach ($ids as $id) {
            $idx = findInHand($respP['hand'], $id);
            if ($idx === false) {
                throw new Exception('Invalid card');
            }
            $c = $respP['hand'][$idx];
            $names[] = cardDisplayName($c);
            if (($c['card_type'] ?? '') === 'ライブ') {
                $hasLive = true;
            }
        }
        $state = addLog($state, $state['players'][$effectOwner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] opponent revealed: ' .
            implode(', ', $names) . '.');
        if (!$hasLive) {
            $drawn = drawCardsForPlayer($state, $effectOwner, 1);
            $state = addLog($state, $state['players'][$effectOwner]['name'] .
                " — no Live revealed; drew $drawn.");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($type === 'optional_leave_mus_score_add_wr_live') {
        $ability = $prompt['ability'] ?? [];
        $group = $ability['group'] ?? "μ's";
        $sourceId = $prompt['source_id'] ?? '';
        $scoreAmt = intval($ability['score_amount'] ?? 1);

        if ($choice === 'no' || $choice === 'skip') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }

        if (($prompt['step'] ?? '') === 'confirm') {
            $candidates = listStageMemberChoices($p, $group);
            if (empty($candidates)) {
                unset($state['pending_prompt']);
                $state['seq']++;
                return addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped (no μ\'s Members on Stage).');
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_leave_mus_score_add_wr_live',
                'step'          => 'pick_member',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_id'     => $sourceId,
                'source_name'   => $prompt['source_name'] ?? 'Live',
                'ability'       => $ability,
                'candidates'    => array_map('cardPromptSummary', $candidates),
                'prompt'        => 'Choose 1 μ\'s Member to put into the Waiting Room.',
            ];
            $state['seq']++;
            return $state;
        }

        if (($prompt['step'] ?? '') === 'pick_member') {
            $pickId = $data['card_id'] ?? $choice;
            $slot = findMemberSlot($p, $pickId);
            if ($slot === null || empty($p['stage'][$slot])) {
                throw new Exception('Choose a μ\'s Member on your Stage');
            }
            $leaving = $p['stage'][$slot];
            if (($leaving['group'] ?? '') !== $group) {
                throw new Exception('Choose a μ\'s Member');
            }
            $p['stage'][$slot] = null;
            $state = resolveOnLeaveStageAbilities($state, $owner, $leaving);
            $p['waiting_room'][] = $leaving;
            if ($sourceId !== '') {
                bumpLiveCardScore($state, $owner, $sourceId, $scoreAmt);
            }
            $liveSource = findCardInState($state, $sourceId, $owner) ?? ['instance_id' => $sourceId, 'name_en' => $prompt['source_name'] ?? 'Live'];
            $cfg = wrPickCfgFromAbility(array_merge($ability, ['filter' => 'live', 'group' => $group]));
            $count = intval($ability['count'] ?? 1);
            $added = addFromWaitingRoomWithChoice(
                $state,
                $owner,
                $liveSource,
                $ability,
                ['phase' => $state['phase'] ?? ''],
                $cfg,
                $count
            );
            unset($state['pending_prompt']);
            $state['seq']++;
            if ($added === null) {
                return addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . "] score +$scoreAmt; choose a Live from Waiting Room.");
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] score +$scoreAmt; added $added Live from Waiting Room.");
            return finishPromptEffects($state);
        }
    }

    return null;
}
