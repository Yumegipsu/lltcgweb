<?php
/**
 * Interactive prompt resolution dispatcher — extracted from effects.php.
 */

function applyWaitGroupMemberDrawDiscard(
    array $state,
    string $owner,
    array $prompt,
    string $memberId
): array {
    $ability = $prompt['ability'] ?? [];
    $group = $ability['group'] ?? $prompt['group'] ?? 'Nijigasaki';
    $ownerP = &$state['players'][$owner];
    $found = false;
    foreach ($ownerP['stage'] as &$mbr) {
        if ($mbr && ($mbr['instance_id'] ?? '') === $memberId
            && ($mbr['group'] ?? '') === $group) {
            waitMember($mbr, $state);
            $found = true;
            break;
        }
    }
    unset($mbr);
    if (!$found) {
        throw new Exception('Choose a ' . $group . ' Member on Stage');
    }
    $srcName = $prompt['source_name'] ?? 'Member';
    $drawnCards = drawCardInstances($ownerP, intval($ability['draw'] ?? 1));
    foreach ($drawnCards as $c) {
        $state = logEffectDraw($state, $owner, $srcName, $c,
            [animSpec($c['instance_id'], 'main_deck', 'hand', $owner)]);
    }
    $drawn = count($drawnCards);
    $state = addLog($state, $state['players'][$owner]['name'] .
        " — [$srcName] Waited Member; drew $drawn.");
    unset($state['pending_prompt']);
    $need = intval($ability['discard'] ?? 1);
    if ($need > 0 && !empty($ownerP['hand'])) {
        return startEffectDiscardHandPrompt($state, $owner, $srcName, $need);
    }
    $state['seq']++;
    return finishPromptEffects($state);
}

function applyOptionalWaitGroupMemberBlade(
    array $state,
    string $owner,
    array $prompt,
    string $memberId
): array {
    $ability = $prompt['ability'] ?? [];
    $group = $ability['group'] ?? $prompt['group'] ?? 'Nijigasaki';
    $ownerP = &$state['players'][$owner];
    $found = false;
    foreach ($ownerP['stage'] as &$mbr) {
        if ($mbr && ($mbr['instance_id'] ?? '') === $memberId
            && ($mbr['group'] ?? '') === $group
            && !memberIsInWait($mbr)) {
            waitMember($mbr, $state);
            $found = true;
            break;
        }
    }
    unset($mbr);
    if (!$found) {
        throw new Exception('Choose a ' . $group . ' Member on Stage');
    }
    $amt = intval($prompt['amount'] ?? $ability['amount'] ?? 2);
    $srcName = $prompt['source_name'] ?? 'Member';
    $srcId = $prompt['source_id'] ?? '';
    if ($srcId !== '') {
        foreach ($ownerP['stage'] as &$mbr) {
            if ($mbr && ($mbr['instance_id'] ?? '') === $srcId) {
                $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $amt;
                break;
            }
        }
        unset($mbr);
    } else {
        $state = applyModifierEffect($state, $owner, ['type' => 'blade_bonus', 'amount' => $amt]);
    }
    $state = addLog($state, $state['players'][$owner]['name'] .
        " — [$srcName] Waited Member; gained +$amt Blade until Live ends.");
    unset($state['pending_prompt']);
    $state['seq']++;
    return finishLiveStartEffects($state);
}

function applyAutoOnAllyWaitActivateBlade(array $state, string $owner, array $prompt): array {
    $ownerP = &$state['players'][$owner];
    $waitedId = (string)($prompt['waited_id'] ?? '');
    $amt = intval($prompt['amount'] ?? 2);
    $slot = $prompt['source_slot'] ?? '';
    $idx = intval($prompt['ability_index'] ?? -1);
    $srcName = $prompt['source_name'] ?? 'Member';
    if ($slot !== '' && $idx >= 0 && !empty($ownerP['stage'][$slot])) {
        markAbilityUsed($ownerP['stage'][$slot], $idx);
    }
    foreach ($ownerP['stage'] as &$wMbr) {
        if ($wMbr && ($wMbr['instance_id'] ?? '') === $waitedId) {
            clearMemberWait($wMbr);
            $wMbr['live_blade_bonus'] = intval($wMbr['live_blade_bonus'] ?? 0) + $amt;
            $src = ($slot !== '' && !empty($ownerP['stage'][$slot])) ? $ownerP['stage'][$slot] : null;
            if (($src['group'] ?? '') === 'Nijigasaki'
                || (($prompt['ability']['group'] ?? '') === 'Nijigasaki')) {
                $ownerP['_niji_turn_flags']['activated_wait_member'] = true;
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] activated ' .
                ($wMbr['name_en'] ?? $wMbr['name'] ?? 'Member') .
                " and granted +$amt Blade (ally Wait Auto).");
            break;
        }
    }
    unset($wMbr);
    return $state;
}

/**
 * Apply a full Stage formation map (slot → instance_id) for optional_formation_change_group.
 * Empty slots stay empty; every Stage Member must appear exactly once.
 */
function applyOptionalFormationChangeGroup(
    array $state,
    string $owner,
    array $prompt,
    array $assignments
): array {
    $ownerP = &$state['players'][$owner];
    $before = [];
    $members = [];
    foreach ($ownerP['stage'] as $slot => $mbr) {
        if (!$mbr) {
            continue;
        }
        $iid = (string)($mbr['instance_id'] ?? '');
        if ($iid === '') {
            continue;
        }
        $before[$iid] = (string)$slot;
        $members[$iid] = $mbr;
    }
    $used = [];
    foreach (['left', 'center', 'right'] as $slot) {
        $id = (string)($assignments[$slot] ?? '');
        if ($id === '') {
            continue;
        }
        if (!isset($members[$id])) {
            throw new Exception('Invalid formation assignment');
        }
        if (isset($used[$id])) {
            throw new Exception('Cannot place the same Member in two areas');
        }
        $used[$id] = true;
    }
    foreach ($members as $id => $_) {
        if (!isset($used[$id])) {
            throw new Exception('Every Stage Member must be assigned to an area');
        }
    }

    $markSrc = ['group' => (string)(($prompt['ability'] ?? [])['group'] ?? '')];
    if (function_exists('spBp2MarkEffectAreaMove')) {
        spBp2MarkEffectAreaMove($state, $markSrc);
    }

    foreach (['left', 'center', 'right'] as $slot) {
        $ownerP['stage'][$slot] = null;
    }
    foreach (['left', 'center', 'right'] as $slot) {
        $id = (string)($assignments[$slot] ?? '');
        if ($id !== '' && isset($members[$id])) {
            $ownerP['stage'][$slot] = $members[$id];
        }
    }

    // Drop the formation prompt before Auto area-move skills open their own.
    unset($state['pending_prompt']);

    $state = addLog($state, $state['players'][$owner]['name'] .
        ' — [' . ($prompt['source_name'] ?? 'Live') . '] formation-changed Stage Members.');

    foreach (['left', 'center', 'right'] as $slot) {
        $mbr = $state['players'][$owner]['stage'][$slot] ?? null;
        if (!$mbr) {
            continue;
        }
        $iid = (string)($mbr['instance_id'] ?? '');
        if ($iid === '') {
            continue;
        }
        $from = $before[$iid] ?? $slot;
        if ($from === $slot) {
            continue;
        }
        $state = resolveAutoAreaMoveAbilities($state, $owner, $iid, $from);
        if (!empty($state['pending_prompt'])) {
            if (function_exists('spBp2ClearEffectAreaMove')) {
                spBp2ClearEffectAreaMove($state);
            }
            return $state;
        }
    }
    if (function_exists('spBp2ClearEffectAreaMove')) {
        spBp2ClearEffectAreaMove($state);
    }
    return $state;
}

// actionResolvePrompt — completes pending_prompt from client resolve_prompt actions

function actionResolvePrompt(array $state, string $pid, array $data): array {
    $out = actionResolvePromptDispatch($state, $pid, $data);
    unset($out['_mod_source']);
    return $out;
}

function actionResolvePromptDispatch(array $state, string $pid, array $data): array {
    $prompt = $state['pending_prompt'] ?? null;
    // Stale/duplicate submission: the prompt this player answered was already
    // resolved (poll/submit race or double-click), or it now belongs to the
    // opponent. Treat as an idempotent no-op so the client silently resyncs
    // instead of surfacing a "Not your prompt to answer" error toast.
    if (!$prompt || ($prompt['responder'] ?? '') !== $pid) {
        $state['_resolve_prompt_noop'] = true;
        return $state;
    }

    if (array_key_exists('discard_ids', $data)) {
        $data['discard_ids'] = normalizeDiscardIds($data['discard_ids']);
    }

    $choice = $data['choice'] ?? '';
    $promptType = $prompt['type'] ?? '';
    $ability = $prompt['ability'] ?? [];
    $owner = $prompt['owner'] ?? $pid;
    $srcId = (string)($prompt['source_id'] ?? $prompt['source_instance_id'] ?? '');
    if ($srcId !== '') {
        $found = findSourceCard($state, $owner, $srcId);
        $state['_mod_source'] = (is_array($found) && ($found['instance_id'] ?? '') !== '')
            ? $found
            : ['instance_id' => $srcId];
    }
    $ownerP = &$state['players'][$owner];

    $nijiPrompt = nijiHandlePrompt($state, $promptType, $prompt, $choice, $data);
    if ($nijiPrompt !== null) {
        return $nijiPrompt;
    }

    if (in_array($promptType, [
        'reveal_hand_named_stack_under',
        'play_stacked_member_from_under',
        'pl_muse_stack_heart_choice',
        'mandatory_discard_group_branch',
        'mandatory_discard_color_threshold_reveal5',
        'maki_reveal5_choose_color',
        'maki_reveal5_pick_mus',
    ], true)) {
        // Sayaka-style named stack (has names) is resolved by hs_pb1, not μ's Kotori path.
        $museStack = $promptType !== 'reveal_hand_named_stack_under'
            || empty($prompt['ability']['names'] ?? ($ability['names'] ?? []));
        if ($museStack) {
            $plMuseEarly = plMuseGapResolvePrompt($state, $owner, $prompt, $choice, $data);
            if ($plMuseEarly !== null) {
                return $plMuseEarly;
            }
        }
    }

    $hsPrompt = hsResolveHasunosoraPrompt($state, $owner, $prompt, $choice, $data);
    if ($hsPrompt !== null) {
        return $hsPrompt;
    }

    $hsPb1Prompt = hsPb1ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($hsPb1Prompt !== null) {
        return $hsPb1Prompt;
    }

    $hsCl1Prompt = hsCl1ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($hsCl1Prompt !== null) {
        return $hsCl1Prompt;
    }

    $nBp5Prompt = nBp5ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($nBp5Prompt !== null) {
        return $nBp5Prompt;
    }

    $sBp5Prompt = sBp5ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($sBp5Prompt !== null) {
        return $sBp5Prompt;
    }

    $sBp6Prompt = sBp6ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($sBp6Prompt !== null) {
        return $sBp6Prompt;
    }

    $sSd1Prompt = sSd1ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($sSd1Prompt !== null) {
        return $sSd1Prompt;
    }

    $spBp5Prompt = spBp5ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($spBp5Prompt !== null) {
        return $spBp5Prompt;
    }

    $plMusePrompt = plMuseGapResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($plMusePrompt !== null) {
        return $plMusePrompt;
    }

    $plSpSd2Prompt = plSpSd2ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($plSpSd2Prompt !== null) {
        return $plSpSd2Prompt;
    }

    $prVol9Prompt = prVol9ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($prVol9Prompt !== null) {
        return $prVol9Prompt;
    }

    $bp7Prompt = bp7ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($bp7Prompt !== null) {
        return $bp7Prompt;
    }

    $batch99Prompt = batch99ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($batch99Prompt !== null) {
        return $batch99Prompt;
    }

    $spBp2Prompt = spBp2ResolvePrompt($state, $owner, $prompt, $choice, $data);
    if ($spBp2Prompt !== null) {
        return $spBp2Prompt;
    }

    if ($promptType === 'live_success_order_sources') {
        $cands = is_array($prompt['candidates'] ?? null) ? $prompt['candidates'] : [];
        $expected = [];
        foreach ($cands as $c) {
            if (!is_array($c)) {
                continue;
            }
            $id = (string)($c['instance_id'] ?? '');
            if ($id !== '') {
                $expected[] = $id;
            }
        }
        $orderIds = [];
        if (is_array($data['card_ids'] ?? null)) {
            foreach ($data['card_ids'] as $id) {
                $id = (string)$id;
                if ($id !== '') {
                    $orderIds[] = $id;
                }
            }
        } elseif (is_array($data['order_ids'] ?? null)) {
            foreach ($data['order_ids'] as $id) {
                $id = (string)$id;
                if ($id !== '') {
                    $orderIds[] = $id;
                }
            }
        }
        if (count($orderIds) !== count($expected)) {
            throw new Exception('Order every Live Success card');
        }
        $sortedExpected = $expected;
        $sortedPicked = $orderIds;
        sort($sortedExpected);
        sort($sortedPicked);
        if ($sortedPicked !== $sortedExpected) {
            throw new Exception('Order must include each Live Success card exactly once');
        }
        if (!is_array($state['_live_success_ctx'] ?? null)) {
            $state['_live_success_ctx'] = ['pid' => $owner];
        }
        $state['_live_success_ctx']['order_ids'] = $orderIds;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — chose Live Success activation order.');
        unset($state['pending_prompt']);
        $state['seq'] = intval($state['seq'] ?? 0) + 1;
        return finishLiveSuccessEffects($state);
    }

    if ($promptType === 'live_start_order_sources') {
        $cands = is_array($prompt['candidates'] ?? null) ? $prompt['candidates'] : [];
        $expected = [];
        foreach ($cands as $c) {
            if (!is_array($c)) {
                continue;
            }
            $id = (string)($c['instance_id'] ?? '');
            if ($id !== '') {
                $expected[] = $id;
            }
        }
        $orderIds = [];
        if (is_array($data['card_ids'] ?? null)) {
            foreach ($data['card_ids'] as $id) {
                $id = (string)$id;
                if ($id !== '') {
                    $orderIds[] = $id;
                }
            }
        } elseif (is_array($data['order_ids'] ?? null)) {
            foreach ($data['order_ids'] as $id) {
                $id = (string)$id;
                if ($id !== '') {
                    $orderIds[] = $id;
                }
            }
        }
        if (count($orderIds) !== count($expected)) {
            throw new Exception('Order every Live Start card');
        }
        $sortedExpected = $expected;
        $sortedPicked = $orderIds;
        sort($sortedExpected);
        sort($sortedPicked);
        if ($sortedPicked !== $sortedExpected) {
            throw new Exception('Order must include each Live Start card exactly once');
        }
        $orderMap = is_array($state['_live_start_order'] ?? null) ? $state['_live_start_order'] : [];
        $orderMap[$owner] = $orderIds;
        $state['_live_start_order'] = $orderMap;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — chose Live Start activation order.');
        unset($state['pending_prompt']);
        $state['seq'] = intval($state['seq'] ?? 0) + 1;
        $state['_live_start_resume_from'] = $owner;
        return resumeLiveStartEffectPhase($state);
    }

    if ($promptType === 'surveil_arrange') {
        $looked = $state['surveil_stash'] ?? [];
        if (empty($looked)) throw new Exception('No surveil cards');
        $topIds = $data['top_ids'] ?? [];
        $wrIds = $data['wr_ids'] ?? [];
        $returnAll = !empty($prompt['return_all']);
        if ($returnAll) {
            if (!empty($wrIds)) {
                throw new Exception('All looked cards must be returned to the top of the deck');
            }
            $wrIds = [];
        }
        $allIds = array_column($looked, 'instance_id');
        $picked = array_merge($topIds, $wrIds);
        sort($allIds);
        $sortedPicked = $picked;
        sort($sortedPicked);
        if ($sortedPicked !== $allIds) {
            throw new Exception($returnAll
                ? 'Must put every looked card back on top of the deck'
                : 'Must assign every looked card to deck top or Waiting Room');
        }
        $chain = $state['_surveil_chain'] ?? null;
        $arrangeTarget = $chain['target'] ?? $owner;
        applySurveilArrangement($state['players'][$arrangeTarget], $looked, $topIds, $wrIds);
        unset($state['surveil_stash'], $state['pending_prompt'], $state['_surveil_chain']);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] arranged ' . count($looked) . ' looked card(s).');
        if ($chain && ($chain['type'] ?? '') === 'reveal_top_live_score') {
            $source = findSourceCard($state, $owner, $chain['source_id'] ?? '');
            if ($source) {
                $state = revealDeckTopLiveScore(
                    $state,
                    $owner,
                    $source,
                    intval($chain['score_amount'] ?? 1)
                );
            }
        }
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'effect_discard_hand') {
        $need = intval($prompt['count'] ?? 1);
        $isDeckTop = ($prompt['pick_mode'] ?? '') === 'deck_top';
        $discardIds = $isDeckTop ? ($data['card_ids'] ?? []) : ($data['discard_ids'] ?? []);
        if (count($discardIds) !== $need) {
            throw new Exception("Must select exactly $need card(s)");
        }
        $srcName = $prompt['source_name'] ?? 'Member';
        if ($isDeckTop) {
            $picked = [];
            foreach ($discardIds as $id) {
                foreach ($ownerP['hand'] as $i => $c) {
                    if (($c['instance_id'] ?? '') === $id) {
                        $picked[] = $c;
                        array_splice($ownerP['hand'], $i, 1);
                        break;
                    }
                }
            }
            if (count($picked) !== $need) {
                throw new Exception('Invalid hand cards selected');
            }
            $ownerP['main_deck'] = array_merge(array_reverse($picked), $ownerP['main_deck']);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$srcName] put $need card(s) on deck top.");
        } else {
            $moved = discardHandCardsByIds($ownerP, $discardIds, $state, $owner);
            foreach ($moved as $c) {
                $state = logEffectPutWr($state, $owner, $srcName, $c,
                    [animSpec($c['instance_id'], 'hand', 'waiting_room', $owner)]);
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
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
        if (($prompt['ability']['type'] ?? '') === 'discard_add_from_wr') {
            $ab = $prompt['ability'] ?? [];
            $srcId = (string)($prompt['source_id'] ?? '');
            $abIdx = intval($prompt['ability_index'] ?? 0);
            $slot = (string)($prompt['source_slot'] ?? 'center');
            $member = null;
            foreach ($ownerP['stage'] as $s => &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $srcId) {
                    $member = &$mbr;
                    $slot = (string)$s;
                    break;
                }
            }
            unset($mbr);
            if ($member) {
                startPickWrToHandPrompt(
                    $state,
                    $owner,
                    $member,
                    $slot,
                    $abIdx,
                    $ab,
                    wrPickCfgFromAbility($ab),
                    false,
                    max(1, intval($ab['count'] ?? 1))
                );
                $state['seq']++;
                return $state;
            }
            return finishPromptEffects($state);
        }
        $then = $prompt['then'] ?? null;
        if ($then) {
            $source = findSourceCard($state, $owner, $prompt['source_id'] ?? '') ?? [
                'name_en' => $srcName,
                'name'    => $srcName,
            ];
            $state = resolveAbilityEffect($state, $owner, $source, $then, ['phase' => 'on_enter']);
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
        }
        return finishPromptEffects($state);
    }

    if ($promptType === 'optional_live_start') {
        if ($choice === 'skip' || $choice === 'cancel') {
            $choice = 'no';
        }
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        $state = markLiveStartOptionalResolved(
            $state,
            $owner,
            $prompt['source_id'] ?? '',
            intval($prompt['ability_index'] ?? 0)
        );
        if ($choice === 'yes') {
            $ab = $prompt['ability'] ?? [];
            $need = intval($prompt['discard_count'] ?? $ab['discard'] ?? 0);
            $maxDiscard = intval($prompt['max_discard'] ?? $ab['max_discard'] ?? 0);
            if (($ab['type'] ?? '') === 'optional_discard_named' && !empty($ab['exact_total'])) {
                $need = intval($ab['exact_total']);
                $maxDiscard = 0;
            }
            $discardIds = $data['discard_ids'] ?? [];
            if ($maxDiscard > 0) {
                if (count($discardIds) > $maxDiscard) {
                    throw new Exception("Must select at most $maxDiscard card(s) to discard");
                }
            } elseif ($need > 0 && count($discardIds) !== $need) {
                throw new Exception("Must select exactly $need card(s) to discard");
            }
            if (($ab['type'] ?? '') === 'optional_discard_same_group' && $need > 0) {
                if (!validateSameGroupDiscard($ownerP, $discardIds, $need)) {
                    throw new Exception("Must discard exactly $need cards sharing the same unit name");
                }
            }
            $sourceId = $prompt['source_id'] ?? '';
            $source = findLiveStartSourceCard($state, $owner, $sourceId);
            if (!$source) throw new Exception('Source card not found on Stage or in Live storage');
            if (($ab['type'] ?? '') === 'optional_return_member_energy') {
                unset($state['pending_prompt']);
                $candidates = stageMembersWithStackedEnergy($ownerP);
                if (empty($candidates)) {
                    $state = addLog($state, $state['players'][$owner]['name'] .
                        ' — [' . ($prompt['source_name'] ?? 'Live') . '] no Energy stacked under Stage Members.');
                    $state['seq']++;
                    return finishLiveStartEffects($state);
                }
                $state['pending_prompt'] = [
                    'type'          => 'pick_member_return_energy',
                    'owner'         => $owner,
                    'responder'     => $owner,
                    'source_id'     => $sourceId,
                    'source_name'   => $prompt['source_name'] ?? 'Live',
                    'prompt'        => 'Choose a Stage Member and how many stacked Energy to return to your Energy deck.',
                    'members'       => array_map(function ($row) {
                        $m = $row['member'];
                        return [
                            'instance_id'   => $m['instance_id'] ?? '',
                            'slot'          => $row['slot'],
                            'name'          => $m['name_en'] ?? $m['name'] ?? 'Member',
                            'stacked_count' => countMemberStackedEnergy($ownerP, $m),
                        ];
                    }, $candidates),
                    'ability'       => $ab,
                ];
                $state['seq']++;
                return $state;
            }
            if (($ab['type'] ?? '') === 'optional_discard_prompt'
                || ($ab['type'] ?? '') === 'optional_discard_blade_named_extra') {
                unset($state['pending_prompt']);
                $promptAbility = ($ab['type'] ?? '') === 'optional_discard_blade_named_extra'
                    ? [
                        'discard' => 1,
                        'then'    => [
                            'type'         => 'blade_bonus_named_extra',
                            'amount'       => intval($ab['amount'] ?? 1),
                            'extra_amount' => intval($ab['extra_amount'] ?? 1),
                            'named'        => $ab['named'] ?? '',
                        ],
                    ]
                    : $ab;
                $state = resolveOptionalDiscardPromptChoice($state, $owner, [
                    'ability'     => $promptAbility,
                    'source_name' => $prompt['source_name'] ?? 'Live',
                    'source_id'   => $sourceId,
                    'live_start'  => true,
                ], 'yes', ['discard_ids' => $discardIds], true);
                if (!empty($state['pending_prompt'])) {
                    $state['seq']++;
                    return $state;
                }
            } else {
            $needsPay = ($choice === 'yes' && !empty($prompt['needs_pay']));
            if ($needsPay && empty($data['pay'])) {
                throw new Exception('Must confirm Energy payment');
            }
            $ctx = [
                'phase'        => 'live_start',
                'confirm'      => true,
                'discard_ids'  => $discardIds,
                'pay'          => $needsPay,
            ];
            unset($state['pending_prompt']);
            $state = resolveAbilityEffect($state, $owner, $source, $ab, $ctx);
            // Ability ignored confirm and opened another yes/no — apply it as Yes once.
            if (isRedundantOptionalLiveStartYesNoFollowUp($state['pending_prompt'] ?? null, $prompt)) {
                return actionResolvePrompt($state, $owner, array_merge($data, [
                    'choice' => 'yes',
                    'pay' => true,
                ]));
            }
            }
        } else {
            unset($state['pending_prompt']);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Card') . '] skipped optional Live Start effect.');
        }
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'look_top_optional_wr') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        $target = $prompt['target'] ?? $owner;
        $pl = &$state['players'][$target];
        if ($choice === 'yes' && !empty($pl['main_deck'])) {
            $top = array_shift($pl['main_deck']);
            $pl['waiting_room'][] = $top;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] put ' .
                cardDisplayName($top) . ' into Waiting Room.');
        }
        unset($pl);
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'optional_pay_play_hand_member') {
        if (($prompt['step'] ?? '') === 'pick_slot') {
            $slot = $data['choice'] ?? $data['slot'] ?? '';
            if (!in_array($slot, $prompt['slots'] ?? [], true)) {
                throw new Exception('Choose a Stage area');
            }
            $cardId = $prompt['card_id'] ?? '';
            $ability = $prompt['ability'] ?? [];
            $group = $ability['group'] ?? 'Nijigasaki';
            $cost = intval($ability['cost'] ?? 0);
            // Pay when placing (deferred from confirm so failed picks do not spend Energy).
            if ($cost > 0 && empty($prompt['energy_paid']) && !payEnergyCost($ownerP, $cost)) {
                throw new Exception("Need $cost active Energy");
            }
            $played = null;
            $ownerP['hand'] = array_values(array_filter(
                $ownerP['hand'],
                function ($c) use ($cardId, $ability, $group, &$played) {
                    if (($c['instance_id'] ?? '') !== $cardId) {
                        return true;
                    }
                    if (($c['card_type'] ?? '') !== 'メンバー') {
                        throw new Exception('Must choose a Member card');
                    }
                    $names = $ability['names'] ?? [];
                    $anyGroup = !empty($ability['any_group']);
                    if (!empty($names)) {
                        if (!cardMatchesNames($c, $names)) {
                            throw new Exception('Must choose a matching Member');
                        }
                    } elseif (!$anyGroup && ($c['group'] ?? '') !== $group) {
                        throw new Exception("Must choose a $group Member");
                    }
                    if (intval($c['cost'] ?? 0) > intval($ability['max_cost'] ?? 4)) {
                        throw new Exception('Member cost too high');
                    }
                    $played = $c;
                    return false;
                }
            ));
            if (!$played) {
                throw new Exception('Invalid hand card');
            }
            $allowOverlap = !empty($ability['allow_overlap']);
            if ($allowOverlap && !empty($ownerP['stage'][$slot])) {
                $replaced = $ownerP['stage'][$slot];
                $ownerP['waiting_room'][] = $replaced;
                $state = resolveOnLeaveStageAbilities($state, $owner, $replaced);
            }
            clearMemberWait($played);
            $played['entered_turn'] = intval($state['turn'] ?? 1);
            $ownerP['stage'][$slot] = $played;
            // Clear parent before On Enter so child prompts can open and the yes/no UI cannot reopen.
            unset($state['pending_prompt']);
            $state = resolveOnEnterAbilities($state, $owner, $played, $slot);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] played ' .
                cardDisplayName($played) . ' from hand.');
            return returnAfterPlacedMemberEnter($state, false, $prompt);
        }
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $cost = intval($ability['cost'] ?? 0);
            $cardId = $data['card_id'] ?? '';
            $slot = $data['slot'] ?? '';
            // Empty pick = decline (empty hand overlay / no matching Ayumu).
            if ($cardId === '' || $cardId === 'NO_CARD_NEEDED') {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional effect.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $group = $ability['group'] ?? 'Nijigasaki';
            $anyGroup = !empty($ability['any_group']);
            $played = null;
            foreach ($ownerP['hand'] as $c) {
                if (($c['instance_id'] ?? '') !== $cardId) {
                    continue;
                }
                if (($c['card_type'] ?? '') !== 'メンバー') {
                    throw new Exception('Must choose a Member card');
                }
                $names = $ability['names'] ?? [];
                if (!empty($names)) {
                    if (!cardMatchesNames($c, $names)) {
                        throw new Exception('Must choose a matching Member');
                    }
                } elseif (!$anyGroup && ($c['group'] ?? '') !== $group) {
                    throw new Exception("Must choose a $group Member");
                }
                if (intval($c['cost'] ?? 0) > intval($ability['max_cost'] ?? 4)) {
                    throw new Exception('Member cost too high');
                }
                $played = $c;
                break;
            }
            if (!$played) {
                throw new Exception('Invalid hand card');
            }
            $allowOverlap = !empty($ability['allow_overlap']);
            $blockEntered = !empty($ability['block_entered_this_turn']);
            $turn = intval($state['turn'] ?? 1);
            $validSlots = [];
            foreach (['left', 'center', 'right'] as $s) {
                $existing = $ownerP['stage'][$s] ?? null;
                if ($blockEntered && $existing && intval($existing['entered_turn'] ?? 0) === $turn) {
                    continue;
                }
                if (!$allowOverlap && $existing) {
                    continue;
                }
                $validSlots[] = $s;
            }
            if (!in_array($slot, $validSlots, true)) {
                if (count($validSlots) === 1) {
                    $slot = $validSlots[0];
                } elseif (count($validSlots) > 1) {
                    // Defer energy payment until a Stage area is chosen.
                    $state['pending_prompt'] = [
                        'type'          => 'optional_pay_play_hand_member',
                        'owner'         => $owner,
                        'responder'     => $owner,
                        'source_id'     => $prompt['source_id'] ?? '',
                        'source_name'   => $prompt['source_name'] ?? 'Member',
                        'prompt'        => 'Choose a Stage area for ' . cardDisplayName($played) . '.',
                        'step'          => 'pick_slot',
                        'card_id'       => $cardId,
                        'slots'         => $validSlots,
                        'ability'       => $ability,
                        'energy_paid'   => false,
                    ];
                    $state['seq']++;
                    return $state;
                } else {
                    $state = addLog($state, $state['players'][$owner]['name'] .
                        ' — [' . ($prompt['source_name'] ?? 'Member') .
                        '] skipped optional effect (no Stage area).');
                    unset($state['pending_prompt']);
                    $state['seq']++;
                    return finishPromptEffects($state);
                }
            }
            if ($cost > 0 && !payEnergyCost($ownerP, $cost)) {
                throw new Exception("Need $cost active Energy");
            }
            $ownerP['hand'] = array_values(array_filter(
                $ownerP['hand'],
                fn($c) => ($c['instance_id'] ?? '') !== $cardId
            ));
            if ($allowOverlap && !empty($ownerP['stage'][$slot])) {
                $replaced = $ownerP['stage'][$slot];
                $ownerP['waiting_room'][] = $replaced;
                $state = resolveOnLeaveStageAbilities($state, $owner, $replaced);
            }
            clearMemberWait($played);
            $played['entered_turn'] = $turn;
            $ownerP['stage'][$slot] = $played;
            // Clear parent before On Enter so child prompts can open and the yes/no UI cannot reopen.
            unset($state['pending_prompt']);
            $state = resolveOnEnterAbilities($state, $owner, $played, $slot);
            $sourceId = $prompt['source_id'] ?? '';
            if (!empty($ability['wait_self_if_blade_heart']) && !empty($played['blade_hearts'])) {
                foreach ($ownerP['stage'] as &$mbr) {
                    if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                        waitMember($mbr, $state);
                        break;
                    }
                }
                unset($mbr);
            }
            $payNote = $cost > 0 ? "paid $cost Energy; " : '';
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] ' . $payNote .
                'played ' . cardDisplayName($played) . ' from hand.');
            return returnAfterPlacedMemberEnter($state, false, $prompt);
        }
        unset($state['pending_prompt']);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'pick_live_match_success_heart') {
        $pickId = $data['card_id'] ?? '';
        if ($pickId === '') {
            throw new Exception('Choose a Live card');
        }
        $picked = null;
        foreach ($ownerP['live_zone'] as $lc) {
            if ($lc && ($lc['instance_id'] ?? '') === $pickId) {
                $picked = $lc;
                break;
            }
        }
        if (!$picked) {
            throw new Exception('Invalid Live card');
        }
        $pName = $picked['name_en'] ?? $picked['name'] ?? '';
        $matched = false;
        foreach ($ownerP['success_lives'] ?? [] as $sl) {
            $sName = $sl['name_en'] ?? $sl['name'] ?? '';
            if ($sName === $pName) {
                $matched = true;
                break;
            }
        }
        if ($matched) {
            foreach ($ability['hearts'] ?? [['color' => 'purple', 'count' => 4]] as $h) {
                addBonusHeartsToModifier($state, $owner, [[
                    'color' => $h['color'] ?? 'purple',
                    'count' => intval($h['count'] ?? 4),
                ]]);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] gained bonus hearts (matching Success Live).');
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] no matching Success Live name.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'optional_wr_to_deck_top') {
        $step = (string)($prompt['step'] ?? '');
        $group = (string)($prompt['group'] ?? $ability['group'] ?? '');
        $cardId = (string)($data['card_id'] ?? '');
        // Client may send only card_id on the pick step (or bare yes first).
        if ($step === 'pick' || ($choice === 'yes' && $cardId !== '')) {
            if ($cardId === '' && $choice !== '' && $choice !== 'yes' && $choice !== 'no') {
                $cardId = $choice;
            }
            if ($cardId === '') {
                throw new Exception('Choose a card from Waiting Room');
            }
            $allowed = [];
            foreach ($prompt['candidates'] ?? [] as $cand) {
                if (is_array($cand) && ($cand['instance_id'] ?? '') !== '') {
                    $allowed[$cand['instance_id']] = true;
                }
            }
            if (!empty($allowed) && empty($allowed[$cardId])) {
                throw new Exception('Choose a listed Waiting Room card');
            }
            if ($group !== '') {
                $match = null;
                foreach ($ownerP['waiting_room'] as $wrCard) {
                    if (($wrCard['instance_id'] ?? '') === $cardId) {
                        $match = $wrCard;
                        break;
                    }
                }
                if (!$match || ($match['group'] ?? '') !== $group) {
                    throw new Exception('Choose a ' . groupPromptLabel($group) . ' card from Waiting Room');
                }
            }
            if (!putWrCardOnDeckTop($ownerP, $cardId)) {
                throw new Exception('Choose a card from Waiting Room');
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] put a card from Waiting Room on deck top.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            // Bare yes without card_id → open pick step (avoids softlock / empty resolve).
            $cands = $prompt['candidates'] ?? [];
            if (empty($cands)) {
                foreach ($ownerP['waiting_room'] as $wrCard) {
                    if ($group !== '' && ($wrCard['group'] ?? '') !== $group) {
                        continue;
                    }
                    $cands[] = cardPromptSummary($wrCard);
                }
            }
            if (empty($cands)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] no matching Waiting Room card.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $label = $group !== ''
                ? (groupPromptLabel($group) . ' card')
                : 'card';
            $state['pending_prompt'] = [
                'type'          => 'optional_wr_to_deck_top',
                'step'          => 'pick',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'candidates'    => $cands,
                'group'         => $group,
                'prompt'        => "Choose 1 $label from your Waiting Room to put on top of your deck.",
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional effect.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'optional_wait_group_member_draw_discard') {
        $step = $prompt['step'] ?? '';
        if ($step === 'pick_member') {
            $mid = $data['member_id'] ?? '';
            if ($mid === '') {
                throw new Exception('Choose a Member on Stage');
            }
            return applyWaitGroupMemberDrawDiscard($state, $owner, $prompt, $mid);
        }
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $group = $ability['group'] ?? $prompt['group'] ?? 'Nijigasaki';
            $members = listGroupStageMembers($ownerP, $group);
            if (empty($members)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] no $group Members on Stage.");
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            if (count($members) === 1) {
                return applyWaitGroupMemberDrawDiscard(
                    $state,
                    $owner,
                    $prompt,
                    (string)($members[0]['instance_id'] ?? '')
                );
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_group_member_draw_discard',
                'step'          => 'pick_member',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_id'     => $prompt['source_id'] ?? '',
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'group'         => $group,
                'stage_members' => $members,
                'prompt'        => 'Choose 1 ' . $group . ' Member to put into Wait.',
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'optional_wait_group_member_blade') {
        $step = $prompt['step'] ?? '';
        if ($step === 'pick_member') {
            $mid = $data['member_id'] ?? '';
            if ($mid === '' && !empty($data['slot'])) {
                $mid = trim((string)($ownerP['stage'][$data['slot']]['instance_id'] ?? ''));
            }
            if ($mid === '') {
                throw new Exception('Choose a Member on Stage');
            }
            return applyOptionalWaitGroupMemberBlade($state, $owner, $prompt, $mid);
        }
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $group = $ability['group'] ?? $prompt['group'] ?? 'Nijigasaki';
            $members = listGroupStageMembersNotWaiting($ownerP, $group);
            if (empty($members)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] no $group Members on Stage.");
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishLiveStartEffects($state);
            }
            if (count($members) === 1) {
                return applyOptionalWaitGroupMemberBlade(
                    $state,
                    $owner,
                    $prompt,
                    (string)($members[0]['instance_id'] ?? '')
                );
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_group_member_blade',
                'step'          => 'pick_member',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_id'     => $prompt['source_id'] ?? '',
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'group'         => $group,
                'amount'        => intval($prompt['amount'] ?? $ability['amount'] ?? 2),
                'stage_members' => $members,
                'prompt'        => 'Choose 1 ' . $group . ' Member to put into Wait.',
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional Live Start effect.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'optional_wait_up_to_group_live_score') {
        $step = $prompt['step'] ?? '';
        if ($step === 'pick_members') {
            $ids = $data['member_ids'] ?? [];
            if (!is_array($ids)) {
                $ids = [];
            }
            $max = intval($prompt['max_wait'] ?? $ability['max_wait'] ?? 3);
            if (count($ids) > $max) {
                throw new Exception("Choose at most $max Member(s)");
            }
            $group = $ability['group'] ?? $prompt['group'] ?? 'Nijigasaki';
            $waited = 0;
            foreach ($ownerP['stage'] as &$mbr) {
                if (!$mbr || !in_array($mbr['instance_id'] ?? '', $ids, true)) {
                    continue;
                }
                if (($mbr['group'] ?? '') !== $group) {
                    continue;
                }
                waitMember($mbr, $state);
                $waited++;
            }
            unset($mbr);
            $scorePer = intval($prompt['score_per'] ?? $ability['score_per'] ?? 1);
            if ($waited > 0 && $scorePer > 0) {
                bumpLiveCardScore($state, $owner, $prompt['source_id'] ?? '', $waited * $scorePer);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] put $waited Member(s) into Wait; score +"
                . ($waited * $scorePer) . '.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $group = $ability['group'] ?? $prompt['group'] ?? 'Nijigasaki';
            $members = listGroupStageMembersNotWaiting($ownerP, $group);
            $max = intval($prompt['max_wait'] ?? $ability['max_wait'] ?? 3);
            if (empty($members)) {
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishLiveStartEffects($state);
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_wait_up_to_group_live_score',
                'step'          => 'pick_members',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_id'     => $prompt['source_id'] ?? '',
                'source_name'   => $prompt['source_name'] ?? 'Live',
                'group'         => $group,
                'max_wait'      => $max,
                'score_per'     => intval($prompt['score_per'] ?? $ability['score_per'] ?? 1),
                'stage_members' => $members,
                'prompt'        => "Choose up to $max $group Member(s) to put into Wait.",
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped optional Live Start effect.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'activate_members_pick') {
        // "Up to N" (Honoka PL!-bp3-001-R Live Start, etc.) — allow decline.
        if ($choice === 'skip' || $choice === 'cancel' || $choice === 'no') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Card') . '] skipped activating a Member.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishAfterBranchChoicePrompt($state, $prompt);
        }
        // Clients historically send card_id (openStageMemberPickById); CPU sends member_id.
        $memberId = trim((string)($data['member_id'] ?? ''));
        if ($memberId === '' && !empty($data['member_ids'][0])) {
            $memberId = trim((string)$data['member_ids'][0]);
        }
        if ($memberId === '') {
            $memberId = trim((string)($data['card_id'] ?? ''));
        }
        if ($memberId === '' && !empty($data['slot'])) {
            $memberId = trim((string)($ownerP['stage'][$data['slot']]['instance_id'] ?? ''));
        }
        if ($memberId === '') {
            throw new Exception('Choose a Member to activate');
        }
        $cands = $prompt['candidates'] ?? [];
        $ok = false;
        foreach ($cands as $c) {
            if (($c['instance_id'] ?? '') === $memberId) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            throw new Exception('Invalid Member');
        }
        $wasWait = false;
        foreach ($ownerP['stage'] as $mbr) {
            if ($mbr && ($mbr['instance_id'] ?? '') === $memberId && memberIsInWait($mbr)) {
                $wasWait = true;
                break;
            }
        }
        $activated = activateStageMemberByInstanceId($ownerP, $memberId);
        if ($activated > 0) {
            if ($wasWait) {
                $src = findSourceCard($state, $owner, (string)($prompt['source_id'] ?? ''));
                if (!empty($ownerP['_effect_source_is_niji'])
                    || !empty($prompt['from_niji_effect'])
                    || ($src['group'] ?? '') === 'Nijigasaki'
                    || (($prompt['ability']['group'] ?? '') === 'Nijigasaki')) {
                    $ownerP['_niji_turn_flags']['activated_wait_member'] = true;
                }
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Card') . '] activated 1 Member.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishAfterBranchChoicePrompt($state, $prompt);
    }

    if ($promptType === 'auto_on_ally_wait_activate_blade') {
        $step = $prompt['step'] ?? '';
        if ($step === 'discard' || !empty($data['discard_ids'])) {
            $need = intval($prompt['discard_count'] ?? 1);
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== $need) {
                throw new Exception("Must select exactly $need card(s) to discard");
            }
            discardHandCardsByIds($ownerP, $ids, $state, $owner);
            $state = applyAutoOnAllyWaitActivateBlade($state, $owner, $prompt);
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'no') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped ally Wait Auto.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $need = intval($prompt['discard_count'] ?? 1);
        if (count($ownerP['hand'] ?? []) < $need) {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $state['pending_prompt'] = array_merge($prompt, [
            'step'   => 'discard',
            'prompt' => $need === 1
                ? 'Choose 1 card to put into the Waiting Room.'
                : "Choose $need cards to put into the Waiting Room.",
        ]);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'live_success_pick_energy_or_member') {
        if (!in_array($choice, ['energy', 'member', 'both', 'skip'], true)) {
            throw new Exception('Invalid choice');
        }
        if ($choice !== 'skip') {
            $srcName = $prompt['source_name'] ?? 'Live';
            $prefix = $state['players'][$owner]['name'] . ' — [' . $srcName . '] ';
            $doEnergy = $choice === 'energy' || $choice === 'both';
            $doMember = $choice === 'member' || $choice === 'both';
            if ($doEnergy) {
                if (putEnergyFromDeckInWait($ownerP)) {
                    $state = addLog($state, $prefix . 'put 1 Energy from Energy deck into Wait.');
                } else {
                    $state = addLog($state, $prefix . 'could not put Energy into Wait (Energy deck empty).');
                }
            }
            if ($doMember) {
                // Daydream Mermaid (#98): player chooses which Member — never auto-first/bottom.
                $src = [
                    'instance_id' => (string)($prompt['source_id'] ?? ''),
                    'name_en'     => $srcName,
                    'name'        => $srcName,
                ];
                $added = addFromWaitingRoomWithChoice(
                    $state,
                    $owner,
                    $src,
                    $ability,
                    ['skip_stage_writeback' => true],
                    ['filter' => 'member'],
                    1
                );
                if ($added === null) {
                    $state = addLog($state, $prefix . 'choose a Member from Waiting Room.');
                    $state['seq']++;
                    return $state;
                }
                if ($added > 0) {
                    $state = addLog($state, $prefix . "added $added Member card from Waiting Room to hand.");
                } else {
                    $state = addLog($state, $prefix . 'no Member card in Waiting Room to add to hand.');
                }
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveSuccessEffects($state);
        return $state;
    }

    if ($promptType === 'pick_member_return_energy') {
        $memberId = $data['member_id'] ?? '';
        $count = intval($data['count'] ?? 0);
        if ($memberId === '') throw new Exception('Choose a Member');
        $found = false;
        foreach ($ownerP['stage'] as &$mbr) {
            if (!$mbr || ($mbr['instance_id'] ?? '') !== $memberId) continue;
            $max = countMemberStackedEnergy($ownerP, $mbr);
            if ($max <= 0) throw new Exception('Member has no stacked Energy');
            if ($count <= 0) $count = $max;
            if ($count > $max) throw new Exception("Return at most $max Energy");
            $returned = returnMemberStackedEnergyToDeck($ownerP, $mbr, $count);
            if ($returned > 0) {
                addBonusHeartsToMember($mbr, $ability['hearts_per_energy'] ?? [['color' => 'red', 'count' => 3]], $returned);
            }
            $found = true;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] returned $returned Energy; Member gained bonus hearts.");
            break;
        }
        unset($mbr);
        if (!$found) throw new Exception('Member not found');
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'optional_wait_self') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $sourceId = $prompt['source_id'] ?? '';
            $srcName = $prompt['source_name'] ?? 'Member';
            $pickCount = isset($ability['pick_count']) ? intval($ability['pick_count']) : null;
            $liveStart = !empty($prompt['live_start'])
                || (($state['phase'] ?? '') === 'live_start_effects');
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    waitMember($mbr, $state);
                    break;
                }
            }
            unset($mbr);
            $opp = ($owner === 'p1') ? 'p2' : 'p1';
            $subunitOnly = $ability['require_stage_subunit_only'] ?? '';
            if ($subunitOnly !== '' && !stageAllMembersInSubunit($ownerP, $subunitOnly)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . $srcName . '] Waited self (stage not all ' . $subunitOnly . ').');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishAfterBranchChoicePrompt($state, $prompt);
            }
            // Targeted Wait (pick_count set): let the controller choose opponent Members.
            if ($pickCount !== null && $pickCount > 0) {
                unset($state['pending_prompt']);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . $srcName . '] Waited self; choose opponent Member(s) to put into Wait.');
                return beginWaitOpponentStagePick(
                    $state,
                    $owner,
                    $srcName,
                    $ability,
                    $sourceId,
                    $liveStart
                );
            }
            if (!empty($ability['max_original_blades'])) {
                $waited = waitOpponentStageByOriginalBlades(
                    $state,
                    $opp,
                    intval($ability['max_original_blades']),
                    $pickCount,
                    $owner
                );
            } elseif (!empty($ability['max_original_hearts'])) {
                $waited = waitOpponentStageByOriginalHearts(
                    $state,
                    $opp,
                    intval($ability['max_original_hearts']),
                    $pickCount,
                    $owner
                );
            } else {
                $maxCost = intval($ability['max_cost'] ?? 4);
                $waited = waitOpponentStageByCost($state, $opp, $maxCost, $pickCount, $owner);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . "] Waited self; $waited opponent Member(s) put into Wait.");
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional Wait effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        // PL!-PR-007-PR Live Start: skip / non-pick resolve must resume the phase
        // (otherwise live_start_effects softlocks with no pending_prompt).
        return finishAfterBranchChoicePrompt($state, $prompt);
    }

    if ($promptType === 'optional_discard_look_reveal_subunit') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $need = intval($ability['discard'] ?? 1);
            $ids = $data['discard_ids'] ?? [];
            if ($need > 0 && count($ids) !== $need) {
                throw new Exception("Must select exactly $need card(s) to discard");
            }
            if (!empty($ids)) {
                discardFromHandByIds($ownerP, $ids, $state, $owner);
            }
            unset($state['pending_prompt']);
            $state = beginLookRevealPick(
                $state,
                $owner,
                $prompt['source_name'] ?? 'Member',
                $ownerP,
                [
                    'type'          => 'look_reveal_filter',
                    'look'          => intval($ability['look'] ?? 4),
                    'subunit'       => $ability['subunit'] ?? 'lily white',
                    'pick'          => intval($ability['pick'] ?? 1),
                    'optional_pick' => true,
                ]
            );
            if (!empty($state['pending_prompt'])) {
                $state['seq']++;
                return $state;
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'optional_wait_self_draw_discard_unless_baton') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $sourceId = $prompt['source_id'] ?? '';
            $batonSub = '';
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    $batonSub = $mbr['baton_from_subunit'] ?? '';
                    waitMember($mbr, $state);
                    break;
                }
            }
            unset($mbr);
            $srcName = $prompt['source_name'] ?? 'Member';
            $drawnCards = drawCardInstances($ownerP, intval($ability['draw'] ?? 1));
            foreach ($drawnCards as $c) {
                $state = logEffectDraw($state, $owner, $srcName, $c,
                    [animSpec($c['instance_id'], 'main_deck', 'hand', $owner)]);
            }
            $needBaton = $ability['baton_subunit'] ?? 'Printemps';
            if ($batonSub !== $needBaton && !empty($ownerP['hand'])) {
                $discardNeed = intval($ability['discard'] ?? 1);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . $srcName . '] Waited self.');
                return startEffectDiscardHandPrompt($state, $owner, $srcName, $discardNeed);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] Waited self.');
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_wait_self_energy_subunit') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $sourceId = $prompt['source_id'] ?? '';
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    waitMember($mbr, $state);
                    break;
                }
            }
            unset($mbr);
            $subunit = $ability['subunit'] ?? '';
            $count = countStageSubunitMembers($ownerP, $subunit);
            $activated = activateEnergyForPlayer($ownerP, $count);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . "] Waited self; activated $activated Energy ($count $subunit Member(s)).");
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_wait_members_draw') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $members = [];
            foreach ($ownerP['stage'] as $slot => $mbr) {
                if ($mbr) {
                    $members[] = cardPromptSummary($mbr) + ['slot' => $slot];
                }
            }
            if (empty($members)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] no Stage Members to Wait.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return $state;
            }
            $state['pending_prompt'] = [
                'type'          => 'wait_members_pick',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'prompt'        => 'Choose up to ' . intval($ability['max_members'] ?? 3) .
                    ' Members to put into Wait.',
                'max_members'   => intval($ability['max_members'] ?? 3),
                'draw_per'      => intval($ability['draw_per'] ?? 1),
                'stage_members' => $members,
            ];
            $state['seq']++;
            return $state;
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'wait_members_pick') {
        $ids = $data['member_ids'] ?? [];
        $max = intval($prompt['max_members'] ?? 3);
        if (count($ids) > $max) {
            throw new Exception("Choose at most $max Member(s)");
        }
        $waited = 0;
        foreach ($ownerP['stage'] as &$mbr) {
            if ($mbr && in_array($mbr['instance_id'] ?? '', $ids, true)) {
                waitMember($mbr, $state);
                $waited++;
            }
        }
        unset($mbr);
        $drawn = 0;
        if ($waited > 0) {
            $drawn = drawCardsForPlayer($state, $owner, $waited * intval($prompt['draw_per'] ?? 1));
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] put $waited Member(s) into Wait and drew $drawn.");
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_wait_subunit_opp_active') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $state = beginWaitSubunitOppActiveChain($state, $owner, $prompt);
            if (!empty($state['pending_prompt'])) {
                $state['seq']++;
                if (($state['phase'] ?? '') === 'live_start_effects') {
                    return $state;
                }
                return $state;
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional effect.');
            unset($state['pending_prompt']);
        }
        $state['seq']++;
        if (($state['phase'] ?? '') === 'live_start_effects') {
            $state = finishLiveStartEffects($state);
        }
        return $state;
    }

    if ($promptType === 'wait_subunit_member_pick') {
        $ids = $data['member_ids'] ?? [];
        $max = intval($prompt['max_members'] ?? 1);
        if (count($ids) < 1 || count($ids) > $max) {
            throw new Exception("Choose $max Member(s) to put into Wait");
        }
        $waited = 0;
        foreach ($ownerP['stage'] as &$mbr) {
            if ($mbr && in_array($mbr['instance_id'] ?? '', $ids, true)) {
                waitMember($mbr, $state);
                $waited++;
            }
        }
        unset($mbr);
        $sourceId = $prompt['source_id'] ?? '';
        if (!empty($prompt['ability']['center_only']) && findMemberSlot($ownerP, $sourceId) !== 'center') {
            unset($state['pending_prompt']);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] effect cancelled (no longer in Center).');
            $state['seq']++;
            return $state;
        }
        $opp = ($owner === 'p1') ? 'p2' : 'p1';
        $active = listActiveStageMembers($state['players'][$opp]);
        if ($waited > 0 && !empty($active)) {
            $state['pending_prompt'] = [
                'type'          => 'opp_pick_stage_active',
                'owner'         => $owner,
                'responder'     => $opp,
                'source_id'     => $sourceId,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'effect_source' => $owner,
                'stage_members' => $active,
                'prompt'        => 'Choose 1 active Member on your Stage to put into Wait.',
            ];
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . "] put $waited Member(s) into Wait; opponent chooses.");
            $state['seq']++;
            return $state;
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] put $waited Member(s) into Wait.");
        unset($state['pending_prompt']);
        $state['seq']++;
        if (($state['phase'] ?? '') === 'live_start_effects') {
            $state = finishLiveStartEffects($state);
        }
        return $state;
    }

    if ($promptType === 'opp_pick_stage_active') {
        $pickId = $data['member_id'] ?? $data['card_id'] ?? '';
        if ($pickId === '') throw new Exception('Choose a Member');
        $stageP = &$state['players'][$pid];
        $slot = findMemberSlot($stageP, $pickId);
        if ($slot === '' || empty($stageP['stage'][$slot]) || !($stageP['stage'][$slot]['active'] ?? true)) {
            throw new Exception('Must choose an active Member on your Stage');
        }
        $effectSource = $prompt['effect_source'] ?? $prompt['owner'] ?? $pid;
        waitOpponentMemberAtSlot($state, $pid, $slot, $effectSource);
        $mbr = $stageP['stage'][$slot];
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — put ' . ($mbr['name_en'] ?? $mbr['name'] ?? 'Member') . ' into Wait.');
        unset($state['pending_prompt']);
        $state['seq']++;
        if (($state['phase'] ?? '') === 'live_start_effects') {
            $state = finishLiveStartEffects($state);
        }
        return $state;
    }

    if ($promptType === 'opp_pick_hidden_hand') {
        $cardId = $data['card_id'] ?? '';
        if ($cardId === '') throw new Exception('Choose a card');
        $handOwnerP = &$state['players'][$owner];
        $picked = null;
        foreach ($handOwnerP['hand'] as $c) {
            if (($c['instance_id'] ?? '') === $cardId) {
                $picked = $c;
                break;
            }
        }
        if (!$picked) throw new Exception('Invalid hand card');
        $srcName = $prompt['source_name'] ?? 'Member';
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . $srcName . '] opponent revealed ' . cardDisplayName($picked) . ' from hand.');
        $ab = $prompt['ability'] ?? [];
        if (($picked['card_type'] ?? '') === 'ライブ') {
            $amount = intval($ab['live_score_amount'] ?? 1);
            grantMemberLiveScoreBonus($state, $owner, $prompt['source_id'] ?? '', $amount);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$srcName] gains +$amount Live total score until this Live ends.");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_judge_success_live') {
        return actionResolvePickJudgeSuccessLive($state, $owner, $prompt, $data);
    }

    if ($promptType === 'hs_pick_wr_live_to_zone') {
        $cardId = trim((string)($data['card_id'] ?? $data['wr_card_id'] ?? $choice));
        if ($cardId === '' || in_array($cardId, ['yes', 'no', 'skip', 'cancel'], true)) {
            throw new Exception('Choose a Live card from your Waiting Room');
        }
        $slot = (string)($prompt['source_slot'] ?? '');
        $srcId = (string)($prompt['source_id'] ?? '');
        $idx = intval($prompt['ability_index'] ?? -1);
        $member = ($slot !== '' && !empty($ownerP['stage'][$slot])) ? $ownerP['stage'][$slot] : null;
        if (!$member || ($srcId !== '' && ($member['instance_id'] ?? '') !== $srcId)) {
            throw new Exception('Source Member no longer on Stage');
        }
        unset($state['pending_prompt']);
        $state = applyPayEnergyAddLiveZoneFromWr(
            $state,
            $owner,
            $member,
            $slot,
            $idx,
            $prompt['ability'] ?? $ability,
            $cardId
        );
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'pick_wr_to_hand') {
        $pickCount = intval($prompt['pick_count'] ?? 1);
        $ids = [];
        if (!empty($data['card_ids']) && is_array($data['card_ids'])) {
            $ids = array_values(array_filter($data['card_ids'], fn($id) => $id !== '' && $id !== null));
        } elseif (($data['card_id'] ?? '') !== '') {
            $ids = [$data['card_id']];
        }
        // "Up to N" (pick_count > 1): allow confirming zero selections.
        if ($choice === 'skip' || ($pickCount > 1 && empty($ids))) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] added 0 from Waiting Room.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        if (empty($ids)) {
            throw new Exception('Choose a card');
        }
        if (count($ids) > max(1, $pickCount)) {
            throw new Exception('Choose at most ' . max(1, $pickCount) . ' card(s)');
        }
        $cfg = $prompt['wr_pick_cfg'] ?? wrPickCfgFromAbility($ability);
        $picked = [];
        $rest = [];
        $seen = [];
        foreach ($ownerP['waiting_room'] as $c) {
            $cid = $c['instance_id'] ?? '';
            if ($cid !== '' && in_array($cid, $ids, true)) {
                if (isset($seen[$cid])) {
                    throw new Exception('Duplicate Waiting Room card selected');
                }
                hydrateWrCardForPick($c);
                if (!cardMatchesWrPick($c, $cfg)) {
                    throw new Exception('Invalid Waiting Room card');
                }
                $picked[] = $c;
                $seen[$cid] = true;
            } else {
                $rest[] = $c;
            }
        }
        if (count($picked) !== count($ids)) {
            throw new Exception('Invalid Waiting Room card');
        }
        $ownerP['waiting_room'] = $rest;
        $ownerP['hand'] = array_merge($ownerP['hand'], $picked);
        $names = array_map('cardDisplayName', $picked);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] added ' .
            implode(', ', $names) . ' from Waiting Room to hand.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'shuffle_named_from_waiting_pick') {
        $ids = $data['card_ids'] ?? $data['wr_ids'] ?? [];
        $max = intval($prompt['max_pick'] ?? $ability['max_total'] ?? 6);
        if (empty($ids)) {
            throw new Exception('Choose at least 1 matching Member');
        }
        if (count($ids) > $max) {
            throw new Exception("Choose at most $max matching Member(s)");
        }
        $picked = [];
        $rest = [];
        $seen = [];
        foreach ($ownerP['waiting_room'] as $c) {
            $cid = $c['instance_id'] ?? '';
            if ($cid !== '' && in_array($cid, $ids, true)) {
                if (isset($seen[$cid])) {
                    throw new Exception('Duplicate Waiting Room card selected');
                }
                hydrateWrCardForPick($c);
                if (($c['card_type'] ?? '') !== 'メンバー' || !cardMatchesNames($c, $ability['names'] ?? [])) {
                    throw new Exception('Invalid Waiting Room card');
                }
                $picked[] = $c;
                $seen[$cid] = true;
            } else {
                $rest[] = $c;
            }
        }
        if (count($picked) !== count($ids)) {
            throw new Exception('Invalid Waiting Room card');
        }
        shuffle($picked);
        $ownerP['waiting_room'] = $rest;
        $ownerP['main_deck'] = array_merge($ownerP['main_deck'], $picked);
        $activated = activateEnergyForPlayer($ownerP, intval($ability['then']['max'] ?? 6));
        $sourceId = $prompt['source_id'] ?? '';
        $abilityIdx = intval($prompt['ability_index'] ?? 0);
        foreach ($ownerP['stage'] as &$mbr) {
            if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                markAbilityUsed($mbr, $abilityIdx);
                break;
            }
        }
        unset($mbr, $state['pending_prompt']);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] shuffled ' . count($picked) .
            " Member(s) to deck bottom and activated $activated Energy.");
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_wr_leave_stage_add') {
        $pickId = $data['card_id'] ?? '';
        $needsPick = intval($prompt['pick_count'] ?? 1) > 0;
        if (!$needsPick) {
            $pickId = 'NO_CARD_NEEDED';
        }
        if ($needsPick && $pickId === '') {
            throw new Exception('Choose a card');
        }
        $cfg = $prompt['wr_pick_cfg'] ?? wrPickCfgForLeaveStageAbility($ability);
        $pickIndex = null;
        $picked = null;
        foreach ($ownerP['waiting_room'] as $i => &$c) {
            if (($c['instance_id'] ?? '') !== $pickId) {
                continue;
            }
            hydrateWrCardForPick($c);
            if (!cardMatchesWrPick($c, $cfg)) {
                throw new Exception('Invalid Waiting Room card');
            }
            $picked = $c;
            $pickIndex = $i;
            break;
        }
        unset($c);
        if ((!$picked || $pickIndex === null) && $pickId !== 'NO_CARD_NEEDED') {
            throw new Exception('Invalid Waiting Room card');
        }
        $slot = $prompt['source_slot'] ?? '';
        if ($slot === '') {
            $slot = findMemberSlot($ownerP, $prompt['source_id'] ?? '');
        }
        if ($slot === '' || empty($ownerP['stage'][$slot])) {
            throw new Exception('Member no longer on Stage');
        }
        $leavingMember = $ownerP['stage'][$slot];
        $ownerP['stage'][$slot] = null;
        $pickPromptType = $prompt['type'] ?? '';
        $state = resolveOnLeaveStageAbilities($state, $owner, $leavingMember);
        if (!empty($state['pending_prompt']) && ($state['pending_prompt']['type'] ?? '') !== $pickPromptType) {
            $ownerP['stage'][$slot] = $leavingMember;
            $state['seq']++;
            return $state;
        }
        if ($pickId !== 'NO_CARD_NEEDED') {
            array_splice($ownerP['waiting_room'], $pickIndex, 1);
        }
        $ownerP['waiting_room'][] = $leavingMember;
        if ($pickId !== 'NO_CARD_NEEDED') {
            $ownerP['hand'][] = $picked;
        }
        $mName = $leavingMember['name_en'] ?? $leavingMember['name'] ?? 'Member';
        $state = ($pickId !== 'NO_CARD_NEEDED')
            ? addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $mName . '] left Stage; added ' .
                cardDisplayName($picked) . ' from Waiting Room to hand.')
            : addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $mName . '] left Stage; no card added from Waiting Room.');
        $group = $ability['group'] ?? '';
        $minScore = intval($ability['activate_energy_if_score_min'] ?? 0);
        if ($pickId !== 'NO_CARD_NEEDED' && $minScore > 0 && ($picked['card_type'] ?? '') === 'ライブ'
            && intval($picked['score'] ?? 0) >= $minScore
            && cardMatchesGroup($picked, $group, 'live')) {
            $activated = activateEnergyForPlayer($ownerP, intval($ability['activate_energy_count'] ?? 4));
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — activated $activated Energy (high-score Aqours Live).");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_wr_live_deck_top') {
        $pickId = $data['card_id'] ?? '';
        if ($pickId === '') throw new Exception('Choose a Live card');
        if (!putWrCardOnDeckTop($ownerP, $pickId)) {
            throw new Exception('Invalid Waiting Room card');
        }
        $ability = $prompt['ability'] ?? [];
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] put a Live card on top of deck.');
        $opp = ($owner === 'p1') ? 'p2' : 'p1';
        if (stageHasWaitMember($state, $opp)) {
            $drawn = drawCardsForPlayer($state, $owner, intval($ability['draw'] ?? 1));
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [" . ($prompt['source_name'] ?? 'Member') . "] drew $drawn (opponent has a Member in Wait).");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_wr_members_deck_top') {
        // Accept card_ids[] (preferred) or a single card_id (CPU Hard/Expert used to send this).
        $pickIds = $data['card_ids'] ?? null;
        if (!is_array($pickIds)) {
            $pickIds = [];
            if (($data['card_id'] ?? '') !== '') {
                $pickIds = [$data['card_id']];
            }
        }
        $pickIds = array_values(array_filter(array_map('strval', $pickIds), static fn($id) => $id !== ''));
        $need = intval($prompt['pick_count'] ?? 2);
        $upTo = !empty($prompt['up_to']) || stripos((string)($prompt['prompt'] ?? ''), 'up to') !== false;
        if ($upTo) {
            if (count($pickIds) > $need) {
                throw new Exception("Select at most $need Member card(s)");
            }
        } elseif (count($pickIds) !== $need) {
            throw new Exception("Choose exactly $need Member card(s)");
        }
        $picked = [];
        foreach ($pickIds as $id) {
            $found = false;
            foreach ($ownerP['waiting_room'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $id && ($c['card_type'] ?? '') === 'メンバー') {
                    $picked[] = $c;
                    array_splice($ownerP['waiting_room'], $i, 1);
                    $found = true;
                    break;
                }
            }
            if (!$found) throw new Exception('Invalid Waiting Room Member');
        }
        $n = count($picked);
        if ($n > 0) {
            $picked = array_reverse($picked);
            $ownerP['main_deck'] = array_merge($picked, $ownerP['main_deck']);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . "] put $n Member card(s) from Waiting Room on deck top.");
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] chose no Waiting Room Members for deck top.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        if (($state['phase'] ?? '') === 'live_start_effects') {
            $state = finishLiveStartEffects($state);
        }
        return $state;
    }

    if ($promptType === 'optional_wait_self_add_wr') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $sourceId = $prompt['source_id'] ?? '';
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    waitMember($mbr, $state);
                    break;
                }
            }
            unset($mbr);
            $added = addFromWaitingRoomFiltered(
                $ownerP,
                $ability['group'] ?? '',
                $ability['filter'] ?? '',
                intval($ability['count'] ?? 1)
            );
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . "] Waited self; added $added μ's Member(s) from Waiting Room.");
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_pay_energy_if_baton') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $cost = intval($ability['cost'] ?? 1);
            if (!payEnergyCost($ownerP, $cost)) {
                throw new Exception("Need $cost active Energy");
            }
            $sourceId = $prompt['source_id'] ?? '';
            $source = findSourceCard($state, $owner, $sourceId);
            if ($source && memberBatonFromLowerCostSubunit($source, $ability['baton_subunit'] ?? '')) {
                $then = $ability['then'] ?? [];
                if (!empty($then['hearts'])) {
                    addBonusHeartsToModifier($state, $owner, $then['hearts']);
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') .
                    '] paid ' . $cost . ' Energy; Baton Touch bonus applied.');
            } else {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') .
                    "] paid $cost Energy but Baton Touch condition was not met.");
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_same_name_member') {
        $memberId = $data['member_id'] ?? ($data['member_ids'][0] ?? '');
        if ($memberId === '') throw new Exception('Choose a Member');
        $effect = $prompt['ability'] ?? [];
        if (applyNamedMemberHeartsBlade($state, $owner, $memberId, $effect)) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') .
                '] granted bonus hearts and Blade to a matching-name Member.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'pick_member_grant_hearts') {
        $memberId = trim((string)($data['member_id'] ?? ''));
        if ($memberId === '' && !empty($data['member_ids'][0])) {
            $memberId = trim((string)$data['member_ids'][0]);
        }
        if ($memberId === '') {
            $memberId = trim((string)($data['card_id'] ?? ''));
        }
        if ($memberId === '' && !empty($data['slot'])) {
            $memberId = trim((string)($ownerP['stage'][$data['slot']]['instance_id'] ?? ''));
        }
        if ($memberId === '') {
            throw new Exception('Choose a Member');
        }
        $effect = [
            'hearts' => $prompt['hearts'] ?? [],
            'blade'  => intval($prompt['blade'] ?? 0),
        ];
        if (applyNamedMemberHeartsBlade($state, $owner, $memberId, $effect)) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] granted bonus hearts/Blade.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'optional_discard_prompt') {
        return resolveOptionalDiscardPromptChoice($state, $owner, $prompt, $choice, $data);
    }

    if ($promptType === 'optional_pay_energy_on_enter') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $cost = intval($prompt['pay_cost'] ?? $ability['cost'] ?? 0);
            if (!payEnergyCost($ownerP, $cost)) {
                throw new Exception("Need $cost active Energy");
            }
            $source = findSourceCard($state, $owner, $prompt['source_id'] ?? '');
            // Clear before nested then — player_choice (and similar) skip when a
            // pending prompt is still set (Live Start unsets first for the same reason).
            unset($state['pending_prompt']);
            if ($source && !empty($ability['then'])) {
                $state = resolveAbilityEffect($state, $owner, $source, $ability['then'], [
                    'phase' => 'on_enter',
                    'slot'  => findMemberSlot($ownerP, $prompt['source_id'] ?? ''),
                    'pay'   => true,
                ]);
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
            unset($state['pending_prompt']);
        }
        $state['seq']++;
        if (empty($state['pending_prompt'])) {
            $state = finishPromptEffects($state);
        }
        return $state;
    }

    if ($promptType === 'mandatory_discard_look_reveal') {
        $need = intval($prompt['discard_count'] ?? 1);
        $ids = $data['discard_ids'] ?? [];
        if (count($ids) !== $need) {
            throw new Exception("Must select exactly $need card(s) to discard");
        }
        discardHandCardsByIds($ownerP, $ids, $state, $owner);
        unset($state['pending_prompt']);
        $ab = $prompt['ability'] ?? [];
        $then = [
            'type'   => 'look_reveal_filter',
            'look'   => intval($ab['look'] ?? 5),
            'group'  => $ab['group'] ?? '',
            'filter' => $ab['filter'] ?? '',
            'pick'   => intval($ab['pick'] ?? 1),
        ];
        $state = beginLookRevealPick($state, $owner, $prompt['source_name'] ?? 'Member', $ownerP, $then);
        if (empty($state['pending_prompt']) && isset($prompt['ability_index'])) {
            $slot = $prompt['source_slot'] ?? '';
            if ($slot !== '' && !empty($ownerP['stage'][$slot])) {
                markAbilityUsed($ownerP['stage'][$slot], abilityMarkKey($prompt['ability_index']));
            }
        }
        if (empty($state['pending_prompt'])) {
            $state['seq']++;
            $state = finishPromptEffects($state);
        }
        return $state;
    }

    if ($promptType === 'reveal_hand_member_cost_live_score') {
        $ids = $data['card_ids'] ?? [];
        $milestones = $prompt['milestones'] ?? [10, 20, 30, 40, 50];
        $total = 0;
        $revealed = [];
        foreach ($ownerP['hand'] as $c) {
            if (in_array($c['instance_id'] ?? '', $ids, true)
                && ($c['card_type'] ?? '') === 'メンバー') {
                $total += intval($c['cost'] ?? 0);
                $revealed[] = $c;
            }
        }
        unset($state['pending_prompt']);
        if (!empty($revealed)) {
            $state = queuePublicSkillReveal($state, $owner, $revealed, $prompt['source_name'] ?? 'Member', 'hand');
        }
        if (in_array($total, $milestones, true)) {
            $then = $ability['then'] ?? ['type' => 'live_score_bonus', 'amount' => 1];
            $state = applyModifierEffect($state, $owner, $then);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [" . ($prompt['source_name'] ?? 'Member') . "] revealed cost $total; +1 Live Score until Live ends.");
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [" . ($prompt['source_name'] ?? 'Member') . "] revealed cost $total (no milestone).");
        }
        if (isset($prompt['ability_index'])) {
            $slot = $prompt['source_slot'] ?? '';
            if ($slot !== '' && !empty($ownerP['stage'][$slot])) {
                markAbilityUsed($ownerP['stage'][$slot], abilityMarkKey($prompt['ability_index']));
            }
        }
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'optional_discard_blade_draw_if_live') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $need = intval($ability['discard'] ?? 1);
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== $need) {
                throw new Exception("Must select exactly $need card(s) to discard");
            }
            $hadLive = false;
            foreach ($ids as $id) {
                foreach ($ownerP['hand'] as $c) {
                    if (($c['instance_id'] ?? '') === $id && ($c['card_type'] ?? '') === 'ライブ') {
                        $hadLive = true;
                        break 2;
                    }
                }
            }
            discardHandCardsByIds($ownerP, $ids, $state, $owner);
            $state = applyModifierEffect($state, $owner, [
                'type'   => 'blade_bonus',
                'amount' => intval($ability['blade_amount'] ?? 1),
            ]);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] discarded for Blade bonus.');
            if ($hadLive) {
                $drawn = drawCardsForPlayer($state, $owner, 1);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — drew $drawn (Live discarded).");
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional Live Start effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'live_start_pay_or_discard') {
        if ($choice === 'pay') {
            $cost = intval($prompt['pay_cost'] ?? 2);
            if (!payEnergyCost($ownerP, $cost)) {
                throw new Exception("Need $cost active Energy");
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [" . ($prompt['source_name'] ?? 'Member') . "] paid $cost Energy (Live Start).");
        } elseif ($choice === 'discard') {
            $need = intval($prompt['discard_count'] ?? 2);
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== $need) {
                throw new Exception("Must select exactly $need card(s) to discard");
            }
            discardHandCardsByIds($ownerP, $ids, $state, $owner);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [" . ($prompt['source_name'] ?? 'Member') . "] discarded $need (Live Start).");
        } else {
            throw new Exception('Invalid choice');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'live_start_unless_discard_return_energy') {
        $srcName = $prompt['source_name'] ?? 'Member';
        $need = intval($prompt['discard_count'] ?? 1);
        if ($choice === 'discard') {
            $ids = normalizeDiscardIds($data['discard_ids'] ?? []);
            if (count($ids) !== $need) {
                throw new Exception("Must select exactly $need card(s) to discard");
            }
            discardHandCardsByIds($ownerP, $ids, $state, $owner);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$srcName] discarded $need (Live Start).");
        } elseif ($choice === 'return_energy') {
            if (!returnOneEnergyFromZoneToDeck($ownerP)) {
                throw new Exception('No Energy to return to Energy deck');
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$srcName] put 1 Energy into Energy deck (Live Start).");
        } elseif ($choice === 'skip') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$srcName] skipped Live Start discard (no Energy to return).");
        } else {
            throw new Exception('Invalid choice');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'live_success_choose_draw_or_energy_wait') {
        $srcName = $prompt['source_name'] ?? 'Member';
        $prefix = $state['players'][$owner]['name'] . " — [$srcName] ";
        if ($choice === 'draw') {
            $n = intval($prompt['draw'] ?? $ability['draw'] ?? 2);
            $drawn = drawCardsForPlayer($state, $owner, $n);
            $state = addLog($state, $prefix . "drew $drawn.");
        } elseif ($choice === 'energy') {
            if (putEnergyFromDeckInWait($ownerP)) {
                $state = addLog($state, $prefix . 'put 1 Energy from Energy deck into Wait.');
            } else {
                $state = addLog($state, $prefix . 'could not put Energy into Wait (Energy deck empty).');
            }
        } else {
            throw new Exception('Invalid choice');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveSuccessEffects($state);
    }

    if ($promptType === 'optional_pay_energy_live_success') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $cost = intval($prompt['pay_cost'] ?? $ability['cost'] ?? 6);
            if (!payEnergyCost($ownerP, $cost)) {
                throw new Exception("Need $cost active Energy");
            }
            if (!empty($ability['then'])) {
                $state = applyModifierEffect($state, $owner, $ability['then']);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] paid $cost Energy; +1 Live Score.");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveSuccessEffects($state);
    }

    if ($promptType === 'optional_pay_energy_add_from_wr') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'no') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveSuccessEffects($state);
        }
        $cost = intval($prompt['pay_cost'] ?? $ability['cost'] ?? 0);
        if ($cost > 0 && !payEnergyCost($ownerP, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $cfg = $prompt['wr_pick_cfg'] ?? wrPickCfgFromAbility($ability);
        $need = max(1, intval($ability['count'] ?? 1));
        if (wrPickMatchCount($ownerP, $cfg, $need) < $need) {
            throw new Exception('No matching card in Waiting Room');
        }
        $srcId = $prompt['source_id'] ?? '';
        $slot = $prompt['source_slot'] ?? findMemberSlot($ownerP, $srcId);
        if ($slot === null || $slot === '') {
            throw new Exception('Member not found');
        }
        $member = &$ownerP['stage'][$slot];
        if (!$member || ($srcId !== '' && ($member['instance_id'] ?? '') !== $srcId)) {
            throw new Exception('Member not found');
        }
        $abilityIdx = intval($prompt['ability_index'] ?? 0);
        startPickWrToHandPrompt($state, $owner, $member, $slot, $abilityIdx, $ability, $cfg, false, $need);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] paid $cost Energy; choose a card from Waiting Room.");
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'on_enter_draw_swap_area') {
        $slot = $choice;
        $srcSlot = $prompt['source_slot'] ?? '';
        $srcId = $prompt['source_id'] ?? '';
        if (!in_array($slot, $prompt['slots'] ?? [], true)) {
            throw new Exception('Choose a valid area');
        }
        $member = $ownerP['stage'][$srcSlot] ?? null;
        if (!$member || ($member['instance_id'] ?? '') !== $srcId) {
            throw new Exception('Member not found');
        }
        if (function_exists('spBp2MarkEffectAreaMove')) {
            spBp2MarkEffectAreaMove($state, $member);
        }
        $other = $ownerP['stage'][$slot] ?? null;
        $ownerP['stage'][$slot] = $member;
        $ownerP['stage'][$srcSlot] = $other;
        if ($other) {
            $other['moved_this_turn'] = true;
            $other['moved_from_slot'] = $srcSlot;
            $ownerP['stage'][$srcSlot] = $other;
        }
        $member['moved_this_turn'] = true;
        $member['moved_from_slot'] = $srcSlot;
        $ownerP['stage'][$slot] = $member;
        $sourceName = $prompt['source_name'] ?? 'Member';
        // Drop the swap prompt before observer autos (e.g. Tomari pb2-022 on move to Center).
        unset($state['pending_prompt']);
        // Displaced Member first: they may be landing on Center (move-to-Center autos)
        // before the mover's Center-leave choose prompt (#151).
        if ($other) {
            // $other left $slot and landed on $srcSlot.
            $state = resolveAutoAreaMoveAbilities($state, $owner, $other['instance_id'] ?? '', $slot);
        }
        if (empty($state['pending_prompt'])) {
            $state = resolveAutoAreaMoveAbilities($state, $owner, $srcId, $srcSlot);
        }
        if (function_exists('spBp2ClearEffectAreaMove')) {
            spBp2ClearEffectAreaMove($state);
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            " — [$sourceName] moved to $slot.");
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'optional_wr_member_reenter') {
        if (($prompt['step'] ?? '') === 'pick_stage' || (!empty($data['slot']) && $choice === '')) {
            $slot = $data['slot'] ?? $choice;
            $mbr = $ownerP['stage'][$slot] ?? null;
            if (!$mbr) throw new Exception('Choose a Member on Stage');
            $nameKey = cardNameKey($mbr);
            $ownerP['waiting_room'][] = $mbr;
            $ownerP['stage'][$slot] = null;
            $state = resolveOnLeaveStageAbilities($state, $owner, $mbr);
            $reenter = null;
            $rest = [];
            foreach ($ownerP['waiting_room'] as $c) {
                if (!$reenter && ($c['card_type'] ?? '') === 'メンバー'
                    && cardNameKey($c) === $nameKey) {
                    $reenter = $c;
                } else {
                    $rest[] = $c;
                }
            }
            $ownerP['waiting_room'] = $rest;
            if ($reenter) {
                $reenter['entered_this_turn'] = true;
                $reenter['moved_this_turn'] = true;
                $ownerP['stage'][$slot] = $reenter;
                notifyMemberEnteredStage($state, $owner, $reenter);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] re-entered ' .
                    ($reenter['name_en'] ?? $reenter['name']) . " on $slot.");
            }
            unset($state['pending_prompt']);
            $state['seq']++;
            $state = finishPromptEffects($state);
            return $state;
        }
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $slot = $data['slot'] ?? '';
            if ($slot === '') {
                $state['pending_prompt'] = array_merge($prompt, [
                    'step'    => 'pick_stage',
                    'prompt'  => 'Choose a Member on your Stage to swap with Waiting Room.',
                    'choices' => [],
                ]);
                $state['seq']++;
                return $state;
            }
            $mbr = $ownerP['stage'][$slot] ?? null;
            if (!$mbr) throw new Exception('Choose a Member on Stage');
            $nameKey = cardNameKey($mbr);
            $ownerP['waiting_room'][] = $mbr;
            $ownerP['stage'][$slot] = null;
            $state = resolveOnLeaveStageAbilities($state, $owner, $mbr);
            $reenter = null;
            $rest = [];
            foreach ($ownerP['waiting_room'] as $c) {
                if (!$reenter && ($c['card_type'] ?? '') === 'メンバー'
                    && cardNameKey($c) === $nameKey) {
                    $reenter = $c;
                } else {
                    $rest[] = $c;
                }
            }
            $ownerP['waiting_room'] = $rest;
            if ($reenter) {
                $reenter['entered_this_turn'] = true;
                $reenter['moved_this_turn'] = true;
                $ownerP['stage'][$slot] = $reenter;
                notifyMemberEnteredStage($state, $owner, $reenter);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] re-entered ' .
                    ($reenter['name_en'] ?? $reenter['name']) . " on $slot.");
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'activate_energy_up_to') {
        $count = intval($choice);
        $max = intval($prompt['max'] ?? 6);
        if ($count < 0 || $count > $max) throw new Exception("Choose 0–$max");
        if ($count > 0) {
            $activated = activateEnergyForPlayer($ownerP, $count);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [" . ($prompt['source_name'] ?? 'Live') . "] activated $activated Energy.");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'pick_yell_member') {
        if (($data['choice'] ?? '') === 'skip') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped Yell member pick.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $cardId = $data['card_id'] ?? '';
        $eligibleIds = yellPromptCandidateIds($prompt);
        $picked = takeFromPendingYellPool($ownerP, $cardId, $prompt, $state, $owner);
        if (!$picked && count($eligibleIds) === 1) {
            $picked = takeFromPendingYellPool($ownerP, $eligibleIds[0], $prompt, $state, $owner);
        }
        if (!$picked) {
            if (empty($eligibleIds)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . '] no Yell cards available; skipped.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            throw new Exception('Invalid Yell card');
        }
        if (!cardMatchesYellPick($picked, $ability)) {
            throw new Exception('Must pick a qualifying card');
        }
        $ownerP['hand'][] = $picked;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . '] added ' .
            ($picked['name_en'] ?? $picked['name']) . ' from Yell to hand.');
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishPromptEffects($state);
        return $state;
    }

    if ($promptType === 'optional_wait_self_center_blade') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $sourceId = $prompt['source_id'] ?? '';
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    waitMember($mbr, $state);
                    break;
                }
            }
            unset($mbr);
            $group = $ability['group'] ?? 'μ\'s';
            $amount = intval($ability['amount'] ?? 1);
            if (applyCenterGroupBladeBonus($state, $owner, $group, $amount)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [" . ($prompt['source_name'] ?? 'Member') . "] Waited self; Center $group Member gained +$amount Blade.");
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional Live Start effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'optional_stage_reposition') {
        $step = (string)($prompt['step'] ?? '');
        $srcName = $prompt['source_name'] ?? 'Member';
        if ($step === '' && in_array($choice, ['no', 'skip'], true)) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] skipped optional Position Change.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $fromSlot = (string)($prompt['from_slot'] ?? '');
        $pickedSlot = (string)($data['slot'] ?? '');
        if ($pickedSlot === 'yes' || $pickedSlot === 'no' || $pickedSlot === 'skip') {
            $pickedSlot = '';
        }
        $cid = (string)($data['card_id'] ?? '');
        if ($pickedSlot === '' && $cid !== '') {
            $pickedSlot = findMemberSlot($ownerP, $cid);
        }
        if ($step === 'pick_member' || ($step === '' && $choice === 'yes' && $fromSlot === '')) {
            $cands = is_array($prompt['candidates'] ?? null) ? $prompt['candidates'] : [];
            if ($pickedSlot === '' && count($cands) === 1) {
                $pickedSlot = (string)($cands[0]['slot'] ?? '');
            }
            if ($pickedSlot === '' || empty($ownerP['stage'][$pickedSlot])) {
                if ($step === 'pick_member') {
                    throw new Exception('Choose a Member to Position Change');
                }
                $state['pending_prompt'] = array_merge($prompt, [
                    'step'    => 'pick_member',
                    'choices' => [],
                    'prompt'  => 'Choose 1 Member to Position Change.',
                ]);
                $state['seq']++;
                return $state;
            }
            $fromSlot = $pickedSlot;
            $destSlots = array_values(array_filter(
                ['left', 'center', 'right'],
                static fn(string $s): bool => $s !== $fromSlot
            ));
            $state['pending_prompt'] = array_merge($prompt, [
                'step'         => 'pick_dest',
                'from_slot'    => $fromSlot,
                'target_slots' => $destSlots,
                'choices'      => [],
                'prompt'       => 'Choose an area to Position Change into.',
            ]);
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_dest') {
            $dest = $pickedSlot !== '' ? $pickedSlot : $choice;
            $effectSrc = [];
            if ($srcId !== '') {
                $foundSrc = findSourceCard($state, $owner, $srcId);
                if (is_array($foundSrc)) {
                    $effectSrc = $foundSrc;
                }
            }
            if (($effectSrc['group'] ?? '') === '') {
                $g = (string)(($prompt['ability'] ?? [])['group'] ?? ($ability['group'] ?? ''));
                if ($g !== '') {
                    $effectSrc = ['group' => $g];
                }
            }
            $state = applyStagePositionChange($state, $owner, $fromSlot, $dest, $effectSrc);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] Position Changed a Member to ' . $dest . '.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        throw new Exception('Invalid Position Change choice');
    }

    if ($promptType === 'optional_position_change_all_muse') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $left = $ownerP['stage']['left'];
            $ownerP['stage']['left'] = $ownerP['stage']['center'];
            $ownerP['stage']['center'] = $left;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] position-changed Center and Left Members.');
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped optional position change.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'choose_heart_mus_member') {
        $choices = $ability['heart_choices'] ?? ['yellow', 'pink', 'purple'];
        if (!in_array($choice, $choices, true)) {
            throw new Exception('Invalid heart choice');
        }
        if (grantHeartToFirstGroupMember($state, $owner, 'μ\'s', $choice)) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] granted 1 $choice ♡ to a μ's Member.");
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'choose_heart_per_success') {
        $choices = $ability['heart_choices'] ?? ['yellow', 'pink', 'purple'];
        if (!in_array($choice, $choices, true)) {
            throw new Exception('Invalid heart choice');
        }
        $n = count($ownerP['success_lives'] ?? []) * intval($ability['per_success'] ?? 1);
        if ($n > 0) {
            addBonusHeartsToModifier($state, $owner, [['color' => $choice, 'count' => $n]]);
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] chose $choice ♡ × $n until Live ends.");
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'choose_heart_modifier') {
        $choices = $ability['heart_choices'] ?? ['yellow', 'pink', 'purple'];
        if (!in_array($choice, $choices, true)) {
            throw new Exception('Invalid heart choice');
        }
        $n = intval($ability['count'] ?? 1);
        addBonusHeartsToModifier($state, $owner, [['color' => $choice, 'count' => $n]]);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] chose $choice ♡ × $n until Live ends.");
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'choose_heart_other_member') {
        $choices = $ability['heart_choices'] ?? ['yellow', 'pink', 'purple', 'green', 'blue', 'red'];
        if (!in_array($choice, $choices, true)) {
            throw new Exception('Invalid heart choice');
        }
        $excludeId = $prompt['source_id'] ?? '';
        $heartCount = intval($ability['heart_count'] ?? 1);
        $applied = 0;
        foreach ($state['players'][$owner]['stage'] as &$mbr) {
            if (!$mbr || ($mbr['instance_id'] ?? '') === $excludeId) continue;
            if (($ability['group'] ?? '') !== '' && ($mbr['group'] ?? '') !== ($ability['group'] ?? '')) {
                continue;
            }
            if (!isset($mbr['bonus_hearts'])) $mbr['bonus_hearts'] = [];
            for ($i = 0; $i < $heartCount; $i++) {
                $mbr['bonus_hearts'][] = $choice;
            }
            $applied++;
            break;
        }
        unset($mbr);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] gave $heartCount $choice ♡ to another Member.");
        unset($state['pending_prompt']);
        $state['seq']++;
        if (!empty($prompt['after_live_start'])) {
            $state = finishLiveStartEffects($state);
        } else {
            $state = finishPromptEffects($state);
        }
        return $state;
    }

    if ($promptType === 'blade_per_discarded_pick_member') {
        if ($choice === 'skip' || $choice === 'no') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped Blade bonus.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        $memberId = $data['card_id'] ?? $choice;
        $per = intval($ability['amount'] ?? 3);
        $discarded = intval($prompt['discarded'] ?? 0);
        $bonus = $per * $discarded;
        foreach ($state['players'][$owner]['stage'] as &$mbr) {
            if (!$mbr || ($mbr['instance_id'] ?? '') !== $memberId) continue;
            $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $bonus;
            break;
        }
        unset($mbr);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] chosen Member gains +$bonus Blade.");
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'waive_required_heart_color') {
        $choices = $ability['colors'] ?? ['pink', 'green', 'blue'];
        if (!in_array($choice, $choices, true)) {
            throw new Exception('Invalid heart choice');
        }
        $sourceId = $prompt['source_id'] ?? '';
        bumpLiveCardColorReduction($state, $owner, $sourceId, $choice, 1);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . "] waived required $choice ♡.");
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'choose_required_heart_pair_gray') {
        $choices = $ability['colors'] ?? ['pink', 'green', 'blue'];
        if (!in_array($choice, $choices, true)) {
            throw new Exception('Invalid heart choice');
        }
        $sourceId = $prompt['source_id'] ?? '';
        foreach ($ownerP['live_zone'] as &$lc) {
            if ($lc && ($lc['instance_id'] ?? '') === $sourceId) {
                $lc['required_hearts'] = [
                    ['color' => $choice, 'count' => 2],
                    ['color' => 'any', 'count' => 1],
                ];
                break;
            }
        }
        unset($lc);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . "] required hearts set to 2 $choice ♡ and 1 Gray ♡.");
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'treat_pick_group_member_hearts_as') {
        // openStageSlotPick sends {slot}; accept choice/card_id for back-compat.
        $slot = $data['slot'] ?? $choice;
        if (($slot === '' || empty($ownerP['stage'][$slot])) && !empty($data['card_id'])) {
            $slot = findMemberSlot($ownerP, (string)$data['card_id']);
        }
        if ($slot === '' || empty($ownerP['stage'][$slot])) {
            throw new Exception('Choose a Stage Member');
        }
        $color = $prompt['color'] ?? 'pink';
        $ownerP['stage'][$slot]['hearts_treat_as'] = $color;
        $mbr = $ownerP['stage'][$slot];
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . '] ' .
            ($mbr['name_en'] ?? $mbr['name'] ?? 'Member') .
            " hearts treated as $color until Live ends.");
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'optional_success_wr_live_swap') {
        $step = $prompt['step'] ?? 'confirm';
        $group = $prompt['group'] ?? 'Nijigasaki';
        $filter = $prompt['filter'] ?? 'live';
        if ($step === 'confirm') {
            if (!isset(['yes' => true, 'no' => true][$choice])) {
                throw new Exception('Invalid choice');
            }
            if ($choice === 'no') {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional effect.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $succ = array_values(array_filter(
                $ownerP['success_lives'] ?? [],
                fn($c) => cardMatchesGroup($c, $group, $filter)
            ));
            if (empty($succ)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional effect (no Success Live).');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_success_wr_live_swap',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_id'     => $prompt['source_id'] ?? '',
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'ability'       => $prompt['ability'] ?? [],
                'ability_index' => $prompt['ability_index'] ?? null,
                'step'          => 'pick_success_live',
                'group'         => $group,
                'filter'        => $filter,
                'prompt'        => 'Choose 1 ' . $group . ' Live from your Success Live area to put into the Waiting Room.',
                'candidates'    => array_map('cardPromptSummary', $succ),
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_success_live') {
            $successId = $data['card_id'] ?? '';
            $successCard = null;
            foreach ($ownerP['success_lives'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $successId
                    && cardMatchesGroup($c, $group, $filter)) {
                    $successCard = $c;
                    array_splice($ownerP['success_lives'], $i, 1);
                    break;
                }
            }
            if ($successCard === null) {
                throw new Exception('Choose a Success Live card');
            }
            $ownerP['waiting_room'][] = $successCard;
            $wrLives = array_values(array_filter(
                $ownerP['waiting_room'],
                fn($c) => cardMatchesGroup($c, $group, $filter)
            ));
            if (empty($wrLives)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] put ' .
                    cardDisplayName($successCard) . ' into the Waiting Room (no WR Live to swap).');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_success_wr_live_swap',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_id'     => $prompt['source_id'] ?? '',
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'ability'       => $prompt['ability'] ?? [],
                'ability_index' => $prompt['ability_index'] ?? null,
                'step'          => 'pick_wr_live',
                'group'         => $group,
                'filter'        => $filter,
                'prompt'        => 'Choose 1 ' . $group . ' Live from your Waiting Room to put into your Success Live area.',
                'candidates'    => array_map('cardPromptSummary', $wrLives),
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_wr_live') {
            $cardId = $data['card_id'] ?? '';
            $wrLive = null;
            foreach ($ownerP['waiting_room'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $cardId
                    && cardMatchesGroup($c, $group, $filter)) {
                    $wrLive = $c;
                    array_splice($ownerP['waiting_room'], $i, 1);
                    break;
                }
            }
            if ($wrLive === null) {
                throw new Exception('Choose a Live card from your Waiting Room');
            }
            $ownerP['success_lives'][] = $wrLive;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] put ' .
                cardDisplayName($wrLive) . ' from Waiting Room into Success Live area.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
    }

    if ($promptType === 'optional_success_live_swap') {
        $step = $prompt['step'] ?? 'confirm';
        if ($step === 'confirm') {
            if (!isset(['yes' => true, 'no' => true][$choice])) {
                throw new Exception('Invalid choice');
            }
            if ($choice === 'no') {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional effect.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return $state;
            }
            $liveHand = array_values(array_filter(
                $ownerP['hand'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if (empty($liveHand) || empty($ownerP['success_lives'])) {
                throw new Exception('Need a Live card in hand and a Success Live card');
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_success_live_swap',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_id'     => $prompt['source_id'] ?? '',
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'ability'       => $prompt['ability'] ?? null,
                'ability_index' => $prompt['ability_index'] ?? null,
                'step'          => 'pick_hand_live',
                'prompt'        => 'Choose 1 Live card from your hand to reveal.',
                'candidates'    => array_map('cardPromptSummary', $liveHand),
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_hand_live') {
            $handLiveId = $data['card_id'] ?? '';
            $found = null;
            foreach ($ownerP['hand'] as $c) {
                if (($c['instance_id'] ?? '') === $handLiveId
                    && ($c['card_type'] ?? '') === 'ライブ') {
                    $found = $c;
                    break;
                }
            }
            if (!$found) throw new Exception('Choose a Live card from your hand');
            $srcName = $prompt['source_name'] ?? 'Member';
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] revealed ' . cardDisplayName($found) . ' from hand.',
                'effect',
                [animSpec($handLiveId, 'hand', 'hand', $owner, ['reveal' => true, 'card' => cardPromptSummary($found)])]);
            $state = queuePublicSkillReveal($state, $owner, [$found], $srcName, 'hand');
            $state['pending_prompt'] = [
                'type'          => 'optional_success_live_swap',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_id'     => $prompt['source_id'] ?? '',
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'ability'       => $prompt['ability'] ?? null,
                'ability_index' => $prompt['ability_index'] ?? null,
                'step'          => 'pick_success_live',
                'hand_live_id'  => $handLiveId,
                'prompt'        => 'Choose 1 card from your Success Live area to add to your hand.',
                'candidates'    => array_map('cardPromptSummary', $ownerP['success_lives'] ?? []),
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_success_live') {
            $successId = $data['card_id'] ?? '';
            $handLiveId = $prompt['hand_live_id'] ?? '';
            $successIdx = null;
            $successCard = null;
            foreach ($ownerP['success_lives'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $successId) {
                    $successIdx = $i;
                    $successCard = $c;
                    break;
                }
            }
            if ($successCard === null) throw new Exception('Choose a Success Live card');
            $handLive = null;
            $handIdx = null;
            foreach ($ownerP['hand'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $handLiveId) {
                    $handLive = $c;
                    $handIdx = $i;
                    break;
                }
            }
            if ($handLive === null) throw new Exception('Revealed Live card no longer in hand');
            array_splice($ownerP['success_lives'], $successIdx, 1);
            array_splice($ownerP['hand'], $handIdx, 1);
            $ownerP['hand'][] = $successCard;
            $ownerP['success_lives'][] = $handLive;
            $srcName = $prompt['source_name'] ?? 'Member';
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] swapped ' .
                cardDisplayName($handLive) . ' into Success Live and added ' .
                cardDisplayName($successCard) . ' to hand.',
                'effect',
                [
                    animSpec($handLiveId, 'hand', 'success', $owner),
                    animSpec($successId, 'success', 'hand', $owner),
                ]);
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }
    }

    if ($promptType === 'optional_wait_mus_hearts') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            if (waitFirstGroupMember($ownerP, $ability['group'] ?? 'μ\'s', $state)) {
                addBonusHeartsToModifier($state, $owner, $ability['hearts'] ?? []);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] Waited a μ\'s Member for bonus hearts.');
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional Live Start effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = finishLiveStartEffects($state);
        return $state;
    }

    if ($promptType === 'optional_wait_self_surveil') {
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $sourceId = $prompt['source_id'] ?? '';
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    waitMember($mbr, $state);
                    break;
                }
            }
            unset($mbr);
            $look = intval($ability['look'] ?? 2);
            $top = array_splice($ownerP['main_deck'], 0, min($look, count($ownerP['main_deck'])));
            if (count($top) <= 1) {
                if (count($top) === 1) {
                    $ownerP['main_deck'] = array_merge($top, $ownerP['main_deck']);
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] Waited self; looked at top $look.");
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $state = startSurveilArrangePrompt($state, $owner, $prompt['source_name'] ?? 'Member', $top);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . "] Waited self; arrange top $look.");
            $state['seq']++;
            return $state;
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'optional_wait_self_look_reveal') {
        if (($prompt['step'] ?? '') === 'discard') {
            $discardNeed = intval($prompt['discard_count'] ?? $ability['discard'] ?? 0);
            $ids = $data['discard_ids'] ?? [];
            if ($discardNeed < 1 || count($ids) !== $discardNeed) {
                throw new Exception("Must discard exactly $discardNeed card(s) from hand");
            }
            discardFromHandByIds($ownerP, $ids, $state, $owner);
            $sourceId = $prompt['source_id'] ?? '';
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    waitMember($mbr, $state);
                    break;
                }
            }
            unset($mbr);
            $cfg = $ability;
            unset($state['pending_prompt']);
            $state = beginLookRevealPick(
                $state,
                $owner,
                $prompt['source_name'] ?? 'Member',
                $ownerP,
                $cfg
            );
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] Waited self; looked at deck top.');
            $state['seq']++;
            return $state;
        }
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $discardNeed = intval($prompt['discard_count'] ?? $ability['discard'] ?? 0);
            if ($discardNeed > 0) {
                $ids = $data['discard_ids'] ?? [];
                if (count($ids) !== $discardNeed) {
                    $state['pending_prompt'] = [
                        'type'          => 'optional_wait_self_look_reveal',
                        'owner'         => $owner,
                        'responder'     => $owner,
                        'source_id'     => $prompt['source_id'] ?? '',
                        'source_name'   => $prompt['source_name'] ?? 'Member',
                        'prompt'        => "Discard $discardNeed card(s) from your hand to look at the top of your deck.",
                        'discard_count' => $discardNeed,
                        'ability'       => $ability,
                        'step'          => 'discard',
                    ];
                    $state['seq']++;
                    return $state;
                }
                discardFromHandByIds($ownerP, $ids, $state, $owner);
            }
            $sourceId = $prompt['source_id'] ?? '';
            foreach ($ownerP['stage'] as &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                    waitMember($mbr, $state);
                    break;
                }
            }
            unset($mbr);
            $cfg = $ability;
            unset($state['pending_prompt']);
            $state = beginLookRevealPick(
                $state,
                $owner,
                $prompt['source_name'] ?? 'Member',
                $ownerP,
                $cfg
            );
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] Waited self; looked at deck top.');
            $state['seq']++;
            return $state;
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'optional_pay_energy_up_to') {
        $paid = intval($choice);
        $max = intval($ability['max_cost'] ?? 2);
        if ($paid < 0 || $paid > $max) throw new Exception('Invalid Energy payment');
        if ($paid > 0 && !payEnergyCost($ownerP, $paid)) {
            throw new Exception("Need $paid active Energy");
        }
        $then = $ability['then'] ?? [];
        if ($paid > 0 && ($then['type'] ?? '') === 'blade_bonus_per_paid') {
            $then['paid'] = $paid;
            $state = applyModifierEffect($state, $owner, $then);
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] paid $paid Energy for Blade bonus.");
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'pick_named_members_grant_hearts') {
        $slot = $data['slot'] ?? $choice;
        $hearts = $prompt['hearts'] ?? [];
        $step = $prompt['step'] ?? 'pick_named';
        $group = $ability['group'] ?? 'Superstar';
        if ($step === 'pick_named') {
            $mbr = $ownerP['stage'][$slot] ?? null;
            if (!$mbr) throw new Exception('Choose a Member');
            $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
            $namedOk = false;
            foreach ($prompt['named_list'] ?? [] as $n) {
                if ($label === $n || str_contains($label, $n)) { $namedOk = true; break; }
            }
            if (!$namedOk) throw new Exception('Choose a named Member');
            $otherCandidates = [];
            foreach ($ownerP['stage'] as $oslot => $ombr) {
                if (!$ombr || $oslot === $slot) {
                    continue;
                }
                if (($ombr['group'] ?? '') !== $group) {
                    continue;
                }
                $otherCandidates[] = array_merge(cardPromptSummary($ombr), [
                    'slot' => $oslot,
                    'named' => false,
                ]);
            }
            if ($otherCandidates === []) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . '] could not choose a second Liella! Member (bonus hearts skipped).');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishLiveStartEffects($state);
            }
            $state['pending_prompt'] = array_merge($prompt, [
                'step'           => 'pick_other',
                'first_slot'     => $slot,
                'candidates'     => $otherCandidates,
                'prompt'         => 'Choose 1 other Liella! Member for bonus hearts.',
                'responder'      => $owner,
            ]);
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_other') {
            if ($slot === '' || $slot === null || $choice === 'cancel' || $choice === 'skip') {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped second Member (bonus hearts cancelled).');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishLiveStartEffects($state);
            }
            if ($slot === ($prompt['first_slot'] ?? '')) {
                throw new Exception('Choose a different Member');
            }
            $mbr = $ownerP['stage'][$slot] ?? null;
            if (!$mbr || ($mbr['group'] ?? '') !== $group) {
                throw new Exception('Choose another Liella! Member');
            }
            $firstSlot = $prompt['first_slot'] ?? '';
            $first = $ownerP['stage'][$firstSlot] ?? null;
            if ($first) {
                addBonusHeartsToMember($first, $hearts, 1);
                $ownerP['stage'][$firstSlot] = $first;
            }
            addBonusHeartsToMember($mbr, $hearts, 1);
            $ownerP['stage'][$slot] = $mbr;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] granted bonus hearts to 2 Members.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
    }

    if ($promptType === 'pick_named_members_grant_blade') {
        $slot = $data['slot'] ?? $choice;
        $blade = intval($prompt['blade'] ?? 1);
        $step = $prompt['step'] ?? 'pick_named';
        $group = $ability['group'] ?? 'Superstar';
        if ($step === 'pick_named') {
            $mbr = $ownerP['stage'][$slot] ?? null;
            if (!$mbr) throw new Exception('Choose a Member');
            $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
            $namedOk = false;
            foreach ($prompt['named_list'] ?? [] as $n) {
                if ($label === $n || str_contains($label, $n)) { $namedOk = true; break; }
            }
            if (!$namedOk) throw new Exception('Choose a named Member');
            // Defer blade until both picks succeed (avoids partial grant + empty second UI #68).
            $otherCandidates = [];
            foreach ($ownerP['stage'] as $oslot => $ombr) {
                if (!$ombr || $oslot === $slot) {
                    continue;
                }
                if (($ombr['group'] ?? '') !== $group) {
                    continue;
                }
                $otherCandidates[] = array_merge(cardPromptSummary($ombr), [
                    'slot' => $oslot,
                    'named' => false,
                ]);
            }
            if ($otherCandidates === []) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . '] could not choose a second Liella! Member (+Blade skipped).');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishLiveStartEffects($state);
            }
            $state['pending_prompt'] = array_merge($prompt, [
                'step'           => 'pick_other',
                'first_slot'     => $slot,
                'candidates'     => $otherCandidates,
                'prompt'         => 'Choose 1 other Liella! Member for +Blade.',
                'responder'      => $owner,
            ]);
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_other') {
            // Empty second-step / softlock recovery (#68).
            if ($slot === '' || $slot === null || $choice === 'cancel' || $choice === 'skip') {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped second Member (+Blade cancelled).');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishLiveStartEffects($state);
            }
            if ($slot === ($prompt['first_slot'] ?? '')) {
                throw new Exception('Choose a different Member');
            }
            $mbr = $ownerP['stage'][$slot] ?? null;
            if (!$mbr || ($mbr['group'] ?? '') !== $group) {
                throw new Exception('Choose another Liella! Member');
            }
            $firstSlot = $prompt['first_slot'] ?? '';
            $first = $ownerP['stage'][$firstSlot] ?? null;
            if ($first) {
                $first['live_blade_bonus'] = intval($first['live_blade_bonus'] ?? 0) + $blade;
                $ownerP['stage'][$firstSlot] = $first;
            }
            $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $blade;
            $ownerP['stage'][$slot] = $mbr;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] granted +$blade Blade to 2 Members.");
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
    }

    if ($promptType === 'optional_formation_change_group') {
        $step = (string)($prompt['step'] ?? '');
        $srcName = (string)($prompt['source_name'] ?? 'Live');

        if ($step === '' && in_array($choice, ['no', 'skip'], true)) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] skipped formation change.');
            unset($state['pending_prompt']);
            $state['seq']++;
            if (($state['phase'] ?? '') === 'live_success_effects') {
                return finishLiveSuccessEffects($state);
            }
            return finishLiveStartEffects($state);
        }

        // One-shot API / tests may send full slot→id map with yes.
        if ($step === '' && $choice === 'yes' && is_array($data['assignments'] ?? null)) {
            $state = applyOptionalFormationChangeGroup($state, $owner, $prompt, $data['assignments']);
            $state['seq'] = intval($state['seq'] ?? 0) + 1;
            if (!empty($state['pending_prompt'])) {
                if (($state['phase'] ?? '') === 'live_success_effects') {
                    return finishLiveSuccessEffects($state);
                }
                return finishPromptEffects($state);
            }
            if (($state['phase'] ?? '') === 'live_success_effects') {
                return finishLiveSuccessEffects($state);
            }
            return finishLiveStartEffects($state);
        }

        // Yes without assignments → interactive per-Member area picks (issue #108).
        if ($step === '' && $choice === 'yes') {
            $queue = [];
            foreach (['left', 'center', 'right'] as $slot) {
                $mbr = $ownerP['stage'][$slot] ?? null;
                if (!$mbr) {
                    continue;
                }
                $queue[] = array_merge(cardPromptSummary($mbr), [
                    'from_slot' => $slot,
                    'slot' => $slot,
                ]);
            }
            if ($queue === []) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . $srcName . '] no Stage Members to formation-change.');
                unset($state['pending_prompt']);
                $state['seq']++;
                if (($state['phase'] ?? '') === 'live_success_effects') {
                    return finishLiveSuccessEffects($state);
                }
                return finishLiveStartEffects($state);
            }
            $first = $queue[0];
            $state['pending_prompt'] = array_merge($prompt, [
                'step' => 'assign',
                'assign_queue' => $queue,
                'assign_index' => 0,
                'assignments' => [],
                'target_slots' => ['left', 'center', 'right'],
                'choices' => [],
                'choice_labels' => [],
                'current_member_id' => (string)($first['instance_id'] ?? ''),
                'prompt' => 'Choose an area for '
                    . (string)($first['name_en'] ?? $first['name'] ?? 'Member') . '.',
            ]);
            $state['seq']++;
            return $state;
        }

        if ($step === 'assign') {
            $dest = (string)($data['slot'] ?? '');
            if ($dest === '' || $dest === 'yes' || $dest === 'no' || $dest === 'skip') {
                $dest = (string)$choice;
            }
            $remaining = array_values(array_filter(
                is_array($prompt['target_slots'] ?? null) ? $prompt['target_slots'] : ['left', 'center', 'right'],
                static fn($s): bool => is_string($s) && $s !== ''
            ));
            if (!in_array($dest, $remaining, true)) {
                throw new Exception('Choose a valid Stage area');
            }
            $queue = is_array($prompt['assign_queue'] ?? null) ? $prompt['assign_queue'] : [];
            $idx = intval($prompt['assign_index'] ?? 0);
            $member = $queue[$idx] ?? null;
            $memberId = (string)($member['instance_id'] ?? '');
            if ($memberId === '') {
                throw new Exception('No Member to place');
            }
            $assignments = is_array($prompt['assignments'] ?? null) ? $prompt['assignments'] : [];
            $assignments[$dest] = $memberId;
            $remaining = array_values(array_filter($remaining, static fn($s): bool => $s !== $dest));
            $idx++;

            // Last Member auto-fills the last open area.
            while ($idx < count($queue) && count($remaining) === 1) {
                $autoId = (string)($queue[$idx]['instance_id'] ?? '');
                if ($autoId === '') {
                    break;
                }
                $assignments[$remaining[0]] = $autoId;
                $remaining = [];
                $idx++;
            }

            if ($idx >= count($queue)) {
                $state = applyOptionalFormationChangeGroup($state, $owner, $prompt, $assignments);
                $state['seq'] = intval($state['seq'] ?? 0) + 1;
                if (!empty($state['pending_prompt'])) {
                    if (($state['phase'] ?? '') === 'live_success_effects') {
                        return finishLiveSuccessEffects($state);
                    }
                    return finishPromptEffects($state);
                }
                if (($state['phase'] ?? '') === 'live_success_effects') {
                    return finishLiveSuccessEffects($state);
                }
                return finishLiveStartEffects($state);
            }

            $next = $queue[$idx];
            $state['pending_prompt'] = array_merge($prompt, [
                'step' => 'assign',
                'assign_queue' => $queue,
                'assign_index' => $idx,
                'assignments' => $assignments,
                'target_slots' => $remaining,
                'choices' => [],
                'choice_labels' => [],
                'current_member_id' => (string)($next['instance_id'] ?? ''),
                'prompt' => 'Choose an area for '
                    . (string)($next['name_en'] ?? $next['name'] ?? 'Member') . '.',
            ]);
            $state['seq']++;
            return $state;
        }

        throw new Exception('Invalid formation change choice');
    }

    if ($promptType === 'wait_pick_member_grant_live_score') {
        if ($choice === 'cancel' || $choice === 'skip') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $slot = $data['slot'] ?? '';
        if ($slot === '' || empty($ownerP['stage'][$slot])) throw new Exception('Choose a Member');
        if (!($ownerP['stage'][$slot]['active'] ?? true)) {
            throw new Exception('Choose an active Member');
        }
        waitMember($ownerP['stage'][$slot], $state);
        grantMemberLiveScoreBonus(
            $state,
            $owner,
            $ownerP['stage'][$slot]['instance_id'] ?? '',
            intval($prompt['amount'] ?? 1)
        );
        $srcSlot = findMemberSlot($ownerP, $prompt['source_id'] ?? '');
        if ($srcSlot !== '' && !empty($ownerP['stage'][$srcSlot])) {
            foreach ($ownerP['stage'][$srcSlot]['abilities'] ?? [] as $idx => $ab) {
                if (($ab['type'] ?? '') === 'wait_pick_member_grant_live_score'
                    && !empty($ab['once_per_turn'])) {
                    markAbilityUsed($ownerP['stage'][$srcSlot], $idx);
                    break;
                }
            }
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] Waited a Member (+1 Live score until Live ends).');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'optional_discard_blade_per_card') {
        if ($choice === 'yes') {
            $ids = $data['discard_ids'] ?? [];
            $n = count($ids);
            $maxDiscard = intval($prompt['max_discard'] ?? $prompt['ability']['max_discard'] ?? 2);
            if ($n > $maxDiscard) {
                throw new Exception("Must select at most $maxDiscard card(s) to discard");
            }
            // "Up to N" — Yes with 0 cards is a no-op (same as Skip).
            if ($n >= 1) {
                discardFromHandByIds($ownerP, $ids, $state, $owner);
                $bladePer = intval($prompt['ability']['blade_per'] ?? 1);
                $state = applyModifierEffect($state, $owner, [
                    'type'   => 'blade_bonus',
                    'amount' => $n * $bladePer,
                ], ['instance_id' => (string)($prompt['source_id'] ?? '')]);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [{$prompt['source_name']}] discarded $n; gained +" . ($n * $bladePer) . ' Blade until Live ends.');
            }
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'score_if_stage_member_hearts') {
        $slot = $data['slot'] ?? '';
        if ($slot === '') {
            throw new Exception('Choose a Member');
        }
        $mbr = $ownerP['stage'][$slot] ?? null;
        $group = $prompt['group'] ?? $ability['group'] ?? 'Sunshine';
        if (!$mbr || ($mbr['group'] ?? '') !== $group) {
            throw new Exception('Choose an Aqours Member');
        }
        $checkBlades = !empty($prompt['check_blades'])
            || intval($prompt['min_blades'] ?? 0) > 0
            || !empty($ability['min_blades']);
        $threshold = $checkBlades
            ? intval($prompt['min_blades'] ?? $ability['min_blades'] ?? 6)
            : intval($prompt['min_hearts'] ?? $ability['min_hearts'] ?? 6);
        $ok = $checkBlades
            ? (getMemberBlade($mbr, $state, $owner, $slot) >= $threshold)
            : (memberHeartCount($mbr) >= $threshold);
        $amount = intval($prompt['amount'] ?? 1);
        if ($ok) {
            $bumped = bumpLiveCardScore($state, $owner, $prompt['source_id'] ?? '', $amount);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] ' .
                ($bumped ? ("score +$amount.") : 'score bonus failed (Live card missing).'));
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] no score (' .
                ($checkBlades ? 'Member under Blade threshold' : 'Member under heart threshold') . ').');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'live_success_yell_live_deck_bottom' || $promptType === 'optional_wr_live_deck_bottom') {
        if ($choice === 'skip' || $choice === 'no') {
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $cardId = $data['card_id'] ?? '';
        if ($cardId === '') throw new Exception('Choose a Live card');
        $pool = $promptType === 'live_success_yell_live_deck_bottom'
            ? ($ownerP['_pending_yell_wr'] ?? [])
            : $ownerP['waiting_room'];
        $picked = null;
        $rest = [];
        foreach ($pool as $c) {
            if (($c['instance_id'] ?? '') === $cardId) {
                $picked = $c;
            } else {
                $rest[] = $c;
            }
        }
        if (!$picked) throw new Exception('Invalid card');
        if ($promptType === 'live_success_yell_live_deck_bottom') {
            $ownerP['_pending_yell_wr'] = $rest;
        } else {
            $ownerP['waiting_room'] = $rest;
        }
        $ownerP['main_deck'][] = $picked;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — put ' . cardDisplayName($picked) . ' on the bottom of the deck.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'opp_may_discard_or_modifier') {
        $ab = $prompt['ability'] ?? [];
        $ownerId = $prompt['owner'] ?? $owner;
        if ($choice === 'yes') {
            $discardIds = $data['discard_ids'] ?? [];
            if (count($discardIds) !== 1) {
                throw new Exception('Must discard exactly 1 Live card from hand');
            }
            $responderP = &$state['players'][$pid];
            $discarded = discardHandCardsByIds($responderP, $discardIds, $state, $pid);
            foreach ($discarded as $c) {
                if (($c['card_type'] ?? '') !== 'ライブ') {
                    throw new Exception('Must discard a Live card');
                }
                $state = logEffectPutWr($state, $pid, $prompt['source_name'] ?? 'Member', $c,
                    [animSpec($c['instance_id'], 'hand', 'waiting_room', $pid)]);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — discarded Live card (' . ($prompt['source_name'] ?? 'effect') . ').');
        } else {
            $mod = $ab['else_modifier'] ?? ['type' => 'live_score_bonus', 'amount' => 1];
            $state = applyModifierEffect($state, $ownerId, $mod);
            $state = addLog($state, $state['players'][$ownerId]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] gains +' .
                intval($mod['amount'] ?? 1) . ' total Live Score until this Live ends.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'reveal_live_opp_discard_or_blade') {
        $ab = $prompt['ability'] ?? [];
        $ownerId = $prompt['owner'] ?? $owner;
        $slot = $prompt['source_slot'] ?? '';
        $abIdx = intval($prompt['ability_index'] ?? 0);
        if ($choice === 'yes') {
            $discardIds = $data['discard_ids'] ?? [];
            if (count($discardIds) !== 1) {
                throw new Exception('Must discard exactly 1 card from hand');
            }
            discardHandCardsByIds($state['players'][$pid], $discardIds, $state, $pid);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — discarded 1 card (' . ($prompt['source_name'] ?? 'effect') . ').');
        } else {
            $amount = intval($ab['blade_amount'] ?? 4);
            if ($slot !== '' && !empty($state['players'][$ownerId]['stage'][$slot])) {
                $mbr = &$state['players'][$ownerId]['stage'][$slot];
                $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $amount;
                if (!empty($ab['once_per_turn'])) {
                    markAbilityUsed($mbr, $abIdx);
                }
                $state = addLog($state, $state['players'][$ownerId]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] gains +$amount Blade until this Live ends.");
            }
        }
        if ($choice === 'yes' && !empty($ab['once_per_turn'])
            && $slot !== '' && !empty($state['players'][$ownerId]['stage'][$slot])) {
            markAbilityUsed($state['players'][$ownerId]['stage'][$slot], $abIdx);
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_surveil_heart_threshold') {
        $looked = $state['surveil_stash'] ?? [];
        if ($choice === 'skip' || $choice === '') {
            if (!empty($looked)) {
                $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $looked);
            }
        } else {
            $rest = [];
            foreach ($looked as $c) {
                if (($c['instance_id'] ?? '') === $choice) {
                    $ownerP['hand'][] = $c;
                } else {
                    $rest[] = $c;
                }
            }
            if (!empty($rest)) {
                $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $rest);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] added 1 card from surveil to hand.');
        }
        unset($state['surveil_stash'], $state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'pick_looked_deck_hand') {
        $ownerP = &$state['players'][$owner];
        $looked = $state['surveil_stash'] ?? [];
        $eligibleIds = $prompt['eligible_ids'] ?? [];
        $pickCount = intval($prompt['pick_count'] ?? 1);
        $optional = !empty($prompt['optional']);
        $srcName = $prompt['source_name'] ?? 'Member';
        $resolveChoice = $data['choice'] ?? $choice;
        $ability = $prompt['ability'] ?? [];
        $destMode = $ability['destination'] ?? 'hand';

        if (($prompt['step'] ?? '') === 'pick_destination') {
            $pickId = $prompt['pick_id'] ?? '';
            $slotChoice = $data['slot'] ?? ($resolveChoice !== 'hand' ? $resolveChoice : '');
            if ($pickId === '') {
                throw new Exception('Invalid looked card');
            }
            $pickedCard = null;
            $rest = [];
            foreach ($looked as $c) {
                if (($c['instance_id'] ?? '') === $pickId) {
                    $pickedCard = $c;
                } else {
                    $rest[] = $c;
                }
            }
            if (!$pickedCard) {
                throw new Exception('Invalid looked card');
            }
            if ($resolveChoice === 'hand' || $slotChoice === 'hand') {
                $ownerP['hand'][] = $pickedCard;
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$srcName] added " . cardDisplayName($pickedCard) . ' to hand.');
                unset($state['pending_prompt']);
                $state = appendDeckCardsToWaitingRoom($state, $owner, $rest);
                return finishAfterDeckCardsToWaitingRoom($state);
            }
            if (!in_array($slotChoice, $prompt['slots'] ?? [], true)) {
                throw new Exception('Choose an empty Stage area');
            }
            if (!empty($ownerP['stage'][$slotChoice])) {
                throw new Exception('Stage area is not empty');
            }
            $pickedCard['active'] = true;
            $pickedCard['entered_turn'] = intval($state['turn'] ?? 1);
            $ownerP['stage'][$slotChoice] = $pickedCard;
            unset($state['pending_prompt']);
            $state = appendDeckCardsToWaitingRoom($state, $owner, $rest);
            $millPrompt = (($state['pending_prompt']['bp7_action'] ?? '') === 'self_milled_recover')
                ? $state['pending_prompt'] : null;
            unset($state['surveil_stash'], $state['pending_prompt']);
            $state = resolveOnEnterAbilities($state, $owner, $pickedCard, $slotChoice);
            if (empty($state['pending_prompt']) && $millPrompt) {
                $state['pending_prompt'] = $millPrompt;
            } elseif (!empty($state['pending_prompt']) && $millPrompt) {
                $state['_bp7_chain_prompt'] = $millPrompt;
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] played ' . cardDisplayName($pickedCard) .
                ' from deck to ' . $slotChoice . '.');
            return returnAfterPlacedMemberEnter($state, false, $prompt);
        }

        if ($optional && ($resolveChoice === 'no' || $resolveChoice === 'cancel')) {
            $resolveChoice = 'skip';
        }

        if ($resolveChoice === 'skip') {
            if (!$optional) {
                throw new Exception('Must pick a card');
            }
            unset($state['pending_prompt']);
            $state = appendDeckCardsToWaitingRoom($state, $owner, $looked);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$srcName] put all looked cards into the Waiting Room.");
        } else {
            $pickIds = [];
            if (!empty($data['card_ids'])) {
                $pickIds = array_values($data['card_ids']);
            } elseif (!empty($data['card_id'])) {
                $pickIds = [$data['card_id']];
            } elseif ($resolveChoice !== '' && $resolveChoice !== 'skip') {
                $pickIds = [$resolveChoice];
            }
            if (count($pickIds) > $pickCount) {
                throw new Exception("Must select at most $pickCount card(s)");
            }
            if (!$optional && count($pickIds) !== $pickCount) {
                throw new Exception("Must select exactly $pickCount card(s)");
            }
            if ($optional && empty($pickIds)) {
                unset($state['pending_prompt']);
                $state = appendDeckCardsToWaitingRoom($state, $owner, $looked);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$srcName] put all looked cards into the Waiting Room.");
            } else {
            $lookedIds = array_map(fn($c) => $c['instance_id'] ?? '', $looked);
            foreach ($pickIds as $id) {
                if (!in_array($id, $lookedIds, true)) {
                    throw new Exception('Invalid looked card');
                }
                if (!in_array($id, $eligibleIds, true)) {
                    throw new Exception('Card not eligible to pick');
                }
            }
            if (!empty($ability['distinct_groups'])) {
                $seenGroups = [];
                foreach ($pickIds as $id) {
                    foreach ($looked as $c) {
                        if (($c['instance_id'] ?? '') !== $id) {
                            continue;
                        }
                        $g = (string)($c['group'] ?? '');
                        if ($g === '' || isset($seenGroups[$g])) {
                            throw new Exception('Must pick at most 1 card per group name');
                        }
                        $seenGroups[$g] = true;
                        break;
                    }
                }
            }
            $pickedN = count($pickIds);
            if ($destMode === 'hand_or_stage' && $pickedN === 1) {
                $pickedCard = null;
                foreach ($looked as $c) {
                    if (($c['instance_id'] ?? '') === $pickIds[0]) {
                        $pickedCard = $c;
                        break;
                    }
                }
                if ($pickedCard && ($pickedCard['card_type'] ?? '') === 'メンバー') {
                    $validSlots = [];
                    foreach (['left', 'center', 'right'] as $s) {
                        if (empty($ownerP['stage'][$s])) {
                            $validSlots[] = $s;
                        }
                    }
                    $state['pending_prompt'] = [
                        'type'        => 'pick_looked_deck_hand',
                        'step'        => 'pick_destination',
                        'owner'       => $owner,
                        'responder'   => $owner,
                        'source_name' => $srcName,
                        'pick_id'     => $pickIds[0],
                        'slots'       => $validSlots,
                        'prompt'      => 'Add ' . cardDisplayName($pickedCard) . ' to your hand or play it to an empty Stage area?',
                        'ability'     => $ability,
                    ];
                    $state['seq']++;
                    return $state;
                }
            }
            $pickedCards = [];
            foreach ($looked as $c) {
                $cid = (string)($c['instance_id'] ?? $c['id'] ?? '');
                if ($cid !== '' && in_array($cid, $pickIds, true)) {
                    $pickedCards[] = $c;
                }
            }
            $restLooked = applyLookPickHand($state['players'][$owner], $looked, $pickIds);
            unset($state['pending_prompt']);
            $state = appendDeckCardsToWaitingRoom($state, $owner, $restLooked);
            $state = queuePublicSkillReveal($state, $owner, $pickedCards, $srcName, 'deck');
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$srcName] added $pickedN card(s) from looked deck to hand.");
            $then = $ability['then'] ?? [];
            if (($then['type'] ?? '') === 'wait_opponent_by_revealed' && !empty($pickIds)) {
                $revealed = null;
                foreach ($looked as $c) {
                    if (in_array($c['instance_id'] ?? '', $pickIds, true)) {
                        $revealed = $c;
                        break;
                    }
                }
                if ($revealed) {
                    $state = addLog($state, $state['players'][$owner]['name'] .
                        ' — [' . $srcName . '] revealed ' .
                        ($revealed['name_en'] ?? $revealed['name']) . ' from deck top.');
                    $opp = ($owner === 'p1') ? 'p2' : 'p1';
                    $maxCost = intval($revealed['cost'] ?? 0);
                    $maxBlade = intval($then['max_blade'] ?? 3);
                    $waited = 0;
                    foreach ($state['players'][$opp]['stage'] as &$mbr) {
                        if (!$mbr) {
                            continue;
                        }
                        if (intval($mbr['cost'] ?? 0) <= $maxCost
                            && intval($mbr['blade'] ?? 0) <= $maxBlade) {
                            waitMember($mbr, $state);
                            $waited++;
                        }
                    }
                    unset($mbr);
                    if ($waited > 0) {
                        $state = addLog($state, $state['players'][$opp]['name'] .
                            " — $waited opponent Member(s) put into Wait.");
                    }
                }
            }
            if (!empty($ability['hearts_if_group_picked']) && !empty($pickIds)) {
                foreach ($looked as $c) {
                    if (!in_array($c['instance_id'] ?? '', $pickIds, true)) continue;
                    if (cardMatchesGroup($c, $ability['blade_if_group_picked'] ?? '', '')) {
                        foreach ($ability['hearts_if_group_picked'] as $h) {
                            addBonusHeartsToModifier($state, $owner, [$h]);
                        }
                        if (!empty($ability['blade_if_group_picked'])) {
                            $state = applyModifierEffect($state, $owner, [
                                'type'   => 'blade_bonus',
                                'amount' => intval($ability['blade_amount'] ?? 1),
                            ]);
                        }
                        $state = addLog($state, $state['players'][$owner]['name'] .
                            ' — [' . $srcName . '] gained bonus heart(s) and Blade (Hasunosora card added).');
                        break;
                    }
                }
            } elseif (!empty($ability['blade_if_group_picked']) && !empty($pickIds)) {
                foreach ($looked as $c) {
                    if (!in_array($c['instance_id'] ?? '', $pickIds, true)) continue;
                    if (cardMatchesGroup($c, $ability['blade_if_group_picked'], '')) {
                        $state = applyModifierEffect($state, $owner, [
                            'type'   => 'blade_bonus',
                            'amount' => intval($ability['blade_amount'] ?? 3),
                        ]);
                        $state = addLog($state, $state['players'][$owner]['name'] .
                            ' — [' . $srcName . '] gained +' . intval($ability['blade_amount'] ?? 3) .
                            ' Blade (Hasunosora card added).');
                        break;
                    }
                }
            }
            }
        }
        return finishAfterDeckCardsToWaitingRoom($state);
    }

    if ($promptType === 'live_success_pick_yell_live') {
        if (($data['choice'] ?? $choice) === 'skip') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped Yell Live pick.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveSuccessEffects($state);
        }
        $cardId = $data['card_id'] ?? $choice;
        $eligibleIds = yellPromptCandidateIds($prompt);
        $picked = takeFromPendingYellPool($ownerP, $cardId, $prompt, $state, $owner);
        if (!$picked && count($eligibleIds) === 1) {
            $picked = takeFromPendingYellPool($ownerP, $eligibleIds[0], $prompt, $state, $owner);
        }
        if (!$picked) {
            if (empty($eligibleIds)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . '] no Yell Live cards available; skipped.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishLiveSuccessEffects($state);
            }
            throw new Exception('Choose a Live card revealed by Yell');
        }
        if (($picked['card_type'] ?? '') !== 'ライブ' && !isLiveTypeCard($picked)) {
            throw new Exception('Choose a Live card revealed by Yell');
        }
        $ownerP['hand'][] = $picked;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] added ' .
            cardDisplayName($picked) . ' from Yell to hand.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveSuccessEffects($state);
    }

    if ($promptType === 'optional_negate_member_live_start_add_wr') {
        if ($choice === 'skip' || $choice === 'no') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional negate.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }
        $targetSlot = '';
        foreach ($ownerP['stage'] as $s => $mbr) {
            if ($mbr && ($mbr['instance_id'] ?? '') === $choice) {
                $targetSlot = $s;
                // Until Live ends — Live Start fire is gated by live_start_negated today.
                $mbr['live_start_negated'] = true;
                $ownerP['stage'][$s] = $mbr;
                break;
            }
        }
        if ($targetSlot === '') {
            throw new Exception('Invalid Member');
        }
        $ab = $prompt['ability'] ?? [];
        // "1 Liella! / Superstar card" — any card type, not Members only.
        $cfg = [
            'group'  => $ab['group'] ?? 'Superstar',
            'filter' => '',
        ];
        $candidates = wrCandidatesMatching($ownerP, $cfg);
        $negatedName = cardDisplayName($ownerP['stage'][$targetSlot] ?? []);
        if (empty($candidates)) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . "] negated $negatedName's abilities until Live ends (no matching Waiting Room card).");
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }
        $state['pending_prompt'] = [
            'type'          => 'pick_wr_to_hand',
            'owner'         => $owner,
            'responder'     => $owner,
            'source_id'     => $prompt['source_id'] ?? '',
            'source_name'   => $prompt['source_name'] ?? 'Member',
            'prompt'        => 'Choose 1 Liella! card from your Waiting Room to add to your hand.',
            'candidates'    => array_map('cardPromptSummary', $candidates),
            'ability'       => $ab,
            'wr_pick_cfg'   => $cfg,
            'pick_count'    => 1,
        ];
        $state['seq']++;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] negated $negatedName's abilities; choose a Waiting Room card.");
        return $state;
    }

    if ($promptType === 'pick_wr_distinct_lives_opp_choice') {
        $pickIds = $data['card_ids'] ?? [];
        $need = intval($prompt['pick_count'] ?? 2);
        if (count($pickIds) !== $need) {
            throw new Exception("Choose exactly $need Live cards");
        }
        $stash = [];
        $names = [];
        $rest = [];
        foreach ($ownerP['waiting_room'] as $c) {
            $id = $c['instance_id'] ?? '';
            if (in_array($id, $pickIds, true)) {
                $label = $c['name_en'] ?? $c['name'] ?? '';
                if (isset($names[$label])) {
                    throw new Exception('Live cards must have different names');
                }
                $names[$label] = true;
                $stash[] = $c;
            } else {
                $rest[] = $c;
            }
        }
        if (count($stash) !== $need) throw new Exception('Invalid Waiting Room selection');
        $ownerP['waiting_room'] = $rest;
        $opp = ($owner === 'p1') ? 'p2' : 'p1';
        $state['_wr_live_offer'] = $stash;
        $state['pending_prompt'] = [
            'type'          => 'opp_pick_wr_live_offer',
            'owner'         => $owner,
            'responder'     => $opp,
            'source_name'   => $prompt['source_name'] ?? 'Member',
            'candidates'    => array_map('cardPromptSummary', $stash),
            'prompt'        => 'Choose 1 Live card for your opponent to add to their hand.',
            'choices'       => $pickIds,
            'choice_labels' => array_map(fn($c) => cardDisplayName($c), $stash),
            'ability'       => $prompt['ability'] ?? [],
        ];
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'opp_pick_wr_live_offer') {
        $cardId = $choice;
        $stash = $state['_wr_live_offer'] ?? [];
        $picked = null;
        $leftover = [];
        foreach ($stash as $c) {
            if (($c['instance_id'] ?? '') === $cardId) {
                $picked = $c;
            } else {
                $leftover[] = $c;
            }
        }
        if (!$picked) throw new Exception('Invalid Live card choice');
        $ownerP['hand'][] = $picked;
        if (!empty($leftover)) {
            $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'], $leftover);
        }
        unset($state['_wr_live_offer']);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] received ' .
            cardDisplayName($picked) . ' from Waiting Room (opponent chose).');
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'activated_swap_area_pick') {
        $toSlot = $choice;
        if (!in_array($toSlot, ['left', 'center', 'right'], true)) {
            throw new Exception('Invalid area');
        }
        $fromSlot = $prompt['source_slot'] ?? '';
        $member = $ownerP['stage'][$fromSlot] ?? null;
        if (!$member) throw new Exception('Member not on Stage');
        if (function_exists('spBp2MarkEffectAreaMove')) {
            spBp2MarkEffectAreaMove($state, $member);
        }
        $other = $ownerP['stage'][$toSlot] ?? null;
        $ownerP['stage'][$toSlot] = $member;
        $ownerP['stage'][$fromSlot] = $other;
        $idx = intval($prompt['ability_index'] ?? 0);
        markAbilityUsed($ownerP['stage'][$toSlot], $idx);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] moved to $toSlot area" .
            ($other ? ' (swapped).' : '.'));
        unset($state['pending_prompt']);
        // Displaced Member first so move-to-Center autos (pb2-022) are not blocked by
        // Center-leave choose prompts on the mover (#151).
        if ($other) {
            $state = resolveAutoAreaMoveAbilities($state, $owner, $other['instance_id'] ?? '', $toSlot);
        }
        if (empty($state['pending_prompt'])) {
            $state = resolveAutoAreaMoveAbilities($state, $owner, $member['instance_id'] ?? '', $fromSlot);
        }
        if (function_exists('spBp2ClearEffectAreaMove')) {
            spBp2ClearEffectAreaMove($state);
        }
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'optional_reveal_live_deck_bottom_surveil') {
        $step = $prompt['step'] ?? 'confirm';
        $lookN = intval($ability['look'] ?? 2);
        if ($step === 'confirm') {
            if (!isset(['yes' => true, 'no' => true][$choice])) {
                throw new Exception('Invalid choice');
            }
            if ($choice === 'no') {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional On Enter effect.');
                unset($state['pending_prompt']);
                $state['seq']++;
                // Must resume Live Start / optional queue — bare return softlocks Performance.
                return finishPromptEffects($state);
            }
            $liveHand = array_values(array_filter(
                $ownerP['hand'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if (empty($liveHand)) throw new Exception('Choose a Live card from your hand');
            $state['pending_prompt'] = [
                'type'          => 'optional_reveal_live_deck_bottom_surveil',
                'step'          => 'pick_hand_live',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'prompt'        => 'Reveal 1 Live card from your hand to put on the bottom of your deck.',
                'candidates'    => array_map('cardPromptSummary', $liveHand),
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_hand_live') {
            $cardId = $data['card_id'] ?? '';
            $picked = putHandLiveOnDeckBottom($ownerP, $cardId);
            if (!$picked) throw new Exception('Choose a Live card from your hand');
            $state = queuePublicSkillReveal($state, $owner, [$picked], $prompt['source_name'] ?? 'Member', 'hand');
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] revealed ' .
                cardDisplayName($picked) . ' and put it on the bottom of the deck.');
            $top = array_splice($ownerP['main_deck'], 0, min($lookN, count($ownerP['main_deck'])));
            if (empty($top)) {
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            if (count($top) === 1) {
                $ownerP['main_deck'] = array_merge($top, $ownerP['main_deck']);
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $state = startSurveilArrangePrompt(
                $state,
                $owner,
                $prompt['source_name'] ?? 'Member',
                $top
            );
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] looked at ' . count($top) . ' card(s).');
            return $state;
        }
    }

    if ($promptType === 'optional_wr_member_deck_top_blade') {
        $step = $prompt['step'] ?? 'confirm';
        $bladeAmt = intval($ability['blade_amount'] ?? 1);
        if ($step === 'confirm') {
            if (!isset(['yes' => true, 'no' => true][$choice])) {
                throw new Exception('Invalid choice');
            }
            if ($choice === 'no') {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped optional On Enter effect.');
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $wrMembers = array_values(array_filter(
                $ownerP['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
            ));
            if (empty($wrMembers)) throw new Exception('No Member in Waiting Room');
            $state['pending_prompt'] = [
                'type'          => 'optional_wr_member_deck_top_blade',
                'step'          => 'pick_wr_member',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Live',
                'prompt'        => 'Choose 1 Member from your Waiting Room to put on top of your deck.',
                'candidates'    => array_map('cardPromptSummary', $wrMembers),
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_wr_member') {
            $cardId = $data['card_id'] ?? '';
            $picked = putWrMemberOnDeckTop($ownerP, $cardId);
            if (!$picked) throw new Exception('Choose a Member from Waiting Room');
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] put ' .
                cardDisplayName($picked) . ' on deck top.');
            $stageMembers = listStageMemberChoices($ownerP);
            if (empty($stageMembers)) {
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_wr_member_deck_top_blade',
                'step'          => 'pick_stage_blade',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Live',
                'prompt'        => 'Choose 1 Stage Member to gain +' . $bladeAmt . ' Blade until this Live ends.',
                'candidates'    => $stageMembers,
                'blade_amount'  => $bladeAmt,
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_stage_blade') {
            $slot = $data['slot'] ?? '';
            if ($slot === '' || empty($ownerP['stage'][$slot])) {
                throw new Exception('Choose a Member on Stage');
            }
            $mbr = &$ownerP['stage'][$slot];
            $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $bladeAmt;
            unset($mbr);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] +$bladeAmt Blade on Stage Member until Live ends.");
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
    }

    if ($promptType === 'player_choice_wr_live_deck_bottom_draw') {
        $step = $prompt['step'] ?? 'pick_player';
        $drawN = intval($ability['draw'] ?? 1);
        if ($step === 'pick_player') {
            if (!in_array($choice, ['self', 'opponent'], true)) {
                throw new Exception('Choose yourself or your opponent');
            }
            $targetPid = $choice === 'self' ? $owner : (($owner === 'p1') ? 'p2' : 'p1');
            $targetP = $state['players'][$targetPid];
            $lives = array_values(array_filter(
                $targetP['waiting_room'],
                fn($c) => isLiveTypeCard($c)
            ));
            if (empty($lives)) throw new Exception('No Live card in that player\'s Waiting Room');
            $state['pending_prompt'] = [
                'type'          => 'player_choice_wr_live_deck_bottom_draw',
                'step'          => 'pick_wr_live',
                'owner'         => $owner,
                'responder'     => $owner,
                'target'        => $targetPid,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'prompt'        => 'Choose 1 Live card from ' .
                    ($choice === 'self' ? 'your' : 'opponent\'s') . ' Waiting Room.',
                'candidates'    => array_map('cardPromptSummary', $lives),
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_wr_live') {
            $cardId = $data['card_id'] ?? '';
            $targetPid = $prompt['target'] ?? $owner;
            $targetP = &$state['players'][$targetPid];
            $picked = putWrLiveOnDeckBottom($targetP, $cardId);
            if (!$picked) throw new Exception('Choose a Live card from Waiting Room');
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] put ' .
                cardDisplayName($picked) . ' on the bottom of ' .
                $state['players'][$targetPid]['name'] . '\'s deck.');
            $drawn = drawCardsForPlayer($state, $owner, $drawN);
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [" . ($prompt['source_name'] ?? 'Member') . "] drew $drawn.");
            unset($state['pending_prompt']);
            $state['seq']++;
            $phase = $state['phase'] ?? '';
            if ($phase === 'live_start_effects') {
                return finishLiveStartEffects($state);
            }
            return finishPromptEffects($state);
        }
    }

    if ($promptType === 'pay_energy_reveal_live_wr_superset') {
        $step = $prompt['step'] ?? 'reveal_hand_live';
        $slot = $prompt['slot'] ?? null;
        $abilityIdx = intval($prompt['ability_idx'] ?? 0);
        $srcName = $prompt['source_name'] ?? 'Member';

        if ($step === 'pick_wr_live') {
            $cardId = $data['card_id'] ?? $choice;
            $needle = $prompt['revealed_needle'] ?? '';
            $wrLive = null;
            foreach ($ownerP['waiting_room'] as $i => $c) {
                if (($c['instance_id'] ?? '') !== $cardId) continue;
                if (($c['card_type'] ?? '') !== 'ライブ') continue;
                $label = $c['name_en'] ?? $c['name'] ?? '';
                if ($needle !== '' && !wrLiveNameContainsNeedle($label, $needle)) {
                    throw new Exception('Choose a matching Live card from your Waiting Room');
                }
                $wrLive = $c;
                array_splice($ownerP['waiting_room'], $i, 1);
                break;
            }
            if (!$wrLive) {
                throw new Exception('Choose a Live card from your Waiting Room');
            }
            $ownerP['hand'][] = $wrLive;
            if ($slot !== null && !empty($ownerP['stage'][$slot])) {
                markAbilityUsed($ownerP['stage'][$slot], $abilityIdx);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] added ' . cardDisplayName($wrLive) . ' from Waiting Room to hand.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }

        $liveId = $data['card_id'] ?? $choice;
        $cost = intval($prompt['pay_cost'] ?? 2);
        if (!payEnergyCost($ownerP, $cost)) {
            throw new Exception("Need $cost active Energy");
        }
        $revealed = null;
        foreach ($ownerP['hand'] as $c) {
            if (($c['instance_id'] ?? '') === $liveId && ($c['card_type'] ?? '') === 'ライブ') {
                $revealed = $c;
                break;
            }
        }
        if (!$revealed) {
            throw new Exception('Choose a Live card from your hand');
        }
        $state = queuePublicSkillReveal($state, $owner, [$revealed], $srcName, 'hand');
        $needle = $revealed['name_en'] ?? $revealed['name'] ?? '';
        $wrLives = array_values(array_filter(
            $ownerP['waiting_room'],
            fn($c) => ($c['card_type'] ?? '') === 'ライブ'
                && wrLiveNameContainsNeedle($c['name_en'] ?? $c['name'] ?? '', $needle)
        ));
        if (empty($wrLives)) {
            if ($slot !== null && !empty($ownerP['stage'][$slot])) {
                markAbilityUsed($ownerP['stage'][$slot], $abilityIdx);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] revealed ' . cardDisplayName($revealed) .
                '; no matching Live in Waiting Room.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }
        if (count($wrLives) === 1) {
            $ownerP['hand'][] = $wrLives[0];
            $ownerP['waiting_room'] = array_values(array_filter(
                $ownerP['waiting_room'],
                fn($c) => ($c['instance_id'] ?? '') !== ($wrLives[0]['instance_id'] ?? '')
            ));
            if ($slot !== null && !empty($ownerP['stage'][$slot])) {
                markAbilityUsed($ownerP['stage'][$slot], $abilityIdx);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . $srcName . '] revealed ' . cardDisplayName($revealed) .
                '; added ' . cardDisplayName($wrLives[0]) . ' from Waiting Room.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return $state;
        }
        $state['pending_prompt'] = [
            'type'            => 'pay_energy_reveal_live_wr_superset',
            'owner'           => $owner,
            'responder'       => $owner,
            'source_id'       => $prompt['source_id'] ?? '',
            'source_name'     => $srcName,
            'ability_idx'     => $abilityIdx,
            'slot'            => $slot,
            'pay_cost'        => $cost,
            'step'            => 'pick_wr_live',
            'revealed_needle' => $needle,
            'revealed_live'   => cardPromptSummary($revealed),
            'candidates'      => array_map('cardPromptSummary', $wrLives),
            'prompt'          => 'Choose 1 Live from your Waiting Room whose name contains ' .
                cardDisplayName($revealed) . '.',
        ];
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . $srcName . '] revealed ' . cardDisplayName($revealed) . ' (choose WR Live).');
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'buff_member_matching_discarded_group') {
        $slot = $data['slot'] ?? $choice;
        if ($slot === '' || empty($ownerP['stage'][$slot])) {
            throw new Exception('Choose a Member on Stage');
        }
        $mbr = &$ownerP['stage'][$slot];
        addBonusHeartsToMember($mbr, $prompt['hearts'] ?? [['color' => 'pink', 'count' => 1]]);
        unset($mbr);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] granted bonus heart(s) until Live ends.');
        unset($state['pending_prompt']);
        $state['seq']++;
        if (($state['phase'] ?? '') === 'live_start_effects') {
            return resumeLiveStartEffectPhase($state);
        }
        return $state;
    }

    if ($promptType === 'live_cost_from_subunit_pick') {
        $slot = $data['slot'] ?? $choice;
        $sourceId = $prompt['source_id'] ?? '';
        if ($slot === '' || empty($ownerP['stage'][$slot])) {
            throw new Exception('Choose a Member on Stage');
        }
        $picked = $ownerP['stage'][$slot];
        $newCost = max(0, intval($picked['cost'] ?? 0) - 1);
        foreach ($ownerP['stage'] as $s => &$mbr) {
            if ($mbr && ($mbr['instance_id'] ?? '') === $sourceId) {
                $mbr['live_cost_override'] = $newCost;
                if ($newCost >= 10) {
                    $heartColor = $prompt['heart_color']
                        ?? ($prompt['ability']['heart_color'] ?? 'blue');
                    addBonusHeartsToModifier($state, $owner, [['color' => $heartColor, 'count' => 1]]);
                }
                break;
            }
        }
        unset($mbr);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] cost set to $newCost until Live ends.");
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishLiveStartEffects($state);
    }

    if ($promptType === 'live_start_edel_choice') {
        if ($choice === 'no' || $choice === 'skip' || $choice === 'cancel') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] skipped optional Live Start effect.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        if ($choice === 'reduce') {
            $liveId = $prompt['source_id'] ?? '';
            bumpLiveCardColorReduction(
                $state,
                $owner,
                $liveId,
                $ability['reduce_color'] ?? 'purple',
                1
            );
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] required purple hearts reduced by 1.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        if ($choice === 'play') {
            $subunit = $ability['subunit'] ?? 'Edel Note';
            $maxCost = intval($ability['max_cost'] ?? 4);
            $candidates = [];
            foreach ($ownerP['waiting_room'] as $c) {
                if (cardMatchesWrPick($c, [
                    'subunit'  => $subunit,
                    'filter'   => 'member',
                    'max_cost' => $maxCost,
                ])) {
                    $candidates[] = cardPromptSummary($c);
                }
            }
            if (empty($candidates)) throw new Exception('No matching Member in Waiting Room');
            $state['pending_prompt'] = [
                'type'        => 'live_start_edel_play_wr',
                'owner'       => $owner,
                'responder'   => $owner,
                'source_name' => $prompt['source_name'] ?? 'Live',
                'subunit'     => $subunit,
                'max_cost'    => $maxCost,
                'candidates'  => $candidates,
                'pick_count'  => 1,
                'wr_pick_cfg' => [
                    'subunit'  => $subunit,
                    'filter'   => 'member',
                    'max_cost' => $maxCost,
                ],
                'prompt'      => 'Choose 1 Edel Note Member from Waiting Room to play into an empty area.',
            ];
            $state['seq']++;
            return $state;
        }
        throw new Exception('Invalid choice');
    }

    if ($promptType === 'live_start_edel_play_wr') {
        $cardId = $data['card_id'] ?? $choice;
        $subunit = $prompt['subunit'] ?? 'Edel Note';
        $maxCost = intval($prompt['max_cost'] ?? 4);
        $played = null;
        $targetSlot = null;
        foreach (['left', 'center', 'right'] as $s) {
            if (empty($ownerP['stage'][$s])) {
                $targetSlot = $s;
                break;
            }
        }
        if ($targetSlot === null) throw new Exception('No empty Stage area');
        foreach ($ownerP['waiting_room'] as $i => $c) {
            if (($c['instance_id'] ?? '') !== $cardId) continue;
            if (!cardMatchesWrPick($c, [
                'subunit'  => $subunit,
                'filter'   => 'member',
                'max_cost' => $maxCost,
            ])) {
                throw new Exception('Invalid Member choice');
            }
            $played = $c;
            array_splice($ownerP['waiting_room'], $i, 1);
            break;
        }
        if (!$played) throw new Exception('Card not in Waiting Room');
        clearMemberWait($played);
        $played['entered_turn'] = intval($state['turn'] ?? 1);
        $ownerP['stage'][$targetSlot] = $played;
        unset($state['pending_prompt']);
        $state = resolveOnEnterAbilities($state, $owner, $played, $targetSlot);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Live') . '] played ' .
            cardDisplayName($played) . ' from Waiting Room.');
        return returnAfterPlacedMemberEnter($state, true, $prompt);
    }

    if ($promptType === 'wait_opponent_stage_pick') {
        if (($prompt['step'] ?? '') !== 'pick_opp_wait') {
            throw new Exception('Invalid wait opponent pick step');
        }
        $opp = $prompt['opp'] ?? (($owner === 'p1') ? 'p2' : 'p1');
        $oppChooses = !empty($prompt['opp_chooses']) || !empty($ability['opp_chooses']);
        $maxCost = intval($prompt['max_cost'] ?? $ability['max_cost'] ?? 4);
        $maxBlade = $prompt['max_original_blade'] ?? $ability['max_original_blade']
            ?? $ability['max_original_blades'] ?? null;
        $maxHearts = $prompt['max_original_hearts'] ?? $ability['max_original_hearts'] ?? null;
        $pickCount = intval($prompt['pick_count'] ?? $ability['pick_count'] ?? 1);
        $upTo = !empty($prompt['up_to']) || $pickCount > 1;

        // Multi / up-to: slots[] or member_ids[]; empty allowed when up_to.
        $slots = $data['slots'] ?? null;
        if (!is_array($slots)) {
            $memberIds = $data['member_ids'] ?? [];
            if (is_array($memberIds) && $memberIds !== []) {
                $slots = [];
                foreach ($memberIds as $mid) {
                    foreach ($prompt['candidates'] ?? [] as $cand) {
                        if (($cand['instance_id'] ?? '') === $mid && !empty($cand['slot'])) {
                            $slots[] = $cand['slot'];
                            break;
                        }
                    }
                }
            } elseif (($data['slot'] ?? '') !== '') {
                $slots = [$data['slot']];
            } elseif ($choice === 'skip' || $choice === 'no' || $choice === 'cancel') {
                $slots = [];
            } else {
                $slots = [];
            }
        }
        $slots = array_values(array_unique(array_filter(array_map('strval', $slots))));
        if (!$upTo && count($slots) !== 1) {
            throw new Exception($oppChooses
                ? 'Choose a Member on your Stage'
                : 'Choose an opponent Stage Member');
        }
        if (count($slots) > $pickCount) {
            throw new Exception("Select at most $pickCount Member(s)");
        }
        $waited = 0;
        $names = [];
        foreach ($slots as $slot) {
            if ($slot === '' || empty($state['players'][$opp]['stage'][$slot])) {
                throw new Exception($oppChooses
                    ? 'Choose a Member on your Stage'
                    : 'Choose an opponent Stage Member');
            }
            $picked = $state['players'][$opp]['stage'][$slot];
            if ($maxHearts !== null && memberHeartCount($picked) > intval($maxHearts)) {
                throw new Exception('Member has too many printed hearts');
            }
            if ($maxBlade !== null && intval($picked['blade'] ?? 0) > intval($maxBlade)) {
                throw new Exception('Member Blade too high');
            }
            if ($maxHearts === null && $maxBlade === null && intval($picked['cost'] ?? 0) > $maxCost) {
                throw new Exception('Member cost too high');
            }
            if (!empty($ability['active_only']) && memberIsInWait($picked)) {
                throw new Exception($oppChooses
                    ? 'Must choose an active Member on your Stage'
                    : 'Must choose an active opponent Stage Member');
            }
            if (memberIsInWait($picked)) {
                continue;
            }
            waitOpponentMemberAtSlot($state, $opp, $slot, $owner);
            $mbr = $state['players'][$opp]['stage'][$slot];
            $names[] = $mbr['name_en'] ?? $mbr['name'] ?? 'Member';
            $waited++;
        }
        if ($waited > 0) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — put ' . implode(', ', $names) . ' into Wait (' .
                ($prompt['source_name'] ?? 'effect') . ').');
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'effect') . '] chose no Members to put into Wait.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishAfterBranchChoicePrompt($state, $prompt);
    }

    if ($promptType === 'live_start_center_cost_choice') {
        $step = $prompt['step'] ?? 'pick_mode';
        if ($step === 'pick_mode') {
            if (!in_array($choice, ['blade', 'wait_opp'], true)) {
                throw new Exception('Invalid choice');
            }
            if ($choice === 'blade') {
                $members = listStageMemberChoices($ownerP);
                if (empty($members)) throw new Exception('No Member on Stage');
                $state['pending_prompt'] = [
                    'type'          => 'live_start_center_cost_choice',
                    'step'          => 'pick_stage_blade',
                    'owner'         => $owner,
                    'responder'     => $owner,
                    'source_name'   => $prompt['source_name'] ?? 'Live',
                    'prompt'        => 'Choose 1 Stage Member to gain +' .
                        intval($ability['blade_amount'] ?? 2) . ' Blade until this Live ends.',
                    'candidates'    => $members,
                    'blade_amount'  => intval($ability['blade_amount'] ?? 2),
                    'ability'       => $ability,
                ];
            } else {
                $opp = ($owner === 'p1') ? 'p2' : 'p1';
                $members = listOppStageMembersByMaxCost(
                    $state,
                    $opp,
                    intval($ability['wait_opp_max_cost'] ?? 4)
                );
                if (empty($members)) throw new Exception('No valid opponent Member');
                $state['pending_prompt'] = [
                    'type'          => 'live_start_center_cost_choice',
                    'step'          => 'pick_opp_wait',
                    'owner'         => $owner,
                    'responder'     => $owner,
                    'opp'           => $opp,
                    'source_name'   => $prompt['source_name'] ?? 'Live',
                    'prompt'        => 'Choose 1 opponent Stage Member (cost ≤' .
                        intval($ability['wait_opp_max_cost'] ?? 4) . ') to put into Wait.',
                    'candidates'    => $members,
                    'ability'       => $ability,
                ];
            }
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_stage_blade') {
            $slot = $data['slot'] ?? '';
            if ($slot === '' || empty($ownerP['stage'][$slot])) {
                throw new Exception('Choose a Member on Stage');
            }
            $amt = intval($prompt['blade_amount'] ?? 2);
            $mbr = &$ownerP['stage'][$slot];
            $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $amt;
            unset($mbr);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] +$amt Blade on Stage Member until Live ends.");
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
        if ($step === 'pick_opp_wait') {
            $slot = $data['slot'] ?? '';
            $opp = $prompt['opp'] ?? (($owner === 'p1') ? 'p2' : 'p1');
            if ($slot === '' || empty($state['players'][$opp]['stage'][$slot])) {
                throw new Exception('Choose an opponent Member');
            }
            $maxCost = intval($ability['wait_opp_max_cost'] ?? 4);
            if (intval($state['players'][$opp]['stage'][$slot]['cost'] ?? 0) > $maxCost) {
                throw new Exception('Member cost too high');
            }
            waitOpponentMemberAtSlot($state, $opp, $slot, $owner);
            $mbr = $state['players'][$opp]['stage'][$slot];
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . '] put opponent ' .
                ($mbr['name_en'] ?? $mbr['name'] ?? 'Member') . ' into Wait.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveStartEffects($state);
        }
    }

    if ($promptType === 'wait_swap_wr_member_center') {
        $step = $prompt['step'] ?? 'discard_hand';
        $group = $ability['group'] ?? '';
        $bonus = intval($ability['cost_bonus'] ?? 2);
        $sourceSlot = $prompt['source_slot'] ?? '';
        $abIdx = intval($prompt['ability_index'] ?? 0);
        if ($step === 'discard_hand') {
            $ids = $data['discard_ids'] ?? [];
            if (count($ids) !== 1) {
                throw new Exception('Must discard exactly 1 card from hand');
            }
            discardFromHandByIds($ownerP, $ids, $state, $owner);
            $others = listStageMemberChoices($ownerP, $group, $prompt['source_id'] ?? '');
            if (empty($others)) throw new Exception('No other group Member on Stage');
            $state['pending_prompt'] = [
                'type'          => 'wait_swap_wr_member_center',
                'step'          => 'pick_stage_member',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_id'     => $prompt['source_id'] ?? '',
                'source_slot'   => $sourceSlot,
                'ability_index' => $abIdx,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'prompt'        => 'Choose 1 other group Member on your Stage to put into the Waiting Room.',
                'candidates'    => $others,
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_stage_member') {
            $slot = $data['slot'] ?? '';
            if ($slot === '' || $slot === $sourceSlot || empty($ownerP['stage'][$slot])) {
                throw new Exception('Choose another Member on Stage');
            }
            $mbr = $ownerP['stage'][$slot];
            if ($group !== '' && ($mbr['group'] ?? '') !== $group) {
                throw new Exception('Must choose a group Member');
            }
            $targetCost = intval($mbr['cost'] ?? 0) + $bonus;
            $wrCands = [];
            foreach ($ownerP['waiting_room'] as $c) {
                if (($c['card_type'] ?? '') !== 'メンバー') continue;
                if ($group !== '' && ($c['group'] ?? '') !== $group) continue;
                if (intval($c['cost'] ?? 0) !== $targetCost) continue;
                $wrCands[] = cardPromptSummary($c);
            }
            if (empty($wrCands)) throw new Exception('No Waiting Room Member with cost ' . $targetCost);
            $ownerP['waiting_room'][] = $mbr;
            $ownerP['stage'][$slot] = null;
            $state = resolveOnLeaveStageAbilities($state, $owner, $mbr);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] put ' .
                cardDisplayName($mbr) . ' into the Waiting Room.');
            $state['pending_prompt'] = [
                'type'          => 'wait_swap_wr_member_center',
                'step'          => 'pick_wr_member',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_id'     => $prompt['source_id'] ?? '',
                'source_slot'   => $sourceSlot,
                'ability_index' => $abIdx,
                'target_slot'   => $slot,
                'target_cost'   => $targetCost,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'prompt'        => 'Play 1 Member from your Waiting Room with cost ' . $targetCost . '.',
                'candidates'    => $wrCands,
                'ability'       => $ability,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick_wr_member') {
            $cardId = $data['card_id'] ?? '';
            $targetSlot = $prompt['target_slot'] ?? '';
            $targetCost = intval($prompt['target_cost'] ?? 0);
            if ($targetSlot === '' || !empty($ownerP['stage'][$targetSlot])) {
                throw new Exception('Target Stage area is not empty');
            }
            $played = null;
            foreach ($ownerP['waiting_room'] as $i => $c) {
                if (($c['instance_id'] ?? '') === $cardId
                    && ($c['card_type'] ?? '') === 'メンバー'
                    && ($group === '' || ($c['group'] ?? '') === $group)
                    && intval($c['cost'] ?? 0) === $targetCost) {
                    $played = $c;
                    array_splice($ownerP['waiting_room'], $i, 1);
                    break;
                }
            }
            if (!$played) throw new Exception('Choose a valid Member from Waiting Room');
            clearMemberWait($played);
            $played['entered_turn'] = intval($state['turn'] ?? 1);
            $played['entered_from_wr'] = true;
            unset($played['entered_from_hand'], $played['entered_via_baton']);
            $ownerP['stage'][$targetSlot] = $played;
            unset($state['pending_prompt']);
            $state = resolveOnEnterAbilities($state, $owner, $played, $targetSlot);
            if ($sourceSlot !== '' && !empty($ownerP['stage'][$sourceSlot])) {
                markAbilityUsed($ownerP['stage'][$sourceSlot], $abIdx);
            }
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] played ' .
                cardDisplayName($played) . ' from Waiting Room into the ' . $targetSlot . ' area.');
            return returnAfterPlacedMemberEnter($state, false, $prompt);
        }
    }

    if ($promptType === 'activated_pick_on_enter_ability') {
        $abIdx = intval($choice);
        $onEnter = $prompt['on_enter'] ?? [];
        if (!isset($onEnter[$abIdx])) throw new Exception('Invalid ability');
        $discId = $prompt['discarded_id'] ?? '';
        $discarded = null;
        foreach ($ownerP['waiting_room'] as $c) {
            if (($c['instance_id'] ?? '') === $discId) {
                $discarded = $c;
                break;
            }
        }
        if (!$discarded) throw new Exception('Discarded Member not found');
        $slot = $prompt['source_slot'] ?? '';
        if ($slot !== '' && !empty($ownerP['stage'][$slot])) {
            markAbilityUsed($ownerP['stage'][$slot], abilityMarkKey($prompt['ability_index'] ?? 0));
        }
        $state = resolveAbilityEffect($state, $owner, $discarded, $onEnter[$abIdx], ['phase' => 'on_enter']);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] triggered [On Enter] ability ' .
            ($abIdx + 1) . '.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return $state;
    }

    if ($promptType === 'both_wr_member_to_empty_stage') {
        $placer = $pid;
        $placerP = &$state['players'][$placer];
        $maxCost = intval($prompt['max_cost'] ?? $ability['max_cost'] ?? 2);
        $sourceName = $prompt['source_name'] ?? 'Member';
        $remaining = array_values($prompt['remaining'] ?? []);
        if (($prompt['step'] ?? '') === 'pick_wr') {
            $cardId = $data['card_id'] ?? $choice;
            $candidateIds = array_values(array_filter(array_map(
                fn($c) => $c['instance_id'] ?? '',
                $prompt['candidates'] ?? []
            )));
            if (!in_array($cardId, $candidateIds, true)) {
                throw new Exception('Choose a Member with cost ' . $maxCost . ' or less from Waiting Room');
            }
            $found = null;
            foreach ($placerP['waiting_room'] as $c) {
                if (($c['instance_id'] ?? '') === $cardId) {
                    $found = $c;
                    break;
                }
            }
            if (!$found || intval($found['cost'] ?? 0) > $maxCost || ($found['card_type'] ?? '') !== 'メンバー') {
                throw new Exception('Choose a Member with cost ' . $maxCost . ' or less from Waiting Room');
            }
            $slots = $prompt['slots'] ?? listEmptyStageSlots($placerP);
            if (count($slots) === 1) {
                $placed = putChosenWrMemberToEmptyStageWait(
                    $placerP,
                    $cardId,
                    $slots[0],
                    $state,
                    true
                );
                if (!$placed) {
                    throw new Exception('Choose an empty Stage area');
                }
                unset($state['pending_prompt']);
                $state = afterBothWrMemberPlacedToStage(
                    $state,
                    $placer,
                    $placed['member'],
                    $placed['slot'],
                    $owner,
                    $sourceName,
                    $ability,
                    $remaining,
                    (string)($prompt['source_id'] ?? '')
                );
                if (!empty($state['pending_prompt'])) {
                    return $state;
                }
                return finishPromptEffects($state);
            }
            $state['pending_prompt'] = array_merge($prompt, [
                'step'    => 'pick_slot',
                'card_id' => $cardId,
                'slots'   => $slots,
                'prompt'  => 'Choose an empty Stage area for that Member (enters in Wait).',
            ]);
            $state['seq']++;
            return $state;
        }
        $cardId = $prompt['card_id'] ?? '';
        $slot = $data['slot'] ?? $choice;
        $slots = $prompt['slots'] ?? [];
        if (!in_array($slot, $slots, true) || !empty($placerP['stage'][$slot])) {
            throw new Exception('Choose an empty Stage area');
        }
        $placed = putChosenWrMemberToEmptyStageWait(
            $placerP,
            $cardId,
            $slot,
            $state,
            true
        );
        if (!$placed) {
            throw new Exception('Invalid Waiting Room card');
        }
        unset($state['pending_prompt']);
        $state = afterBothWrMemberPlacedToStage(
            $state,
            $placer,
            $placed['member'],
            $placed['slot'],
            $owner,
            $sourceName,
            $ability,
            $remaining,
            (string)($prompt['source_id'] ?? '')
        );
        if (!empty($state['pending_prompt'])) {
            return $state;
        }
        return finishPromptEffects($state);
    }

    if ($promptType === 'play_wr_members_combined_cost') {
        if (($data['choice'] ?? $choice) === 'skip' || ($data['choice'] ?? $choice) === 'no') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped WR Member play.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $cardIds = array_values(array_filter($data['card_ids'] ?? []));
        if (empty($cardIds) && ($data['card_id'] ?? '') !== '') {
            $cardIds = [$data['card_id']];
        }
        $maxCount = intval($prompt['max_count'] ?? 2);
        $maxCombined = intval($prompt['max_combined_cost'] ?? 4);
        if (count($cardIds) > $maxCount) {
            throw new Exception("Choose at most $maxCount Member(s)");
        }
        $ab = $prompt['ability'] ?? [];
        $group = $ab['group'] ?? '';
        $picked = [];
        foreach ($cardIds as $id) {
            $found = null;
            foreach ($ownerP['waiting_room'] as $c) {
                if (($c['instance_id'] ?? '') !== $id) {
                    continue;
                }
                if (($c['card_type'] ?? '') !== 'メンバー') {
                    throw new Exception('Choose Member cards from Waiting Room');
                }
                if ($group !== '' && !cardMatchesGroup($c, $group, 'member')) {
                    throw new Exception('Choose matching Members from Waiting Room');
                }
                $found = $c;
                break;
            }
            if (!$found) {
                throw new Exception('Invalid Waiting Room selection');
            }
            $picked[] = $found;
        }
        $totalCost = array_sum(array_map(fn($c) => intval($c['cost'] ?? 0), $picked));
        if ($totalCost > $maxCombined) {
            throw new Exception("Combined cost must be $maxCombined or less");
        }
        $emptySlots = array_values(array_filter(
            ['left', 'center', 'right'],
            fn($slot) => empty($ownerP['stage'][$slot])
        ));
        if (count($picked) > count($emptySlots)) {
            throw new Exception('Not enough empty Stage areas');
        }
        unset($state['pending_prompt']);
        $names = [];
        foreach ($picked as $i => $member) {
            $slot = $emptySlots[$i];
            foreach ($ownerP['waiting_room'] as $wi => $c) {
                if (($c['instance_id'] ?? '') === ($member['instance_id'] ?? '')) {
                    array_splice($ownerP['waiting_room'], $wi, 1);
                    break;
                }
            }
            $member['active'] = false;
            $member['entered_turn'] = intval($state['turn'] ?? 1);
            $ownerP['stage'][$slot] = $member;
            $names[] = cardDisplayName($member);
            $state = resolveOnEnterAbilities($state, $owner, $member, $slot);
            if (!empty($state['pending_prompt'])) {
                return $state;
            }
        }
        if (!empty($names)) {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] played ' .
                implode(', ', $names) . ' from Waiting Room in Wait.');
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] chose no Waiting Room Members.');
        }
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($promptType === 'auto_yell_no_live_retry') {
        unset($state['pending_prompt']);
        if ($choice === 'yes') {
            $state = executeYellRetry($state, $owner, $prompt);
            if (!empty($state['pending_prompt'])) {
                $state['phase'] = 'live_success_effects';
                $state['_performance_continue'] = $owner;
                $state['seq']++;
                return $state;
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — kept Yell cards (declined retry).');
        }
        $state['seq']++;
        if (!empty($state['_yell_retry_offers'])) {
            return openNextYellRetryPrompt($state);
        }
        return finishYellRetryAndHearts($state);
    }

    if ($promptType === 'opponent_text_answer') {
        $answerText = trim($data['answer_text'] ?? $data['text'] ?? '');
        if ($answerText === '') {
            throw new Exception('Type an answer');
        }
        $choice = classifyOpponentTextAnswer($ability, $answerText);
        $choices = $ability['choices'] ?? [];
        if (!isset($choices[$choice])) {
            $choice = 'other';
        }
        $outcomeLabel = opponentTextAnswerOutcomeLabel($choices[$choice] ?? []);
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' answered: "' . $answerText . '" → ' . $outcomeLabel . '.');
        $choiceEntry = $choices[$choice];
        $effect = $choiceEntry['effect'] ?? [];
        unset($state['pending_prompt']);
        $state = applyChoiceEffect($state, $owner, $ownerP, $effect, $prompt);
        if (!empty($state['pending_prompt'])) {
            return $state;
        }
        $state['seq']++;
        return finishAfterBranchChoicePrompt($state, $prompt);
    }

    if (!in_array($promptType, ['player_choice', 'opponent_choice'], true)) {
        throw new Exception('Unhandled prompt: ' . $promptType);
    }

    $choices = $ability['choices'] ?? [];
    if (!isset($choices[$choice])) throw new Exception('Invalid choice');

    $choiceEntry = $choices[$choice];
    $choiceLabel = playerChoiceLabelText(is_array($choiceEntry) ? $choiceEntry : []);
    $state = addLog($state, $state['players'][$owner]['name'] .
        ' — [' . ($prompt['source_name'] ?? 'Member') . '] chose: ' . $choiceLabel);

    $effect = $choiceEntry['effect'] ?? [];
    unset($state['pending_prompt']);
    $state = applyChoiceEffect($state, $owner, $ownerP, $effect, $prompt);
    if (!empty($state['pending_prompt'])) {
        return $state;
    }

    $state['seq']++;
    return finishAfterBranchChoicePrompt($state, $prompt);
}
