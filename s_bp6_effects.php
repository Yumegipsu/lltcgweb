<?php
/**
 * Sunshine (Aqours) bp6 effect handlers.
 * Included by effects.php.
 */

function sBp6EffectTypes(): array {
    return [
        'on_enter_wr_wait_opp_side_min_cost',
        'auto_on_live_wr_deck_position',
        'live_start_wild_hearts_if_live_zone_colors',
        'activated_swap_stage_wr_member',
        'live_start_optional_live_zone_deck_top_hearts',
        'look_reveal_member_all_colors',
        'on_enter_wr_blade_bonus',
        'live_start_optional_pay_grant_members_live_score',
        'activated_leave_play_wr_same_slot',
        'blade_per_success_count_diff',
        'live_success_center_yell_live_score',
        'live_start_heart_if_performing_color_total',
        'draw_and_discard_if_wr',
        'look_reveal_filter_if_wr',
        'live_start_all_stage_group_bonus',
        'grant_live_success_draw',
        'grant_baton_entered_member_heart',
        'live_score_if_self_success_count',
        'auto_yell_wr_members_extra_yell',
        'live_success_score_if_opp_more_energy',
        'live_success_score_if_yell_has_live',
        'live_success_opp_lose_surplus_score',
    ];
}

function sBp6IsEffectType(string $type): bool {
    return in_array($type, sBp6EffectTypes(), true);
}

function sBp6CountLiveZoneColorTotal(array $p, string $group, array $colors): int {
    $total = 0;
    foreach ($p['live_zone'] ?? [] as $lc) {
        if (!$lc) continue;
        if ($group !== '' && ($lc['group'] ?? '') !== $group) continue;
        foreach ($lc['required_hearts'] ?? $lc['hearts'] ?? [] as $h) {
            $c = $h['color'] ?? '';
            if (in_array($c, $colors, true)) {
                $total += intval($h['count'] ?? 1);
            }
        }
    }
    return $total;
}

function sBp6CountPerformingColorTotal(array $p, string $color): int {
    $total = 0;
    foreach ($p['live_zone'] ?? [] as $lc) {
        if (!$lc) continue;
        foreach ($lc['required_hearts'] ?? $lc['hearts'] ?? [] as $h) {
            if (($h['color'] ?? '') === $color) {
                $total += intval($h['count'] ?? 1);
            }
        }
    }
    return $total;
}

function sBp6AllLiveZoneMatchGroup(array $p, string $group): bool {
    $found = false;
    foreach ($p['live_zone'] ?? [] as $lc) {
        if (!$lc) continue;
        $found = true;
        if (($lc['group'] ?? '') !== $group) return false;
    }
    return $found;
}

function sBp6ApplyContinuousBlade(array $state, string $pid, array $member, array $ab): int {
    if (($ab['type'] ?? '') !== 'blade_per_success_count_diff') return 0;
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    $selfCnt = count($state['players'][$pid]['success_lives'] ?? []);
    $oppCnt = count($state['players'][$opp]['success_lives'] ?? []);
    if ($oppCnt <= $selfCnt) return 0;
    return $oppCnt - $selfCnt;
}

function sBp6ApplyContinuousLiveScore(array $state, string $pid, array $member, array $ab): int {
    return 0;
}

function sBp6ResolveAutoOnLiveWr(array $state, string $pid, array $liveCard): array {
    if (!empty($state['pending_prompt'])) return $state;
    $p = &$state['players'][$pid];
    foreach ($p['stage'] as $slot => &$member) {
        if (!$member) continue;
        foreach ($member['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') continue;
            if (($ab['type'] ?? '') !== 'auto_on_live_wr_deck_position') continue;
            if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $idx)) continue;
            $group = $ab['group'] ?? 'Sunshine';
            if ($group !== '' && ($liveCard['group'] ?? '') !== $group) continue;
            if (($liveCard['card_type'] ?? '') !== 'ライブ') continue;
            $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
            markAbilityUsed($member, $idx);
            $p['stage'][$slot] = $member;
            $state['pending_prompt'] = [
                'type'          => 'sbp6_live_wr_deck_position',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $mName,
                'live_id'       => $liveCard['instance_id'] ?? '',
                'prompt'        => 'Put this Aqours Live card on the top or bottom of your deck?',
                'choices'       => ['top', 'bottom', 'skip'],
                'choice_labels' => ['Deck top', 'Deck bottom', 'Waiting Room — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$mName] optional: place Live from storage on deck.");
            return $state;
        }
    }
    unset($member);
    return $state;
}

function sBp6ResolveEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (!sBp6IsEffectType($type)) return $state;

    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'on_enter_wr_wait_opp_side_min_cost':
            if (empty($source['entered_via_baton'])) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $minCost = intval($ab['min_cost'] ?? 13);
            $candidates = [];
            foreach (['left', 'right'] as $slot) {
                $mbr = $state['players'][$opp]['stage'][$slot] ?? null;
                if (!$mbr) continue;
                if (getEffectiveStageMemberCost($state, $opp, $mbr) < $minCost) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'owner' => $opp]);
            }
            if (empty($candidates)) break;
            if (count($candidates) === 1) {
                $c = $candidates[0];
                $om = $state['players'][$opp]['stage'][$c['slot']] ?? null;
                if ($om) {
                    waitMember($om);
                    $state['players'][$opp]['stage'][$c['slot']] = $om;
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Waited 1 opponent side Member (cost $minCost+).");
                break;
            }
            $state['pending_prompt'] = [
                'type'        => 'sbp6_wait_opp_side_member',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'candidates'  => $candidates,
                'prompt'      => "Choose 1 opponent Left/Right Member with cost $minCost+ to put into Wait.",
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] choose opponent side Member to Wait.");
            break;

        case 'live_start_wild_hearts_if_live_zone_colors':
            $group = $ab['group'] ?? 'Sunshine';
            if (!sBp6AllLiveZoneMatchGroup($p, $group)) break;
            $colors = $ab['colors'] ?? ['red', 'green', 'blue'];
            if (sBp6CountLiveZoneColorTotal($p, $group, $colors) < intval($ab['min_total'] ?? 12)) break;
            $wilds = intval($ab['wild_count'] ?? 2);
            $hearts = [];
            for ($i = 0; $i < $wilds; $i++) {
                $hearts[] = ['color' => 'wild', 'count' => 1];
            }
            addBonusHeartsToModifier($state, $pid, $hearts);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] gained $wilds Wild heart(s) (Live storage colors).");
            break;

        case 'activated_swap_stage_wr_member':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'sbp6_swap_stage_wr_member',
                'step'          => 'confirm',
                'owner'         => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'ability'       => $ab,
                'prompt'        => 'Discard 1 card: swap another Aqours Stage Member for one from WR?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional activated swap.");
            break;

        case 'live_start_optional_live_zone_deck_top_hearts':
            if (!empty($state['pending_prompt'])) break;
            if (count($p['live_zone'] ?? []) < intval($ab['min_live_zone'] ?? 2)) break;
            $eligible = array_values(array_filter(
                $p['live_zone'],
                function ($c) use ($ab) {
                    if (($c['group'] ?? '') !== ($ab['group'] ?? 'Sunshine')) {
                        return false;
                    }
                    if (!empty($ab['no_live_start'])) {
                        return !cardHasLiveStartAbility($c);
                    }
                    if (!empty($ab['no_ability'])) {
                        return !cardHasAbilities($c);
                    }
                    return true;
                }
            ));
            if (empty($eligible)) break;
            $state['pending_prompt'] = [
                'type'          => 'sbp6_live_zone_deck_top_hearts',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'ability'       => $ab,
                'candidates'    => array_map('cardPromptSummary', $eligible),
                'prompt'        => 'Put 1 Aqours Live without (Live Start) from storage on deck top for Red+Green hearts?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Choose Live', 'No — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Live storage effect.");
            break;

        case 'look_reveal_member_all_colors':
            if (!empty($state['pending_prompt'])) break;
            $look = intval($ab['look'] ?? 2);
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            if (empty($top)) break;
            $colors = $ab['colors'] ?? ['red', 'green', 'blue'];
            $eligible = array_values(array_filter($top, function ($c) use ($colors) {
                if (($c['card_type'] ?? '') !== 'メンバー') return false;
                $hearts = $c['hearts'] ?? [];
                if (is_string($hearts)) {
                    $decoded = json_decode($hearts, true);
                    $hearts = $decoded['hearts'] ?? $decoded ?? [];
                }
                foreach ($colors as $col) {
                    if (intval($hearts[$col] ?? 0) < 1) return false;
                }
                return true;
            }));
            $state['sbp6_look_stash'] = $top;
            if (empty($eligible)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $top);
                unset($state['sbp6_look_stash']);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at $look; none eligible.");
                break;
            }
            if (count($eligible) === 1) {
                $pickId = $eligible[0]['instance_id'] ?? '';
                foreach ($top as $c) {
                    if (($c['instance_id'] ?? '') === $pickId) {
                        $p['hand'][] = $c;
                    } else {
                        $p['waiting_room'][] = $c;
                    }
                }
                unset($state['sbp6_look_stash']);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added 1 Member to hand.");
                break;
            }
            $state['pending_prompt'] = [
                'type'        => 'sbp6_pick_revealed_member',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'candidates'  => array_map('cardPromptSummary', $eligible),
                'prompt'      => 'Choose 1 Member with Red, Green, and Blue hearts to add to hand.',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] choose Member from surveil.");
            break;

        case 'on_enter_wr_blade_bonus':
            if (empty($source['entered_via_baton'])) break;
            $state = applyModifierEffect($state, $pid, [
                'type'   => 'blade_bonus',
                'amount' => intval($ab['amount'] ?? 3),
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] gained +' . intval($ab['amount'] ?? 3) . ' Blade (from WR).');
            break;

        case 'live_start_optional_pay_grant_members_live_score':
            if (!empty($state['pending_prompt'])) break;
            if (!empty($ab['requires_no_self_success']) && !empty($p['success_lives'])) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (count($state['players'][$opp]['success_lives'] ?? [])
                < intval($ab['min_opp_success'] ?? 2)) break;
            $aqours = array_values(array_filter(
                listStageMemberChoices($p),
                fn($c) => ($c['group'] ?? '') === ($ab['group'] ?? 'Sunshine')
            ));
            if (empty($aqours)) break;
            $state['pending_prompt'] = [
                'type'          => 'sbp6_live_start_pay_member_score',
                'step'          => 'confirm',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'ability'       => $ab,
                'prompt'        => 'Pay 2 Energy or discard 2 cards: up to 2 Aqours Members gain +1 Live Score?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Live Start (member score).");
            break;

        case 'activated_leave_play_wr_same_slot':
            if (!empty($state['pending_prompt'])) break;
            $slot = findMemberSlot($p, $source['instance_id'] ?? '');
            if ($slot === null) break;
            $eligible = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
                    && cardMatchesGroup($c, $ab['group'] ?? 'Sunshine', 'member')
                    && intval($c['cost'] ?? 0) <= intval($ab['max_cost'] ?? 17)
            ));
            if (empty($eligible)) break;
            $state['pending_prompt'] = [
                'type'          => 'sbp6_leave_play_wr_slot',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_slot'   => $slot,
                'source_name'   => $name,
                'ability'       => $ab,
                'ability_index' => intval($ctx['ability_index'] ?? -1),
                'candidates'    => array_map('cardPromptSummary', $eligible),
                'prompt'        => 'Pay 2 Energy: leave Stage and play 1 Aqours Member from WR to this area?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Choose Member', 'No — Skip'],
            ];
            $state['seq']++;
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] activated: play an Aqours Member from Waiting Room.");
            break;

        case 'live_success_center_yell_live_score':
            if (!empty($ab['center_only']) && findMemberSlot($p, $source['instance_id'] ?? '') !== 'center') {
                break;
            }
            $yellCards = $ctx['yell_cards'] ?? $state['_last_yell_cards'] ?? [];
            $group = $ab['group'] ?? 'Sunshine';
            $found = false;
            foreach ($yellCards as $yc) {
                if (($yc['group'] ?? '') !== $group) continue;
                if (($yc['card_type'] ?? '') !== 'ライブ') continue;
                if (!empty($ab['requires_score']) && intval($yc['score'] ?? 0) < 1) continue;
                $found = true;
                break;
            }
            if (!$found) break;
            $state = applyModifierEffect($state, $pid, [
                'type'   => 'live_score_bonus',
                'amount' => intval($ab['amount'] ?? 1),
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live total score +' . intval($ab['amount'] ?? 1) . ' (Yell Live).');
            break;

        case 'live_start_heart_if_performing_color_total':
            $color = $ab['color'] ?? 'red';
            if (sBp6CountPerformingColorTotal($p, $color) < intval($ab['min_total'] ?? 4)) break;
            if (!empty($ab['hearts'])) {
                addBonusHeartsToModifier($state, $pid, $ab['hearts']);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] gained bonus $color heart (performing Lives).");
            break;

        case 'draw_and_discard_if_wr':
            if (empty($source['entered_from_wr']) && empty($source['entered_via_baton'])) break;
            $drawCount = intval($ab['draw'] ?? 2);
            $drawnCards = drawCardsForPlayerWithEffectLog($state, $pid, $name, $drawCount);
            $drawn = count($drawnCards);
            $need = intval($ab['discard'] ?? 1);
            if ($drawCount > 0 && $drawn === 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] could not draw (deck empty).");
            } elseif ($need > 0 && count($p['hand']) >= $need) {
                if (!empty($state['pending_prompt'])) break;
                $state['pending_prompt'] = [
                    'type'          => 'sbp6_discard_after_draw',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_name'   => $name,
                    'discard_count' => $need,
                    'prompt'        => "Drew $drawn — discard $need card(s).",
                ];
            }
            break;

        case 'look_reveal_filter_if_wr':
            if (empty($source['entered_from_wr']) && empty($source['entered_via_baton'])) break;
            $look = intval($ab['look'] ?? 3);
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            if (empty($top)) break;
            if (count($top) === 1) {
                $p['hand'][] = $top[0];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added 1 card to hand.");
                break;
            }
            $state['surveil_stash'] = $top;
            $state['pending_prompt'] = [
                'type'          => 'surveil_pick_one_hand_rest_wr',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'look_cards'    => $top,
                'candidates'    => array_map('cardPromptSummary', $top),
                'prompt'        => 'Choose 1 card to add to hand (rest to Waiting Room).',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] looked at $look (from WR).");
            break;

        case 'live_start_all_stage_group_bonus':
            $group = $ab['group'] ?? 'Sunshine';
            $allGroup = true;
            $hasMember = false;
            foreach ($p['stage'] as $mbr) {
                if (!$mbr) continue;
                $hasMember = true;
                if (($mbr['group'] ?? '') !== $group) {
                    $allGroup = false;
                    break;
                }
            }
            if (!$hasMember || !$allGroup) break;
            bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['score_bonus'] ?? 1));
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $need = intval($ab['deck_position'] ?? 1);
            if ($need > 0 && count($p['hand']) >= $need) {
                $state['pending_prompt'] = [
                    'type'          => 'sbp6_hand_deck_position',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_name'   => $name,
                    'count'         => $need,
                    'drawn'         => $drawn,
                    'prompt'        => "Drew $drawn — put 1 card on deck top or bottom.",
                    'choices'       => ['top', 'bottom'],
                    'choice_labels' => ['Deck top', 'Deck bottom'],
                ];
            } else {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] score +1, drew $drawn.");
            }
            break;

        case 'grant_live_success_draw':
            if (!isset($source['granted_live_success_effects'])) {
                $source['granted_live_success_effects'] = [];
            }
            $liveRef = &$source;
            foreach ($p['live_zone'] as $li => &$lc) {
                if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $liveRef = &$lc;
                    break;
                }
            }
            unset($lc);
            if (!isset($liveRef['granted_live_success_effects'])) {
                $liveRef['granted_live_success_effects'] = [];
            }
            $liveRef['granted_live_success_effects'][] = ['type' => 'draw_cards', 'draw' => intval($ab['draw'] ?? 1)];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] gained [Live Success] draw effect.');
            break;

        case 'grant_baton_entered_member_heart':
            $granted = 0;
            $group = $ab['group'] ?? 'Sunshine';
            $color = $ab['color'] ?? 'red';
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                if (($mbr['group'] ?? '') !== $group) continue;
                if (empty($mbr['entered_via_baton']) || empty($mbr['entered_this_turn'])) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($candidates)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no Baton-entered {$group} Member on Stage.");
                break;
            }
            if (count($candidates) === 1) {
                $slot = $candidates[0]['slot'] ?? '';
                if ($slot !== '' && !empty($p['stage'][$slot])) {
                    if (!isset($p['stage'][$slot]['bonus_hearts'])) {
                        $p['stage'][$slot]['bonus_hearts'] = [];
                    }
                    $p['stage'][$slot]['bonus_hearts'][] = $color;
                    $granted = 1;
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] granted $color heart to $granted Baton Member(s).");
                break;
            }
            if (!empty($state['pending_prompt'])) break;
            $isLiveStart = ($ctx['phase'] ?? '') === 'live_start'
                || ($state['phase'] ?? '') === 'live_start_effects';
            $state['pending_prompt'] = [
                'type'        => 'pick_baton_entered_member_heart',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'live_start'  => $isLiveStart,
                'candidates'  => $candidates,
                'color'       => $color,
                'ability'     => $ab,
                'prompt'      => 'Choose 1 Aqours Member that entered via Baton Touch this turn to gain 1 Red heart.',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] choose Baton-entered Member for +heart.");
            break;

        case 'live_score_if_self_success_count':
            if (count($p['success_lives'] ?? []) < intval($ab['min_success_count'] ?? 2)) break;
            bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (Success Lives).');
            break;

        case 'auto_yell_wr_members_extra_yell':
            if (!empty($state['pending_prompt'])) break;
            $yellCards = $ctx['yell_cards'] ?? [];
            $candidates = array_values(array_filter(
                $yellCards,
                fn($c) => ($c['group'] ?? '') === ($ab['group'] ?? 'Sunshine')
                    && ($c['card_type'] ?? '') === 'メンバー'
                    && !yellCardHasBladeHeart($c)
            ));
            if (empty($candidates)) break;
            $max = min(intval($ab['max_mill'] ?? 1), count($candidates));
            $state['pending_prompt'] = [
                'type'          => 'sbp6_yell_mill_extra_yell',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', array_slice($candidates, 0, $max)),
                'max_pick'      => $max,
                'ability'       => $ab,
                'prompt'        => "Put up to $max bladeless Aqours Member(s) from Yell into WR for extra Yell?",
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Yell mill.");
            break;

        case 'live_success_score_if_opp_more_energy':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (countEnergyInZone($state['players'][$opp])
                <= countEnergyInZone($state['players'][$pid])) break;
            bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] score +1 (opponent has more Energy).');
            break;

        case 'live_success_score_if_yell_has_live':
            $yellCards = $ctx['yell_cards'] ?? $state['_last_yell_cards'] ?? [];
            if (countYellLiveCards($yellCards) < 1) break;
            bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] score +1 (Yell revealed Live).');
            break;

        case 'live_success_opp_lose_surplus_score':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $oppExcess = intval($state['_live_excess_hearts'][$opp] ?? $state['_surplus_hearts_opp'] ?? 0);
            if ($oppExcess <= 0) break;
            $state['_live_excess_hearts'][$opp] = 0;
            $state['_opp_surplus_lost_' . $opp] = $oppExcess;
            $state['live_modifiers'][$opp]['opp_lose_surplus'] = true;
            $lost = intval($state['_opp_surplus_lost_' . $opp] ?? $oppExcess);
            if ($lost >= intval($ab['min_opp_lost'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['score_bonus'] ?? 1));
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] opponent lost $lost surplus heart(s).");
            break;
    }

    return $state;
}

function sBp6ResolveActivatedAbility(
    array $state,
    string $pid,
    array &$p,
    array &$member,
    ?string $slot,
    array $ab,
    int $abilityIdx,
    array $data
): ?array {
    $type = $ab['type'] ?? '';
    if (!sBp6IsEffectType($type)) return null;

    if ($type === 'activated_swap_stage_wr_member') {
        return sBp6ResolveEffect($state, $pid, $member, $ab, ['phase' => 'activated']);
    }
    if ($type === 'activated_leave_play_wr_same_slot') {
        return sBp6ResolveEffect($state, $pid, $member, $ab, [
            'phase' => 'activated',
            'ability_index' => $abilityIdx,
        ]);
    }
    return null;
}

function sBp6ResolvePrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $promptType = $prompt['type'] ?? '';
    $ownerP = &$state['players'][$owner];
    $ability = $prompt['ability'] ?? [];

    if ($promptType === 'sbp6_wait_opp_side_member') {
        $slot = $data['slot'] ?? $choice;
        $opp = ($owner === 'p1') ? 'p2' : 'p1';
        foreach ($prompt['candidates'] ?? [] as $c) {
            if (($c['slot'] ?? '') !== $slot) continue;
            $om = $state['players'][$opp]['stage'][$slot] ?? null;
            if ($om) {
                waitMember($om);
                $state['players'][$opp]['stage'][$slot] = $om;
            }
            break;
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'sbp6_live_wr_deck_position') {
        $liveId = $prompt['live_id'] ?? '';
        $card = null;
        $zoneIdx = null;
        foreach ($ownerP['live_zone'] ?? [] as $i => $c) {
            if (($c['instance_id'] ?? '') === $liveId) {
                $card = $c;
                $zoneIdx = $i;
                break;
            }
        }
        if ($card === null) {
            foreach ($ownerP['waiting_room'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $liveId) {
                    $card = $c;
                    $zoneIdx = $i;
                    $fromWr = true;
                    break;
                }
            }
        }
        if ($card) {
            if (isset($fromWr)) {
                array_splice($ownerP['waiting_room'], $zoneIdx, 1);
            } else {
                array_splice($ownerP['live_zone'], $zoneIdx, 1);
            }
            if ($choice === 'skip') {
                $ownerP['waiting_room'][] = $card;
            } elseif ($choice === 'bottom') {
                $ownerP['main_deck'][] = $card;
            } else {
                array_unshift($ownerP['main_deck'], $card);
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'sbp6_pick_revealed_member') {
        $cardId = $data['card_id'] ?? $choice;
        $top = $state['sbp6_look_stash'] ?? [];
        unset($state['sbp6_look_stash']);
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

    if ($promptType === 'surveil_pick_one_hand_rest_wr') {
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
        $ownerP['hand'][] = $picked;
        if (!empty($rest)) {
            $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $rest);
        }
        unset($state['surveil_stash']);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] added 1 to hand; rest to Waiting Room.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'sbp6_discard_after_draw') {
        $ids = $data['discard_ids'] ?? [];
        $need = intval($prompt['discard_count'] ?? 1);
        if (count($ids) !== $need) throw new Exception("Discard exactly $need card(s)");
        discardFromHandByIds($ownerP, $ids);
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'sbp6_live_start_pay_member_score') {
        $step = $prompt['step'] ?? 'confirm';
        if ($step === 'confirm' && $choice === 'no') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        if ($step === 'confirm' && $choice === 'yes') {
            $state['pending_prompt'] = [
                'type'          => 'sbp6_live_start_pay_member_score',
                'step'          => 'pay',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? '',
                'ability'       => $ability,
                'prompt'        => 'Pay 2 Energy or discard 2 cards.',
                'choices'       => ['energy', 'discard'],
                'choice_labels' => ['Pay 2 Energy', 'Discard 2 cards'],
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pay') {
            if ($choice === 'energy') {
                if (!payEnergyCost($ownerP, 2)) throw new Exception('Need 2 Energy');
            } else {
                $ids = $data['discard_ids'] ?? [];
                if (count($ids) !== 2) throw new Exception('Discard exactly 2 cards');
                discardFromHandByIds($ownerP, $ids);
            }
            $max = intval($ability['max_members'] ?? 2);
            $cands = array_values(array_filter(
                listStageMemberChoices($ownerP),
                fn($c) => ($c['group'] ?? '') === ($ability['group'] ?? 'Sunshine')
            ));
            if (count($cands) <= $max) {
                foreach ($cands as $c) {
                    $slot = $c['slot'] ?? '';
                    if ($slot && !empty($ownerP['stage'][$slot])) {
                        $ownerP['stage'][$slot]['live_score_bonus'] =
                            intval($ownerP['stage'][$slot]['live_score_bonus'] ?? 0)
                            + intval($ability['score_amount'] ?? 1);
                    }
                }
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishLiveStartEffects($state);
            }
            $state['pending_prompt'] = [
                'type'          => 'sbp6_pick_members_live_score',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? '',
                'candidates'    => $cands,
                'max_pick'      => $max,
                'score_amount'  => intval($ability['score_amount'] ?? 1),
                'prompt'        => "Choose up to $max Aqours Member(s) for +1 Live Score.",
            ];
            $state['seq']++;
            return $state;
        }
    }

    if ($promptType === 'sbp6_pick_members_live_score') {
        $ids = $data['card_ids'] ?? [];
        $max = intval($prompt['max_pick'] ?? 2);
        if (count($ids) < 1 || count($ids) > $max) throw new Exception("Pick 1–$max Members");
        $amt = intval($prompt['score_amount'] ?? 1);
        foreach ($ownerP['stage'] as $slot => &$mbr) {
            if (!$mbr) continue;
            if (!in_array($mbr['instance_id'] ?? '', $ids, true)) continue;
            $mbr['live_score_bonus'] = intval($mbr['live_score_bonus'] ?? 0) + $amt;
            $ownerP['stage'][$slot] = $mbr;
        }
        unset($mbr);
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'pick_baton_entered_member_heart') {
        $slot = $data['slot'] ?? '';
        if ($slot === '') throw new Exception('Choose a Member');
        $valid = false;
        foreach ($prompt['candidates'] ?? [] as $c) {
            if (($c['slot'] ?? '') === $slot) {
                $valid = true;
                break;
            }
        }
        if (!$valid) throw new Exception('Invalid Member');
        $color = $prompt['color'] ?? 'red';
        if (!empty($ownerP['stage'][$slot])) {
            if (!isset($ownerP['stage'][$slot]['bonus_hearts'])) {
                $ownerP['stage'][$slot]['bonus_hearts'] = [];
            }
            $ownerP['stage'][$slot]['bonus_hearts'][] = $color;
            $mbrName = $ownerP['stage'][$slot]['name_en'] ?? $ownerP['stage'][$slot]['name'] ?? 'Member';
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] granted $color heart to $mbrName.");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        if (($state['phase'] ?? '') === 'live_start_effects' || !empty($prompt['live_start'])) {
            return finishLiveStartEffects($state);
        }
        return finishPromptEffects($state);
    }

    if ($promptType === 'sbp6_swap_stage_wr_member') {
        $step = $prompt['step'] ?? 'confirm';
        if ($step === 'confirm' && $choice === 'no') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        if ($step === 'confirm' && $choice === 'yes') {
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== 1) {
                throw new Exception('Discard exactly 1 card');
            }
            discardFromHandByIds($ownerP, $ids);
            $srcId = $prompt['source_id'] ?? '';
            $others = array_values(array_filter(
                listStageMemberChoices($ownerP),
                fn($c) => ($c['instance_id'] ?? '') !== $srcId
                    && ($c['group'] ?? '') === ($ability['group'] ?? 'Sunshine')
            ));
            if (empty($others)) throw new Exception('No other Aqours Member on Stage');
            $state['pending_prompt'] = [
                'type'        => 'sbp6_swap_pick_stage_member',
                'owner'       => $owner,
                'responder'   => $owner,
                'source_id'   => $srcId,
                'source_name' => $prompt['source_name'] ?? '',
                'ability'     => $ability,
                'candidates'  => $others,
                'prompt'      => 'Choose 1 other Aqours Member to put into WR.',
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_stage') {
            $stageId = $data['card_id'] ?? $choice;
            $slot = null;
            $removed = null;
            foreach ($ownerP['stage'] as $s => &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $stageId) {
                    $removed = $mbr;
                    $slot = $s;
                    $ownerP['waiting_room'][] = $mbr;
                    $ownerP['stage'][$s] = null;
                    break;
                }
            }
            unset($mbr);
            if (!$removed || !$slot) throw new Exception('Invalid Member');
            $targetCost = getEffectiveStageMemberCost($state, $owner, $removed)
                + intval($ability['cost_plus'] ?? 2);
            $eligible = array_values(array_filter(
                $ownerP['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
                    && cardMatchesGroup($c, $ability['group'] ?? 'Sunshine', 'member')
                    && intval($c['cost'] ?? 0) === $targetCost
            ));
            if (empty($eligible)) {
                $ownerP['stage'][$slot] = $removed;
                throw new Exception("No Aqours Member with cost $targetCost in WR");
            }
            $state['pending_prompt'] = [
                'type'        => 'sbp6_swap_pick_wr_member',
                'owner'       => $owner,
                'responder'   => $owner,
                'target_slot' => $slot,
                'source_name' => $prompt['source_name'] ?? '',
                'candidates'  => array_map('cardPromptSummary', $eligible),
                'prompt'      => "Play 1 cost-$targetCost Aqours Member from WR to $slot.",
            ];
            $state['seq']++;
            return $state;
        }
    }

    if ($promptType === 'sbp6_swap_pick_stage_member') {
        $stageId = $data['card_id'] ?? $choice;
        $slot = null;
        $removed = null;
        foreach ($ownerP['stage'] as $s => &$mbr) {
            if ($mbr && ($mbr['instance_id'] ?? '') === $stageId) {
                $removed = $mbr;
                $slot = $s;
                $ownerP['waiting_room'][] = $mbr;
                $ownerP['stage'][$s] = null;
                break;
            }
        }
        unset($mbr);
        if (!$removed || !$slot) throw new Exception('Invalid Member');
        $targetCost = getEffectiveStageMemberCost($state, $owner, $removed)
            + intval($ability['cost_plus'] ?? 2);
        $eligible = array_values(array_filter(
            $ownerP['waiting_room'],
            fn($c) => ($c['card_type'] ?? '') === 'メンバー'
                && cardMatchesGroup($c, $ability['group'] ?? 'Sunshine', 'member')
                && intval($c['cost'] ?? 0) === $targetCost
        ));
        if (empty($eligible)) {
            $ownerP['stage'][$slot] = $removed;
            throw new Exception("No Aqours Member with cost $targetCost in WR");
        }
        $state['pending_prompt'] = [
            'type'        => 'sbp6_swap_pick_wr_member',
            'owner'       => $owner,
            'responder'   => $owner,
            'target_slot' => $slot,
            'source_name' => $prompt['source_name'] ?? '',
            'candidates'  => array_map('cardPromptSummary', $eligible),
            'prompt'      => "Play 1 cost-$targetCost Aqours Member from WR to $slot.",
        ];
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'sbp6_swap_pick_wr_member') {
        $cardId = $data['card_id'] ?? $choice;
        $slot = $prompt['target_slot'] ?? 'center';
        foreach ($ownerP['waiting_room'] as $i => $c) {
            if (($c['instance_id'] ?? '') !== $cardId) continue;
            $played = $c;
            array_splice($ownerP['waiting_room'], $i, 1);
            $played['active'] = true;
            $played['entered_turn'] = intval($state['turn'] ?? 1);
            $ownerP['stage'][$slot] = $played;
            $state = resolveOnEnterAbilities($state, $owner, $played, $slot);
            break;
        }
        return returnAfterPlacedMemberEnter($state);
    }

    if ($promptType === 'sbp6_live_zone_deck_top_hearts') {
        if ($choice === 'no') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        $cardId = $data['card_id'] ?? '';
        foreach ($ownerP['live_zone'] as $i => $c) {
            if (($c['instance_id'] ?? '') !== $cardId) continue;
            array_splice($ownerP['live_zone'], $i, 1);
            array_unshift($ownerP['main_deck'], $c);
            addBonusHeartsToModifier($state, $owner, $ability['hearts'] ?? []);
            break;
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'sbp6_leave_play_wr_slot') {
        if ($choice === 'no') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $slot = $prompt['source_slot'] ?? '';
        $cfg = [
            'filter'   => 'member',
            'group'    => $ability['group'] ?? 'Sunshine',
            'max_cost' => intval($ability['max_cost'] ?? 17),
        ];
        if (($prompt['step'] ?? '') !== 'pick') {
            if ($choice !== 'yes') {
                throw new Exception('Choose Yes or No');
            }
            $eligible = wrCandidatesMatching($ownerP, $cfg);
            if (empty($eligible)) {
                throw new Exception('No matching Member in Waiting Room');
            }
            $slotLabel = $slot !== '' ? ucfirst($slot) : 'Stage';
            $state['pending_prompt'] = array_merge($prompt, [
                'step' => 'pick',
                'prompt' => "Choose 1 Aqours Member (cost {$cfg['max_cost']} or less) from your Waiting Room to play to $slotLabel.",
                'candidates' => array_map('cardPromptSummary', $eligible),
            ]);
            $state['seq']++;
            return $state;
        }
        $cardId = $data['card_id'] ?? '';
        if ($cardId === '') {
            throw new Exception('Choose a Member from your Waiting Room');
        }
        $cost = intval($ability['cost'] ?? 2);
        if ($cost > 0 && !payEnergyCost($ownerP, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        if ($slot === '' || empty($ownerP['stage'][$slot])) {
            throw new Exception('Member no longer on Stage');
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
        $leaving = $ownerP['stage'][$slot];
        $ownerP['stage'][$slot] = null;
        $ownerP['waiting_room'][] = $leaving;
        $state = resolveOnLeaveStageAbilities($state, $owner, $leaving);
        $played['active'] = true;
        $played['entered_turn'] = intval($state['turn'] ?? 1);
        $ownerP['stage'][$slot] = $played;
        $state = resolveOnEnterAbilities($state, $owner, $played, $slot);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($leaving['name_en'] ?? $leaving['name'] ?? 'Member') . '] left Stage; played ' .
            cardDisplayName($played) . ' from Waiting Room.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return returnAfterPlacedMemberEnter($state);
    }

    if ($promptType === 'sbp6_hand_deck_position') {
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== 1) throw new Exception('Choose exactly 1 card');
        $pos = $data['position'] ?? $choice;
        foreach ($ids as $id) {
            $idx = findInHand($ownerP['hand'], $id);
            if ($idx === false) throw new Exception('Invalid card');
            $card = $ownerP['hand'][$idx];
            array_splice($ownerP['hand'], $idx, 1);
            if ($pos === 'bottom') {
                $ownerP['main_deck'][] = $card;
            } else {
                array_unshift($ownerP['main_deck'], $card);
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'sbp6_yell_mill_extra_yell') {
        if ($choice === 'no') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }
        $ids = $data['card_ids'] ?? [];
        $costPer = intval($ability['cost_per_extra'] ?? 5);
        $maxExtra = intval($ability['max_extra_yell'] ?? 4);
        $extra = 0;
        $milledCost = 0;
        $pool = $state['_last_yell_cards'] ?? [];
        foreach ($ids as $id) {
            foreach ($pool as $i => $c) {
                if (($c['instance_id'] ?? '') !== $id) continue;
                $ownerP['waiting_room'][] = $c;
                $milledCost += intval($c['cost'] ?? 0);
                unset($pool[$i]);
                break;
            }
        }
        $extra = min($maxExtra, intdiv($milledCost, $costPer));
        if ($extra > 0) {
            $state['_extra_yell_count'] = intval($state['_extra_yell_count'] ?? 0) + $extra;
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    return null;
}
