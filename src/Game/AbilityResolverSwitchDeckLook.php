<?php
/**
 * Draw / look / deck / mill / surveil ability cases — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchDeckLook(
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
        case 'look_reveal_named':
            $cfg = array_merge($ab, [
                'filter' => 'member',
                'optional_pick' => true,
            ]);
            $state = beginLookRevealPick($state, $pid, $name, $p, $cfg);
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
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

        case 'draw_if_wr_min':
            if (count($p['waiting_room'] ?? []) >= intval($ab['min_wr'] ?? 10)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Waiting Room has " . intval($ab['min_wr'] ?? 10) . "+ cards).");
            }
            break;

        case 'deck_surveil':
            $look = intval($ab['look'] ?? 2);
            $returnAll = !empty($ab['return_all']);
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            if (count($top) <= 1) {
                if (count($top) === 1) {
                    $p['main_deck'] = array_merge($top, $p['main_deck']);
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at deck top.");
            } else {
                $state = startSurveilArrangePrompt(
                    $state,
                    $pid,
                    $name,
                    $top,
                    null,
                    $source['instance_id'] ?? '',
                    $returnAll
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at top " . count($top) . ' — arrange them.');
            }
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
                    'ability'        => $ab,
                    'source_id'      => $source['instance_id'] ?? '',
                    'ability_index'  => intval($ctx['ability_index'] ?? 0),
                ]
            );

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

        case 'draw_if_stage_cost_less_than_opp':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (sumStageMemberCost($p, $state, $pid) < sumStageMemberCost($state['players'][$opp], $state, $opp)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (Stage cost lower than opponent's).");
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

        case 'draw_discard_if_group_on_stage':
            if (!stageHasGroupMember($p, $ab['group'] ?? '')) break;
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            if (intval($ab['discard'] ?? 0) > 0 && !empty($p['hand'])) {
                $discard = min(intval($ab['discard'] ?? 1), count($p['hand']));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn.");
                return startEffectDiscardHandPrompt($state, $pid, $name, $discard);
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

        case 'draw_per_yell_heart':
            break;

        case 'draw_per_yell_draw':
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

        case 'draw_per_yell_card':
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

        case 'draw_if_other_subunit_on_stage':
            if (countOtherSubunitOnStage($p, $ab['subunit'] ?? '', $source['instance_id'] ?? '') > 0) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (other subunit on Stage).");
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

    }
    return $state;
}
