<?php
/**
 * Activated ability handler — extracted from effects.php.
 */

// ─────────────────────────────────────────────
// [Activated] abilities on stage Members
// ─────────────────────────────────────────────


function actionActivateAbility(array $state, string $pid, array $data): array {
    $onEnterWr = !empty($data['_on_enter_wr_activate']);
    if (!$onEnterWr) {
        validateTurn($state, $pid, 'main');
        assertNoPendingPromptForPlayerAction($state, $pid);
    }

    $instanceId = $data['card_id'] ?? '';
    $abilityIdx = intval($data['ability_index'] ?? 0);

    $p = &$state['players'][$pid];
    $found = findActivatedAbilitySource($p, $instanceId);
    if (!$found) throw new Exception('Card not found on Stage or in Waiting Room');

    $slot = $found['slot'] ?? null;
    $zone = $found['zone'] ?? 'stage';
    $wrIndex = $found['wr_index'] ?? null;
    if ($zone === 'stage' && $slot !== null && !empty($p['stage'][$slot])) {
        $member = &$p['stage'][$slot];
    } elseif ($zone === 'waiting_room' && $wrIndex !== null && isset($p['waiting_room'][$wrIndex])) {
        $member = &$p['waiting_room'][$wrIndex];
    } elseif ($zone === 'hand' && isset($found['hand_index'], $p['hand'][$found['hand_index']])) {
        $member = &$p['hand'][$found['hand_index']];
    } else {
        $member = $found['card'];
    }
    mergeCardCatalogFields($member);

    $abilities = $member['abilities'] ?? [];
    if (!isset($abilities[$abilityIdx])) throw new Exception('Invalid ability');

    $ab = $abilities[$abilityIdx];
    $trigger = $ab['trigger'] ?? '';
    if ($zone === 'stage' && spBp2StageMemberAbilitiesSuppressed($state, $pid)) {
        throw new Exception('Member abilities are currently suppressed');
    }
    if ($onEnterWr && $trigger === 'on_enter') {
        $state = logAbilityChain($state, $pid, $member, 'on_enter');
        $state = resolveAbilityEffect($state, $pid, $member, $ab, [
            'slot'  => $slot ?? '',
            'phase' => 'on_enter',
            'from_wr' => true,
        ]);
        markAbilityUsed($member, $abilityIdx);
        return $state;
    }
    if ($trigger !== 'activated') throw new Exception('Not an activated ability');
    if (!empty($ab['from_wr_only']) && $zone !== 'waiting_room') {
        throw new Exception('This ability can only be used from the Waiting Room');
    }
    if (empty($ab['from_wr_only']) && $zone !== 'stage' && empty($ab['from_hand_only'])) {
        if (!$onEnterWr || activatedAbilityRequiresStageSlot($ab)) {
            throw new Exception('Member not on stage');
        }
    }
    if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $abilityIdx)) {
        throw new Exception('Ability already used this turn');
    }

    $wrBlock = activatedAbilityWrBlockReason($p, $ab);
    if ($wrBlock !== null) {
        return fizzleActivatedAbilityNoWr($state, $pid, $member, $wrBlock);
    }
    if (($ab['type'] ?? '') === 'discard_cost_add_live_subunit') {
        $subunit = $ab['require_other_subunit'] ?? '';
        if ($subunit !== '' && !stageHasOtherSubunitMember($p, $subunit, $member['instance_id'] ?? '')) {
            return fizzleActivatedAbilityNoWr($state, $pid, $member,
                'needs another ' . $subunit . ' Member on Stage.');
        }
    }

    $state = logAbilityChain($state, $pid, $member, 'activated');

    $nijiActivated = nijiResolveActivatedEffect($state, $pid, $p, $member, $slot, $ab, $abilityIdx, $data);
    if ($nijiActivated !== null) {
        return $nijiActivated;
    }

    $cl1Activated = hsCl1ResolveActivatedAbility($state, $pid, $p, $member, $slot, $ab, $abilityIdx);
    if ($cl1Activated !== null) {
        return $cl1Activated;
    }

    $nBp5Activated = nBp5ResolveActivatedAbility($state, $pid, $p, $member, $slot, $ab, $abilityIdx, $data);
    if ($nBp5Activated !== null) {
        return $nBp5Activated;
    }

    $sBp5Activated = sBp5ResolveActivatedAbility($state, $pid, $p, $member, $slot, $ab, $abilityIdx, $data);
    if ($sBp5Activated !== null) {
        return $sBp5Activated;
    }

    $sBp6Activated = sBp6ResolveActivatedAbility($state, $pid, $p, $member, $slot, $ab, $abilityIdx, $data);
    if ($sBp6Activated !== null) {
        return $sBp6Activated;
    }

    $sSd1Activated = sSd1ResolveActivatedAbility($state, $pid, $p, $member, $slot, $ab, $abilityIdx, $data);
    if ($sSd1Activated !== null) {
        return $sSd1Activated;
    }

    $spBp5Activated = spBp5ResolveActivatedAbility($state, $pid, $p, $member, $slot, $ab, $abilityIdx, $data);
    if ($spBp5Activated !== null) {
        return $spBp5Activated;
    }

    if (($ab['type'] ?? '') === 'hand_discard_named_blade') {
        if ($zone !== 'hand') throw new Exception('Activate from hand only');
        return hsResolveHandDiscardNamedBlade($state, $pid, $p, $ab, $data);
    }

    if (($ab['type'] ?? '') === 'shuffle_named_from_waiting') {
        $names = $ab['names'] ?? [];
        $max = intval($ab['max_total'] ?? 6);
        $candidates = [];
        foreach ($p['waiting_room'] as &$c) {
            hydrateWrCardForPick($c);
            if (($c['card_type'] ?? '') === 'メンバー' && cardMatchesNames($c, $names)) {
                $candidates[] = $c;
            }
        }
        unset($c);
        if (empty($candidates)) throw new Exception('No matching Members in Waiting Room');
        $pickedIds = $data['wr_ids'] ?? $data['card_ids'] ?? [];
        if (empty($pickedIds)) {
            $p['stage'][$slot] = $member;
            $state['pending_prompt'] = [
                'type'          => 'shuffle_named_from_waiting_pick',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $member['instance_id'] ?? '',
                'source_slot'   => $slot,
                'source_name'   => $member['name_en'] ?? $member['name'] ?? 'Member',
                'ability_index' => $abilityIdx,
                'ability'       => $ab,
                'max_pick'      => $max,
                'candidates'    => array_map('cardPromptSummary', $candidates),
                'prompt'        => "Choose up to $max matching Member card(s) from your Waiting Room to shuffle into your deck.",
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . ($member['name_en'] ?? $member['name']) . '] choose matching Waiting Room Members.');
        } else {
            if (count($pickedIds) > $max) {
                throw new Exception("Choose at most $max matching Member(s)");
            }
            $picked = [];
            $rest = [];
            $seen = [];
            foreach ($p['waiting_room'] as $c) {
                $cid = $c['instance_id'] ?? '';
                if ($cid !== '' && in_array($cid, $pickedIds, true)) {
                    if (isset($seen[$cid])) {
                        throw new Exception('Duplicate Waiting Room card selected');
                    }
                    if (($c['card_type'] ?? '') !== 'メンバー' || !cardMatchesNames($c, $names)) {
                        throw new Exception('Invalid Waiting Room card');
                    }
                    $picked[] = $c;
                    $seen[$cid] = true;
                } else {
                    $rest[] = $c;
                }
            }
            if (empty($picked)) throw new Exception('No matching Members in Waiting Room');
            if (count($picked) !== count($pickedIds)) {
                throw new Exception('Invalid Waiting Room card');
            }
            shuffle($picked);
            $p['waiting_room'] = $rest;
            $p['main_deck'] = array_merge($p['main_deck'], $picked);
            $activated = activateEnergyForPlayer($p, intval($ab['then']['max'] ?? 6));
            markAbilityUsed($member, $abilityIdx);
            $p['stage'][$slot] = $member;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . ($member['name_en'] ?? $member['name']) . '] shuffled ' . count($picked) .
                " Member(s) to deck bottom and activated $activated Energy.");
        }
    } elseif (($ab['type'] ?? '') === 'activated_pay_energy_mill') {
        $cost = intval($ab['cost'] ?? 2);
        if (!payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $n = intval($ab['count'] ?? 10);
        $milled = takeFromMainDeckTop($state, $pid, $n);
        if (!empty($milled)) {
            $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
            $state = spBp5NotifyCardsToWr($state, $pid, $milled);
        }
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] paid $cost Energy; put " .
            count($milled) . ' card(s) from deck top into Waiting Room.');
    } elseif (($ab['type'] ?? '') === 'discard_hand_add_live_from_wr') {
        $need = intval($ab['discard'] ?? 2);
        if (!empty($ab['min_success_score_sum'])) {
            $scoreSum = sumSuccessLiveScores($p);
            $minScore = intval($ab['min_success_score_sum']);
            if ($scoreSum < $minScore) {
                throw new Exception("Need Success Live total score $minScore+ to use this ability (have $scoreSum)");
            }
        }
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== $need) {
            throw new Exception("Must discard exactly $need cards from hand");
        }
        discardFromHandByIds($p, $ids);
        $cfg = wrPickCfgFromAbility($ab);
        if (empty($ab['group'])) {
            $cfg['filter'] = 'live';
            if (isset($ab['min_required_hearts'])) {
                $cfg['min_required_hearts'] = intval($ab['min_required_hearts']);
            }
            if (!empty($ab['min_required_heart_color'])) {
                $cfg['min_required_heart_color'] = (string)$ab['min_required_heart_color'];
            }
        }
        startPickWrToHandPrompt($state, $pid, $member, $slot, $abilityIdx, $ab, $cfg);
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] choose a card from Waiting Room.');
    } elseif (($ab['type'] ?? '') === 'leave_stage_add_from_wr') {
        $cfg = wrPickCfgForLeaveStageAbility($ab);
        startPickWrToHandPrompt($state, $pid, $member, $slot, $abilityIdx, $ab, $cfg, true);
        $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $mName . '] choose a card from Waiting Room.');
    } elseif (($ab['type'] ?? '') === 'reveal_live_opp_discard_or_blade') {
        $revealId = $data['card_id'] ?? '';
        $revealed = null;
        foreach ($p['hand'] as $c) {
            if (($c['instance_id'] ?? '') === $revealId && ($c['card_type'] ?? '') === 'ライブ') {
                $revealed = $c;
                break;
            }
        }
        if (!$revealed) {
            throw new Exception('Choose a Live card from your hand to reveal');
        }
        $opp = ($pid === 'p1') ? 'p2' : 'p1';
        $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
        $state['pending_prompt'] = [
            'type'          => 'reveal_live_opp_discard_or_blade',
            'owner'         => $pid,
            'responder'     => $opp,
            'source_id'     => $member['instance_id'] ?? '',
            'source_slot'   => $slot,
            'ability_index' => $abilityIdx,
            'source_name'   => $mName,
            'revealed'      => cardPromptSummary($revealed),
            'prompt'        => 'Opponent revealed ' . cardDisplayName($revealed) .
                '. Put 1 card from your hand into the Waiting Room? (If not, they gain +4 Blade.)',
            'choices'       => ['yes', 'no'],
            'choice_labels' => ['Yes — Discard 1', 'No — Opponent gains Blade'],
            'ability'       => $ab,
        ];
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$mName] revealed " . cardDisplayName($revealed) . ' from hand.');
    } elseif (($ab['type'] ?? '') === 'wait_pick_member_grant_live_score') {
        if (!empty($ab['center_only']) && $slot !== 'center') {
            throw new Exception('This ability can only be used from the Center position');
        }
        $others = [];
        foreach ($p['stage'] as $s => $mbr) {
            if (!$mbr || $s === $slot) continue;
            $others[] = ['slot' => $s, 'summary' => cardPromptSummary($mbr)];
        }
        if (empty($others)) throw new Exception('No other Member on Stage to put into Wait');
        $state['pending_prompt'] = [
            'type'          => 'wait_pick_member_grant_live_score',
            'owner'         => $pid,
            'responder'     => $pid,
            'source_id'     => $member['instance_id'] ?? '',
            'source_name'   => $member['name_en'] ?? $member['name'] ?? 'Member',
            'candidates'    => $others,
            'amount'        => intval($ab['amount'] ?? 1),
            'prompt'        => 'Choose 1 Member to put into Wait: that Member gains "[Always] +1 Live total score" until this Live ends.',
        ];
        if (!empty($ab['once_per_turn'])) {
            markAbilityUsed($member, $abilityIdx);
        }
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] choose a Member to Wait.');
    } elseif (($ab['type'] ?? '') === 'player_choice_wr_live_deck_bottom_draw') {
        $cost = intval($ab['energy_cost'] ?? 0);
        if ($cost > 0 && !payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $opp = ($pid === 'p1') ? 'p2' : 'p1';
        $selfLives = array_values(array_filter(
            $p['waiting_room'],
            fn($c) => ($c['card_type'] ?? '') === 'ライブ'
        ));
        $oppLives = array_values(array_filter(
            $state['players'][$opp]['waiting_room'],
            fn($c) => ($c['card_type'] ?? '') === 'ライブ'
        ));
        if (empty($selfLives) && empty($oppLives)) {
            throw new Exception('No Live card in either Waiting Room');
        }
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
            'source_name'   => $member['name_en'] ?? $member['name'] ?? 'Member',
            'prompt'        => 'Choose yourself or your opponent: put 1 Live from that player\'s Waiting Room on the bottom of their deck (then draw ' .
                intval($ab['draw'] ?? 1) . ').',
            'choices'       => $choices,
            'choice_labels' => $labels,
            'ability'       => $ab,
        ];
        if (!empty($ab['once_per_turn'])) {
            markAbilityUsed($member, $abilityIdx);
        }
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] activated: choose a player.');
    } elseif (($ab['type'] ?? '') === 'wait_self_discard_draw') {
        waitMember($member, $state);
        $need = intval($ab['discard'] ?? 1);
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== $need) {
            throw new Exception("Must discard exactly $need card(s) from hand");
        }
        discardFromHandByIds($p, $ids);
        $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
        if (!empty($ab['once_per_turn'])) markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] Waited, discarded $need, drew $drawn.");
    } elseif (($ab['type'] ?? '') === 'wait_self_discard_reveal_until') {
        if (!empty($ab['center_only']) && $slot !== 'center') {
            throw new Exception('This ability can only be used from the Center position');
        }
        $filterKey = $data['reveal_filter'] ?? '';
        $choices = $ab['reveal_choices'] ?? [];
        if (!isset($choices[$filterKey])) {
            throw new Exception('Choose Live or Member (cost 10+) to search for');
        }
        waitMember($member, $state);
        $need = intval($ab['discard'] ?? 1);
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== $need) {
            throw new Exception("Must discard exactly $need card(s) from hand");
        }
        discardFromHandByIds($p, $ids);
        $found = revealFromDeckUntil($p, $choices[$filterKey], $state, $pid);
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        if ($found) {
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . ($member['name_en'] ?? $member['name']) . '] revealed ' .
                ($found['name_en'] ?? $found['name']) . ' from deck and added it to hand.');
        } else {
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . ($member['name_en'] ?? $member['name']) . '] searched the deck; no matching card found.');
        }
    } elseif (($ab['type'] ?? '') === 'wait_self_draw_discard_activate') {
        waitMember($member, $state);
        $p['stage'][$slot] = $member;
        $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $mName . '] put self into Wait.');
        $drawnCards = drawCardInstances($p, intval($ab['draw'] ?? 1));
        foreach ($drawnCards as $c) {
            $state = logEffectDraw($state, $pid, $mName, $c,
                [animSpec($c['instance_id'], 'main_deck', 'hand', $pid)]);
        }
        $discardNeed = intval($ab['discard'] ?? 1);
        $activateThen = [
            'type'  => 'activate_members',
            'max'   => intval($ab['activate_members'] ?? 1),
        ];
        if ($discardNeed > 0 && !empty($p['hand'])) {
            $state = startEffectDiscardHandPrompt($state, $pid, $mName, $discardNeed, '', [
                'source_id'     => $member['instance_id'] ?? '',
                'source_slot'   => $slot ?? '',
                'ability_index' => $abilityIdx,
                'ability'       => $ab,
                'then'          => $activateThen,
            ]);
        } else {
            $state = resolveAbilityEffect($state, $pid, $member, $activateThen, ['slot' => $slot ?? '']);
            markAbilityUsed($member, $abilityIdx);
            $p['stage'][$slot] = $member;
            if ($discardNeed > 0 && empty($p['hand'])) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$mName] drew " . count($drawnCards) . ' but had no cards in hand to discard.');
            }
        }
    } elseif (($ab['type'] ?? '') === 'wait_self_draw_discard') {
        waitMember($member, $state);
        $p['stage'][$slot] = $member;
        $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $mName . '] put self into Wait.');
        $state = applyDrawThenDiscard(
            $state,
            $pid,
            $p,
            $mName,
            intval($ab['draw'] ?? 1),
            intval($ab['discard'] ?? 1)
        );
        if (!empty($state['pending_prompt'])) {
            $state['pending_prompt'] = array_merge($state['pending_prompt'], [
                'source_id'     => $member['instance_id'] ?? '',
                'source_slot'   => $slot ?? '',
                'ability_index' => $abilityIdx,
                'ability'       => $ab,
            ]);
        } else {
            markAbilityUsed($member, $abilityIdx);
            $p['stage'][$slot] = $member;
        }
    } elseif (($ab['type'] ?? '') === 'discard_cost_add_live_subunit') {
        $need = max(
            0,
            intval($ab['base_discard'] ?? 3) -
                count($p['success_lives'] ?? []) * intval($ab['reduce_per_success'] ?? 1)
        );
        if ($need > 0) {
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== $need) {
                throw new Exception("Must discard exactly $need card(s) from hand");
            }
            discardFromHandByIds($p, $ids);
        }
        $subunit = $ab['require_other_subunit'] ?? '';
        if ($subunit !== '' && !stageHasOtherSubunitMember($p, $subunit, $member['instance_id'] ?? '')) {
            throw new Exception("Need another $subunit Member on your Stage");
        }
        startPickWrToHandPrompt(
            $state,
            $pid,
            $member,
            $slot,
            $abilityIdx,
            $ab,
            wrPickCfgFromAbility($ab)
        );
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] choose a card from Waiting Room.');
    } elseif (($ab['type'] ?? '') === 'wait_self_add_wr') {
        waitMember($member, $state);
        startPickWrToHandPrompt(
            $state,
            $pid,
            $member,
            $slot,
            $abilityIdx,
            $ab,
            wrPickCfgFromAbility($ab)
        );
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] choose a card from Waiting Room.');
    } elseif (($ab['type'] ?? '') === 'wait_self_choose_heart') {
        $choices = $ab['heart_choices'] ?? ['yellow', 'pink', 'purple'];
        $color = $data['heart_choice'] ?? '';
        if (!in_array($color, $choices, true)) {
            throw new Exception('Must choose a heart color: ' . implode(', ', $choices));
        }
        waitMember($member, $state);
        addBonusHeartsToModifier($state, $pid, [['color' => $color, 'count' => 1]]);
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] Waited; gained 1 $color ♡ until Live ends.");
    } elseif (($ab['type'] ?? '') === 'pay_energy_opp_pick_hand_reveal') {
        $cost = intval($ab['cost'] ?? 2);
        if (!payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        if (empty($p['hand'])) {
            throw new Exception('No cards in hand');
        }
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $opp = ($pid === 'p1') ? 'p2' : 'p1';
        $state['pending_prompt'] = [
            'type'        => 'opp_pick_hidden_hand',
            'owner'       => $pid,
            'responder'   => $opp,
            'source_id'   => $member['instance_id'] ?? '',
            'source_name' => $member['name_en'] ?? $member['name'] ?? 'Member',
            'ability'     => $ab,
            'hand_slots'  => array_map(
                fn($c) => ['instance_id' => $c['instance_id'] ?? ''],
                $p['hand']
            ),
            'prompt'      => 'Choose 1 card from opponent\'s hand without looking.',
        ];
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] paid $cost Energy; opponent chooses a hand card.");
    } elseif (($ab['type'] ?? '') === 'pay_energy_add_from_wr') {
        $cfg = wrPickCfgFromAbility($ab);
        if (wrPickMatchCount($p, $cfg, max(1, intval($ab['count'] ?? 1))) < 1) {
            throw new Exception('No matching card in Waiting Room');
        }
        $cost = intval($ab['cost'] ?? 0);
        if ($cost > 0 && !payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        startPickWrToHandPrompt($state, $pid, $member, $slot, $abilityIdx, $ab, $cfg);
        $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $mName . "] paid $cost Energy; choose a card from Waiting Room.");
    } elseif (($ab['type'] ?? '') === 'pay_energy_energy_wait_from_deck') {
        $cost = intval($ab['cost'] ?? 0);
        if ($cost > 0 && !payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $placed = 0;
        $n = intval($ab['count'] ?? 1);
        for ($i = 0; $i < $n; $i++) {
            if (putEnergyFromDeckInWait($p, $state, $pid)) {
                $placed++;
            }
        }
        if ($placed < 1) {
            throw new Exception('No Energy card in Energy deck');
        }
        if (!empty($ab['once_per_turn'])) {
            markAbilityUsed($member, $abilityIdx);
        }
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] paid $cost Energy; put $placed Energy into Wait.");
    } elseif (($ab['type'] ?? '') === 'pay_energy_draw') {
        $cost = intval($ab['cost'] ?? 0);
        if (!payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
        markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] paid $cost Energy; drew $drawn.");
    } elseif (($ab['type'] ?? '') === 'pay_energy_play_wr_empty') {
        $cost = intval($ab['cost'] ?? 0);
        if ($cost > 0 && countActiveEnergyInZone($p) < $cost) {
            throw new Exception("Need $cost active Energy");
        }
        $cfg = [
            'filter'   => 'member',
            'max_cost' => intval($ab['max_cost'] ?? 2),
            'group'    => $ab['group'] ?? '',
            'subunit'  => $ab['subunit'] ?? '',
        ];
        $eligible = wrCandidatesMatching($p, $cfg);
        $emptySlots = array_values(array_filter(
            ['left', 'center', 'right'],
            fn($targetSlot) => empty($p['stage'][$targetSlot])
        ));
        if (empty($eligible) || empty($emptySlots)) {
            throw new Exception('No matching Member in Waiting Room or no empty Stage area');
        }
        $p['stage'][$slot] = $member;
        $state['pending_prompt'] = [
            'type'          => 'ssd1_play_wr_empty',
            'owner'         => $pid,
            'responder'     => $pid,
            'source_id'     => $member['instance_id'] ?? '',
            'source_name'   => $member['name_en'] ?? $member['name'] ?? 'Member',
            'source_slot'   => $slot,
            'ability_index' => $abilityIdx,
            'step'          => 'pick_wr',
            'candidates'    => array_map('cardPromptSummary', $eligible),
            'slots'         => $emptySlots,
            'pay_cost'      => $cost,
            'ability'       => $ab,
            'prompt'        => 'Pay ' . $cost . ' Energy: choose 1 ' . ($ab['group'] ?? '') .
                ' Member (cost <= ' . intval($ab['max_cost'] ?? 2) . ') from your Waiting Room.',
        ];
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] choose WR Member to play.');
    } elseif (($ab['type'] ?? '') === 'pay_energy_reveal_live_wr_superset') {
        $lives = array_values(array_filter(
            $p['hand'] ?? [],
            fn($c) => ($c['card_type'] ?? '') === 'ライブ'
        ));
        if (empty($lives)) throw new Exception('Need a Live card in hand');
        $state['pending_prompt'] = [
            'type'         => 'pay_energy_reveal_live_wr_superset',
            'owner'        => $pid,
            'responder'    => $pid,
            'source_id'    => $member['instance_id'] ?? '',
            'source_name'  => $member['name_en'] ?? $member['name'] ?? 'Member',
            'ability_idx'  => $abilityIdx,
            'slot'         => $slot,
            'step'         => 'reveal_hand_live',
            'pay_cost'     => intval($ab['cost'] ?? 2),
            'candidates'   => array_map('cardPromptSummary', $lives),
            'prompt'       => 'Pay ' . intval($ab['cost'] ?? 2) .
                ' Energy and reveal 1 Live card from your hand: add 1 Live from Waiting Room whose name contains it?',
        ];
        $p['stage'][$slot] = $member;
    } elseif (($ab['type'] ?? '') === 'pay_leave_stage_play_wr_member') {
        $cost = intval($ab['cost'] ?? 0);
        if ($cost > 0 && !payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $cfg = [
            'group'    => $ab['group'] ?? '',
            'max_cost' => intval($ab['max_cost'] ?? 99),
        ];
        $leavingMember = $member;
        $played = takeWrMemberToStageSlot($p, $cfg, $slot);
        if (!$played) throw new Exception('No matching Member in Waiting Room');
        $p['waiting_room'][] = $leavingMember;
        $state = resolveOnLeaveStageAbilities($state, $pid, $leavingMember);
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($leavingMember['name_en'] ?? $leavingMember['name']) . '] left Stage; played ' .
            cardDisplayName($played) . ' from Waiting Room.');
    } elseif (($ab['type'] ?? '') === 'pay_energy_add_live_zone_from_wr') {
        $cost = intval($ab['cost'] ?? 0);
        if ($cost > 0 && !payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $cfg = [
            'group' => $ab['group'] ?? '',
            'filter' => $ab['filter'] ?? 'live',
        ];
        if (isset($ab['max_live_score'])) {
            $cfg['max_live_score'] = intval($ab['max_live_score']);
        }
        $added = addLiveFromWrToZone($p, $cfg);
        if ($added < 1) throw new Exception('No matching Live card in Waiting Room or Live storage is full');
        $penalty = intval($ab['next_live_set_cap_penalty'] ?? 0);
        if ($penalty > 0) {
            $p['live_set_cap_penalty'] = intval($p['live_set_cap_penalty'] ?? 0) + $penalty;
        }
        if (!empty($ab['once_per_turn'])) markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] paid $cost Energy; placed Live card from Waiting Room into storage.");
    } elseif (($ab['type'] ?? '') === 'discard_play_self_from_wr') {
        $cost = intval($ab['cost'] ?? 0);
        if ($cost > 0 && !payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $need = intval($ab['discard'] ?? 1);
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== $need) {
            throw new Exception("Must discard exactly $need card(s) from hand");
        }
        discardFromHandByIds($p, $ids);
        $targetSlot = $data['slot'] ?? '';
        if (!in_array($targetSlot, ['left', 'center', 'right'], true)) {
            $targetSlot = null;
            foreach (['left', 'center', 'right'] as $s) {
                if (empty($p['stage'][$s])) {
                    $targetSlot = $s;
                    break;
                }
            }
        }
        if ($targetSlot === null || !empty($p['stage'][$targetSlot])) {
            throw new Exception('No empty Stage area');
        }
        $wrIdx = $found['wr_index'] ?? null;
        if ($wrIdx === null) throw new Exception('Card not in Waiting Room');
        $played = $member;
        array_splice($p['waiting_room'], $wrIdx, 1);
        $played['active'] = false;
        $played['entered_turn'] = intval($state['turn'] ?? 1);
        $p['stage'][$targetSlot] = $played;
        $state = resolveOnEnterAbilities($state, $pid, $played, $targetSlot);
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($played['name_en'] ?? $played['name']) . '] entered Stage from Waiting Room.');
    } elseif (($ab['type'] ?? '') === 'discard_hand_activate_pick') {
        $need = intval($ab['discard'] ?? 1);
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== $need) {
            throw new Exception("Must discard exactly $need card(s) from hand");
        }
        discardFromHandByIds($p, $ids);
        $pick = $data['pick'] ?? '';
        if ($pick === 'energy') {
            $activated = activateEnergyForPlayer($p, 1);
            markAbilityUsed($member, $abilityIdx);
            $p['stage'][$slot] = $member;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . ($member['name_en'] ?? $member['name']) . "] discarded $need; activated $activated Energy.");
        } elseif ($pick === 'member') {
            $mid = $data['member_id'] ?? '';
            $activated = 0;
            foreach ($p['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $mid
                    && ($mbr['group'] ?? '') === ($ab['group'] ?? 'Nijigasaki')
                    && memberIsInWait($mbr)) {
                    clearMemberWait($mbr);
                    $activated = 1;
                    break;
                }
            }
            unset($mbr);
            if ($activated < 1) {
                throw new Exception('Choose a Nijigasaki Member in Wait to activate');
            }
            markAbilityUsed($member, $abilityIdx);
            $p['stage'][$slot] = $member;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . ($member['name_en'] ?? $member['name']) . "] discarded $need; activated 1 Member.");
        } else {
            throw new Exception('Choose Energy or Member to activate');
        }
    } elseif (($ab['type'] ?? '') === 'discard_activate_energy_if_group_entered') {
        $need = intval($ab['discard'] ?? 1);
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== $need) {
            throw new Exception("Must discard exactly $need card(s) from hand");
        }
        discardFromHandByIds($p, $ids);
        $turn = intval($state['turn'] ?? 1);
        $group = $ab['group'] ?? '';
        $activated = 0;
        if (groupMemberEnteredThisTurn($p, $group, $turn)) {
            $activated = activateEnergyForPlayer($p, intval($ab['count'] ?? 2));
        }
        markAbilityUsed($member, $abilityIdx);
        if ($zone === 'stage') {
            $p['stage'][$slot] = $member;
        }
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] discarded $need; activated $activated Energy.");
    } elseif (($ab['type'] ?? '') === 'wait_self_energy_wait') {
        $energyCost = intval($ab['energy_cost'] ?? 0);
        if ($energyCost > 0 && !payEnergyCost($p, $energyCost)) {
            throw new Exception("Need $energyCost active Energy");
        }
        waitMember($member, $state);
        $n = intval($ab['count'] ?? 1);
        for ($i = 0; $i < $n; $i++) {
            putEnergyFromDeckInWait($p, $state, $pid);
        }
        if (!empty($ab['once_per_turn'])) markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] Waited self; put $n Energy into Wait.");
    } elseif (($ab['type'] ?? '') === 'activated_swap_area_member') {
        $cost = intval($ab['cost'] ?? 0);
        $energyCost = intval($ab['energy_cost'] ?? 0);
        if ($energyCost > 0 && !payEnergyCost($p, $energyCost)) {
            throw new Exception("Need $energyCost active Energy");
        }
        if ($cost > 0 && !payEnergyCost($p, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $slots = ['left', 'center', 'right'];
        $choices = array_values(array_filter($slots, fn($s) => $s !== $slot));
        $state['pending_prompt'] = [
            'type'          => 'activated_swap_area_pick',
            'owner'         => $pid,
            'responder'     => $pid,
            'source_id'     => $member['instance_id'] ?? '',
            'source_slot'   => $slot,
            'ability_index' => $abilityIdx,
            'source_name'   => $member['name_en'] ?? $member['name'] ?? 'Member',
            'choices'       => $choices,
            'choice_labels' => array_map(fn($s) => ucfirst($s) . ' area', $choices),
            'prompt'        => 'Choose an area to move this Member to (swap with occupant if any).',
            'ability'       => $ab,
        ];
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . '] choose area to swap.');
    } elseif (($ab['type'] ?? '') === 'activated_discard_trigger_on_enter') {
        $handId = $data['hand_card_id'] ?? '';
        $discarded = null;
        foreach ($p['hand'] as $i => $c) {
            if (($c['instance_id'] ?? '') === $handId) {
                if (!cardMatchesGroup($c, $ab['group'] ?? '', 'member')) {
                    throw new Exception('Must discard a matching Member from hand');
                }
                if (intval($c['cost'] ?? 0) > intval($ab['max_cost'] ?? 4)) {
                    throw new Exception('Member cost too high');
                }
                $discarded = $c;
                array_splice($p['hand'], $i, 1);
                break;
            }
        }
        if (!$discarded) throw new Exception('Choose a Member card from your hand');
        $p['waiting_room'][] = $discarded;
        $onEnter = getAbilitiesByTrigger($discarded, 'on_enter');
        if (empty($onEnter)) {
            throw new Exception('Discarded Member has no [On Enter] abilities');
        }
        if (count($onEnter) === 1) {
            markAbilityUsed($member, $abilityIdx);
            $p['stage'][$slot] = $member;
            $state = resolveAbilityEffect($state, $pid, $discarded, $onEnter[0], ['phase' => 'on_enter']);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . ($member['name_en'] ?? $member['name']) . '] triggered [On Enter] of ' .
                cardDisplayName($discarded) . '.');
        } else {
            $state['pending_prompt'] = [
                'type'          => 'activated_pick_on_enter_ability',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $member['instance_id'] ?? '',
                'source_slot'   => $slot,
                'ability_index' => $abilityIdx,
                'discarded_id'  => $discarded['instance_id'] ?? '',
                'source_name'   => $member['name_en'] ?? $member['name'] ?? 'Member',
                'choices'       => array_map(fn($i) => (string)$i, array_keys($onEnter)),
                'choice_labels' => array_map(
                    fn($i) => 'Ability ' . ($i + 1),
                    array_keys($onEnter)
                ),
                'on_enter'      => $onEnter,
                'prompt'        => 'Choose 1 [On Enter] ability to activate from the discarded Member.',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . ($member['name_en'] ?? $member['name']) . '] choose [On Enter] to trigger.');
        }
    } elseif (($ab['type'] ?? '') === 'wait_swap_wr_member_center') {
        if (!empty($ab['center_only']) && $slot !== 'center') {
            throw new Exception('This ability can only be used from the Center position');
        }
        if (empty($p['hand'])) {
            throw new Exception('Need at least 1 card in hand to discard');
        }
        $group = $ab['group'] ?? '';
        $bonus = intval($ab['cost_bonus'] ?? 2);
        if (!waitSwapHasValidTarget($p, $group, $bonus, $slot)) {
            throw new Exception('No valid Stage Member and Waiting Room swap available');
        }
        waitMember($member, $state);
        $p['stage'][$slot] = $member;
        $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
        $state['pending_prompt'] = [
            'type'          => 'wait_swap_wr_member_center',
            'step'          => 'discard_hand',
            'owner'         => $pid,
            'responder'     => $pid,
            'source_id'     => $member['instance_id'] ?? '',
            'source_slot'   => $slot,
            'ability_index' => $abilityIdx,
            'source_name'   => $mName,
            'ability'       => $ab,
            'prompt'        => 'Discard 1 card from your hand to the Waiting Room.',
        ];
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$mName] put self into Wait; discard 1 from hand.");
    } elseif (($ab['type'] ?? '') === 'draw_and_discard') {
        $state = applyDrawThenDiscard(
            $state,
            $pid,
            $p,
            $member['name_en'] ?? $member['name'] ?? 'Member',
            intval($ab['draw'] ?? 1),
            intval($ab['discard'] ?? 1)
        );
        if (empty($state['pending_prompt'])) {
            markAbilityUsed($member, $abilityIdx);
            persistActivatedMemberAfterUse($p, $member, $slot, $zone, $wrIndex);
        }
    } elseif (($ab['type'] ?? '') === 'discard_add_from_wr') {
        $need = intval($ab['discard'] ?? 2);
        if (count($p['hand'] ?? []) < $need) {
            throw new Exception("Need at least $need card(s) in hand");
        }
        $cfg = wrPickCfgFromAbility($ab);
        if (wrPickMatchCount($p, $cfg, max(1, intval($ab['count'] ?? 1))) < 1) {
            return fizzleActivatedAbilityNoWr($state, $pid, $member, 'no matching card in Waiting Room.');
        }
        if ($zone === 'stage' && $slot !== null) {
            $p['stage'][$slot] = $member;
        }
        $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
        $state = startEffectDiscardHandPrompt($state, $pid, $mName, $need, '', [
            'source_id'     => $member['instance_id'] ?? '',
            'source_slot'   => $slot ?? '',
            'ability_index' => $abilityIdx,
            'ability'       => $ab,
        ]);
        return $state;
    } elseif (plMuseGapIsEffectType($ab['type'] ?? '')) {
        if (($ab['type'] ?? '') === 'activated_wait_opp_reduce_cost_per_group') {
            $baseEnergy = intval($ab['energy_cost'] ?? 4);
            $reduce = plMuseGapCountDistinctGroupsOnStage($p);
            $cost = max(0, $baseEnergy - $reduce);
            if ($cost > 0 && !payEnergyCost($p, $cost)) {
                throw new Exception("Need $cost active Energy");
            }
        }
        $state = plMuseGapResolveEffect($state, $pid, $member, $ab, ['slot' => $slot ?? '']);
        if (empty($state['pending_prompt'])) {
            markAbilityUsed($member, $abilityIdx);
            persistActivatedMemberAfterUse($p, $member, $slot, $zone, $wrIndex);
        }
    } else {
        throw new Exception('Ability type not implemented');
    }

    if ($onEnterWr && $zone === 'waiting_room' && isset($p['stage'][''])) {
        unset($p['stage']['']);
    }

    $state['seq']++;
    return $state;
}
