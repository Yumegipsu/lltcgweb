<?php
/**
 * Sunshine (Aqours) starter deck effect handlers.
 * Included by effects.php.
 */

function sSd1EffectTypes(): array {
    return [
        'auto_yell_hearts_per_yell_live',
        'live_start_optional_draw_deck_top',
        'optional_play_wr_empty_slot',
        'activated_discard_add_wr_scored_live',
        'live_start_reveal_group_deck_blade',
        'draw_per_stage_group_discard',
    ];
}

function sSd1IsEffectType(string $type): bool {
    return in_array($type, sSd1EffectTypes(), true);
}

function sSd1CountYellLives(array $yellCards): int {
    $n = 0;
    foreach ($yellCards as $c) {
        if (($c['card_type'] ?? '') === 'ライブ') {
            $n++;
        }
    }
    return $n;
}

function sSd1ResolveAutoYell(
    array $state,
    string $pid,
    array &$member,
    string $slot,
    int $idx,
    array $ab,
    array $yellCards
): array {
    if (($ab['type'] ?? '') !== 'auto_yell_hearts_per_yell_live') {
        return $state;
    }
    $p = &$state['players'][$pid];
    $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
    $liveCnt = sSd1CountYellLives($yellCards);
    if ($liveCnt < 1) {
        return $state;
    }
    $per = intval($ab['heart_count'] ?? 1);
    $max = intval($ab['max_hearts'] ?? 3);
    $total = min($liveCnt * $per, $max);
    $color = $ab['heart_color'] ?? 'red';
    $state = initLiveModifiers($state);
    for ($i = 0; $i < $total; $i++) {
        $state['live_modifiers'][$pid]['bonus_hearts'][] = $color;
    }
    markAbilityUsed($member, $idx);
    $p['stage'][$slot] = $member;
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$mName] gained $total " . ucfirst($color) . " heart(s) from Yell Live cards.");
    return $state;
}

function sSd1ResolveEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (!sSd1IsEffectType($type)) {
        return $state;
    }

    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'live_start_optional_draw_deck_top':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'ssd1_live_start_draw',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Draw 1 card? If you do, put 2 cards from your hand on top of your deck in any order.',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Draw', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Live Start draw.");
            break;

        case 'optional_play_wr_empty_slot':
            $group = $ab['group'] ?? 'Sunshine';
            $maxCost = intval($ab['max_cost'] ?? 2);
            $eligible = array_values(array_filter(
                $p['waiting_room'] ?? [],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
                    && cardMatchesGroup($c, $group, 'member')
                    && intval($c['cost'] ?? 0) <= $maxCost
            ));
            $emptySlots = [];
            foreach (['left', 'center', 'right'] as $s) {
                if (empty($p['stage'][$s])) {
                    $emptySlots[] = $s;
                }
            }
            if (empty($eligible) || empty($emptySlots)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no matching WR Member or empty Stage area.");
                break;
            }
            if (count($eligible) === 1 && count($emptySlots) === 1) {
                $played = $eligible[0];
                foreach ($p['waiting_room'] as $i => $c) {
                    if (($c['instance_id'] ?? '') === ($played['instance_id'] ?? '')) {
                        array_splice($p['waiting_room'], $i, 1);
                        break;
                    }
                }
                $played['active'] = true;
                $played['entered_turn'] = intval($state['turn'] ?? 1);
                if (!empty($ab['block_entered_this_turn'])) {
                    $played['blocks_slot_entries'] = true;
                }
                $p['stage'][$emptySlots[0]] = $played;
                $state = resolveOnEnterAbilities($state, $pid, $played, $emptySlots[0]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] played ' . cardDisplayName($played) . ' from WR.');
                break;
            }
            $state['pending_prompt'] = [
                'type'        => 'ssd1_play_wr_empty',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_id'   => $source['instance_id'] ?? '',
                'source_name' => $name,
                'step'        => 'pick_wr',
                'candidates'  => array_map('cardPromptSummary', $eligible),
                'slots'       => $emptySlots,
                'ability'     => $ab,
                'prompt'      => 'Choose 1 Aqours Member (cost ≤' . $maxCost . ') from your Waiting Room.',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] choose WR Member to play.");
            break;

        case 'live_start_reveal_group_deck_blade':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $group = $ab['group'] ?? 'Sunshine';
            $handCards = array_values(array_filter(
                $p['hand'] ?? [],
                fn($c) => cardMatchesGroup($c, $group, '')
            ));
            if (empty($handCards)) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'ssd1_reveal_group_deck',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'step'          => 'confirm',
                'candidates'    => array_map('cardPromptSummary', $handCards),
                'prompt'        => 'Reveal 1 Aqours card from your hand and put it on the top or bottom of your deck: gain +' .
                    intval($ab['blade_amount'] ?? 1) . ' Blade until this Live ends?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Live Start reveal.");
            break;

        case 'draw_per_stage_group_discard':
            $group = $ab['group'] ?? 'Sunshine';
            $n = countStageGroupMembers($p, $group);
            if ($n < 1) {
                break;
            }
            $drawnCards = drawCardsForPlayerWithEffectLog($state, $pid, $name, $n);
            $drawn = count($drawnCards);
            if ($n > 0 && $drawn === 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] could not draw (deck empty).");
            } elseif ($n > 0 && count($p['hand']) >= $n) {
                return startEffectDiscardHandPrompt(
                    $state,
                    $pid,
                    $name,
                    $n,
                    "Discard $n card(s) to the Waiting Room (drew $drawn)."
                );
            }
            break;
    }

    return $state;
}

function sSd1ResolveActivatedAbility(
    array $state,
    string $pid,
    array &$p,
    array $member,
    $slot,
    array $ab,
    int|string $abilityIdx,
    array $data
): ?array {
    $type = $ab['type'] ?? '';
    if ($type !== 'activated_discard_add_wr_scored_live') {
        return null;
    }

    $name = $member['name_en'] ?? $member['name'] ?? 'Member';
    $need = intval($ab['discard'] ?? 2);
    $ids = $data['discard_ids'] ?? [];
    if (count($ids) !== $need) {
        throw new Exception("Must discard exactly $need card(s)");
    }
    discardFromHandByIds($p, $ids);
    $added = addFromWaitingRoomFiltered(
        $p,
        $ab['group'] ?? 'Sunshine',
        'live',
        1,
        null,
        ['min_score' => intval($ab['min_score'] ?? 1)]
    );
    if ($added < 1) {
        throw new Exception('No scored Aqours Live card in Waiting Room');
    }
    if (!empty($ab['once_per_turn'])) {
        markAbilityUsed($member, $abilityIdx);
    }
    $p['stage'][$slot] = $member;
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$name] discarded $need; added 1 scored Live from WR.");
    return $state;
}

function sSd1ResolvePrompt(
    array $state,
    string $owner,
    array $prompt,
    string $choice,
    array $data
): ?array {
    $promptType = $prompt['type'] ?? '';
    if (!str_starts_with($promptType, 'ssd1_')) {
        return null;
    }

    $ownerP = &$state['players'][$owner];
    $ability = $prompt['ability'] ?? [];

    if ($promptType === 'ssd1_live_start_draw') {
        if ($choice === 'no') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        $drawn = drawCardsForPlayer($state, $owner, intval($ability['draw'] ?? 1));
        $need = intval($ability['deck_top'] ?? 2);
        if ($need > 0 && count($ownerP['hand']) >= $need) {
            unset($state['pending_prompt']);
            $state['seq']++;
            $state = startEffectDiscardHandPrompt(
                $state,
                $owner,
                $prompt['source_name'] ?? 'Live',
                $need,
                "Drew $drawn — choose $need card(s) for deck top (left = top).",
                ['pick_mode' => 'deck_top', 'source_id' => $prompt['source_id'] ?? '']
            );
            return finishLiveStartEffects($state);
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . "] drew $drawn.");
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'ssd1_play_wr_empty') {
        if (($prompt['step'] ?? '') === 'pick_wr') {
            if ($choice === 'no' || $choice === 'cancel' || $choice === 'skip') {
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $cardId = $data['card_id'] ?? $choice;
            $candidateIds = array_values(array_filter(array_map(
                fn($c) => $c['instance_id'] ?? '',
                $prompt['candidates'] ?? []
            )));
            if (!in_array($cardId, $candidateIds, true)) {
                throw new Exception('Choose a matching Member from Waiting Room');
            }
            $state['pending_prompt'] = array_merge($prompt, [
                'step'    => 'pick_slot',
                'card_id' => $cardId,
                'prompt'  => 'Choose an empty Stage area.',
            ]);
            $state['seq']++;
            return $state;
        }
        $cardId = $prompt['card_id'] ?? '';
        $slot = $data['slot'] ?? $choice;
        $slots = $prompt['slots'] ?? [];
        if (!in_array($slot, $slots, true)) {
            throw new Exception('Choose an empty Stage area');
        }
        if (!empty($ownerP['stage'][$slot])) {
            throw new Exception('Choose an empty Stage area');
        }
        $ability = $prompt['ability'] ?? [];
        $cost = intval($prompt['pay_cost'] ?? $ability['cost'] ?? 0);
        if ($cost > 0 && !payEnergyCost($ownerP, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        foreach ($ownerP['waiting_room'] as $i => $c) {
            if (($c['instance_id'] ?? '') !== $cardId) {
                continue;
            }
            if (!cardMatchesWrPick($c, [
                'filter'   => 'member',
                'max_cost' => intval($ability['max_cost'] ?? 2),
                'group'    => $ability['group'] ?? '',
                'subunit'  => $ability['subunit'] ?? '',
            ])) {
                throw new Exception('Choose a matching Member from Waiting Room');
            }
            $played = $c;
            array_splice($ownerP['waiting_room'], $i, 1);
            $played['active'] = true;
            $played['entered_turn'] = intval($state['turn'] ?? 1);
            if (!empty($ability['block_entered_this_turn'])) {
                $played['blocks_slot_entries'] = true;
            }
            $ownerP['stage'][$slot] = $played;
            unset($state['pending_prompt']);
            $state = resolveOnEnterAbilities($state, $owner, $played, $slot);
            break;
        }
        if (!isset($played)) {
            throw new Exception('Invalid Waiting Room card');
        }
        if (isset($prompt['ability_index'], $prompt['source_id'])) {
            $srcId = (string)$prompt['source_id'];
            $abIdx = intval($prompt['ability_index']);
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $srcId) {
                    markAbilityUsed($mbr, $abIdx);
                    break;
                }
            }
            unset($mbr);
        }
        return returnAfterPlacedMemberEnter($state, false, $prompt);
    }

    if ($promptType === 'ssd1_reveal_group_deck') {
        if (($prompt['step'] ?? '') === 'confirm') {
            if ($choice === 'no') {
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishLiveStartEffects($state);
            }
            $state['pending_prompt'] = array_merge($prompt, [
                'step'   => 'pick_hand',
                'prompt' => 'Choose 1 Aqours card from your hand to reveal.',
            ]);
            $state['seq']++;
            return $state;
        }
        if (($prompt['step'] ?? '') === 'pick_hand') {
            $cardId = $data['card_id'] ?? $choice;
            $picked = null;
            foreach ($ownerP['hand'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $cardId) {
                    $picked = $c;
                    array_splice($ownerP['hand'], $i, 1);
                    break;
                }
            }
            if (!$picked) {
                throw new Exception('Invalid hand card');
            }
            $state['pending_prompt'] = array_merge($prompt, [
                'step'          => 'deck_pos',
                'revealed_id'   => $cardId,
                'prompt'        => 'Put ' . cardDisplayName($picked) . ' on the top or bottom of your deck?',
                'choices'       => ['top', 'bottom'],
                'choice_labels' => ['Deck top', 'Deck bottom'],
            ]);
            $state['surveil_stash'] = [$picked];
            $state['seq']++;
            return $state;
        }
        if (($prompt['step'] ?? '') === 'deck_pos') {
            $stash = $state['surveil_stash'] ?? [];
            $card = $stash[0] ?? null;
            if (!$card) {
                throw new Exception('No revealed card');
            }
            if ($choice === 'top') {
                array_unshift($ownerP['main_deck'], $card);
            } else {
                $ownerP['main_deck'][] = $card;
            }
            unset($state['surveil_stash']);
            $state = applyModifierEffect($state, $owner, [
                'type'   => 'blade_bonus',
                'amount' => intval($ability['blade_amount'] ?? 1),
            ]);
            unset($state['pending_prompt']);
            $state['seq']++;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] revealed card to deck; +' .
                intval($ability['blade_amount'] ?? 1) . ' Blade.');
            return finishLiveStartEffects($state);
        }
    }

    return null;
}
