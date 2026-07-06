<?php
/**
 * Nijigasaki bp1/bp3/pb1 backlog effect handlers.
 * Included by effects.php.
 */

function nijiStackEnergyUnderMember(array &$p, array &$member, int $count): int {
    normalizeLegacyStackedEnergyZoneRefs($p, $member);
    $taken = takeActiveEnergyFromZone($p, $count);
    if (!empty($taken)) {
        attachStackedEnergyCardsToMember($member, $taken);
    }
    return count($taken);
}

function nijiCountLiveZoneCards(array $p): int {
    return countLiveCardsInZone($p['live_zone'] ?? []);
}

function nijiLiveZoneHasGroupLive(array $p, string $group): bool {
    foreach ($p['live_zone'] ?? [] as $lc) {
        if (!$lc || !isLiveTypeCard($lc)) continue;
        if (($lc['group'] ?? '') === $group) return true;
    }
    return false;
}

function nijiResolveNijigasakiEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'discard_member_add_lower_wr_member':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'discard_member_add_lower_wr_member',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Put 1 Member from your hand into the Waiting Room: add 1 lower-cost Member from your Waiting Room to your hand?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] optional On Enter.");
            break;

        case 'optional_discard_mill_wr_add_member':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_mill_wr_add_member',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Put 1 card from hand into WR, mill 2 from deck to WR, then add 1 Member from WR to hand?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
                'discard_count' => intval($ab['discard'] ?? 1),
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] optional On Enter.");
            break;

        case 'reveal_deck_until_live':
            $found = revealFromDeckUntil($p, ['filter' => 'live'], $state, $pid);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ($found ? " — [$name] revealed Live " . ($found['name_en'] ?? $found['name']) . ' from deck.'
                    : " — [$name] revealed deck; no Live found."));
            break;

        case 'live_success_add_yell_group_to_hand':
            $yellPool = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            $group = $ab['group'] ?? 'Nijigasaki';
            $filter = $ab['filter'] ?? '';
            $groupLabel = groupPromptLabel($group);
            $candidates = array_values(array_filter(
                $yellPool,
                fn($c) => cardMatchesGroup($c, $group, $filter)
            ));
            if (empty($candidates) || !empty($state['pending_prompt'])) break;
            $requiresWinning = $ab['requires_winning'] ?? true;
            if ($requiresWinning
                && getLiveTotalScore($state, $pid) <= getLiveTotalScore($state, $pid === 'p1' ? 'p2' : 'p1')) break;
            if ($filter === 'live' && count($candidates) === 1) {
                $picked = $candidates[0];
                $p['hand'][] = $picked;
                $p['_pending_yell_wr'] = array_values(array_filter(
                    $p['_pending_yell_wr'] ?? $yellPool,
                    fn($c) => ($c['instance_id'] ?? '') !== ($picked['instance_id'] ?? '')
                ));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added ' . cardDisplayName($picked) . ' from Yell to hand.');
                break;
            }
            $cardKind = ($filter === 'live') ? 'Live card' : 'card';
            $promptType = ($filter === 'live') ? 'live_success_pick_yell_live' : 'pick_yell_member';
            $pickPrompt = ($filter === 'live')
                ? "Add 1 {$groupLabel} Live card revealed for your Yell to your hand."
                : "Add 1 {$groupLabel} card revealed for Yell to your hand.";
            $state['pending_prompt'] = [
                'type'        => $promptType,
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'prompt'      => $pickPrompt,
                'candidates'  => array_map('cardPromptSummary', $candidates),
                'ability'     => array_merge($ab, ['filter' => $filter, 'group' => $group]),
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] Live Success: pick Yell card.");
            break;

        case 'optional_stack_energy_draw_blade_all':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_stack_energy_draw_blade_all',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Place 1 Energy under this Member: draw 1 and all Stage Members gain +' .
                    intval($ab['blade'] ?? 1) . ' Blade until Live ends?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] optional stack Energy.");
            break;

        case 'optional_stack_energy_draw':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_stack_energy_draw',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Place 1 Energy under this Member and draw 2 cards?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] optional stack Energy.");
            break;

        case 'optional_stack_energy':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_stack_energy',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Place ' . intval($ab['energy'] ?? 2) . ' Energy under this Member?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] optional stack Energy.");
            break;

        case 'optional_stack_energy_add_wr_live':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_stack_energy_add_wr_live',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Place 1 Energy under this Member: add 1 Nijigasaki Live from WR to hand?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] optional stack Energy.");
            break;

        case 'optional_discard_grant_heart_other_member':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_discard_grant_heart_other_member',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Discard 1 card: choose a heart color for another Nijigasaki Member to gain?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] optional grant heart.");
            break;

        case 'activate_wr_member_ability':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $group = $ab['group'] ?? 'Nijigasaki';
            $maxCost = intval($ab['max_cost'] ?? 4);
            $candidates = [];
            foreach ($p['waiting_room'] as $c) {
                if (($c['card_type'] ?? '') !== 'メンバー') {
                    continue;
                }
                if (!cardMatchesGroup($c, $group, 'member')) {
                    continue;
                }
                if (intval($c['cost'] ?? 0) > $maxCost) {
                    continue;
                }
                if (empty(listWrMemberActivatableAbilities($p, $c))
                    && empty($ab['enter_only'] ? listWrMemberEnterAbilities($c) : [])) {
                    continue;
                }
                $candidates[] = cardPromptSummary($c);
            }
            if (empty($candidates)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no $group Member (cost ≤$maxCost) in Waiting Room with an activatable ability.");
                break;
            }
            $state['pending_prompt'] = [
                'type'        => 'activate_wr_member_pick',
                'step'        => 'pick_member',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_id'   => $source['instance_id'] ?? '',
                'source_name' => $name,
                'candidates'  => $candidates,
                'ability'     => $ab,
                'prompt'      => "Choose 1 $group Member (cost ≤$maxCost) in your Waiting Room, then activate one of its abilities.",
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] choose a Waiting Room Member to activate.");
            break;

        case 'wait_other_group_draw':
            $group = $ab['group'] ?? 'Nijigasaki';
            $srcId = $source['instance_id'] ?? '';
            $waited = false;
            foreach ($p['stage'] as &$mbr) {
                if (!$mbr || ($mbr['instance_id'] ?? '') === $srcId) continue;
                if (($mbr['group'] ?? '') !== $group) continue;
                waitMember($mbr, $state);
                $waited = true;
                break;
            }
            unset($mbr);
            if ($waited) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Waited other $group Member; drew $drawn.");
            }
            break;

        case 'optional_discard_activate_wait_blade':
        case 'optional_discard_activate_wait_hearts':
            if (!empty($state['pending_prompt'])) break;
            $grantHearts = ($type === 'optional_discard_activate_wait_hearts');
            $state['pending_prompt'] = [
                'type'          => $grantHearts
                    ? 'optional_discard_activate_wait_hearts'
                    : 'optional_discard_activate_wait_blade',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => $grantHearts
                    ? 'Discard 2: activate 1 other Wait Member and both gain 1 Green Heart until Live ends?'
                    : 'Discard 2: activate 1 other Wait Member and both gain +1 Blade until Live ends?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] optional activate Wait.");
            break;

        case 'optional_wr_members_deck_bottom_milestones':
            if (!empty($state['pending_prompt'])) break;
            $candidates = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
            ));
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'          => 'optional_wr_members_deck_bottom_milestones',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Put up to 2 WR Members on deck bottom (milestones at cost 6/8/25)?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'candidates'    => array_map('cardPromptSummary', $candidates),
                'max_pick'      => intval($ab['max_pick'] ?? 2),
                'milestones'    => $ab['milestones'] ?? [],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] optional WR to deck bottom.");
            break;

        case 'player_choice_wr_members_deck_bottom':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'player_choice_wr_members_deck_bottom',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Choose yourself or opponent for WR Members to deck bottom.',
                'choices'       => ['self', 'opp'],
                'choice_labels' => ['Yourself', 'Opponent'],
                'max_pick'      => intval($ab['max_pick'] ?? 2),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] choose player.");
            break;

        case 'opp_member_match_heart_blade':
            if (!empty($state['pending_prompt'])) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $exclude = $ab['exclude_names'] ?? [];
            $candidates = [];
            foreach ($state['players'][$opp]['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                if (cardMatchesNames($mbr, $exclude)) continue;
                $candidates[] = ['instance_id' => $mbr['instance_id'] ?? '', 'slot' => $slot, 'summary' => cardPromptSummary($mbr)];
            }
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'          => 'opp_member_match_heart_blade',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Choose 1 opponent Stage Member to compare hearts/cost/blade.',
                'candidates'    => $candidates,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] choose opponent Member.");
            break;

        case 'choose_replace_member_hearts':
            if (!empty($state['pending_prompt'])) break;
            $choices = $ab['heart_choices'] ?? ['yellow', 'pink', 'purple'];
            $state['pending_prompt'] = [
                'type'          => 'choose_replace_member_hearts',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Choose a heart color to replace this Member\'s printed hearts until Live ends.',
                'choices'       => $choices,
                'choice_labels' => array_map(fn($c) => ucfirst($c) . ' ♡', $choices),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] choose heart replacement.");
            break;

        case 'wait_self_on_enter':
            $slot = findMemberSlot($p, $source['instance_id'] ?? '');
            if ($slot !== null && !empty($p['stage'][$slot])) {
                waitMember($p['stage'][$slot], $state);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put self into Wait (On Enter).");
            }
            break;

        case 'reveal_top_play_or_position':
            if (!empty($p['main_deck'])) {
                $top = array_shift($p['main_deck']);
                if (($top['card_type'] ?? '') === 'メンバー' && intval($top['cost'] ?? 99) <= intval($ab['max_cost'] ?? 9)) {
                    $p['hand'][] = $top;
                    $srcId = $source['instance_id'] ?? '';
                    $slot = findMemberSlot($p, $srcId);
                    if ($slot !== null && nijiApplyPositionChangeOffCenter($p, $srcId)) {
                        $state = addLog($state, $state['players'][$pid]['name'] .
                            " — [$name] position-changed off Center.");
                    } elseif ($slot !== null) {
                        $state = addLog($state, $state['players'][$pid]['name'] .
                            " — [$name] could not Position Change (not in Center).");
                    }
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] revealed ' . ($top['name_en'] ?? $top['name']) . ' to hand.');
                } else {
                    $p['waiting_room'][] = $top;
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] revealed top card to Waiting Room.');
                }
            }
            break;

        case 'choice_energy_or_wr_lives_deck_top':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'choice_energy_or_wr_lives_deck_top',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Activate 1 Energy OR put up to 2 Nijigasaki Lives from WR on deck top?',
                'choices'       => ['energy', 'lives'],
                'choice_labels' => ['Activate 1 Energy', 'Put Lives on deck top'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] . " — [$name] choose effect.");
            break;

        case 'baton_enter_draw_discard':
            if (empty($source['entered_via_baton'])) break;
            $names = $ab['baton_names'] ?? [];
            $batonOk = false;
            $wrId = $source['baton_wr_member_id'] ?? '';
            if ($wrId !== '') {
                foreach ($p['waiting_room'] as $wr) {
                    if (($wr['instance_id'] ?? '') === $wrId && cardMatchesNames($wr, $names)) {
                        $batonOk = true;
                        break;
                    }
                }
            }
            if (!$batonOk) break;
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 2));
            $need = intval($ab['discard'] ?? 1);
            if ($need > 0 && !empty($p['hand'])) {
                return startEffectDiscardHandPrompt($state, $pid, $name, $need);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Baton enter: drew $drawn.");
            break;

        case 'score_if_yell_activated_energy_and_members':
            $flags = $state['players'][$pid]['_niji_turn_flags'] ?? [];
            $amt = 0;
            if (!empty($flags['activated_wait_energy'])) $amt = intval($ab['amount_one'] ?? 1);
            if (!empty($flags['activated_wait_member'])) $amt = intval($ab['amount_two'] ?? 2);
            if ($amt > 0) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $amt);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] score +$amt (Nijigasaki activation effects).");
            }
            break;

        case 'score_if_success_or_live_zone_color_count':
            $group = $ab['group'] ?? 'Nijigasaki';
            $min = intval($ab['min_count'] ?? 4);
            $filterColor = $ab['heart_color'] ?? '';
            $found = false;
            foreach (array_merge($p['success_lives'] ?? [], array_filter($p['live_zone'] ?? [])) as $lc) {
                if (!$lc || ($lc['group'] ?? '') !== $group || ($lc['card_type'] ?? '') !== 'ライブ') continue;
                $byColor = countHeartsByColor($lc);
                if ($filterColor !== '') {
                    if (intval($byColor[$filterColor] ?? 0) >= $min) { $found = true; break; }
                } else {
                    foreach ($byColor as $cnt) {
                        if ($cnt >= $min) { $found = true; break 2; }
                    }
                }
            }
            if ($found) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $colorLabel = $filterColor !== '' ? ucfirst($filterColor) . ' ' : '';
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . " ($min+ {$colorLabel}Hearts).");
            }
            break;

        case 'member_hearts_if_live_zone_heart_color':
            $group = $ab['group'] ?? 'Nijigasaki';
            $checkColor = $ab['check_color'] ?? 'pink';
            $min = intval($ab['min_count'] ?? 3);
            $ok = false;
            foreach (array_merge($p['success_lives'] ?? [], array_filter($p['live_zone'] ?? [])) as $lc) {
                if (!$lc || ($lc['group'] ?? '') !== $group) continue;
                if (intval((countHeartsByColor($lc)[$checkColor] ?? 0)) >= $min) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) break;
            $memberColor = $ab['member_color'] ?? 'purple';
            $memberMin = intval($ab['member_min_hearts'] ?? 1);
            $grant = $ab['grant_hearts'] ?? [['color' => $memberColor, 'count' => 4]];
            $maxMembers = intval($ab['max_members'] ?? 1);
            $granted = 0;
            foreach ($p['stage'] as $slot => &$mbr) {
                if (!$mbr || ($mbr['group'] ?? '') !== $group) continue;
                $cnt = 0;
                foreach ($mbr['hearts'] ?? [] as $h) {
                    if (($h['color'] ?? '') === $memberColor) $cnt += intval($h['count'] ?? 1);
                }
                foreach ($mbr['bonus_hearts'] ?? [] as $c) {
                    if ($c === $memberColor) $cnt++;
                }
                if ($cnt < $memberMin) continue;
                foreach ($grant as $h) {
                    for ($i = 0; $i < intval($h['count'] ?? 1); $i++) {
                        $mbr['bonus_hearts'][] = $h['color'] ?? $memberColor;
                    }
                }
                $p['stage'][$slot] = $mbr;
                $granted++;
                if ($granted >= $maxMembers) break;
            }
            unset($mbr);
            if ($granted > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] $granted Member(s) gained bonus hearts.");
            }
            break;

        case 'live_score_bonus_if_min_entered':
            $cnt = intval($state['players'][$pid]['members_entered_this_turn'] ?? 0);
            if ($cnt >= intval($ab['min_entered'] ?? 2)) {
                $state = applyModifierEffect($state, $pid, [
                    'type'   => 'live_score_bonus',
                    'amount' => intval($ab['amount'] ?? 1),
                ]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] gained +1 Live total score (2+ enters this turn).");
            }
            break;

        case 'member_blade_if_live_zone_heart_color':
            $group = $ab['group'] ?? 'Nijigasaki';
            $min = intval($ab['min_count'] ?? 3);
            $ok = false;
            foreach (array_merge($p['success_lives'] ?? [], array_filter($p['live_zone'] ?? [])) as $lc) {
                if (!$lc || ($lc['group'] ?? '') !== $group) continue;
                foreach (countHeartsByColor($lc) as $cnt) {
                    if ($cnt >= $min) { $ok = true; break 2; }
                }
            }
            if ($ok) {
                $n = applyMemberBladeBonus($state, $pid, [
                    'group' => $group,
                    'amount' => intval($ab['blade'] ?? 1),
                    'max_members' => 1,
                    'requires_blade_hearts' => true,
                ]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] $n Member(s) gained +" . intval($ab['blade'] ?? 1) . ' Blade.');
            }
            break;
    }
    return $state;
}

function nijiIsNijigasakiEffectType(string $type): bool {
    static $types = [
        'discard_member_add_lower_wr_member', 'optional_discard_mill_wr_add_member',
        'reveal_deck_until_live', 'live_success_add_yell_group_to_hand',
        'optional_stack_energy_draw_blade_all', 'optional_stack_energy_draw',
        'optional_stack_energy', 'optional_stack_energy_add_wr_live',
        'optional_discard_grant_heart_other_member', 'activate_wr_member_ability',
        'wait_other_group_draw', 'optional_discard_activate_wait_blade',
        'optional_discard_activate_wait_hearts',
        'optional_wr_members_deck_bottom_milestones', 'player_choice_wr_members_deck_bottom',
        'opp_member_match_heart_blade', 'choose_replace_member_hearts',
        'reveal_top_play_or_position', 'choice_energy_or_wr_lives_deck_top',
        'baton_enter_draw_discard', 'score_if_yell_activated_energy_and_members',
        'score_if_success_or_live_zone_color_count', 'member_blade_if_live_zone_heart_color',
        'member_hearts_if_live_zone_heart_color',
        'blade_if_live_zone_group_live', 'wait_self_only', 'wait_self_activate_energy',
        'wait_self_discard_add_wr_live', 'leave_play_named_from_hand_stack_energy',
        'hand_discard_for_stage_blade', 'draw_on_stage_cost_enter',
        'draw_blade_if_no_blade_left_live_zone', 'energy_wait_on_stage_cost_enter',
        'blade_if_live_zone_min', 'live_score_if_stacked_energy', 'blade_per_stacked_energy',
        'blade_if_not_moved_this_turn', 'blade_if_live_zone_four_colors',
        'hearts_if_live_zone_six_colors',
        'hand_cost_reduction_if_wait_group', 'live_score_bonus_if_min_entered',
        'reduce_hearts_if_same_name_duplicate', 'wait_self_on_enter',
    ];
    return in_array($type, $types, true);
}

function nijiResolveActivatedEffect(array $state, string $pid, array &$p, array &$member, $slot, array $ab, int $abilityIdx, array $data): ?array {
    $type = $ab['type'] ?? '';
    if ($type === 'wait_self_only') {
        waitMember($member, $state);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] put self into Wait.');
        return $state;
    }
    if ($type === 'wait_self_activate_energy') {
        waitMember($member, $state);
        $activated = activateEnergyForPlayer($p, intval($ab['count'] ?? 1));
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [" . ($member['name_en'] ?? $member['name']) . "] Waited; activated $activated Energy.");
        return $state;
    }
    if ($type === 'wait_self_discard_add_wr_live') {
        waitMember($member, $state);
        $need = intval($ab['discard'] ?? 1);
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== $need) throw new Exception("Must discard exactly $need card(s)");
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
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] choose a Live card from Waiting Room.');
        return $state;
    }
    if ($type === 'wait_other_group_draw') {
        $group = $ab['group'] ?? 'Nijigasaki';
        $srcId = $member['instance_id'] ?? '';
        $waited = false;
        foreach ($p['stage'] as &$mbr) {
            if (!$mbr || ($mbr['instance_id'] ?? '') === $srcId) continue;
            if (($mbr['group'] ?? '') !== $group) continue;
            waitMember($mbr, $state);
            $waited = true;
            break;
        }
        unset($mbr);
        if ($waited) {
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $p['stage'][$slot] = $member;
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [" . ($member['name_en'] ?? $member['name']) . "] Waited other $group Member; drew $drawn.");
        }
        return $state;
    }
    if ($type === 'discard_member_add_lower_wr_member') {
        if (!empty($state['pending_prompt'])) return $state;
        $state['pending_prompt'] = [
            'type'          => 'discard_member_add_lower_wr_member',
            'owner'         => $pid,
            'responder'     => $pid,
            'source_id'     => $member['instance_id'] ?? '',
            'source_slot'   => $slot,
            'source_name'   => $member['name_en'] ?? $member['name'] ?? 'Member',
            'ability_idx'   => $abilityIdx,
            'prompt'        => 'Put 1 Member from your hand into the Waiting Room: add 1 lower-cost Member from your Waiting Room to your hand?',
            'choices'       => ['yes', 'no'],
            'choice_labels' => ['Yes', 'No — Skip'],
            'ability'       => $ab,
        ];
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] optional activated effect.');
        return $state;
    }
    if ($type === 'leave_play_named_from_hand_stack_energy') {
        $energyCost = intval($ab['energy_cost'] ?? 0);
        if ($energyCost > 0 && !payEnergyCost($p, $energyCost)) {
            throw new Exception("Need $energyCost Energy");
        }
        $handId = $data['hand_card_id'] ?? '';
        $played = null;
        foreach ($p['hand'] as $i => $c) {
            if (($c['instance_id'] ?? '') !== $handId) continue;
            if (!cardMatchesNames($c, $ab['names'] ?? [])) throw new Exception('Must choose matching Member');
            if (intval($c['cost'] ?? 0) > intval($ab['max_cost'] ?? 13)) throw new Exception('Cost too high');
            $played = $c;
            array_splice($p['hand'], $i, 1);
            break;
        }
        if (!$played) throw new Exception('Choose a Member from hand');
        $p['waiting_room'][] = $member;
        $p['stage'][$slot] = $played;
        $played['active'] = true;
        $played['entered_turn'] = intval($state['turn'] ?? 1);
        nijiStackEnergyUnderMember($p, $played, intval($ab['energy'] ?? 1));
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] swapped for ' .
            ($played['name_en'] ?? $played['name']) . ' and stacked Energy.');
        return $state;
    }
    if ($type === 'hand_discard_for_stage_blade') {
        if (($member['card_type'] ?? '') !== 'メンバー') throw new Exception('Must activate from hand');
        throw new Exception('Use hand-activated ability from hand UI');
    }
    return null;
}

function countHeartsByColor(array $card): array {
    $out = [];
    foreach ($card['hearts'] ?? [] as $h) {
        $c = $h['color'] ?? '';
        if ($c === '') continue;
        $out[$c] = ($out[$c] ?? 0) + intval($h['count'] ?? 1);
    }
    return $out;
}

function nijiApplyContinuousBlade(array $member, array $ab, array $state, string $pid, string $slot, int $blade): int {
    if (($ab['trigger'] ?? '') !== 'continuous') {
        return $blade;
    }
    $type = $ab['type'] ?? '';
    $p = $state['players'][$pid];
    if ($type === 'blade_if_live_zone_group_live') {
        $min = intval($ab['min_count'] ?? 3);
        $group = $ab['group'] ?? 'Nijigasaki';
        if (nijiCountLiveZoneCards($p) >= $min && nijiLiveZoneHasGroupLive($p, $group)) {
            $blade += intval($ab['amount'] ?? 1);
        }
    }
    if ($type === 'blade_if_live_zone_min') {
        if (nijiCountLiveZoneCards($p) >= intval($ab['min_live_cards'] ?? 2)) {
            $blade += intval($ab['amount'] ?? 1);
        }
    }
    if ($type === 'blade_per_stacked_energy') {
        $blade += countMemberStackedEnergy($p, $member) * intval($ab['amount'] ?? 1);
    }
    if ($type === 'blade_if_not_moved_this_turn' && empty($member['moved_this_turn'])) {
        $blade += intval($ab['amount'] ?? 1);
    }
    if ($type === 'blade_if_live_zone_four_colors') {
        $colors = [];
        foreach ($p['live_zone'] ?? [] as $lc) {
            if (!$lc || ($lc['card_type'] ?? '') !== 'ライブ') continue;
            foreach ($lc['hearts'] ?? [] as $h) {
                $colors[$h['color'] ?? ''] = true;
            }
        }
        if (count($colors) >= 4) $blade += intval($ab['amount'] ?? 1);
    }
    return $blade;
}

function nijiApplyContinuousLiveScore(array $state, string $pid, array $source, array $ab): array {
    $type = $ab['type'] ?? '';
    if ($type === 'live_score_if_stacked_energy') {
        $slot = findMemberSlot($state['players'][$pid], $source['instance_id'] ?? '');
        if ($slot !== null) {
            $mbr = $state['players'][$pid]['stage'][$slot];
            if ($mbr && countMemberStackedEnergy($state['players'][$pid], $mbr) >= intval($ab['min_energy'] ?? 2)) {
                $state['live_modifiers'][$pid]['live_score_bonus'] =
                    intval($state['live_modifiers'][$pid]['live_score_bonus'] ?? 0) + intval($ab['amount'] ?? 1);
            }
        }
    }
    if ($type === 'live_score_bonus_if_min_entered') {
        $cnt = intval($state['players'][$pid]['members_entered_this_turn'] ?? 0);
        if ($cnt >= intval($ab['min_entered'] ?? 2)) {
            $state['live_modifiers'][$pid]['live_score_bonus'] =
                intval($state['live_modifiers'][$pid]['live_score_bonus'] ?? 0) + intval($ab['amount'] ?? 1);
        }
    }
    if ($type === 'reduce_hearts_if_same_name_duplicate') {
        $group = $ab['group'] ?? 'Nijigasaki';
        $names = [];
        foreach ($state['players'][$pid]['stage'] as $mbr) {
            if (!$mbr || ($mbr['group'] ?? '') !== $group) continue;
            $k = cardNameKey($mbr);
            $names[$k] = ($names[$k] ?? 0) + 1;
        }
        $dup = max(0, ...array_values($names)) >= intval($ab['min_duplicates'] ?? 2);
        if ($dup) {
            $reduce = intval($ab['reduce'] ?? 1);
            $reduceColor = $ab['reduce_color'] ?? '';
            foreach ($state['players'][$pid]['live_zone'] as &$lc) {
                if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    if ($reduceColor === 'gray') {
                        $lc['hearts_reduction_gray'] = intval($lc['hearts_reduction_gray'] ?? 0) + $reduce;
                    } else {
                        $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $reduce;
                    }
                }
            }
            unset($lc);
        }
    }
    return $state;
}

function nijiWrActivatedAbilityDiscardCount(array $ab): int {
    return max(0, intval($ab['discard'] ?? 0));
}

function nijiApplyPositionChangeOffCenter(array &$p, string $sourceId): bool {
    if (($p['stage']['center']['instance_id'] ?? '') !== $sourceId) {
        return false;
    }
    $member = $p['stage']['center'];
    foreach (['left', 'right'] as $toSlot) {
        $other = $p['stage'][$toSlot];
        $member['moved_this_turn'] = true;
        $p['stage'][$toSlot] = $member;
        if ($other) {
            $other['moved_this_turn'] = true;
            $p['stage']['center'] = $other;
        } else {
            $p['stage']['center'] = null;
        }
        return true;
    }
    return false;
}

function nijiHandlePrompt(array $state, string $promptType, array $prompt, string $choice, array $data): ?array {
    $owner = $prompt['owner'] ?? '';
    $ownerP = &$state['players'][$owner];
    $ability = $prompt['ability'] ?? [];

    if ($promptType === 'activate_wr_member_pick') {
        $step = $prompt['step'] ?? 'pick_member';
        $srcName = $prompt['source_name'] ?? 'Member';

        if ($step === 'pick_member') {
            $memberId = $data['card_id'] ?? $data['pick_id'] ?? '';
            if ($memberId === '') {
                throw new Exception('Choose a Member from your Waiting Room');
            }
            $picked = null;
            foreach ($ownerP['waiting_room'] as $c) {
                if (($c['instance_id'] ?? '') === $memberId) {
                    $picked = $c;
                    break;
                }
            }
            if (!$picked) {
                throw new Exception('Member not in Waiting Room');
            }
            $activatable = !empty($ab['enter_only'])
                ? listWrMemberEnterAbilities($picked)
                : listWrMemberActivatableAbilities($ownerP, $picked);
            if (empty($activatable)) {
                throw new Exception('That Member has no activatable abilities from the Waiting Room');
            }
            $choices = [];
            $labels = [];
            $abilities = [];
            foreach ($activatable as $entry) {
                $idx = intval($entry['index']);
                $choices[] = (string)$idx;
                $abilities[$idx] = $entry['ability'];
                $labels[] = abilityEffectTextFromSource($picked, $entry['ability'], $idx);
            }
            if (count($activatable) === 1) {
                $abIdx = intval($activatable[0]['index']);
                $chosen = $activatable[0]['ability'];
                $discardNeed = nijiWrActivatedAbilityDiscardCount($chosen);
                if ($discardNeed > 0 && empty($data['discard_ids'])) {
                    $state['pending_prompt'] = [
                        'type'          => 'activate_wr_member_pick',
                        'step'          => 'pick_discard',
                        'owner'         => $owner,
                        'responder'     => $owner,
                        'source_name'   => $srcName,
                        'wr_member_id'  => $memberId,
                        'wr_member_name'=> $picked['name_en'] ?? $picked['name'] ?? 'Member',
                        'ability_index' => $abIdx,
                        'ability'       => $chosen,
                        'discard_count' => $discardNeed,
                        'prompt'        => 'Discard ' . $discardNeed . ' card(s) from your hand to pay the ability cost.',
                    ];
                    $state['seq']++;
                    return $state;
                }
                $state = actionActivateAbility($state, $owner, [
                    'card_id'                 => $memberId,
                    'ability_index'           => $abIdx,
                    'discard_ids'             => $data['discard_ids'] ?? [],
                    'slot'                    => $data['slot'] ?? '',
                    '_on_enter_wr_activate'   => true,
                ]);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . $srcName . '] activated [' . ($picked['name_en'] ?? $picked['name']) . '] ability from Waiting Room.');
                if (!empty($state['pending_prompt'])) {
                    $state['seq']++;
                    return $state;
                }
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $state['pending_prompt'] = [
                'type'           => 'activate_wr_member_pick',
                'step'           => 'pick_ability',
                'owner'          => $owner,
                'responder'      => $owner,
                'source_name'    => $srcName,
                'wr_member_id'   => $memberId,
                'wr_member_name' => $picked['name_en'] ?? $picked['name'] ?? 'Member',
                'choices'        => $choices,
                'choice_labels'  => $labels,
                'abilities'      => $abilities,
                'prompt'         => 'Choose 1 ability to activate on ' .
                    ($picked['name_en'] ?? $picked['name'] ?? 'Member') . '.',
            ];
            $state['seq']++;
            return $state;
        }

        if ($step === 'pick_ability') {
            $memberId = $prompt['wr_member_id'] ?? '';
            $abIdx = intval($choice);
            $abilitiesMap = $prompt['abilities'] ?? [];
            if ($memberId === '' || (!isset($abilitiesMap[$abIdx]) && !isset($abilitiesMap[(string)$abIdx]))) {
                throw new Exception('Invalid ability choice');
            }
            $chosen = $abilitiesMap[$abIdx] ?? $abilitiesMap[(string)$abIdx];
            $discardNeed = nijiWrActivatedAbilityDiscardCount($chosen);
            if ($discardNeed > 0 && empty($data['discard_ids'])) {
                $state['pending_prompt'] = [
                    'type'          => 'activate_wr_member_pick',
                    'step'          => 'pick_discard',
                    'owner'         => $owner,
                    'responder'     => $owner,
                    'source_name'   => $srcName,
                    'wr_member_id'  => $memberId,
                    'wr_member_name'=> $prompt['wr_member_name'] ?? 'Member',
                    'ability_index' => $abIdx,
                    'ability'       => $chosen,
                    'discard_count' => $discardNeed,
                    'prompt'        => 'Discard ' . $discardNeed . ' card(s) from your hand to pay the ability cost.',
                ];
                $state['seq']++;
                return $state;
            }
            $state = actionActivateAbility($state, $owner, [
                'card_id'                 => $memberId,
                'ability_index'           => $abIdx,
                'discard_ids'             => $data['discard_ids'] ?? [],
                'slot'                    => $data['slot'] ?? '',
                '_on_enter_wr_activate'   => true,
            ]);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] activated [' . ($prompt['wr_member_name'] ?? 'Member') . '] ability from Waiting Room.');
            if (!empty($state['pending_prompt'])) {
                $state['seq']++;
                return $state;
            }
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }

        if ($step === 'pick_discard') {
            $memberId = $prompt['wr_member_id'] ?? '';
            $abIdx = intval($prompt['ability_index'] ?? 0);
            $need = intval($prompt['discard_count'] ?? 1);
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== $need) {
                throw new Exception("Must discard exactly $need card(s) from hand");
            }
            $state = actionActivateAbility($state, $owner, [
                'card_id'                 => $memberId,
                'ability_index'           => $abIdx,
                'discard_ids'             => $ids,
                'slot'                    => $data['slot'] ?? '',
                '_on_enter_wr_activate'   => true,
            ]);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] activated [' . ($prompt['wr_member_name'] ?? 'Member') . '] ability from Waiting Room.');
            if (!empty($state['pending_prompt'])) {
                $state['seq']++;
                return $state;
            }
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
    }

    if (in_array($promptType, ['optional_discard_activate_wait_blade', 'optional_discard_activate_wait_hearts'], true)) {
        if ($choice === 'no') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        if ($choice !== 'yes') {
            throw new Exception('Invalid choice');
        }
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== 2) {
            throw new Exception('Discard exactly 2 cards');
        }
        discardFromHandByIds($ownerP, $ids);
        $srcId = $prompt['source_id'] ?? '';
        $waitSlots = [];
        foreach ($ownerP['stage'] as $slot => $mbr) {
            if (!$mbr || ($mbr['instance_id'] ?? '') === $srcId) continue;
            if (!($mbr['active'] ?? true)) {
                $waitSlots[] = $slot;
            }
        }
        if (empty($waitSlots)) {
            throw new Exception('No other Wait Members on Stage');
        }
        if (count($waitSlots) === 1) {
            $pickSlot = $waitSlots[0];
        } else {
            $state['pending_prompt'] = [
                'type'        => $promptType,
                'step'        => 'pick_wait',
                'owner'       => $owner,
                'responder'   => $owner,
                'source_id'   => $srcId,
                'source_name' => $prompt['source_name'] ?? 'Member',
                'wait_slots'  => $waitSlots,
                'ability'     => $ability,
                'prompt'      => 'Choose 1 Wait Member to activate.',
            ];
            $state['seq']++;
            return $state;
        }
        $ownerP['stage'][$pickSlot]['active'] = true;
        $grantHearts = ($promptType === 'optional_discard_activate_wait_hearts');
        if ($grantHearts) {
            $heartSpec = $ability['hearts'] ?? [['color' => 'green', 'count' => 1]];
            addBonusHeartsToMember($ownerP['stage'][$pickSlot], $heartSpec);
            $srcSlot = findMemberSlot($ownerP, $srcId);
            if ($srcSlot !== null && !empty($ownerP['stage'][$srcSlot])) {
                addBonusHeartsToMember($ownerP['stage'][$srcSlot], $heartSpec);
            }
        } else {
            $amt = intval($ability['blade'] ?? 1);
            $ownerP['stage'][$pickSlot]['live_blade_bonus'] =
                intval($ownerP['stage'][$pickSlot]['live_blade_bonus'] ?? 0) + $amt;
            $srcSlot = findMemberSlot($ownerP, $srcId);
            if ($srcSlot !== null && !empty($ownerP['stage'][$srcSlot])) {
                $ownerP['stage'][$srcSlot]['live_blade_bonus'] =
                    intval($ownerP['stage'][$srcSlot]['live_blade_bonus'] ?? 0) + $amt;
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'optional_discard_activate_wait_blade' && ($prompt['step'] ?? '') === 'pick_wait') {
        $pickSlot = $choice;
        $srcId = $prompt['source_id'] ?? '';
        if (!in_array($pickSlot, $prompt['wait_slots'] ?? [], true)) {
            throw new Exception('Invalid Wait Member');
        }
        $ownerP['stage'][$pickSlot]['active'] = true;
        $grantHearts = (($prompt['type'] ?? '') === 'optional_discard_activate_wait_hearts');
        $ab = $prompt['ability'] ?? [];
        if ($grantHearts) {
            $heartSpec = $ab['hearts'] ?? [['color' => 'green', 'count' => 1]];
            addBonusHeartsToMember($ownerP['stage'][$pickSlot], $heartSpec);
            $srcSlot = findMemberSlot($ownerP, $srcId);
            if ($srcSlot !== null && !empty($ownerP['stage'][$srcSlot])) {
                addBonusHeartsToMember($ownerP['stage'][$srcSlot], $heartSpec);
            }
        } else {
            $amt = intval($ab['blade'] ?? 1);
            $ownerP['stage'][$pickSlot]['live_blade_bonus'] =
                intval($ownerP['stage'][$pickSlot]['live_blade_bonus'] ?? 0) + $amt;
            $srcSlot = findMemberSlot($ownerP, $srcId);
            if ($srcSlot !== null && !empty($ownerP['stage'][$srcSlot])) {
                $ownerP['stage'][$srcSlot]['live_blade_bonus'] =
                    intval($ownerP['stage'][$srcSlot]['live_blade_bonus'] ?? 0) + $amt;
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'discard_member_add_lower_wr_member' && $choice === 'yes') {
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== 1) throw new Exception('Discard exactly 1 Member from hand');
        $discarded = null;
        foreach ($ownerP['hand'] as $i => $c) {
            if (($c['instance_id'] ?? '') === $ids[0]) {
                if (($c['card_type'] ?? '') !== 'メンバー') throw new Exception('Must discard a Member');
                $discarded = $c;
                array_splice($ownerP['hand'], $i, 1);
                $ownerP['waiting_room'][] = $c;
                break;
            }
        }
        if (!$discarded) throw new Exception('Invalid discard');
        $maxCost = intval($discarded['cost'] ?? 0) - 1;
        $added = null;
        foreach ($ownerP['waiting_room'] as $i => $c) {
            if (($c['card_type'] ?? '') !== 'メンバー') continue;
            if (intval($c['cost'] ?? 0) <= $maxCost) {
                $added = $c;
                array_splice($ownerP['waiting_room'], $i, 1);
                break;
            }
        }
        if ($added) $ownerP['hand'][] = $added;
        $slot = $prompt['source_slot'] ?? null;
        $abIdx = $prompt['ability_idx'] ?? null;
        if ($slot !== null && $abIdx !== null && !empty($ownerP['stage'][$slot])) {
            markAbilityUsed($ownerP['stage'][$slot], $abIdx);
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — added ' . ($added['name_en'] ?? $added['name'] ?? 'Member') . ' from WR.');
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'optional_discard_mill_wr_add_member' && $choice === 'yes') {
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== 1) throw new Exception('Discard exactly 1 card');
        discardFromHandByIds($ownerP, $ids);
        $mill = intval($ability['mill'] ?? 2);
        $milled = array_splice($ownerP['main_deck'], 0, min($mill, count($ownerP['main_deck'])));
        $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $milled);
        $srcName = $prompt['source_name'] ?? 'Member';
        $cfg = wrPickCfgFromAbility(array_merge($ability, ['filter' => 'member']));
        $candidates = wrCandidatesMatching($ownerP, $cfg);
        if (empty($candidates)) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$srcName] milled " . count($milled) . ' card(s); no Member in Waiting Room to add.');
            unset($state['pending_prompt']);
            $state['seq']++;
            $state = finishPromptEffects($state);
            return $state;
        }
        $state['pending_prompt'] = [
            'type'        => 'pick_wr_to_hand',
            'owner'       => $owner,
            'responder'   => $owner,
            'source_name' => $srcName,
            'prompt'      => 'Choose 1 Member card from your Waiting Room to add to your hand.',
            'candidates'  => array_map('cardPromptSummary', $candidates),
            'ability'     => $ability,
            'wr_pick_cfg' => $cfg,
        ];
        $state = addLog($state, $state['players'][$owner]['name'] .
            " — [$srcName] discarded and milled " . count($milled) .
            '; choose a Member from Waiting Room.');
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_stack_energy_draw_blade_all' && $choice === 'yes') {
        $srcId = $prompt['source_id'] ?? '';
        foreach ($ownerP['stage'] as &$mbr) {
            if (!$mbr || ($mbr['instance_id'] ?? '') !== $srcId) continue;
            nijiStackEnergyUnderMember($ownerP, $mbr, intval($ability['energy'] ?? 1));
            drawCardsForPlayer($state, $owner, intval($ability['draw'] ?? 1));
            applyMemberBladeBonus($state, $owner, ['amount' => intval($ability['blade'] ?? 1), 'max_members' => 99]);
            $ownerP['stage'][findMemberSlot($ownerP, $srcId) ?? 'center'] = $mbr;
            break;
        }
        unset($mbr);
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if (in_array($promptType, [
        'optional_stack_energy_draw',
        'optional_stack_energy',
        'optional_stack_energy_add_wr_live',
        'optional_stack_energy_draw_blade_all',
    ], true) && $choice === 'no') {
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if (in_array($promptType, ['optional_stack_energy_draw', 'optional_stack_energy', 'optional_stack_energy_add_wr_live'], true) && $choice === 'yes') {
        $srcId = $prompt['source_id'] ?? '';
        $slot = findMemberSlot($ownerP, $srcId);
        if ($slot !== null && !empty($ownerP['stage'][$slot])) {
            $placed = nijiStackEnergyUnderMember($ownerP, $ownerP['stage'][$slot], intval($ability['energy'] ?? 1));
            if ($placed > 0) {
                $mName = $ownerP['stage'][$slot]['name_en'] ?? $ownerP['stage'][$slot]['name'] ?? 'Member';
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — placed $placed Energy under [$mName].");
            }
            if ($promptType === 'optional_stack_energy_draw') {
                drawCardsForPlayer($state, $owner, intval($ability['draw'] ?? 2));
            }
            if ($promptType === 'optional_stack_energy_add_wr_live') {
                addFromWaitingRoomFiltered($ownerP, $ability['group'] ?? 'Nijigasaki', 'live', 1);
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'choose_replace_member_hearts') {
        $srcId = $prompt['source_id'] ?? '';
        $slot = findMemberSlot($ownerP, $srcId);
        if ($slot !== null && !empty($ownerP['stage'][$slot])) {
            $ownerP['stage'][$slot]['replaced_hearts'] = [$choice];
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'choice_energy_or_wr_lives_deck_top') {
        $srcName = $prompt['source_name'] ?? 'Member';
        $prefix = $state['players'][$owner]['name'] . ' — [' . $srcName . '] ';
        if ($choice === 'energy') {
            $activated = activateEnergyForPlayer($ownerP, 1);
            $state = addLog($state, $prefix . "activated $activated Energy.");
        } else {
            $lives = array_values(array_filter(
                $ownerP['waiting_room'],
                fn($c) => cardMatchesGroup($c, $ability['group'] ?? 'Nijigasaki', 'live')
            ));
            $maxLives = intval($ability['max_lives'] ?? 2);
            $pick = array_slice($lives, 0, min($maxLives, count($lives)));
            $pickIds = array_map(fn($c) => $c['instance_id'] ?? '', $pick);
            $ownerP['waiting_room'] = array_values(array_filter(
                $ownerP['waiting_room'],
                fn($c) => !in_array($c['instance_id'] ?? '', $pickIds, true)
            ));
            $ownerP['main_deck'] = array_merge(array_reverse($pick), $ownerP['main_deck']);
            $put = count($pick);
            if ($put > 0) {
                $state = addLog($state, $prefix .
                    "put $put Nijigasaki Live card" . ($put === 1 ? '' : 's') .
                    ' from Waiting Room on deck top.');
            } else {
                $state = addLog($state, $prefix .
                    'no Nijigasaki Live cards in Waiting Room for deck top.');
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'optional_wr_members_deck_bottom_milestones') {
        $step = $prompt['step'] ?? 'confirm';
        $maxPick = intval($prompt['max_pick'] ?? $ability['max_pick'] ?? 2);
        $srcName = $prompt['source_name'] ?? 'Member';
        $prefix = $state['players'][$owner]['name'] . ' — [' . $srcName . '] ';

        if ($step === 'confirm') {
            if ($choice === 'no') {
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            if ($choice !== 'yes') {
                throw new Exception('Invalid choice');
            }
            $members = array_values(array_filter(
                $ownerP['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
            ));
            if (empty($members)) {
                throw new Exception('No Members in Waiting Room');
            }
            $state['pending_prompt'] = [
                'type'        => 'optional_wr_members_deck_bottom_milestones',
                'step'        => 'pick_members',
                'owner'       => $owner,
                'responder'   => $owner,
                'source_name' => $srcName,
                'candidates'  => array_map('cardPromptSummary', $members),
                'max_pick'    => $maxPick,
                'milestones'  => $prompt['milestones'] ?? $ability['milestones'] ?? [],
                'ability'     => $ability,
                'prompt'      => "Choose up to $maxPick Member(s) from Waiting Room for deck bottom.",
            ];
            $state['seq']++;
            return $state;
        }

        if ($step === 'pick_members') {
            $ids = $data['card_ids'] ?? [];
            if (count($ids) < 1 || count($ids) > $maxPick) {
                throw new Exception("Choose 1–$maxPick Member(s)");
            }
            $moved = [];
            $totalCost = 0;
            foreach ($ids as $id) {
                foreach ($ownerP['waiting_room'] as $i => $c) {
                    if (($c['instance_id'] ?? '') !== $id) continue;
                    if (($c['card_type'] ?? '') !== 'メンバー') {
                        throw new Exception('Selected cards must be Members');
                    }
                    $moved[] = $c;
                    $totalCost += intval($c['cost'] ?? 0);
                    array_splice($ownerP['waiting_room'], $i, 1);
                    break;
                }
            }
            if (count($moved) !== count($ids)) {
                throw new Exception('Invalid Member selection');
            }
            $ownerP['main_deck'] = array_merge($ownerP['main_deck'], $moved);
            $state = addLog($state, $prefix .
                'put ' . count($moved) . ' Member(s) on deck bottom (combined cost ' . $totalCost . ').');
            $milestones = $prompt['milestones'] ?? $ability['milestones'] ?? [];
            $milestoneKey = (string)$totalCost;
            if (isset($milestones[$milestoneKey])) {
                $eff = $milestones[$milestoneKey];
                $effType = $eff['type'] ?? '';
                if ($effType === 'draw_cards') {
                    $drawn = drawCardsForPlayer($state, $owner, intval($eff['count'] ?? 1));
                    $state = addLog($state, $prefix . "drew $drawn (cost $totalCost milestone).");
                } elseif ($effType === 'blade_bonus') {
                    $state = initLiveModifiers($state);
                    $state['live_modifiers'][$owner]['blade_bonus'] +=
                        intval($eff['amount'] ?? 1);
                    $state = addLog($state, $prefix .
                        'gained +' . intval($eff['amount'] ?? 1) . ' Blade until Live ends (cost $totalCost).');
                } elseif ($effType === 'grant_hearts') {
                    addBonusHeartsToModifier($state, $owner, $eff['hearts'] ?? []);
                    $state = addLog($state, $prefix .
                        'gained All Heart until Live ends (cost $totalCost).');
                } elseif ($effType === 'live_score_bonus') {
                    $state = applyModifierEffect($state, $owner, [
                        'type'   => 'live_score_bonus',
                        'amount' => intval($eff['amount'] ?? 1),
                    ]);
                    $state = addLog($state, $prefix .
                        'gained +1 Live total score until Live ends (cost $totalCost).');
                }
            }
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
    }

    if ($promptType === 'position_change_pick') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        $srcName = $prompt['source_name'] ?? 'Member';
        $prefix = $state['players'][$owner]['name'] . ' — [' . $srcName . '] ';
        if ($choice === 'yes') {
            $srcId = $prompt['source_id'] ?? '';
            if (nijiApplyPositionChangeOffCenter($ownerP, $srcId)) {
                $state = addLog($state, $prefix . 'position-changed off Center.');
            } else {
                $state = addLog($state, $prefix . 'could not Position Change (not in Center).');
            }
        } else {
            $state = addLog($state, $prefix . 'skipped Position Change.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'player_choice_wr_members_deck_bottom') {
        $step = $prompt['step'] ?? 'pick_player';
        $maxPick = intval($prompt['max_pick'] ?? $ability['max_pick'] ?? 2);
        $srcName = $prompt['source_name'] ?? 'Member';

        if ($step === 'pick_player') {
            if (!in_array($choice, ['self', 'opp'], true)) {
                throw new Exception('Choose yourself or your opponent');
            }
            $targetPid = $choice === 'self'
                ? $owner
                : (($owner === 'p1') ? 'p2' : 'p1');
            $targetP = $state['players'][$targetPid];
            $members = array_values(array_filter(
                $targetP['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
            ));
            if (empty($members)) {
                throw new Exception('No Members in that player\'s Waiting Room');
            }
            $state['pending_prompt'] = [
                'type'        => 'player_choice_wr_members_deck_bottom',
                'step'        => 'pick_members',
                'owner'       => $owner,
                'responder'   => $owner,
                'target'      => $targetPid,
                'source_name' => $srcName,
                'candidates'  => array_map('cardPromptSummary', $members),
                'max_pick'    => $maxPick,
                'ability'     => $ability,
                'prompt'      => 'Choose up to ' . $maxPick . ' Member(s) from ' .
                    ($choice === 'self' ? 'your' : 'opponent\'s') . ' Waiting Room for deck bottom.',
            ];
            $state['seq']++;
            return $state;
        }

        if ($step === 'pick_members') {
            $ids = $data['card_ids'] ?? [];
            if (count($ids) < 1 || count($ids) > $maxPick) {
                throw new Exception("Choose 1–$maxPick Member(s)");
            }
            $targetPid = $prompt['target'] ?? $owner;
            $targetP = &$state['players'][$targetPid];
            $moved = [];
            foreach ($ids as $id) {
                foreach ($targetP['waiting_room'] as $i => $c) {
                    if (($c['instance_id'] ?? '') !== $id) continue;
                    if (($c['card_type'] ?? '') !== 'メンバー') {
                        throw new Exception('Selected cards must be Members');
                    }
                    $moved[] = $c;
                    array_splice($targetP['waiting_room'], $i, 1);
                    break;
                }
            }
            if (count($moved) !== count($ids)) {
                throw new Exception('Invalid Member selection');
            }
            $targetP['main_deck'] = array_merge($targetP['main_deck'], $moved);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] put ' . count($moved) . ' Member(s) on the bottom of ' .
                $state['players'][$targetPid]['name'] . '\'s deck.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
    }

    return null;
}

function nijiOnMemberEntered(array $state, string $pid, array $entered): array {
    $p = &$state['players'][$pid];
    $p['members_entered_this_turn'] = intval($p['members_entered_this_turn'] ?? 0) + 1;
    $enterCount = $p['members_entered_this_turn'];
    $enteredCost = intval($entered['cost'] ?? 0);

    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        foreach ($mbr['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') continue;
            $type = $ab['type'] ?? '';
            $mName = $mbr['name_en'] ?? $mbr['name'] ?? 'Member';
            if ($type === 'draw_on_stage_cost_enter' && $enteredCost === intval($ab['cost'] ?? 10)) {
                if (($mbr['instance_id'] ?? '') === ($entered['instance_id'] ?? '')) continue;
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$mName] drew $drawn (cost {$ab['cost']} Member entered).");
            }
            if ($type === 'energy_wait_on_stage_cost_enter'
                && $enteredCost === intval($ab['cost'] ?? 11)
                && (empty($ab['exclude_self']) || ($mbr['instance_id'] ?? '') !== ($entered['instance_id'] ?? ''))) {
                putEnergyFromDeckInWait($p, $state, $pid);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$mName] put 1 Energy into Wait (cost {$ab['cost']} entered).");
            }
            if ($type === 'draw_on_member_enter_count' && $enterCount === intval($ab['enter_count'] ?? 3)) {
                while (count($p['hand']) < intval($ab['draw_to'] ?? 5) && !empty($p['main_deck'])) {
                    drawCardsForPlayer($state, $pid, 1);
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$mName] drew to " . intval($ab['draw_to'] ?? 5) . " (3rd enter).");
            }
        }
    }
    return batch99OnMemberEntered($state, $pid, $entered);
}
