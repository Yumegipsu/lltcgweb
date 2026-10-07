<?php
/**
 * Hasunosora Clear Pocket cl1 effect handlers.
 * Included by effects.php.
 */

function hsCl1EffectTypes(): array {
    return [
        'live_start_look_top_optional_wr',
        'activated_wait_self_pick_subunit_blade',
        'live_start_pick_stage_member_blade',
        'live_success_pay_choice_wr_add',
        'live_success_pick_yell_if_tied_score',
    ];
}

function hsIsHasunosoraCl1EffectType(string $type): bool {
    return in_array($type, hsCl1EffectTypes(), true);
}

function hsCl1StageMemberBladeCandidates(
    array $state,
    string $pid,
    array $ab,
    string $excludeId = ''
): array {
    $p = $state['players'][$pid] ?? [];
    $group = $ab['group'] ?? '';
    $subunit = $ab['subunit'] ?? '';
    $minCost = intval($ab['min_cost'] ?? 0);
    $candidates = [];
    foreach ($p['stage'] as $slot => $mbr) {
        if (!$mbr) continue;
        if ($excludeId !== '' && ($mbr['instance_id'] ?? '') === $excludeId) continue;
        if ($group !== '' && ($mbr['group'] ?? '') !== $group) continue;
        if ($subunit !== '' && !cardMatchesSubunit($mbr, $subunit)) continue;
        // Issue #94: AWOKE / wait-pick Blade need Live-temp cost (bonus/override), not printed.
        $effCost = getEffectiveStageMemberCost($state, $pid, $mbr);
        if ($minCost > 0 && $effCost < $minCost) continue;
        $candidates[] = array_merge(cardPromptSummary($mbr), [
            'slot' => $slot,
            // UI / CPU: show Live-temporary cost so cost-10 picks are obvious (#111).
            'cost' => $effCost,
            'printed_cost' => intval($mbr['cost'] ?? 0),
        ]);
    }
    return $candidates;
}

function hsCl1ApplyStageMemberBlade(array &$state, string $pid, string $slot, int $amount): void {
    $mbr = &$state['players'][$pid]['stage'][$slot];
    if (!$mbr) return;
    $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $amount;
    unset($mbr);
}

function hsCl1CombinedLiveScore(array $state): int {
    return getLiveTotalScore($state, 'p1') + getLiveTotalScore($state, 'p2');
}

function hsCl1LiveScoresTied(array $state): bool {
    $snap = $state['_perf_live_score_snapshot'] ?? null;
    if (is_array($snap) && isset($snap['p1'], $snap['p2'])) {
        return intval($snap['p1']) === intval($snap['p2']);
    }
    return getLiveTotalScore($state, 'p1') === getLiveTotalScore($state, 'p2');
}

/** Cards revealed by this Performance's Yell, including ones already milled to WR. */
function hsCl1YellRevealPool(array $state, string $pid, array $p, array $ctx = []): array {
    $byId = [];
    $add = static function (array $cards) use (&$byId): void {
        foreach ($cards as &$c) {
            if (!$c || !is_array($c)) {
                continue;
            }
            if (function_exists('mergeCardCatalogFields')) {
                mergeCardCatalogFields($c);
            }
            $id = (string)($c['instance_id'] ?? '');
            $key = $id !== '' ? $id : ('anon_' . count($byId));
            $byId[$key] = $c;
        }
        unset($c);
    };
    $add(is_array($ctx['yell_cards'] ?? null) ? $ctx['yell_cards'] : []);
    $add($p['_pending_yell_wr'] ?? []);
    $add($p['yell_cards'] ?? []);
    $add($state['_yell_revealed_snapshot'][$pid] ?? []);
    $add($state['yell_reveal'][$pid] ?? []);
    // Do not merge `_last_yell_cards`: it is the most recent Yell for either seat.
    $snapIds = [];
    foreach ($state['_yell_revealed_snapshot'][$pid] ?? [] as $c) {
        $iid = (string)($c['instance_id'] ?? '');
        if ($iid !== '') {
            $snapIds[$iid] = true;
        }
    }
    foreach ($p['waiting_room'] ?? [] as $c) {
        $iid = (string)($c['instance_id'] ?? '');
        if ($iid !== '' && isset($snapIds[$iid])) {
            $add([$c]);
        }
    }
    return array_values($byId);
}

function hsResolveHasunosoraCl1Effect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (!hsIsHasunosoraCl1EffectType($type)) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'live_start_look_top_optional_wr':
            if (!empty($state['pending_prompt'])) break;
            if (empty($p['main_deck'])) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at deck top (empty).");
                break;
            }
            $top = $p['main_deck'][0];
            $label = cardDisplayName($top);
            $summary = cardPromptSummary($top);
            $state['pending_prompt'] = [
                'type'          => 'look_top_optional_wr',
                'owner'         => $pid,
                'responder'     => $pid,
                'target'        => $pid,
                'source_name'   => $name,
                'top_card'      => $summary,
                // prompt-look-cards UI reads looked_cards / look_cards (not top_card) (#258).
                'looked_cards'  => [$summary],
                'prompt'        => "Looked at $label on top of your deck. Put it into the Waiting Room?",
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Put in WR', 'No — Leave on top'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] looked at deck top ($label).");
            break;

        case 'live_start_pick_stage_member_blade':
            if (!empty($state['pending_prompt'])) break;
            $candidates = hsCl1StageMemberBladeCandidates($state, $pid, $ab);
            if (empty($candidates)) break;
            $amt = intval($ab['amount'] ?? 1);
            if (count($candidates) === 1) {
                hsCl1ApplyStageMemberBlade($state, $pid, $candidates[0]['slot'], $amt);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] +$amt Blade on Stage Member until Live ends.");
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'cl1_pick_stage_member_blade',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'source_id'     => $source['instance_id'] ?? '',
                'candidates'    => $candidates,
                'blade_amount'  => $amt,
                'live_start'    => ($ctx['phase'] ?? '') === 'live_start'
                    || ($state['phase'] ?? '') === 'live_start_effects',
                'prompt'        => 'Choose 1 Stage Member to gain +' . $amt . ' Blade until this Live ends.',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] choose a Stage Member for +$amt Blade.");
            break;

        case 'live_success_pay_choice_wr_add':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'live_success_pay_choice_wr_add',
                'step'          => 'confirm',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Pay ' . intval($ab['cost'] ?? 1) . ' Energy and choose an effect?',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Pay', 'No — Skip'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Live Success (pay Energy).");
            break;

        case 'live_success_pick_yell_if_tied_score':
            if (($ctx['phase'] ?? '') !== 'live_success') break;
            if (!hsCl1LiveScoresTied($state)) break;
            $yellPool = hsCl1YellRevealPool($state, $pid, $p, $ctx);
            $candidates = array_values(array_filter(
                $yellPool,
                fn($c) => cardMatchesYellPick($c, $ab)
            ));
            if (empty($candidates)) break;
            if (count($candidates) === 1) {
                $picked = $candidates[0];
                $pickedId = (string)($picked['instance_id'] ?? '');
                if ($pickedId !== '' && function_exists('takeFromPendingYellPool')) {
                    $fromPool = takeFromPendingYellPool(
                        $p,
                        $pickedId,
                        ['candidates' => array_map('cardPromptSummary', $candidates)],
                        $state,
                        $pid
                    );
                    if ($fromPool) {
                        $picked = $fromPool;
                    }
                }
                $p['hand'][] = $picked;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added ' . cardDisplayName($picked) . ' from Yell to hand (tied score).');
                break;
            }
            $state['pending_prompt'] = [
                'type'        => 'pick_yell_member',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'prompt'      => 'Combined Live Score is tied — choose 1 of your Yell cards to add to your hand.',
                'candidates'  => array_map('cardPromptSummary', $candidates),
                'ability'     => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose a Yell card (tied score).');
            break;
    }

    return $state;
}

function hsCl1ResolveActivatedAbility(
    array $state,
    string $pid,
    array &$p,
    array &$member,
    ?string $slot,
    array $ab,
    int|string $abilityIdx
): ?array {
    if (($ab['type'] ?? '') !== 'activated_wait_self_pick_subunit_blade') {
        return null;
    }
    if ($slot === null) {
        throw new Exception('Member not on stage');
    }
    waitMember($member, $state);
    $candidates = hsCl1StageMemberBladeCandidates($state, $pid, $ab, $member['instance_id'] ?? '');
    if (empty($candidates)) {
        throw new Exception('No matching Member on Stage');
    }
    $amt = intval($ab['amount'] ?? 1);
    if (count($candidates) === 1) {
        hsCl1ApplyStageMemberBlade($state, $pid, $candidates[0]['slot'], $amt);
        if (!empty($ab['once_per_turn'])) markAbilityUsed($member, $abilityIdx);
        $p['stage'][$slot] = $member;
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — [' . ($member['name_en'] ?? $member['name']) . "] Waited; +$amt Blade on Stage Member.");
        return $state;
    }
    $state['pending_prompt'] = [
        'type'          => 'cl1_pick_stage_member_blade',
        'owner'         => $pid,
        'responder'     => $pid,
        'source_id'     => $member['instance_id'] ?? '',
        'source_slot'   => $slot,
        'source_name'   => $member['name_en'] ?? $member['name'] ?? 'Member',
        'ability_index' => $abilityIdx,
        'candidates'    => $candidates,
        'blade_amount'  => $amt,
        'wait_self'     => true,
        'prompt'        => 'Choose 1 ' . ($ab['subunit'] ?? 'Member') .
            ' Member to gain +' . $amt . ' Blade until this Live ends.',
        'ability'       => $ab,
    ];
    $p['stage'][$slot] = $member;
    $state = addLog($state, $state['players'][$pid]['name'] .
        ' — [' . ($member['name_en'] ?? $member['name']) . '] Waited; choose a Member for Blade.');
    return $state;
}

function hsCl1ResolvePrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $promptType = $prompt['type'] ?? '';
    $ownerP = &$state['players'][$owner];

    if ($promptType === 'cl1_pick_stage_member_blade') {
        $slot = $data['slot'] ?? '';
        if ($slot === '' || empty($ownerP['stage'][$slot])) {
            throw new Exception('Choose a Member on Stage');
        }
        $amt = intval($prompt['blade_amount'] ?? 1);
        hsCl1ApplyStageMemberBlade($state, $owner, $slot, $amt);
        if (!empty($prompt['wait_self'])) {
            $srcSlot = $prompt['source_slot'] ?? '';
            $srcId = $prompt['source_id'] ?? '';
            $mbr = $ownerP['stage'][$srcSlot] ?? null;
            if ($mbr && ($mbr['instance_id'] ?? '') === $srcId) {
                $idx = intval($prompt['ability_index'] ?? 0);
                markAbilityUsed($mbr, $idx);
                $ownerP['stage'][$srcSlot] = $mbr;
            }
        }
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . "] +$amt Blade on Stage Member until Live ends.");
        unset($state['pending_prompt']);
        $state['seq']++;
        // AWOKE Live Start (#111): must resume Live Start / advance performance —
        // returning raw state left live_start_effects with no prompt and re-fired the pick.
        return finishAfterBranchChoicePrompt($state, $prompt);
    }

    if ($promptType === 'live_success_pay_choice_wr_add') {
        $step = $prompt['step'] ?? 'confirm';
        $ability = $prompt['ability'] ?? [];
        if ($step === 'confirm') {
            if (!isset(['yes' => true, 'no' => true][$choice])) {
                throw new Exception('Invalid choice');
            }
            if ($choice === 'no') {
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishLiveSuccessEffects($state);
            }
            $cost = intval($ability['cost'] ?? 1);
            if (!payEnergyCost($ownerP, $cost)) {
                throw new Exception("Need $cost active Energy");
            }
            $group = $ability['group'] ?? 'Hasunosora';
            $srcName = $prompt['source_name'] ?? 'Live';
            $choices = [];
            $memberCfg = ['group' => '', 'filter' => 'member'];
            if (!empty(wrCandidatesMatching($ownerP, $memberCfg))) {
                $choices['member'] = [
                    'label' => 'Add 1 Member card from your Waiting Room to your hand.',
                    'effect' => ['type' => 'add_from_wr', 'filter' => 'member', 'count' => 1],
                ];
            }
            $liveZoneCount = count(array_filter($ownerP['live_zone'] ?? []));
            $liveCfg = ['group' => $group, 'filter' => 'live'];
            if ($liveZoneCount >= intval($ability['min_live_zone'] ?? 2)
                && !empty(wrCandidatesMatching($ownerP, $liveCfg))) {
                $choices['live'] = [
                    'label' => "Add 1 $group Live card from your Waiting Room to your hand.",
                    'effect' => ['type' => 'add_from_wr', 'group' => $group, 'filter' => 'live', 'count' => 1],
                ];
            }
            if (empty($choices)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$srcName] paid $cost Energy; no matching Waiting Room card.");
                unset($state['pending_prompt']);
                $state['seq']++;
                return finishLiveSuccessEffects($state);
            }
            // One viable mode → skip mode buttons and open WR pick immediately.
            if (count($choices) === 1) {
                $onlyKey = array_key_first($choices);
                $effect = $choices[$onlyKey]['effect'] ?? [];
                $cfg = [
                    'group'  => (string)($effect['group'] ?? ''),
                    'filter' => (string)($effect['filter'] ?? ''),
                ];
                $wrCands = wrCandidatesMatching($ownerP, $cfg);
                $state['pending_prompt'] = [
                    'type'          => 'pick_wr_to_hand',
                    'owner'         => $owner,
                    'responder'     => $owner,
                    'source_id'     => $prompt['source_id'] ?? '',
                    'source_name'   => $srcName,
                    'prompt'        => 'Choose 1 ' . wrPickFilterLabel($cfg['filter']) .
                        ' from your Waiting Room to add to your hand.',
                    'candidates'    => array_map('cardPromptSummary', $wrCands),
                    'wr_pick_cfg'   => $cfg,
                    'pick_count'    => 1,
                    'up_to'         => false,
                ];
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [$srcName] paid $cost Energy; choose a card from Waiting Room.");
                $state['seq']++;
                return $state;
            }
            $choiceFields = buildPlayerChoicePromptFields(['choices' => $choices]);
            $state['pending_prompt'] = [
                'type'          => 'live_success_pay_choice_wr_add',
                'step'          => 'pick',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_id'     => $prompt['source_id'] ?? '',
                'source_name'   => $srcName,
                'prompt'        => $choiceFields['prompt'],
                'choices'       => array_keys($choices),
                'choice_labels' => $choiceFields['choice_labels'],
                'ability'       => $ability,
                'choice_map'    => $choices,
            ];
            $state['seq']++;
            return $state;
        }
        if ($step === 'pick') {
            $choices = $prompt['choice_map'] ?? ($ability['choices'] ?? []);
            if (!isset($choices[$choice])) throw new Exception('Invalid choice');
            $effect = $choices[$choice]['effect'] ?? [];
            $cfg = [
                'group'  => (string)($effect['group'] ?? ''),
                'filter' => (string)($effect['filter'] ?? ''),
            ];
            $wrCands = wrCandidatesMatching($ownerP, $cfg);
            if (empty($wrCands)) {
                throw new Exception('No matching card in Waiting Room');
            }
            $srcName = $prompt['source_name'] ?? 'Live';
            // Always open pick_wr_to_hand — never auto-first-match (same class as bp6-003).
            $state['pending_prompt'] = [
                'type'          => 'pick_wr_to_hand',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_id'     => $prompt['source_id'] ?? '',
                'source_name'   => $srcName,
                'prompt'        => 'Choose 1 ' . wrPickFilterLabel($cfg['filter']) .
                    ' from your Waiting Room to add to your hand.',
                'candidates'    => array_map('cardPromptSummary', $wrCands),
                'wr_pick_cfg'   => $cfg,
                'pick_count'    => 1,
                'up_to'         => false,
            ];
            $state = addLog($state, $state['players'][$owner]['name'] .
                " — [$srcName] choose a card from Waiting Room.");
            $state['seq']++;
            return $state;
        }
    }

    return null;
}
