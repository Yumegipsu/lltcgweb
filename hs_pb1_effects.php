<?php
/**
 * Hasunosora premium pb1 effect handlers.
 * Included by effects.php.
 */

function hsPb1EffectTypes(): array {
    return [
        'auto_subunit_enter_pay_activate_energy',
        'reveal_hand_named_stack_under',
        'cost_blade_per_stacked_max',
        'discard_subunit_hand_draw',
        'auto_hand_discard_blade',
        'optional_discard_mill_add_wr_subunit_live',
        'pick_number_reveal_deck_top',
        'live_start_cost_hearts_per_stacked',
        'optional_pos_change_subunit_blade',
        'blade_if_stage_exact_opp_min',
        'wait_both_stages_max_original_hearts',
        'wait_both_stages_max_original_blades',
        'opp_stage_cannot_activate',
        'auto_group_enter_blade',
        'draw_discard_if_heart_count',
        'draw_discard_if_blade_count',
        'wait_opp_if_self_cost_min',
        'both_shuffle_wr_members_deck_bottom_threshold',
        'draw_if_higher_cost_on_stage',
        'pos_change_opp_front_if_subunit_only',
        'blade_if_front_opp_higher_cost',
        'heart_if_front_opp_higher_cost',
        'lose_blade_if_solo_stage',
        'pick_other_blade_member_bonus',
        'pick_other_heart_member_bonus',
        'optional_discard_add_cb_member_hs_live',
        'draw_if_live_zone_subunit',
        'live_start_wr_group_member_count_pick_heart',
        'live_success_add_wr_member_if_hand_max',
        'reduce_gray_if_distinct_group_stage_wr',
        'live_success_optional_mill_if_subunit',
        'live_start_activate_stage_live_start_ability',
        'live_start_mp_extra_hearts_draw_reduce',
        'live_start_edel_note_dual_pick_buff',
    ];
}

function hsIsHasunosoraPb1EffectType(string $type): bool {
    return in_array($type, hsPb1EffectTypes(), true);
}

/** Count Member cards currently under a Stage Member (ignore non-Member debris). */
function hsPb1CountStackedMembers(array $member): int {
    $n = 0;
    foreach ($member['stacked_members'] ?? [] as $under) {
        if (!$under) {
            continue;
        }
        if (isMemberCard($under) || (($under['card_type'] ?? '') === 'メンバー')
            || strcasecmp((string)($under['card_type_en'] ?? ''), 'Member') === 0) {
            $n++;
        }
    }
    return $n;
}

/**
 * Max Members allowed under this host for reveal_hand_named_stack_under.
 * Prefer explicit ability max_stacked; else sibling Live Start cost/hearts max.
 */
function hsPb1StackUnderMax(array $member, array $ab = []): int {
    if (isset($ab['max_stacked']) && intval($ab['max_stacked']) > 0) {
        return intval($ab['max_stacked']);
    }
    foreach ($member['abilities'] ?? [] as $sibling) {
        if (($sibling['type'] ?? '') === 'live_start_cost_hearts_per_stacked'
            && intval($sibling['max_stacked'] ?? 0) > 0) {
            return intval($sibling['max_stacked']);
        }
    }
    return 0;
}

function hsPb1OpponentStageBlockedFromActivate(array $state, string $pid): bool {
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    foreach ($state['players'][$opp]['stage'] ?? [] as $mbr) {
        if (!$mbr) continue;
        foreach ($mbr['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') === 'continuous'
                && ($ab['type'] ?? '') === 'opp_stage_cannot_activate') {
                return true;
            }
        }
    }
    return false;
}

function hsPb1ApplyContinuousBlade(array $member, array $state, string $pid, string $slot, int $blade): int {
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') continue;
        $type = $ab['type'] ?? '';
        if ($type === 'cost_blade_per_stacked_max' && empty($ab['heart_color'])) {
            $stacked = min(
                count($member['stacked_members'] ?? []),
                intval($ab['max_stacked'] ?? 3)
            );
            $blade += $stacked * intval($ab['blade_per'] ?? 0);
        }
        if ($type === 'blade_if_stage_exact_opp_min') {
            $p = $state['players'][$pid];
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (countStageMembers($p) === intval($ab['exact_stage'] ?? 2)
                && countStageMembers($state['players'][$opp]) >= intval($ab['min_opp_stage'] ?? 3)) {
                $blade += intval($ab['amount'] ?? 1);
            }
        }
        if ($type === 'purple_heart_if_stage_exact_opp_min') {
            // Hearts applied during Yell resolution (api.php), not blade.
        }
        if ($type === 'blade_if_front_opp_higher_cost' && empty($ab['hearts'])) {
            $frontSlot = ($slot === 'left') ? 'right' : (($slot === 'right') ? 'left' : 'center');
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $oppM = $state['players'][$opp]['stage'][$frontSlot] ?? null;
            if ($oppM && getEffectiveStageMemberCost($state, $opp, $oppM)
                > getEffectiveStageMemberCost($state, $pid, $member)) {
                $blade += intval($ab['amount'] ?? 1);
            }
        }
        if ($type === 'heart_if_front_opp_higher_cost') {
            // Hearts applied during Yell resolution (api.php), not blade.
        }
        if ($type === 'cost_blade_per_stacked_max' && !empty($ab['heart_color'])) {
            // Hearts applied during Yell resolution (api.php), not blade.
        }
        if ($type === 'lose_blade_if_solo_stage') {
            if (countStageMembers($state['players'][$pid]) <= 1) {
                $blade -= intval($ab['amount'] ?? 1);
            }
        }
    }
    return $blade;
}

function hsPb1EffectiveMemberCost(array $member, array $state, string $pid): int {
    $cost = getEffectiveStageMemberCost($state, $pid, $member);
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') === 'continuous'
            && ($ab['type'] ?? '') === 'cost_blade_per_stacked_max') {
            $stacked = min(
                count($member['stacked_members'] ?? []),
                intval($ab['max_stacked'] ?? 3)
            );
            $cost += $stacked * intval($ab['cost_plus_per'] ?? 4);
        }
    }
    return $cost;
}

function hsPb1WaitBothStagesMaxOriginalHearts(array &$state, int $maxHearts): int {
    $waited = 0;
    foreach (['p1', 'p2'] as $pid) {
        foreach ($state['players'][$pid]['stage'] as &$mbr) {
            if (!$mbr) continue;
            if (memberHeartCount($mbr) > $maxHearts) continue;
            waitMember($mbr, $state);
            $waited++;
        }
        unset($mbr);
    }
    return $waited;
}

function hsPb1WaitBothStagesMaxOriginalBlades(array &$state, int $maxBlades): int {
    $waited = 0;
    foreach (['p1', 'p2'] as $pid) {
        foreach ($state['players'][$pid]['stage'] as &$mbr) {
            if (!$mbr) continue;
            if (memberBladeIconCount($mbr) > $maxBlades) continue;
            waitMember($mbr, $state);
            $waited++;
        }
        unset($mbr);
    }
    return $waited;
}

function hsPb1MemberEffectiveBladeCount(array $member): int {
    return intval($member['blade'] ?? 0) + intval($member['live_blade_bonus'] ?? 0);
}

function hsPb1WrCandidates(array $player, string $filter, string $group = '', string $subunit = ''): array {
    return array_values(array_filter(
        $player['waiting_room'] ?? [],
        static function (array $card) use ($filter, $group, $subunit): bool {
            if ($filter === 'live' && !isLiveTypeCard($card)) return false;
            if ($filter === 'member' && !isMemberCard($card)) return false;
            if ($group !== '' && ($card['group'] ?? '') !== $group) return false;
            if ($subunit !== '' && !cardMatchesSubunit($card, $subunit)) return false;
            return true;
        }
    ));
}

function hsPb1MoveChosenWrCardToHand(array &$player, array $prompt, array $data): array {
    $cardId = $data['card_id'] ?? '';
    $candidateIds = array_column($prompt['candidates'] ?? [], 'instance_id');
    if ($cardId === '' || !in_array($cardId, $candidateIds, true)) {
        throw new Exception('Choose a valid Waiting Room card');
    }
    foreach ($player['waiting_room'] as $index => $card) {
        if (($card['instance_id'] ?? '') !== $cardId) continue;
        array_splice($player['waiting_room'], $index, 1);
        $player['hand'][] = $card;
        return $card;
    }
    throw new Exception('Chosen card is no longer in the Waiting Room');
}

function hsPb1StageExactOppMinMet(array $state, string $pid, array $ab): bool {
    $p = $state['players'][$pid];
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    return countStageMembers($p) === intval($ab['exact_stage'] ?? 2)
        && countStageMembers($state['players'][$opp]) >= intval($ab['min_opp_stage'] ?? 3);
}

function hsPb1ApplyContinuousPurpleHeart(array $member, array $state, string $pid): array {
    $hearts = [];
    $slot = findMemberSlot($state['players'][$pid], $member['instance_id'] ?? '');
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') continue;
        $type = $ab['type'] ?? '';
        if ($type === 'purple_heart_if_stage_exact_opp_min') {
            if (!hsPb1StageExactOppMinMet($state, $pid, $ab)) continue;
            for ($i = 0; $i < intval($ab['count'] ?? 1); $i++) {
                $hearts[] = 'purple';
            }
        }
        if ($type === 'heart_if_front_opp_higher_cost') {
            $frontSlot = ($slot === 'left') ? 'right' : (($slot === 'right') ? 'left' : 'center');
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $oppM = $state['players'][$opp]['stage'][$frontSlot] ?? null;
            if (!$oppM || getEffectiveStageMemberCost($state, $opp, $oppM)
                <= getEffectiveStageMemberCost($state, $pid, $member)) {
                continue;
            }
            foreach ($ab['hearts'] ?? [] as $h) {
                $color = $h['color'] ?? 'pink';
                for ($i = 0; $i < intval($h['count'] ?? 1); $i++) {
                    $hearts[] = $color;
                }
            }
        }
        if ($type === 'heart_bonus_if_named_on_stage') {
            if (!stageHasNamedMember($state['players'][$pid], $ab['names'] ?? [])) continue;
            foreach ($ab['hearts'] ?? [] as $h) {
                $color = $h['color'] ?? 'pink';
                for ($i = 0; $i < intval($h['count'] ?? 1); $i++) {
                    $hearts[] = $color;
                }
            }
        }
        if ($type === 'cost_blade_per_stacked_max' && !empty($ab['heart_color'])) {
            $stacked = min(
                count($member['stacked_members'] ?? []),
                intval($ab['max_stacked'] ?? 3)
            );
            $color = $ab['heart_color'] ?? 'blue';
            for ($i = 0; $i < $stacked * intval($ab['heart_per'] ?? 1); $i++) {
                $hearts[] = $color;
            }
        }
        if ($type === 'wild_heart_blade_if_distinct_costs') {
            if (countDistinctCostsOnStage($state['players'][$pid])
                < intval($ab['min_count'] ?? 3)) {
                continue;
            }
            foreach ($ab['hearts'] ?? [] as $h) {
                $color = $h['color'] ?? 'any';
                if ($color === 'any') continue;
                for ($i = 0; $i < intval($h['count'] ?? 1); $i++) {
                    $hearts[] = $color;
                }
            }
        }
    }
    return $hearts;
}

function hsPb1ExtendAutoOnOtherMemberEnter(array $state, string $pid, array $entered): array {
    $p = &$state['players'][$pid];
    foreach ($p['stage'] as $slot => &$member) {
        if (!$member) continue;
        $isSelf = ($member['instance_id'] ?? '') === ($entered['instance_id'] ?? '');
        foreach ($member['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') continue;
            $type = $ab['type'] ?? '';
            if ($type === 'auto_subunit_enter_pay_activate_energy') {
                // Pay-to-activate Auto watches other Members enter, not self.
                if ($isSelf) continue;
                $sub = $ab['subunit'] ?? '';
                if ($sub !== '' && !cardMatchesSubunit($entered, $sub)) continue;
                if (!empty($ab['max_uses_per_turn'])) {
                    $used = intval($member['_auto_uses_' . $idx] ?? 0);
                    if ($used >= intval($ab['max_uses_per_turn'])) continue;
                }
                if (!empty($state['pending_prompt'])) break 2;
                $state['pending_prompt'] = [
                    'type'          => 'auto_subunit_enter_pay_activate_energy',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_id'     => $member['instance_id'] ?? '',
                    'source_name'   => $member['name_en'] ?? $member['name'] ?? 'Member',
                    'ability_index' => $idx,
                    'entered_name'  => $entered['name_en'] ?? $entered['name'] ?? 'Member',
                    'ability'       => $ab,
                    'choices'       => ['yes', 'no'],
                    'choice_labels' => [
                        'Yes — Pay ' . intval($ab['energy_cost'] ?? 1) . ' Energy',
                        'No — Skip',
                    ],
                ];
                break 2;
            }
            if ($type === 'auto_group_enter_blade') {
                // FAQ Q245: also fires when this Member herself enters Center.
                $group = $ab['group'] ?? '';
                if ($group !== '' && ($entered['group'] ?? '') !== $group) continue;
                if (!empty($ab['center_only'])) {
                    $srcSlot = findMemberSlot($p, $member['instance_id'] ?? '');
                    if ($srcSlot !== 'center') continue;
                }
                if (!empty($ab['max_uses_per_turn'])) {
                    $used = intval($member['_auto_uses_' . $idx] ?? 0);
                    if ($used >= intval($ab['max_uses_per_turn'])) continue;
                }
                $amt = intval($ab['amount'] ?? 1);
                $srcSlot = findMemberSlot($state['players'][$pid], $member['instance_id'] ?? '');
                if ($srcSlot !== '') {
                    $state['players'][$pid]['stage'][$srcSlot]['live_blade_bonus'] =
                        intval($state['players'][$pid]['stage'][$srcSlot]['live_blade_bonus'] ?? 0) + $amt;
                }
                if (!empty($ab['max_uses_per_turn'])) {
                    if ($srcSlot !== '') {
                        $state['players'][$pid]['stage'][$srcSlot]['_auto_uses_' . $idx] =
                            intval($state['players'][$pid]['stage'][$srcSlot]['_auto_uses_' . $idx] ?? 0) + 1;
                    }
                }
                $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$mName] gained +$amt Blade (" .
                    ($entered['name_en'] ?? $entered['name'] ?? 'Member') . ' entered).');
            }
        }
    }
    unset($member);
    return $state;
}

function hsResolveHasunosoraPb1Effect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (!hsIsHasunosoraPb1EffectType($type)) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'reveal_hand_named_stack_under':
            if (!empty($state['pending_prompt'])) break;
            $abilityIdx = null;
            foreach ($source['abilities'] ?? [] as $idx => $ability) {
                if (($ability['type'] ?? '') === 'reveal_hand_named_stack_under') {
                    $abilityIdx = $idx;
                    break;
                }
            }
            if (!empty($ab['once_per_turn']) && $abilityIdx !== null && isAbilityUsed($source, $abilityIdx)) {
                break;
            }
            // Fantasy Sayaka (pb1-002): skill text max applies to cards under her.
            $maxStacked = hsPb1StackUnderMax($source, $ab);
            if ($maxStacked > 0 && hsPb1CountStackedMembers($source) >= $maxStacked) {
                throw new Exception("This Member already has the maximum of $maxStacked card(s) stacked underneath");
            }
            $names = $ab['names'] ?? [];
            $candidates = array_values(array_filter(
                $p['hand'] ?? [],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー' && memberMatchesNames($c, $names)
            ));
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'          => 'reveal_hand_named_stack_under',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_slot'   => $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? ''),
                'source_name'   => $name,
                'ability_idx'   => $abilityIdx,
                'once_per_turn' => !empty($ab['once_per_turn']),
                'ability'       => $ab,
                'max_stacked'   => $maxStacked,
                'candidates'    => array_map('cardPromptSummary', $candidates),
                'prompt'        => 'Reveal 1 matching Member from your hand to stack under this Member?',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] reveal hand to stack.");
            break;

        case 'discard_subunit_hand_draw':
            if (!empty($state['pending_prompt'])) break;
            $sub = $ab['subunit'] ?? '';
            $candidates = array_values(array_filter(
                $p['hand'] ?? [],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー' && cardMatchesSubunit($c, $sub)
            ));
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'        => 'discard_subunit_hand_draw',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'subunit'     => $sub,
                'candidates'  => array_map('cardPromptSummary', $candidates),
                'prompt'      => "Put any number of $sub Member cards from your hand into the Waiting Room, then draw that many +1?",
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] discard $sub from hand (choose).");
            break;

        case 'auto_hand_discard_blade':
            break;

        case 'optional_discard_mill_add_wr_subunit_live':
            $energyCost = intval($ab['energy_cost'] ?? 0);
            if ($energyCost > 0 && !payEnergyCost($p, $energyCost)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] could not pay $energyCost Energy; On Enter effect skipped.");
                break;
            }
            if ($energyCost > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] paid $energyCost Energy.");
            }
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_mill_add_wr_subunit_live',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'ability'       => $ab,
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'prompt'        => 'Put 1 card from hand into WR, mill ' . intval($ab['mill'] ?? 3) .
                    ' from deck, then add 1 subunit Live from WR?',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] optional mill + WR Live.");
            break;

        case 'live_start_cost_hearts_per_stacked':
            // PL!HS-pb1-002: Live Start (not Always) — lock cost/hearts from stacks this Live (#79).
            $stacked = min(
                hsPb1CountStackedMembers($source),
                intval($ab['max_stacked'] ?? 3)
            );
            if ($stacked <= 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Live Start: no Members stacked underneath.");
                break;
            }
            $costPlus = $stacked * intval($ab['cost_plus_per'] ?? 4);
            $slot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
            $heartColor = $ab['heart_color'] ?? 'blue';
            $heartN = $stacked * intval($ab['heart_per'] ?? 1);
            if ($slot !== null && $slot !== '' && !empty($p['stage'][$slot])) {
                $p['stage'][$slot]['live_cost_bonus'] =
                    intval($p['stage'][$slot]['live_cost_bonus'] ?? 0) + $costPlus;
                // Member-scoped hearts (not Blade): show on this Member and count for Live.
                if ($heartN > 0) {
                    addBonusHeartsToMember($p['stage'][$slot], [['color' => $heartColor, 'count' => $heartN]]);
                }
            } elseif ($heartN > 0) {
                addBonusHeartsToModifier($state, $pid, [['color' => $heartColor, 'count' => $heartN]]);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Live Start: +$costPlus cost and +$heartN $heartColor ♡ from $stacked stacked Member(s).");
            break;

        case 'pick_number_reveal_deck_top':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'pick_number_reveal_deck_top',
                'step'          => 'pick_number',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                // Comprehensive rules: any integer ≥ 0 (wiki). UI offers 0–30 + custom.
                'numbers'       => range(0, 30),
                'min_number'    => 0,
                'max_number'    => 99,
                'allow_custom'  => true,
                'ability'       => $ab,
                'prompt'        => 'Choose a number (0 or higher), then reveal your deck top. Member cost ≥ that number → hand; cost ≤ that number → +' .
                    intval($ab['blade_amount'] ?? 2) . ' Blade until Live ends (both if equal).',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] pick number + reveal deck top.");
            break;

        case 'optional_pos_change_subunit_blade':
            if (!empty($state['pending_prompt'])) break;
            $sub = $ab['subunit'] ?? '';
            $slots = [];
            foreach ($p['stage'] as $s => $mbr) {
                if (!$mbr || ($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) continue;
                if (cardMatchesSubunit($mbr, $sub)) {
                    $slots[] = $s;
                }
            }
            if (empty($slots)) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_pos_change_subunit_blade',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_slot'   => $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? ''),
                'source_name'   => $name,
                'target_slots'  => $slots,
                'blade'         => intval($ab['amount'] ?? 1),
                'ability'       => $ab,
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Position Change', 'No — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] optional Position Change.");
            break;

        case 'wait_both_stages_max_original_hearts':
            $n = hsPb1WaitBothStagesMaxOriginalHearts($state, intval($ab['max_hearts'] ?? 3));
            if ($n > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put $n Member(s) with ≤" . intval($ab['max_hearts'] ?? 3) . ' hearts into Wait.');
            }
            break;

        case 'wait_both_stages_max_original_blades':
            $n = hsPb1WaitBothStagesMaxOriginalBlades($state, intval($ab['max_blades'] ?? 3));
            if ($n > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put $n Member(s) with ≤" . intval($ab['max_blades'] ?? 3) . ' Blades into Wait.');
            }
            break;

        case 'draw_discard_if_blade_count':
            $srcSlot = findMemberSlot($state['players'][$pid], $source['instance_id'] ?? '');
            $bladeCount = getMemberBlade($source, $state, $pid, $srcSlot);
            if ($bladeCount >= intval($ab['min_blades'] ?? 8)) {
                $drawCount = intval($ab['draw'] ?? 2);
                $drawnCards = drawCardsForPlayerWithEffectLog($state, $pid, $name, $drawCount);
                $drawn = count($drawnCards);
                $discardNeed = intval($ab['discard'] ?? 0);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Live Start ($bladeCount Blades): drew $drawn card(s).");
                if ($drawCount > 0 && $drawn === 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] could not draw (deck empty).");
                } elseif ($discardNeed > 0 && !empty($state['pending_prompt'])) {
                    break;
                } elseif ($discardNeed > 0 && count($p['hand']) >= $discardNeed) {
                    $state['pending_prompt'] = [
                        'type'          => 'mandatory_discard_after_draw',
                        'owner'         => $pid,
                        'responder'     => $pid,
                        'source_name'   => $name,
                        'discard_count' => $discardNeed,
                        'live_start'    => ($ctx['phase'] ?? '') === 'live_start',
                        'prompt'        => "Drew $drawn — put $discardNeed card(s) from hand into the Waiting Room.",
                    ];
                } elseif ($discardNeed > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] could not discard (not enough cards in hand).");
                }
            }
            break;

        case 'draw_discard_if_heart_count':
            if (memberHeartCount($source) + memberContinuousHeartCount($source, $state, $pid)
                >= intval($ab['min_hearts'] ?? 8)) {
                $drawCount = intval($ab['draw'] ?? 2);
                $drawnCards = drawCardsForPlayerWithEffectLog($state, $pid, $name, $drawCount);
                $drawn = count($drawnCards);
                $discardNeed = intval($ab['discard'] ?? 0);
                if ($drawCount > 0 && $drawn === 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] could not draw (deck empty).");
                } elseif ($discardNeed > 0 && !empty($state['pending_prompt'])) {
                    break;
                } elseif ($discardNeed > 0 && count($p['hand']) >= $discardNeed) {
                    $state['pending_prompt'] = [
                        'type'          => 'mandatory_discard_after_draw',
                        'owner'         => $pid,
                        'responder'     => $pid,
                        'source_name'   => $name,
                        'discard_count' => $discardNeed,
                        'prompt'        => "Drew $drawn — put $discardNeed card(s) from hand into the Waiting Room.",
                    ];
                }
            }
            break;

        case 'wait_opp_if_self_cost_min':
            $hasHigh = false;
            foreach ($p['stage'] as $mbr) {
                if ($mbr && intval($mbr['cost'] ?? 0) >= intval($ab['min_self_cost'] ?? 10)) {
                    $hasHigh = true;
                    break;
                }
            }
            if (!$hasHigh) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $waited = 0;
            foreach ($state['players'][$opp]['stage'] as &$mbr) {
                if (!$mbr) continue;
                if (intval($mbr['cost'] ?? 0) > intval($ab['max_opp_cost'] ?? 4)) continue;
                if ($waited >= intval($ab['pick_count'] ?? 1)) break;
                waitMember($mbr, $state);
                $waited++;
            }
            unset($mbr);
            if ($waited > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put $waited opponent Member(s) into Wait.");
            }
            break;

        case 'both_shuffle_wr_members_deck_bottom_threshold':
            $total = 0;
            foreach (['p1', 'p2'] as $pl) {
                $members = array_values(array_filter(
                    $state['players'][$pl]['waiting_room'],
                    fn($c) => ($c['card_type'] ?? '') === 'メンバー'
                ));
                if (empty($members)) continue;
                shuffle($members);
                $state['players'][$pl]['waiting_room'] = array_values(array_filter(
                    $state['players'][$pl]['waiting_room'],
                    fn($c) => ($c['card_type'] ?? '') !== 'メンバー'
                ));
                $state['players'][$pl]['main_deck'] = array_merge(
                    $state['players'][$pl]['main_deck'],
                    $members
                );
                $total += count($members);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] both players shuffled WR Members to deck bottom ($total total).");
            if ($total >= intval($ab['threshold'] ?? 20)) {
                $candidates = hsPb1WrCandidates($p, 'live');
                if (!empty($candidates)) {
                    $state['pending_prompt'] = [
                        'type'          => 'both_shuffle_wr_members_deck_bottom_threshold',
                        'step'          => 'pick_wr_live',
                        'owner'         => $pid,
                        'responder'     => $pid,
                        'source_id'     => $source['instance_id'] ?? '',
                        'source_name'   => $name,
                        'ability'       => $ab,
                        'candidates'    => array_map('cardPromptSummary', $candidates),
                        'prompt'        => 'Choose 1 Live card from your Waiting Room to add to your hand.',
                    ];
                }
            }
            break;

        case 'draw_if_higher_cost_on_stage':
            $srcCost = intval($source['cost'] ?? 0);
            foreach ($p['stage'] as $mbr) {
                if (!$mbr || ($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) continue;
                if (intval($mbr['cost'] ?? 0) > $srcCost) {
                    $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] drew $drawn (higher-cost Member on Stage).");
                    break;
                }
            }
            break;

        case 'pos_change_opp_front_if_subunit_only':
            // Official: if every Stage Member is Mira-Cra Park!, Position Change
            // 1 opponent Stage Member into the area in front of this Member.
            // That rearranges the OPPONENT's Stage only (swap with whoever is already
            // in the facing slot). It must never exchange ownership between players.
            // The controller chooses which opponent Member to move (excluding one
            // already in the facing slot).
            $onlySub = true;
            $sub = $ab['subunit'] ?? '';
            foreach ($p['stage'] as $mbr) {
                if (!$mbr) continue;
                if (!cardMatchesSubunit($mbr, $sub)) {
                    $onlySub = false;
                    break;
                }
            }
            if (!$onlySub || countStageMembers($p) === 0) break;
            if (!empty($state['pending_prompt'])) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $srcSlot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
            if ($srcSlot === null || $srcSlot === '') {
                $srcSlot = 'center';
            }
            $frontSlot = hsPb1FacingStageSlot((string) $srcSlot);
            $candidates = hsPb1OppStagePosChangeCandidates($state['players'][$opp] ?? [], $frontSlot);
            if (empty($candidates)) break;
            if (count($candidates) === 1) {
                $fromSlot = (string) ($candidates[0]['slot'] ?? '');
                $state = hsPb1ApplyOppFrontPositionChange(
                    $state,
                    $pid,
                    $opp,
                    $fromSlot,
                    $frontSlot,
                    $name
                );
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'pos_change_opp_front_pick',
                'owner'         => $pid,
                'responder'     => $pid,
                'opp'           => $opp,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'source_slot'   => $srcSlot,
                'front_slot'    => $frontSlot,
                'candidates'    => $candidates,
                'ability'       => $ab,
                'prompt'        => 'Choose 1 opponent Stage Member to Position Change into the area in front of this Member.',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Position Change: choose an opponent Stage Member for the facing area.');
            $state['seq']++;
            break;

        case 'pick_other_blade_member_bonus':
        case 'pick_other_heart_member_bonus':
            if (!empty($state['pending_prompt'])) break;
            $candidates = [];
            $heartColor = $ab['heart_color'] ?? '';
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr || ($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) continue;
                if ($heartColor !== '') {
                    if (!memberHasHeartColor($mbr, $heartColor)) continue;
                } elseif (memberBladeIconCount($mbr) <= 0) {
                    continue;
                }
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($candidates)) break;
            $isHeart = ($ab['type'] ?? '') === 'pick_other_heart_member_bonus';
            $state['pending_prompt'] = [
                'type'        => $ab['type'] ?? 'pick_other_blade_member_bonus',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'candidates'  => $candidates,
                'blade'       => intval($ab['amount'] ?? 1),
                'hearts'      => $ab['hearts'] ?? [],
                'prompt'      => $isHeart
                    ? 'Choose 1 other Member with a ' . ucfirst($heartColor) . ' Heart to gain a bonus heart.'
                    : 'Choose 1 other Member with Blade to gain +' . intval($ab['amount'] ?? 1) . ' Blade.',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] pick Member for " . ($isHeart ? 'heart' : 'Blade') . '.');
            break;

        case 'optional_discard_add_cb_member_hs_live':
            $wrLive = count(array_filter(
                $p['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if ($wrLive < intval($ab['min_wr_live'] ?? 3)) break;
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_add_cb_member_hs_live',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'ability'       => $ab,
                'discard'       => intval($ab['discard'] ?? 2),
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] optional discard for WR picks.");
            break;

        case 'draw_if_live_zone_subunit':
            $sub = $ab['subunit'] ?? '';
            foreach ($p['live_zone'] ?? [] as $lc) {
                if ($lc && cardMatchesSubunit($lc, $sub)) {
                    $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] drew $drawn ($sub in Live zone).");
                    break;
                }
            }
            break;

        case 'live_start_wr_group_member_count_pick_heart':
            $group = $ab['group'] ?? 'Hasunosora';
            $cnt = count(array_filter(
                $p['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー' && ($c['group'] ?? '') === $group
            ));
            if ($cnt < intval($ab['min_wr_members'] ?? 10)) break;
            if (!empty($state['pending_prompt'])) break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if ($mbr && ($mbr['group'] ?? '') === $group) {
                    $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
                }
            }
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'        => 'live_start_wr_group_member_count_pick_heart',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'candidates'  => $candidates,
                'hearts'      => $ab['hearts'] ?? [['color' => 'purple', 'count' => 1]],
                'prompt'      => 'Choose 1 Hasunosora Member on Stage to grant bonus hearts.',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] pick Member for hearts.");
            break;

        case 'live_success_add_wr_member_if_hand_max':
            if (count($p['hand'] ?? []) > intval($ab['max_hand'] ?? 6)) break;
            $added = addFromWaitingRoomFiltered($p, '', $ab['filter'] ?? 'member', 1);
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added Member from Waiting Room (hand ≤" . intval($ab['max_hand'] ?? 6) . ').');
            }
            break;

        case 'reduce_gray_if_distinct_group_stage_wr':
            if (countDistinctGroupStageWr($p, $ab['group'] ?? '', 'member')
                < intval($ab['min_distinct'] ?? 6)) {
                break;
            }
            bumpLiveCardColorReduction(
                $state,
                $pid,
                $source['instance_id'] ?? '',
                'any',
                intval($ab['reduce'] ?? 2)
            );
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Required Gray Hearts -' . intval($ab['reduce'] ?? 2) . '.');
            break;

        case 'live_success_optional_mill_if_subunit':
            $sub = $ab['subunit'] ?? '';
            $has = false;
            foreach ($p['stage'] as $mbr) {
                if ($mbr && cardMatchesSubunit($mbr, $sub)) {
                    $has = true;
                    break;
                }
            }
            if (!$has) break;
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'live_success_optional_mill_if_subunit',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'count'         => intval($ab['count'] ?? 4),
                'prompt'        => 'Live Success — put the top ' . intval($ab['count'] ?? 4) .
                    ' cards of your deck into the Waiting Room?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Mill ' . intval($ab['count'] ?? 4), 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] optional mill on Live Success.");
            break;

        case 'live_start_activate_stage_live_start_ability':
            if (!empty($state['pending_prompt'])) break;
            $sub = $ab['subunit'] ?? '';
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr || !cardMatchesSubunit($mbr, $sub)) continue;
                $effCost = getEffectiveStageMemberCost($state, $pid, $mbr);
                if ($effCost < intval($ab['min_cost'] ?? 10)) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), [
                    'slot' => $slot,
                    'effective_cost' => $effCost,
                ]);
            }
            if (empty($candidates)) break;
            $minCost = intval($ab['min_cost'] ?? 10);
            $state['pending_prompt'] = [
                'type'        => 'live_start_activate_stage_live_start_ability',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'candidates'  => $candidates,
                'optional'    => true,
                'choices'     => ['skip'],
                'prompt'      => "You may choose 1 $sub Member (cost $minCost+) to activate one [Live Start] ability (or skip).",
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] optional pick Member ability.");
            break;

        case 'live_start_mp_extra_hearts_draw_reduce':
            // Defer until after optional Live Starts (Member heart buffs like Rurino
            // PL!HS-bp5-003) so "more hearts than original" sees those grants (#73).
            if (!isset($state['_deferred_mp_extra_hearts']) || !is_array($state['_deferred_mp_extra_hearts'])) {
                $state['_deferred_mp_extra_hearts'] = [];
            }
            $state['_deferred_mp_extra_hearts'][] = [
                'pid'       => $pid,
                'source_id' => (string)($source['instance_id'] ?? ''),
                'name'      => $name,
                'ability'   => $ab,
            ];
            break;

        case 'live_start_edel_note_dual_pick_buff':
            if (!empty($state['pending_prompt'])) break;
            $sub = $ab['subunit'] ?? 'Edel Note';
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if ($mbr && cardMatchesSubunit($mbr, $sub)) {
                    $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
                }
            }
            if (count($candidates) < 1) break;
            $state['pending_prompt'] = [
                'type'        => 'live_start_edel_note_dual_pick_buff',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'candidates'  => $candidates,
                'blade'       => intval($ab['blade'] ?? 2),
                'hearts'      => $ab['hearts'] ?? [['color' => 'purple', 'count' => 2]],
                'step'        => 1,
                'prompt'      => 'Choose 1 Edel Note Member for +' . intval($ab['blade'] ?? 2) . ' Blade.',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] Edel Note dual buff (step 1).");
            break;
    }

    return $state;
}

/** Stage area across from a Member (left↔right, center↔center). */
function hsPb1FacingStageSlot(string $slot): string {
    return match ($slot) {
        'left' => 'right',
        'right' => 'left',
        default => 'center',
    };
}

/** Opponent Stage Members eligible to Position Change into $frontSlot (not already there). */
function hsPb1OppStagePosChangeCandidates(array $oppP, string $frontSlot): array {
    $candidates = [];
    foreach (['left', 'center', 'right'] as $slot) {
        if ($slot === $frontSlot) {
            continue;
        }
        $mbr = $oppP['stage'][$slot] ?? null;
        if (!$mbr) {
            continue;
        }
        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
    }
    return $candidates;
}

/**
 * Position Change one opponent Stage Member into $frontSlot (swap if occupied).
 * Ownership never changes — only slots on the opponent's Stage move.
 */
function hsPb1ApplyOppFrontPositionChange(
    array $state,
    string $owner,
    string $opp,
    string $fromSlot,
    string $frontSlot,
    string $sourceName
): array {
    if ($fromSlot === '' || $frontSlot === '' || $fromSlot === $frontSlot) {
        return $state;
    }
    $oppP = &$state['players'][$opp];
    $moving = $oppP['stage'][$fromSlot] ?? null;
    if (!$moving) {
        return $state;
    }
    $dest = $oppP['stage'][$frontSlot] ?? null;
    // Position Change: swap areas; moved Members do not become Active.
    $oppP['stage'][$frontSlot] = $moving;
    $oppP['stage'][$fromSlot] = $dest;
    $movedName = $moving['name_en'] ?? $moving['name'] ?? 'Member';
    $destName = $dest
        ? ($dest['name_en'] ?? $dest['name'] ?? 'Member')
        : null;
    $msg = $state['players'][$owner]['name'] . ' — [' . $sourceName . '] Position Changed '
        . $movedName . ' into opponent ' . ucfirst($frontSlot);
    if ($destName !== null) {
        $msg .= ' (swapped with ' . $destName . ')';
    }
    $msg .= '.';
    return addLog($state, $msg);
}

function hsPb1ResolvePrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $promptType = $prompt['type'] ?? '';
    $ownerP = &$state['players'][$owner];

    if ($promptType === 'pos_change_opp_front_pick') {
        $opp = $prompt['opp'] ?? (($owner === 'p1') ? 'p2' : 'p1');
        $frontSlot = (string) ($prompt['front_slot'] ?? '');
        $fromSlot = (string) ($data['slot'] ?? $choice);
        if ($frontSlot === '' || $fromSlot === '' || $fromSlot === $frontSlot) {
            throw new Exception('Choose an opponent Stage Member to Position Change');
        }
        $allowed = [];
        foreach ($prompt['candidates'] ?? [] as $cand) {
            if (!empty($cand['slot'])) {
                $allowed[(string) $cand['slot']] = true;
            }
        }
        if (!isset($allowed[$fromSlot])) {
            throw new Exception('Choose an opponent Member that is not already in the facing area');
        }
        if (empty($state['players'][$opp]['stage'][$fromSlot])) {
            throw new Exception('Choose an opponent Stage Member');
        }
        $state = hsPb1ApplyOppFrontPositionChange(
            $state,
            $owner,
            $opp,
            $fromSlot,
            $frontSlot,
            (string) ($prompt['source_name'] ?? 'Member')
        );
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishAfterBranchChoicePrompt($state, $prompt);
    }

    // Sayaka (PL!HS-bp1-002): pay → leave Stage → play chosen Hasunosora Member from WR (Active).
    if ($promptType === 'hs_leave_play_wr_slot') {
        $slot = $prompt['source_slot'] ?? '';
        $ability = $prompt['ability'] ?? [];
        $cfg = $prompt['wr_pick_cfg'] ?? [
            'filter'   => 'member',
            'group'    => $ability['group'] ?? 'Hasunosora',
            'max_cost' => intval($ability['max_cost'] ?? 15),
        ];
        $cardId = trim((string)($data['card_id'] ?? $data['wr_card_id'] ?? $choice));
        if ($cardId === '' || in_array($cardId, ['yes', 'no'], true)) {
            throw new Exception('Choose a Member from your Waiting Room');
        }
        if ($slot === '' || empty($ownerP['stage'][$slot])) {
            throw new Exception('Member no longer on Stage');
        }
        $sourceId = (string)($prompt['source_id'] ?? '');
        $leaving = $ownerP['stage'][$slot];
        if ($sourceId !== '' && ($leaving['instance_id'] ?? '') !== $sourceId) {
            throw new Exception('Source Member no longer on Stage');
        }
        $played = null;
        foreach ($ownerP['waiting_room'] as $i => $c) {
            if (($c['instance_id'] ?? '') !== $cardId) {
                continue;
            }
            if (!cardMatchesWrPick($c, $cfg)) {
                throw new Exception('Invalid Waiting Room card');
            }
            $played = $c;
            array_splice($ownerP['waiting_room'], $i, 1);
            break;
        }
        if (!$played) {
            throw new Exception('Invalid Waiting Room card');
        }
        // Energy was prepaid on activate for Sayaka.
        if (empty($prompt['cost_prepaid'])) {
            $cost = intval($ability['cost'] ?? 2);
            if ($cost > 0 && !payEnergyCost($ownerP, $cost)) {
                throw new Exception("Need $cost active Energy");
            }
        }
        $ownerP['stage'][$slot] = null;
        $ownerP['waiting_room'][] = $leaving;
        $state = resolveOnLeaveStageAbilities($state, $owner, $leaving);
        // Rebind: $state reassignment detaches prior &$ownerP.
        $ownerP = &$state['players'][$owner];
        mergeCardCatalogFields($played);
        clearMemberWait($played);
        $played['entered_turn'] = intval($state['turn'] ?? 1);
        $ownerP['stage'][$slot] = $played;
        unset($state['pending_prompt']);
        // Stage play counted inside resolveOnEnterAbilities → notifyMemberEnteredStage.
        $state = resolveOnEnterAbilities($state, $owner, $played, $slot);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($leaving['name_en'] ?? $leaving['name'] ?? 'Member') . '] left Stage; played ' .
            cardDisplayName($played) . ' from Waiting Room.');
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'auto_subunit_enter_pay_activate_energy') {
        if ($choice === 'yes') {
            $ab = $prompt['ability'] ?? [];
            $cost = intval($ab['energy_cost'] ?? 1);
            if (!payEnergyCost($ownerP, $cost)) {
                throw new Exception("Need $cost Energy");
            }
            $activated = activateEnergyForPlayer($ownerP, intval($ab['activate_count'] ?? 2));
            $slot = findMemberSlot($ownerP, $prompt['source_id'] ?? '');
            if ($slot !== null && isset($ownerP['stage'][$slot])) {
                $idx = intval($prompt['ability_index'] ?? 0);
                $ownerP['stage'][$slot]['_auto_uses_' . $idx] =
                    intval($ownerP['stage'][$slot]['_auto_uses_' . $idx] ?? 0) + 1;
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . "] activated $activated Energy.");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'reveal_hand_named_stack_under') {
        $handId = $data['card_id'] ?? $choice;
        $stacked = null;
        foreach ($ownerP['hand'] as $i => $c) {
            if (($c['instance_id'] ?? '') === $handId) {
                $stacked = $c;
                array_splice($ownerP['hand'], $i, 1);
                break;
            }
        }
        if (!$stacked) throw new Exception('Choose a card from your hand');
        $slot = (string)($prompt['source_slot'] ?? '');
        if ($slot === '' || empty($ownerP['stage'][$slot])) {
            $slot = findMemberSlot($ownerP, (string)($prompt['source_id'] ?? ''));
        }
        if ($slot === '' || empty($ownerP['stage'][$slot])) {
            // Never delete the revealed card if the Stage host is missing (#76).
            $ownerP['hand'][] = $stacked;
            throw new Exception('Source Member not on Stage');
        }
        $host = $ownerP['stage'][$slot];
        $maxStacked = intval($prompt['max_stacked'] ?? hsPb1StackUnderMax($host, $prompt['ability'] ?? []));
        if ($maxStacked > 0 && hsPb1CountStackedMembers($host) >= $maxStacked) {
            $ownerP['hand'][] = $stacked;
            throw new Exception("This Member already has the maximum of $maxStacked card(s) stacked underneath");
        }
        if (!isset($ownerP['stage'][$slot]['stacked_members'])) {
            $ownerP['stage'][$slot]['stacked_members'] = [];
        }
        $ownerP['stage'][$slot]['stacked_members'][] = $stacked;
        $state = queuePublicSkillReveal($state, $owner, [$stacked], $prompt['source_name'] ?? 'Member', 'hand');
        if (!empty($prompt['once_per_turn'])) {
            $abilities = $ownerP['stage'][$slot]['abilities'] ?? [];
            $idx = $prompt['ability_idx'] ?? null;
            if ($idx !== null && isset($abilities[$idx])) {
                markAbilityUsed($ownerP['stage'][$slot], $idx);
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — stacked ' . ($stacked['name_en'] ?? $stacked['name']) . ' under Member.');
        return $state;
    }

    if ($promptType === 'discard_subunit_hand_draw') {
        $ids = $data['discard_ids'] ?? [];
        $n = discardFromHandByIds($ownerP, $ids, $state, $owner);
        // "Any number" includes 0 — still draw 0+1. Auto blade/♡ comes from hsPb1NotifyHandDiscard
        // when n>0 (do not hardcode blade_bonus here — that skipped pink hearts, issue #67).
        drawCardsForPlayer($state, $owner, $n + 1);
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = addLog($state, $state['players'][$owner]['name'] .
            " — discarded $n subunit card(s); drew " . ($n + 1) . '.');
        return $state;
    }

    if ($promptType === 'optional_discard_mill_add_wr_subunit_live') {
        if (($prompt['step'] ?? '') === 'pick_wr_live') {
            $picked = hsPb1MoveChosenWrCardToHand($ownerP, $prompt, $data);
            $state = addLog($state, $state['players'][$owner]['name'] . ' — [' .
                ($prompt['source_name'] ?? 'Member') . '] added ' .
                ($picked['name_en'] ?? $picked['name'] ?? 'Live') . ' from the Waiting Room.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }
        if ($choice === 'yes') {
            $need = intval($prompt['ability']['discard'] ?? 1);
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== $need) throw new Exception("Discard exactly $need card(s)");
            discardFromHandByIds($ownerP, $ids, $state, $owner);
            $mill = intval($prompt['ability']['mill'] ?? 3);
            $milled = array_splice($ownerP['main_deck'], 0, min($mill, count($ownerP['main_deck'])));
            $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $milled);
            $candidates = hsPb1WrCandidates(
                $ownerP,
                'live',
                '',
                $prompt['ability']['subunit'] ?? ''
            );
            if (!empty($candidates)) {
                $state['pending_prompt'] = array_merge($prompt, [
                    'step'       => 'pick_wr_live',
                    'choices'    => [],
                    'candidates' => array_map('cardPromptSummary', $candidates),
                    'prompt'     => 'Choose 1 matching Live card from your Waiting Room to add to your hand.',
                ]);
                $state['seq']++;
                return $state;
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_number_reveal_deck_top') {
        $step = $prompt['step'] ?? 'pick_number';
        if ($step === 'pick_number') {
            $num = intval($data['number'] ?? $choice);
            if ($num < 0 || (isset($prompt['max_number']) && $num > intval($prompt['max_number']))) {
                throw new Exception('Choose a valid number');
            }
            if (empty($ownerP['main_deck'])) {
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            // Reveal without committing disposition yet — player sees the card (#79).
            $top = $ownerP['main_deck'][0];
            $state = queuePublicSkillReveal($state, $owner, [$top], $prompt['source_name'] ?? 'Member', 'deck');
            $state['pending_prompt'] = [
                'type'           => 'pick_number_reveal_deck_top',
                'step'           => 'resolve_reveal',
                'owner'          => $owner,
                'responder'      => $owner,
                'source_id'      => $prompt['source_id'] ?? '',
                'source_name'    => $prompt['source_name'] ?? 'Member',
                'chosen_number'  => $num,
                'ability'        => $prompt['ability'] ?? [],
                'revealed'       => cardPromptSummary($top),
                'prompt'         => 'Revealed deck top (chosen number: ' . $num . '). Confirm to apply the effect.',
            ];
            $state['seq']++;
            return $state;
        }

        // step === resolve_reveal
        $num = intval($prompt['chosen_number'] ?? $data['number'] ?? $choice);
        $top = array_shift($ownerP['main_deck']);
        if (!$top) {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $isMember = ($top['card_type'] ?? '') === 'メンバー';
        $cost = intval($top['cost'] ?? 0);
        $bladeAmt = intval($prompt['ability']['blade_amount'] ?? 2);
        $label = $top['name_en'] ?? $top['name'] ?? 'card';

        if (!$isMember) {
            // Non-Member: nothing happens — return to deck top.
            array_unshift($ownerP['main_deck'], $top);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — revealed $label (not a Member); nothing happens.");
        } else {
            $toHand = $cost >= $num;
            $gainBlade = $cost <= $num;
            if ($toHand) {
                $ownerP['hand'][] = $top;
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — revealed $label (cost $cost ≥ $num) to hand.");
            } else {
                // Member below threshold: return to deck top after reveal.
                array_unshift($ownerP['main_deck'], $top);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — revealed $label (cost $cost < $num); returned to deck top.");
            }
            if ($gainBlade) {
                $state = applyModifierEffect($state, $owner, ['type' => 'blade_bonus', 'amount' => $bladeAmt]);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — cost ≤ $num; +$bladeAmt Blade until Live ends.");
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        // Must resume remaining Live Starts (e.g. COMPASS after Kosuzu pb1-005).
        return finishPromptEffects($state);
    }

    if ($promptType === 'optional_pos_change_subunit_blade') {
        if ($choice === 'no') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional Position Change.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $targetSlot = $data['target_slot'] ?? $data['slot'] ?? '';
        $srcSlot = $prompt['source_slot'] ?? '';
        if ($targetSlot === '' || $srcSlot === '') throw new Exception('Choose target area');
        $src = $ownerP['stage'][$srcSlot] ?? null;
        $tgt = $ownerP['stage'][$targetSlot] ?? null;
        if (!$src || !$tgt) {
            throw new Exception('Invalid Position Change');
        }
        // Position Change: swap areas; moved Members do not become Active.
        // Must run auto area-move hooks (e.g. Hime bp5-014 blade_if_entered_or_moved).
        $state = applyStagePositionChange($state, $owner, $srcSlot, $targetSlot, $src);
        $state = applyModifierEffect($state, $owner, [
            'type'   => 'blade_bonus',
            'amount' => intval($prompt['blade'] ?? 1),
        ]);
        foreach ($prompt['ability']['hearts'] ?? [] as $hg) {
            $state = applyModifierEffect($state, $owner, [
                'type'   => 'grant_bonus_hearts',
                'hearts' => [$hg],
            ]);
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] Position Changed and gained Live bonuses.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'pick_other_blade_member_bonus' || $promptType === 'pick_other_heart_member_bonus') {
        $slot = $data['slot'] ?? '';
        if ($slot === '' || empty($ownerP['stage'][$slot])) throw new Exception('Choose a Member');
        if ($promptType === 'pick_other_heart_member_bonus') {
            addBonusHeartsToMember($ownerP['stage'][$slot], $prompt['hearts'] ?? [], 1);
        } else {
            $ownerP['stage'][$slot]['live_blade_bonus'] =
                intval($ownerP['stage'][$slot]['live_blade_bonus'] ?? 0) + intval($prompt['blade'] ?? 1);
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'both_shuffle_wr_members_deck_bottom_threshold') {
        if (($prompt['step'] ?? '') !== 'pick_wr_live') {
            throw new Exception('Invalid Waiting Room pick step');
        }
        $picked = hsPb1MoveChosenWrCardToHand($ownerP, $prompt, $data);
        $blade = intval($prompt['ability']['then_blade'] ?? 1);
        $state = applyModifierEffect($state, $owner, [
            'type'   => 'blade_bonus',
            'amount' => $blade,
        ]);
        $state = addLog($state, $state['players'][$owner]['name'] . ' — [' .
            ($prompt['source_name'] ?? 'Member') . '] added ' .
            ($picked['name_en'] ?? $picked['name'] ?? 'Live') .
            " from the Waiting Room; gained +$blade Blade.");
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_discard_add_cb_member_hs_live') {
        $step = $prompt['step'] ?? 'confirm';
        if ($step === 'pick_wr_member') {
            hsPb1MoveChosenWrCardToHand($ownerP, $prompt, $data);
            $candidates = hsPb1WrCandidates($ownerP, 'live', 'Hasunosora');
            if (!empty($candidates)) {
                $state['pending_prompt'] = array_merge($prompt, [
                    'step'       => 'pick_wr_live',
                    'choices'    => [],
                    'candidates' => array_map('cardPromptSummary', $candidates),
                    'prompt'     => 'Choose 1 Hasunosora Live card from your Waiting Room to add to your hand.',
                ]);
                $state['seq']++;
                return $state;
            }
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_wr_live') {
            hsPb1MoveChosenWrCardToHand($ownerP, $prompt, $data);
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }
        if ($choice === 'yes') {
            $need = intval($prompt['discard'] ?? 2);
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== $need) throw new Exception("Discard exactly $need cards");
            discardFromHandByIds($ownerP, $ids, $state, $owner);
            $members = hsPb1WrCandidates($ownerP, 'member', '', 'スリーズブーケ');
            $lives = hsPb1WrCandidates($ownerP, 'live', 'Hasunosora');
            if (!empty($members)) {
                $state['pending_prompt'] = array_merge($prompt, [
                    'step'       => 'pick_wr_member',
                    'choices'    => [],
                    'candidates' => array_map('cardPromptSummary', $members),
                    'prompt'     => 'Choose 1 Cerise Bouquet Member from your Waiting Room to add to your hand.',
                ]);
                $state['seq']++;
                return $state;
            }
            if (!empty($lives)) {
                $state['pending_prompt'] = array_merge($prompt, [
                    'step'       => 'pick_wr_live',
                    'choices'    => [],
                    'candidates' => array_map('cardPromptSummary', $lives),
                    'prompt'     => 'Choose 1 Hasunosora Live card from your Waiting Room to add to your hand.',
                ]);
                $state['seq']++;
                return $state;
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'live_start_wr_group_member_count_pick_heart') {
        if (in_array($choice, ['no', 'skip', 'cancel'], true)) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped Live Start heart pick.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        $slot = $data['slot'] ?? $choice;
        if ($slot === '' || empty($ownerP['stage'][$slot])) throw new Exception('Choose a Member');
        addBonusHeartsToMember($ownerP['stage'][$slot], $prompt['hearts'] ?? [], 1);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . '] granted bonus hearts until Live ends.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'live_success_optional_mill_if_subunit') {
        if ($choice === 'yes') {
            $n = intval($prompt['count'] ?? 4);
            $milled = array_splice($ownerP['main_deck'], 0, min($n, count($ownerP['main_deck'])));
            $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $milled);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] milled ' . count($milled) . ' card(s).');
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped optional mill.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        // Must resume Live Success / Performance — bare return softlocks in live_success_effects.
        return finishPromptEffects($state);
    }

    if ($promptType === 'live_start_activate_stage_live_start_ability') {
        $choice = $data['choice'] ?? $choice;
        if ($choice === 'no' || $choice === 'skip' || $choice === 'cancel') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped optional Live Start effect.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        $slot = $data['slot'] ?? $choice;
        if ($slot === '' || empty($ownerP['stage'][$slot])) throw new Exception('Choose a Member');
        // Hydrate Stage copy — runtime cards may omit abilities (#66 / #91 PR Sayaka).
        mergeCardCatalogFields($ownerP['stage'][$slot]);
        $mbr = &$ownerP['stage'][$slot];
        // Clear COMPASS parent first so nested Live Start prompts can open.
        unset($state['pending_prompt']);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . '] activating [' .
            ($mbr['name_en'] ?? $mbr['name'] ?? 'Member') . '] Live Start.');
        $activated = false;
        foreach ($mbr['abilities'] ?? [] as $abIdx => $ab) {
            $trigger = $ab['trigger'] ?? '';
            if ($trigger !== 'live_start' && $trigger !== 'on_enter_or_live_start') {
                continue;
            }
            $activated = true;
            // Optional Live Starts must open their yes/no (or discard) UI — never force-pay
            // (that softlocked DB Tsuzuri and skipped PR Sayaka discard prompts).
            if (function_exists('isQueuedOptionalLiveStart') && isQueuedOptionalLiveStart($ab)) {
                $srcId = (string)($mbr['instance_id'] ?? '');
                if (function_exists('clearLiveStartOptionalResolved')) {
                    $state = clearLiveStartOptionalResolved($state, $owner, $srcId, intval($abIdx));
                }
                $state['pending_prompt'] = buildOptionalLiveStartPrompt($state, [
                    'owner'         => $owner,
                    'source_id'     => $srcId,
                    'source_name'   => $mbr['name_en'] ?? $mbr['name'] ?? 'Member',
                    'ability_index' => intval($abIdx),
                    'ability'       => $ab,
                ]);
                $state['_live_start_resume_from'] = $owner;
                $state['seq']++;
                return $state;
            }
            $state = resolveAbilityEffect($state, $owner, $mbr, $ab, [
                'phase' => 'live_start',
                'ability_index' => intval($abIdx),
            ]);
            break;
        }
        unset($mbr);
        if (!$activated) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] no Live Start ability to activate.');
        }
        if (!empty($state['pending_prompt'])) {
            $state['seq']++;
            return $state;
        }
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'live_start_edel_note_dual_pick_buff') {
        $slot = $data['slot'] ?? '';
        if ($slot === '' || empty($ownerP['stage'][$slot])) throw new Exception('Choose a Member');
        if (intval($prompt['step'] ?? 1) === 1) {
            $ownerP['stage'][$slot]['live_blade_bonus'] =
                intval($ownerP['stage'][$slot]['live_blade_bonus'] ?? 0) + intval($prompt['blade'] ?? 2);
            $firstName = $ownerP['stage'][$slot]['name_en'] ?? $ownerP['stage'][$slot]['name'] ?? '';
            $candidates = [];
            foreach ($ownerP['stage'] as $s => $mbr) {
                if (!$mbr || $s === $slot) continue;
                $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
                if ($label === $firstName) continue;
                if (cardMatchesSubunit($mbr, 'Edel Note')) {
                    $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $s]);
                }
            }
            if (empty($candidates)) {
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishLiveStartEffects($state);
            }
            $state['pending_prompt'] = [
                'type'        => 'live_start_edel_note_dual_pick_buff',
                'owner'       => $owner,
                'responder'   => $owner,
                'source_name' => $prompt['source_name'] ?? 'Live',
                'candidates'  => $candidates,
                'hearts'      => $prompt['hearts'] ?? [['color' => 'purple', 'count' => 2]],
                'step'        => 2,
                'prompt'      => 'Choose 1 other Edel Note Member for bonus hearts.',
            ];
            $state['seq']++;
            return $state;
        }
        addBonusHeartsToMember($ownerP['stage'][$slot], $prompt['hearts'] ?? [], 1);
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'mandatory_discard_after_draw') {
        $ids = $data['discard_ids'] ?? [];
        $need = intval($prompt['discard_count'] ?? 1);
        if (count($ids) !== $need) throw new Exception("Discard exactly $need card(s)");
        discardFromHandByIds($ownerP, $ids, $state, $owner);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] put $need card(s) into the Waiting Room.");
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishAfterBranchChoicePrompt($state, $prompt);
    }

    return null;
}

function hsPb1NotifyHandDiscard(array &$state, string $pid): void {
    $p = &$state['players'][$pid];
    foreach ($p['stage'] as $slot => &$member) {
        if (!$member) continue;
        foreach ($member['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto'
                || ($ab['type'] ?? '') !== 'auto_hand_discard_blade') {
                continue;
            }
            if (!empty($ab['max_uses_per_turn'])) {
                $used = intval($member['_auto_uses_' . $idx] ?? 0);
                if ($used >= intval($ab['max_uses_per_turn'])) continue;
                $member['_auto_uses_' . $idx] = $used + 1;
            }
            // Attribute to this Member (not player-wide blade_bonus / bonus_hearts).
            // Player-wide modifiers survive Wait and still count toward Yell — same
            // class of bug as GitHub #82 (Natsumi Live Start).
            $bladeAmt = intval($ab['amount'] ?? 1);
            $member['live_blade_bonus'] = intval($member['live_blade_bonus'] ?? 0) + $bladeAmt;
            if (!isset($member['bonus_hearts'])) {
                $member['bonus_hearts'] = [];
            }
            foreach ($ab['hearts'] ?? [] as $hg) {
                $color = is_array($hg) ? ($hg['color'] ?? 'any') : (string)$hg;
                $count = is_array($hg) ? intval($hg['count'] ?? 1) : 1;
                for ($i = 0; $i < $count; $i++) {
                    $member['bonus_hearts'][] = $color;
                }
            }
            $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$mName] gained +$bladeAmt Blade (hand to WR).");
        }
    }
    unset($member);
}

/**
 * Apply Zenhoui Kyun♡-style Live Start after optional Member heart buffs (#73).
 */
function flushDeferredMpExtraHeartsLiveStart(array $state): array {
    $queue = $state['_deferred_mp_extra_hearts'] ?? [];
    unset($state['_deferred_mp_extra_hearts']);
    if (!is_array($queue) || empty($queue)) {
        return $state;
    }
    foreach ($queue as $item) {
        if (!is_array($item)) {
            continue;
        }
        $pid = (string)($item['pid'] ?? '');
        $sourceId = (string)($item['source_id'] ?? '');
        $name = (string)($item['name'] ?? 'Live');
        $ab = is_array($item['ability'] ?? null) ? $item['ability'] : [];
        if ($pid === '' || $sourceId === '' || empty($state['players'][$pid])) {
            continue;
        }
        $p = &$state['players'][$pid];
        $sub = (string)($ab['subunit'] ?? '');
        $cnt = 0;
        $qualified = [];
        foreach ($p['stage'] as $slot => $mbr) {
            if (!$mbr || !cardMatchesSubunit($mbr, $sub)) {
                continue;
            }
            $printed = memberHeartCount($mbr);
            $current = $printed + memberContinuousHeartCount($mbr, $state, $pid);
            if ($current > $printed) {
                $cnt++;
                $qualified[] = ($mbr['name_en'] ?? $mbr['name'] ?? $slot) . " ($slot)";
            }
        }
        if ($cnt >= 1) {
            $drawn = drawCardsForPlayer($state, $pid, 1);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn ($cnt $sub member(s) with extra hearts: " .
                implode(', ', $qualified) . ').');
        } else {
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] no $sub members with extra hearts (need 1+ to draw, 2+ for −2 any).");
        }
        if ($cnt >= 2) {
            $reduce = intval($ab['reduce'] ?? 2);
            foreach ($p['live_zone'] as &$lc) {
                if ($lc && ($lc['instance_id'] ?? '') === $sourceId) {
                    if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                        $lc['hearts_color_reduction'] = [];
                    }
                    $lc['hearts_color_reduction']['any'] = intval($lc['hearts_color_reduction']['any'] ?? 0) + $reduce;
                    break;
                }
            }
            unset($lc);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Required any-color hearts -' . $reduce . '.');
        }
        unset($p);
    }
    return $state;
}
