<?php
/**
 * Sunshine (Aqours) bp5 effect handlers.
 * Included by effects.php.
 */

function sBp5EffectTypes(): array {
    return [
        'if_baton_from_no_ability_draw',
        'aura_hand_cost_reduction_no_ability',
        'live_start_center_equal_sides_wait_opp_blade',
        'on_enter_discard_bladeless_wr_live_match',
        'on_enter_aqours_blade_or_saint_snow_position',
        'live_start_discard_heart_non_aqours_entered',
        'live_success_look_reveal_member_hearts',
        'live_score_if_opp_surplus_hearts',
        'optional_pay_energy_add_wr_subunit_blade',
        'on_enter_set_opp_live_penalty',
        'live_start_heart_if_success_zone_color_total',
        'live_start_heart_if_live_zone_color_total',
        'draw_and_deck_bottom',
        'live_start_blade_if_dominates_opp_cost',
        'live_success_pick_yell_members_if_success_zones',
        'live_success_lose_all_surplus_score',
        'live_start_blade_moved_members',
        'live_success_score_if_more_yell_lives',
        'live_start_shuffle_wr_lives_deck_top',
        'activated_position_change_subunit_area',
        'auto_on_area_move_wait_opp_blade',
        'auto_on_area_move_activate_energy',
    ];
}

function sBp5IsEffectType(string $type): bool {
    return in_array($type, sBp5EffectTypes(), true);
}

function sBp5CountStageHeartColor(array $p, string $color): int {
    $total = 0;
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        foreach ($mbr['hearts'] ?? [] as $h) {
            if (($h['color'] ?? '') === $color) {
                $total += intval($h['count'] ?? 1);
            }
        }
        foreach ($mbr['bonus_hearts'] ?? [] as $c) {
            if ($c === $color) $total++;
        }
    }
    return $total;
}

function sBp5CountLiveZoneHeartColor(array $p, string $color): int {
    $total = 0;
    foreach ($p['live_zone'] ?? [] as $c) {
        foreach ($c['required_hearts'] ?? $c['hearts'] ?? [] as $h) {
            if (($h['color'] ?? '') === $color) {
                $total += intval($h['count'] ?? 1);
            }
        }
    }
    return $total;
}

function sBp5CountSuccessZoneHeartColor(array $p, string $color): int {
    $total = 0;
    foreach ($p['success_lives'] ?? [] as $c) {
        foreach ($c['required_hearts'] ?? $c['hearts'] ?? [] as $h) {
            if (($h['color'] ?? '') === $color) {
                $total += intval($h['count'] ?? 1);
            }
        }
    }
    return $total;
}

function sBp5MemberPrintedBlade(array $member): int {
    return intval($member['blade'] ?? 0);
}

function sBp5StageDominatesOppCost(array $state, string $pid): bool {
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    $oppCosts = [];
    foreach ($state['players'][$opp]['stage'] as $mbr) {
        if (!$mbr) continue;
        $oppCosts[] = getEffectiveStageMemberCost($state, $opp, $mbr);
    }
    if (empty($oppCosts)) return false;
    $maxOpp = max($oppCosts);
    foreach ($state['players'][$pid]['stage'] as $mbr) {
        if (!$mbr) continue;
        if (getEffectiveStageMemberCost($state, $pid, $mbr) > $maxOpp) {
            return true;
        }
    }
    return false;
}

function sBp5StageHasGroupAndSubunit(array $p, string $group, string $subunit, int $minCombinedCost): bool {
    $hasGroup = false;
    $hasSub = false;
    $combined = 0;
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        $cost = intval($mbr['cost'] ?? 0);
        if (($mbr['group'] ?? '') === $group) {
            $hasGroup = true;
            $combined += $cost;
        }
        if (cardMatchesSubunit($mbr, $subunit)) {
            $hasSub = true;
            $combined += $cost;
        }
    }
    return $hasGroup && $hasSub && $combined >= $minCombinedCost;
}

function sBp5SlotHasGroupOrSubunit(array $p, string $slot, array $ab): bool {
    $mbr = $p['stage'][$slot] ?? null;
    if (!$mbr) return false;
    $group = $ab['group'] ?? 'Sunshine';
    if ($group !== '' && ($mbr['group'] ?? '') === $group) return true;
    foreach ($ab['subunits'] ?? [] as $sub) {
        if (cardMatchesSubunit($mbr, $sub)) return true;
    }
    return false;
}

function sBp5PositionChangeSlots(array $p, array $ab, string $excludeId): array {
    $slots = [];
    foreach (['center', 'left', 'right'] as $slot) {
        if (!sBp5SlotHasGroupOrSubunit($p, $slot, $ab)) continue;
        $mbr = $p['stage'][$slot];
        if (($mbr['instance_id'] ?? '') === $excludeId) continue;
        $slots[] = $slot;
    }
    return $slots;
}

function sBp5MoveMemberToSlot(array &$state, string $pid, string $fromSlot, string $toSlot): array {
    $p = &$state['players'][$pid];
    $member = $p['stage'][$fromSlot] ?? null;
    if (!$member) return $state;
    $other = $p['stage'][$toSlot] ?? null;
    $p['stage'][$toSlot] = $member;
    $p['stage'][$fromSlot] = $other;
    if ($other) {
        $other['moved_this_turn'] = true;
        $p['stage'][$fromSlot] = $other;
        $state = resolveAutoAreaMoveAbilities($state, $pid, $other['instance_id'] ?? '');
    }
    $member['moved_this_turn'] = true;
    $p['stage'][$toSlot] = $member;
    $state = resolveAutoAreaMoveAbilities($state, $pid, $member['instance_id'] ?? '');
    return $state;
}

function sBp5FindMemberSlot(array $p, string $instanceId): ?string {
    foreach (['center', 'left', 'right'] as $slot) {
        $mbr = $p['stage'][$slot] ?? null;
        if ($mbr && ($mbr['instance_id'] ?? '') === $instanceId) {
            return $slot;
        }
    }
    return null;
}

function sBp5PositionChangeMember(array &$state, string $pid, string $instanceId): array {
    $p = &$state['players'][$pid];
    $fromSlot = sBp5FindMemberSlot($p, $instanceId);
    if ($fromSlot === null) return $state;
    $otherSlots = array_values(array_filter(
        ['center', 'left', 'right'],
        fn($s) => $s !== $fromSlot
    ));
    foreach ($otherSlots as $toSlot) {
        $state = sBp5MoveMemberToSlot($state, $pid, $fromSlot, $toSlot);
        return $state;
    }
    return $state;
}

function sBp5ApplyContinuousLiveScore(array $state, string $pid, array $member, array $ab): int {
    if (($ab['type'] ?? '') !== 'live_score_if_opp_surplus_hearts') return 0;
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    $excess = intval($state['_live_excess_hearts'][$opp] ?? 0);
    if ($excess >= intval($ab['min_surplus'] ?? 2)) {
        return intval($ab['amount'] ?? 1);
    }
    return 0;
}

function sBp5ApplyHandCostReduction(array $state, string $pid, array $card, int $base): int {
    if (cardHasAbilities($card)) return $base;
    $p = $state['players'][$pid] ?? [];
    foreach ($p['stage'] ?? [] as $stageMbr) {
        if (!$stageMbr) continue;
        foreach ($stageMbr['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'aura_hand_cost_reduction_no_ability') {
                $base = max(0, $base - intval($ab['amount'] ?? 1));
            }
        }
    }
    return $base;
}

function sBp5ApplyOppLivePenalties(array $state, string $pid): array {
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    $penalty = 0;
    foreach ($state['players'][$opp]['stage'] as $mbr) {
        if (!$mbr) continue;
        if (!empty($mbr['sbp5_opp_live_penalty'])) {
            $penalty += intval($mbr['sbp5_opp_live_penalty']);
        }
    }
    if ($penalty <= 0) return $state;
    $p = &$state['players'][$pid];
    if (empty($p['live_zone'])) return $state;
    $target = &$p['live_zone'][0];
    if (!$target) return $state;
    $penaltyColor = 'gray';
    foreach ($state['players'][$opp]['stage'] as $mbr) {
        if (!$mbr) continue;
        if (!empty($mbr['sbp5_opp_live_penalty_color'])) {
            $penaltyColor = $mbr['sbp5_opp_live_penalty_color'];
            break;
        }
    }
    $req = $target['required_hearts'] ?? $target['hearts'] ?? [];
    $req[] = ['color' => $penaltyColor, 'count' => $penalty];
    $target['required_hearts'] = $req;
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — opponent aura: +$penalty required heart on 1 Live card.");
    return $state;
}

function sBp5ResolveAutoAreaMove(
    array $state,
    string $pid,
    array &$member,
    ?string $slot,
    int $abilityIdx,
    array $ab
): array {
    $type = $ab['type'] ?? '';
    if (!sBp5IsEffectType($type)) return $state;
    if (($ab['trigger'] ?? '') !== 'auto') return $state;

    $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
    $p = &$state['players'][$pid];

    if ($type === 'auto_on_area_move_wait_opp_blade') {
        $opp = ($pid === 'p1') ? 'p2' : 'p1';
        $maxBlade = intval($ab['max_blade'] ?? 2);
        $pickCount = intval($ab['pick_count'] ?? 0);
        $waited = 0;
        foreach ($state['players'][$opp]['stage'] as &$om) {
            if (!$om) continue;
            if (sBp5MemberPrintedBlade($om) > $maxBlade) continue;
            waitMember($om, $state);
            $waited++;
            if ($pickCount > 0 && $waited >= $pickCount) break;
        }
        unset($om);
        if ($waited > 0) {
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$mName] put $waited opponent Member(s) with ≤$maxBlade Blade into Wait (moved).");
        }
        return $state;
    }

    if ($type === 'auto_on_area_move_activate_energy') {
        if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $abilityIdx)) {
            return $state;
        }
        $activated = activateEnergyForPlayer($p, intval($ab['energy'] ?? 2));
        if (!empty($ab['once_per_turn'])) markAbilityUsed($member, $abilityIdx);
        if ($slot !== null) $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$mName] activated $activated Energy (moved).");
    }

    return $state;
}

function sBp5ResolveEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (!sBp5IsEffectType($type)) return $state;

    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'if_baton_from_no_ability_draw':
            if (empty($source['entered_via_baton']) || empty($source['baton_from_no_ability'])) break;
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (Baton from ability-less Member).");
            break;

        case 'live_start_center_equal_sides_wait_opp_blade':
            if (findMemberSlot($p, $source['instance_id'] ?? '') !== 'center') break;
            $left = $p['stage']['left'] ?? null;
            $right = $p['stage']['right'] ?? null;
            if (!$left || !$right) break;
            if (getEffectiveStageMemberCost($state, $pid, $left)
                !== getEffectiveStageMemberCost($state, $pid, $right)) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $maxBlade = intval($ab['max_blade'] ?? 3);
            $waited = 0;
            foreach ($state['players'][$opp]['stage'] as &$om) {
                if (!$om) continue;
                if (sBp5MemberPrintedBlade($om) > $maxBlade) continue;
                waitMember($om, $state);
                $waited++;
            }
            unset($om);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] equal side costs: $waited opponent Member(s) Waited.");
            break;

        case 'on_enter_discard_bladeless_wr_live_match':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'sbp5_discard_bladeless_wr_live',
                'step'          => 'discard',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'max_discard'   => intval($ab['max_discard'] ?? 2),
                'ability'       => $ab,
                'prompt'        => 'Put up to ' . intval($ab['max_discard'] ?? 2) .
                    ' Members with no Blade hearts from your hand into the Waiting Room?',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional discard bladeless Members.");
            break;

        case 'on_enter_aqours_blade_or_saint_snow_position':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'sbp5_aqours_blade_or_position',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'ability'       => $ab,
                'prompt'        => 'Choose one effect:',
                'choices'       => ['blade', 'position'],
                'choice_labels' => [
                    'Give +1 Blade until Live ends to another Aqours Member on Stage',
                    'Position-change 1 Saint Snow Member on your Stage',
                ],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] choose On Enter effect.");
            break;

        case 'live_start_discard_heart_non_aqours_entered':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'sbp5_live_start_discard_heart',
                'step'          => 'confirm',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'ability'       => $ab,
                'prompt'        => 'Put 1 card from your hand into the Waiting Room to grant a heart to non-Aqours Members that entered this turn?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Discard', 'No — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Live Start.");
            break;

        case 'live_success_look_reveal_member_hearts':
            if (!empty($state['pending_prompt'])) break;
            $look = intval($ab['look'] ?? 4);
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            $color = $ab['color'] ?? 'green';
            $minHearts = intval($ab['min_hearts'] ?? 2);
            $matches = array_values(array_filter($top, function ($c) use ($color, $minHearts) {
                if (($c['card_type'] ?? '') !== 'メンバー') return false;
                $cnt = 0;
                foreach ($c['hearts'] ?? [] as $h) {
                    if (($h['color'] ?? '') === $color) $cnt += intval($h['count'] ?? 1);
                }
                return $cnt >= $minHearts;
            }));
            if (empty($matches)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $top);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at $look cards (none matched).");
                break;
            }
            if (count($matches) === 1) {
                $pickId = $matches[0]['instance_id'] ?? '';
                $rest = array_values(array_filter($top, fn($c) => ($c['instance_id'] ?? '') !== $pickId));
                $p['hand'][] = $matches[0];
                $p['waiting_room'] = array_merge($p['waiting_room'], $rest);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added ' . cardDisplayName($matches[0]) . ' to hand.');
                break;
            }
            $state['sbp5_look_stash'] = $top;
            $state['pending_prompt'] = [
                'type'        => 'sbp5_pick_revealed_member',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'candidates'  => array_map('cardPromptSummary', $matches),
                'prompt'      => 'Reveal 1 matching Member to add to hand?',
                'ability'     => $ab,
            ];
            break;

        case 'optional_pay_energy_add_wr_subunit_blade':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'sbp5_pay_energy_wr_subunit_blade',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'ability'       => $ab,
                'prompt'        => 'Pay ' . intval($ab['cost'] ?? 1) . ' Energy: add 1 Saint Snow card from WR and gain +2 Blade?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Pay', 'No — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional On Enter (pay Energy).");
            break;

        case 'on_enter_set_opp_live_penalty':
            $color = $ab['color'] ?? 'red';
            if (sBp5CountStageHeartColor($p, $color) < intval($ab['min_stage_hearts'] ?? 5)) break;
            $slot = findMemberSlot($p, $source['instance_id'] ?? '');
            if ($slot !== null && !empty($p['stage'][$slot])) {
                $p['stage'][$slot]['sbp5_opp_live_penalty'] = intval($ab['extra_required'] ?? 1);
                $p['stage'][$slot]['sbp5_opp_live_penalty_color'] = $ab['penalty_color'] ?? 'gray';
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] opponent Live Start penalty aura active.");
            break;

        case 'live_start_heart_if_live_zone_color_total':
            $color = $ab['color'] ?? 'green';
            if (sBp5CountLiveZoneHeartColor($p, $color) < intval($ab['min_total'] ?? 4)) break;
            if (!empty($ab['hearts'])) {
                addBonusHeartsToModifier($state, $pid, $ab['hearts']);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] gained bonus heart (Live Card Zone $color hearts).");
            break;

        case 'live_start_heart_if_success_zone_color_total':
            $color = $ab['color'] ?? 'green';
            if (sBp5CountSuccessZoneHeartColor($p, $color) < intval($ab['min_total'] ?? 4)) break;
            if (!empty($ab['hearts'])) {
                addBonusHeartsToModifier($state, $pid, $ab['hearts']);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] gained bonus heart (Success Live $color hearts).");
            break;

        case 'draw_and_deck_bottom':
            $drawCount = intval($ab['draw'] ?? 1);
            $drawnCards = drawCardsForPlayerWithEffectLog($state, $pid, $name, $drawCount);
            $drawn = count($drawnCards);
            $need = intval($ab['bottom'] ?? 1);
            if ($drawCount > 0 && $drawn === 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] could not draw (deck empty).");
            } elseif ($need > 0 && count($p['hand']) >= $need) {
                if (!empty($state['pending_prompt'])) break;
                $state['pending_prompt'] = [
                    'type'          => 'sbp5_draw_deck_bottom',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_name'   => $name,
                    'bottom_count'  => $need,
                    'drawn'         => $drawn,
                    'prompt'        => "Drew $drawn — choose $need card(s) to put on the bottom of your deck.",
                    'ability'       => $ab,
                ];
            } elseif ($need > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn; could not put $need card(s) on deck bottom (not enough cards in hand).");
            }
            break;

        case 'live_start_blade_if_dominates_opp_cost':
            if (!sBp5StageDominatesOppCost($state, $pid)) break;
            $state = applyModifierEffect($state, $pid, [
                'type'   => 'blade_bonus',
                'amount' => intval($ab['amount'] ?? 2),
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] +' . intval($ab['amount'] ?? 2) . ' Blade (cost dominates opponent).');
            break;

        case 'live_success_pick_yell_members_if_success_zones':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $selfCnt = count($p['success_lives'] ?? []);
            $oppCnt = count($state['players'][$opp]['success_lives'] ?? []);
            if ($selfCnt < intval($ab['min_success_zone'] ?? 2) && $oppCnt < intval($ab['min_success_zone'] ?? 2)) break;
            $yellPool = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            $members = array_values(array_filter(
                $yellPool,
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
            ));
            if (empty($members)) break;
            $maxPick = intval($ab['max_pick'] ?? 2);
            if (count($members) <= $maxPick) {
                foreach ($members as $c) {
                    $p['hand'][] = $c;
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added ' . count($members) . ' Yell Member(s) to hand.');
                break;
            }
            $state['pending_prompt'] = [
                'type'        => 'sbp5_pick_yell_members',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'candidates'  => array_map('cardPromptSummary', $members),
                'max_pick'    => $maxPick,
                'prompt'      => "Choose up to $maxPick Yell Member(s) to add to your hand.",
                'ability'     => $ab,
            ];
            break;

        case 'live_success_lose_all_surplus_score':
            $excess = intval($ctx['excess_hearts'] ?? 0);
            if ($excess < intval($ab['min_surplus'] ?? 3)) break;
            $state['_live_excess_hearts'][$pid] = 0;
            bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['score_bonus'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] lost all surplus hearts; score +' . intval($ab['score_bonus'] ?? 1) . '.');
            break;

        case 'live_start_blade_moved_members':
            $amt = intval($ab['amount'] ?? 1);
            $group = $ab['group'] ?? '';
            $count = 0;
            foreach ($p['stage'] as $slot => &$mbr) {
                if (!$mbr || empty($mbr['moved_this_turn'])) continue;
                if ($group !== '' && ($mbr['group'] ?? '') !== $group) continue;
                $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $amt;
                $p['stage'][$slot] = $mbr;
                $count++;
            }
            unset($mbr);
            if ($count > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] +$amt Blade on $count moved Member(s) until Live ends.");
            }
            break;

        case 'live_success_score_if_more_yell_lives':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $selfLive = intval($state['_last_yell_live_count'] ?? 0);
            $oppLive = intval($state['_last_yell_live_count_' . $opp] ?? 0);
            if ($selfLive <= $oppLive) break;
            bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (more Yell Live cards).');
            break;

        case 'live_start_shuffle_wr_lives_deck_top':
            $group = 'Sunshine';
            $sub = 'Saint Snow';
            if (!sBp5StageHasGroupAndSubunit($p, $group, $sub, intval($ab['min_combined_cost'] ?? 20))) break;
            $pool = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
                    && (cardMatchesGroup($c, $group, 'live') || cardMatchesSubunit($c, $sub))
            ));
            if (empty($pool)) break;
            $state['pending_prompt'] = [
                'type'          => 'sbp5_wr_lives_deck_top',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $pool),
                'max_pick'      => intval($ab['max_pick'] ?? 4),
                'prompt'        => 'Choose up to ' . intval($ab['max_pick'] ?? 4) .
                    ' Aqours/Saint Snow Live cards from WR to put on top of your deck (in order).',
                'ability'       => $ab,
                'picked'        => [],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional WR Lives to deck top.");
            break;
    }

    return $state;
}

function sBp5ResolveActivatedAbility(
    array $state,
    string $pid,
    array &$p,
    array &$member,
    ?string $slot,
    array $ab,
    int $abilityIdx,
    array $data = []
): ?array {
    if (($ab['type'] ?? '') !== 'activated_position_change_subunit_area') {
        return null;
    }
    if ($slot === null) throw new Exception('Member not on stage');
    $cost = intval($ab['cost'] ?? 1);
    if (countActiveEnergyInZone($p) < $cost) throw new Exception('Not enough Energy');
    if (!payEnergyCost($p, $cost)) throw new Exception('Not enough Energy');
    $slots = sBp5PositionChangeSlots($p, $ab, $member['instance_id'] ?? '');
    if (empty($slots)) throw new Exception('No valid area with Aqours or Saint Snow Member');
    if (count($slots) === 1) {
        $targetSlot = $slots[0];
        $fromSlot = sBp5FindMemberSlot($p, $member['instance_id'] ?? '');
        if ($fromSlot !== null) {
            $state = sBp5MoveMemberToSlot($state, $pid, $fromSlot, $targetSlot);
        }
        if (!empty($ab['once_per_turn'])) markAbilityUsed($member, $abilityIdx);
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] position-changed to $targetSlot.");
        return $state;
    }
    $state['pending_prompt'] = [
        'type'          => 'sbp5_position_change_slot',
        'owner'         => $pid,
        'responder'     => $pid,
        'source_id'     => $member['instance_id'] ?? '',
        'source_slot'   => $slot,
        'source_name'   => $member['name_en'] ?? $member['name'] ?? 'Member',
        'ability_index' => $abilityIdx,
        'target_slots'  => $slots,
        'prompt'        => 'Choose an area with an Aqours or Saint Snow Member to move to.',
        'ability'       => $ab,
    ];
    return $state;
}

function sBp5ResolvePrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $promptType = $prompt['type'] ?? '';
    $ownerP = &$state['players'][$owner];
    $ability = $prompt['ability'] ?? [];

    if ($promptType === 'sbp5_discard_bladeless_wr_live') {
        $step = $prompt['step'] ?? 'discard';
        if ($step === 'discard') {
            $ids = $data['discard_ids'] ?? [];
            $max = intval($prompt['max_discard'] ?? 2);
            if (count($ids) < 1 || count($ids) > $max) {
                throw new Exception("Discard 1–$max bladeless Members");
            }
            foreach ($ids as $id) {
                $idx = findInHand($ownerP['hand'], $id);
                if ($idx === false) throw new Exception('Invalid discard');
                $c = $ownerP['hand'][$idx];
                if (!empty($c['blade_hearts'])) throw new Exception('Selected cards must have no Blade hearts');
            }
            discardFromHandByIds($ownerP, $ids);
            $count = count($ids);
            $added = addFromWaitingRoomFiltered(
                $ownerP,
                $ability['group'] ?? 'Sunshine',
                $ability['filter'] ?? 'live',
                $count
            );
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — added $added Live card(s) from WR (discarded $count bladeless).");
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
    }

    if ($promptType === 'sbp5_aqours_blade_or_position') {
        $srcId = $prompt['source_id'] ?? '';
        $ab = $ability;
        if ($choice === 'blade') {
            $candidates = [];
            foreach ($ownerP['stage'] as $slot => $mbr) {
                if (!$mbr || ($mbr['instance_id'] ?? '') === $srcId) continue;
                if (($mbr['group'] ?? '') !== ($ab['group'] ?? 'Sunshine')) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($candidates)) {
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            if (count($candidates) === 1) {
                $ownerP['stage'][$candidates[0]['slot']]['live_blade_bonus'] =
                    intval($ownerP['stage'][$candidates[0]['slot']]['live_blade_bonus'] ?? 0)
                    + intval($ab['blade_amount'] ?? 1);
            } else {
                $state['pending_prompt'] = [
                    'type'        => 'sbp5_pick_stage_member_blade',
                    'owner'       => $owner,
                    'responder'   => $owner,
                    'source_name' => $prompt['source_name'] ?? 'Member',
                    'candidates'  => $candidates,
                    'blade_amount'=> intval($ab['blade_amount'] ?? 1),
                    'prompt'      => 'Choose 1 Aqours Member for +Blade until Live ends.',
                ];
                $state['seq']++;
                return $state;
            }
        } elseif ($choice === 'position') {
            $candidates = [];
            foreach ($ownerP['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                if (!cardMatchesSubunit($mbr, $ab['subunit'] ?? 'Saint Snow')) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (count($candidates) === 1) {
                $state = sBp5PositionChangeMember($state, $owner, $candidates[0]['instance_id'] ?? '');
            } elseif (count($candidates) > 1) {
                $state['pending_prompt'] = [
                    'type'        => 'sbp5_pick_saint_snow_position',
                    'owner'       => $owner,
                    'responder'   => $owner,
                    'source_name' => $prompt['source_name'] ?? 'Member',
                    'candidates'  => $candidates,
                    'prompt'      => 'Choose 1 Saint Snow Member to position-change.',
                ];
                $state['seq']++;
                return $state;
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'sbp5_pick_stage_member_blade') {
        $slot = $data['slot'] ?? ($prompt['candidates'][0]['slot'] ?? null);
        if ($slot && !empty($ownerP['stage'][$slot])) {
            $amt = intval($prompt['blade_amount'] ?? 1);
            $ownerP['stage'][$slot]['live_blade_bonus'] =
                intval($ownerP['stage'][$slot]['live_blade_bonus'] ?? 0) + $amt;
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'sbp5_pick_saint_snow_position') {
        $slot = $data['slot'] ?? null;
        foreach ($prompt['candidates'] ?? [] as $c) {
            if (($c['slot'] ?? '') === $slot || ($c['instance_id'] ?? '') === ($data['card_id'] ?? '')) {
                $state = sBp5PositionChangeMember($state, $owner, $c['instance_id'] ?? '');
                break;
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'sbp5_live_start_discard_heart') {
        $step = $prompt['step'] ?? 'confirm';
        if ($step === 'confirm' && $choice === 'no') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        if ($step === 'confirm' && $choice === 'yes') {
            $state['pending_prompt'] = [
                'type'          => 'sbp5_live_start_discard_heart',
                'step'          => 'discard',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'ability'       => $ability,
                'prompt'        => 'Discard 1 card from your hand.',
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'discard') {
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== 1) throw new Exception('Discard exactly 1 card');
            discardFromHandByIds($ownerP, $ids);
            $choices = $ability['heart_choices'] ?? ['green', 'yellow', 'purple'];
            $state['pending_prompt'] = [
                'type'          => 'sbp5_live_start_discard_heart',
                'step'          => 'pick_color',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'ability'       => $ability,
                'choices'       => $choices,
                'choice_labels' => array_map(fn($c) => ucfirst($c) . ' heart', $choices),
                'prompt'        => 'Choose a heart color for non-Aqours Members that entered this turn.',
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_color') {
            $exclude = $ability['exclude_group'] ?? 'Sunshine';
            $granted = 0;
            foreach ($ownerP['stage'] as $slot => &$mbr) {
                if (!$mbr || empty($mbr['entered_this_turn'])) continue;
                if (($mbr['group'] ?? '') === $exclude) continue;
                if (!isset($mbr['bonus_hearts'])) $mbr['bonus_hearts'] = [];
                $mbr['bonus_hearts'][] = $choice;
                $ownerP['stage'][$slot] = $mbr;
                $granted++;
            }
            unset($mbr);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — granted $choice heart to $granted Member(s).");
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
    }

    if ($promptType === 'sbp5_pay_energy_wr_subunit_blade') {
        if ($choice === 'no') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $cost = intval($ability['cost'] ?? 1);
        if (countActiveEnergyInZone($ownerP) < $cost) throw new Exception('Not enough Energy');
        if (!payEnergyCost($ownerP, $cost)) throw new Exception('Not enough Energy');
        $added = addFromWaitingRoomFiltered($ownerP, '', '', 1, null, ['subunit' => $ability['subunit'] ?? 'Saint Snow']);
        if ($added < 1) throw new Exception('No matching card in Waiting Room');
        $state = applyModifierEffect($state, $owner, [
            'type'   => 'blade_bonus',
            'amount' => intval($ability['blade_amount'] ?? 2),
        ]);
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'sbp5_pick_revealed_member') {
        $cardId = $data['card_id'] ?? '';
        $top = $state['sbp5_look_stash'] ?? [];
        unset($state['sbp5_look_stash']);
        foreach ($top as $c) {
            if (($c['instance_id'] ?? '') === $cardId) {
                $ownerP['hand'][] = $c;
            } else {
                $ownerP['waiting_room'][] = $c;
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'sbp5_draw_deck_bottom') {
        $ids = $data['discard_ids'] ?? [];
        $need = intval($prompt['bottom_count'] ?? 1);
        if (count($ids) !== $need) throw new Exception("Put exactly $need card(s) on deck bottom");
        $picked = [];
        foreach ($ids as $id) {
            $idx = findInHand($ownerP['hand'], $id);
            if ($idx === false) throw new Exception('Invalid card');
            $picked[] = $ownerP['hand'][$idx];
            array_splice($ownerP['hand'], $idx, 1);
        }
        $ownerP['main_deck'] = array_merge($ownerP['main_deck'], $picked);
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'sbp5_pick_yell_members') {
        $ids = $data['card_ids'] ?? [];
        $max = intval($prompt['max_pick'] ?? 2);
        if (count($ids) < 1 || count($ids) > $max) throw new Exception("Pick 1–$max cards");
        $pool = $ownerP['_pending_yell_wr'] ?? [];
        foreach ($ids as $id) {
            foreach ($pool as $i => $c) {
                if (($c['instance_id'] ?? '') !== $id) continue;
                $ownerP['hand'][] = $c;
                unset($pool[$i]);
                break;
            }
        }
        $ownerP['_pending_yell_wr'] = array_values($pool);
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'sbp5_wr_lives_deck_top') {
        $cardId = $data['card_id'] ?? '';
        $picked = $prompt['picked'] ?? [];
        if ($cardId !== '') {
            foreach ($ownerP['waiting_room'] as $i => $c) {
                if (($c['instance_id'] ?? '') !== $cardId) continue;
                $picked[] = $c;
                array_splice($ownerP['waiting_room'], $i, 1);
                break;
            }
        }
        $max = intval($prompt['max_pick'] ?? 4);
        if ($cardId !== '' && count($picked) < $max && !empty($ownerP['waiting_room'])) {
            $state['pending_prompt'] = array_merge($prompt, [
                'picked' => $picked,
                'prompt' => 'Choose another Live (or confirm done).',
            ]);
            $state['seq']++;
            return $state;
        }
        if (!empty($picked)) {
            $ownerP['main_deck'] = array_merge($picked, $ownerP['main_deck']);
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'sbp5_position_change_slot') {
        $targetSlot = $data['target_slot'] ?? $choice;
        $fromSlot = sBp5FindMemberSlot($ownerP, $prompt['source_id'] ?? '');
        if ($fromSlot !== null && in_array($targetSlot, $prompt['target_slots'] ?? [], true)) {
            $state = sBp5MoveMemberToSlot($state, $owner, $fromSlot, $targetSlot);
        }
        $slot = $prompt['source_slot'] ?? null;
        if ($slot !== null && isset($ownerP['stage'][$slot])) {
            $member = $ownerP['stage'][$slot];
            if ($member && isset($prompt['ability_index'])) {
                markAbilityUsed($member, intval($prompt['ability_index']));
                $ownerP['stage'][$slot] = $member;
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    return null;
}
