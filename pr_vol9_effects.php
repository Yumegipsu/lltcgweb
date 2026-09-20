<?php
/**
 * PR pack vol.9 / Anniversary 2026 promo gap handlers.
 */

function prVol9EffectTypes(): array {
    return [
        'mill_fill_wr_optional_live_deck_top',
        'optional_opp_wr_members_to_deck_bottom_then_wait',
        // 2026-09 PR batch (PL!-PR-023… / HS-PR-038…)
        'grant_bonus_hearts_if_not_from_hand',
        'on_leave_baton_incoming_blade',
        'live_success_draw_if_opp_yell_has_live',
        'live_start_wr_lives_deck_top_if_wr_max',
        'wait_own_member_discard_draw',
        'auto_on_either_stage_wait_blade',
        'draw_on_member_enter_from_wr',
        'hearts_if_combined_success_score_min',
    ];
}

function prVol9IsEffectType(string $type): bool {
    return in_array($type, prVol9EffectTypes(), true);
}

function prVol9ResolveEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (!prVol9IsEffectType($type)) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'mill_fill_wr_optional_live_deck_top':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $target = intval($ab['target_wr'] ?? 8);
            $wrCount = count($p['waiting_room'] ?? []);
            if ($wrCount >= $target) {
                break;
            }
            $need = $target - $wrCount;
            $milled = takeFromMainDeckTop($state, $pid, $need);
            if (empty($milled)) {
                break;
            }
            $p['waiting_room'] = array_merge($p['waiting_room'], $milled);
            if (function_exists('spBp5NotifyCardsToWr')) {
                $state = spBp5NotifyCardsToWr($state, $pid, $milled);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] milled " . count($milled) . " to Waiting Room (fill to $target).");
            $liveCandidates = [];
            foreach ($milled as $c) {
                if (isLiveTypeCard($c)) {
                    $liveCandidates[] = cardPromptSummary($c);
                }
            }
            if (empty($liveCandidates)) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'mill_fill_wr_optional_live_deck_top',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'milled_ids'    => array_values(array_map(
                    fn($c) => (string)($c['instance_id'] ?? ''),
                    $milled
                )),
                'candidates'    => $liveCandidates,
                'prompt'        => 'Put 1 milled Live on top of your deck? (or Skip)',
                'choices'       => array_merge(
                    ['skip'],
                    array_map(fn($c) => (string)($c['instance_id'] ?? ''), $liveCandidates)
                ),
                'choice_labels' => array_merge(
                    ['Skip'],
                    array_map(fn($c) => (string)($c['name_en'] ?? $c['name'] ?? 'Live'), $liveCandidates)
                ),
                'ability'       => $ab,
            ];
            break;

        case 'optional_opp_wr_members_to_deck_bottom_then_wait':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $need = intval($ab['wr_count'] ?? 3);
            $candidates = [];
            foreach ($state['players'][$opp]['waiting_room'] ?? [] as $c) {
                if (($c['card_type'] ?? '') === 'メンバー') {
                    $candidates[] = cardPromptSummary($c);
                }
            }
            if (count($candidates) < $need) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'optional_opp_wr_members_to_deck_bottom_then_wait',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'opp'           => $opp,
                'need'          => $need,
                'max_blade'     => intval($ab['max_original_blade'] ?? 3),
                'candidates'    => $candidates,
                'prompt'        => "Optional: put $need opponent WR Members on deck bottom, then Wait 1 (original Blade ≤"
                    . intval($ab['max_original_blade'] ?? 3) . ')?',
                'choices'       => ['yes', 'skip'],
                'choice_labels' => ['Yes — pick Members', 'Skip'],
                'ability'       => $ab,
            ];
            break;

        case 'grant_bonus_hearts_if_not_from_hand':
            if (!empty($source['entered_from_hand'])) {
                break;
            }
            $state = applyModifierEffect($state, $pid, array_merge($ab, [
                'type' => 'grant_bonus_hearts',
            ]), $source);
            if (!empty($ab['hearts'])) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] gained bonus heart(s) until this Live ends (not from hand).");
            }
            break;

        case 'on_leave_baton_incoming_blade':
            $incoming = $ctx['baton_incoming'] ?? null;
            if (!$incoming || !is_array($incoming)) {
                break;
            }
            if (intval($incoming['cost'] ?? 0) < intval($ab['min_cost'] ?? 9)) {
                break;
            }
            $amt = intval($ab['amount'] ?? 2);
            $inId = (string)($incoming['instance_id'] ?? '');
            if ($inId === '') {
                break;
            }
            foreach ($p['stage'] as $slot => &$mbr) {
                if (!$mbr || ($mbr['instance_id'] ?? '') !== $inId) {
                    continue;
                }
                $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $amt;
                $p['stage'][$slot] = $mbr;
                $inName = $mbr['name_en'] ?? $mbr['name'] ?? 'Member';
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Baton leave: [$inName] +$amt Blade until this Live ends.");
                break;
            }
            unset($mbr);
            break;

        case 'live_success_draw_if_opp_yell_has_live':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $liveCount = intval($state['_last_yell_live_count_' . $opp] ?? 0);
            if ($liveCount < 1) {
                $yell = $state['players'][$opp]['yell_cards']
                    ?? $state['yell_reveal'][$opp]
                    ?? [];
                $liveCount = countYellLiveCards($yell);
            }
            if ($liveCount < 1) {
                break;
            }
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (opponent Yell revealed a Live).");
            break;

        case 'live_start_wr_lives_deck_top_if_wr_max':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $maxWr = intval($ab['max_wr'] ?? 9);
            if (count($p['waiting_room'] ?? []) > $maxWr) {
                break;
            }
            $pool = array_values(array_filter(
                $p['waiting_room'] ?? [],
                fn($c) => is_array($c) && isLiveTypeCard($c)
            ));
            if (empty($pool)) {
                break;
            }
            $maxPick = intval($ab['max_pick'] ?? 3);
            $state['pending_prompt'] = [
                'type'          => 'sbp5_wr_lives_deck_top',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $pool),
                'max_pick'      => $maxPick,
                'prompt'        => "Choose up to $maxPick Live card(s) from your Waiting Room "
                    . 'to put on top of your deck (in order).',
                'ability'       => $ab,
                'picked'        => [],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional WR Lives to deck top (WR ≤$maxWr).");
            break;

        case 'wait_own_member_discard_draw':
            // Activated entry — usually handled in ActivateAbility; keep for resolveAbilityEffect.
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $state = prVol9BeginWaitOwnMemberDiscardDraw($state, $pid, $source, $ab, $ctx);
            break;
    }

    return $state;
}

/**
 * Auto: either-side Stage Wait → grant Blade on the source Member until Live ends.
 * Called from flushAutoOnWaitAbilities.
 */
function prVol9ResolveAutoOnEitherStageWaitBlade(
    array $state,
    string $waitedPid,
    array $waitedMember
): array {
    $waitedId = (string)($waitedMember['instance_id'] ?? '');
    if ($waitedId === '') {
        return $state;
    }
    foreach (['p1', 'p2'] as $pid) {
        $p = &$state['players'][$pid];
        foreach ($p['stage'] as $slot => &$ally) {
            if (!$ally) {
                continue;
            }
            foreach ($ally['abilities'] ?? [] as $idx => $ab) {
                if (($ab['trigger'] ?? '') !== 'auto') {
                    continue;
                }
                if (($ab['type'] ?? '') !== 'auto_on_either_stage_wait_blade') {
                    continue;
                }
                $maxUses = intval($ab['max_uses_per_turn'] ?? 0);
                $useKey = '_auto_uses_' . $idx;
                if ($maxUses > 0 && intval($ally[$useKey] ?? 0) >= $maxUses) {
                    continue;
                }
                if (!empty($ab['once_per_turn']) && isAbilityUsed($ally, $idx)) {
                    continue;
                }
                $amt = intval($ab['amount'] ?? 1);
                $ally['live_blade_bonus'] = intval($ally['live_blade_bonus'] ?? 0) + $amt;
                if ($maxUses > 0) {
                    $ally[$useKey] = intval($ally[$useKey] ?? 0) + 1;
                }
                if (!empty($ab['once_per_turn'])) {
                    markAbilityUsed($ally, $idx);
                }
                $p['stage'][$slot] = $ally;
                $aName = $ally['name_en'] ?? $ally['name'] ?? 'Member';
                $wName = $waitedMember['name_en'] ?? $waitedMember['name'] ?? 'Member';
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$aName] +$amt Blade until this Live ends ($wName became Wait).");
            }
        }
        unset($ally);
        unset($p);
    }
    return $state;
}

/** Continuous: combined Success Live score (both players) ≥ N → hearts. */
function prVol9ApplyContinuousHearts(
    array $state,
    string $pid,
    array $member,
    array $ab,
    array $hearts
): array {
    if (($ab['type'] ?? '') !== 'hearts_if_combined_success_score_min') {
        return $hearts;
    }
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    $p = $state['players'][$pid] ?? [];
    $oppP = $state['players'][$opp] ?? [];
    $sum = sumSuccessLiveScores($p, $state, $pid)
        + sumSuccessLiveScores($oppP, $state, $opp);
    if ($sum < intval($ab['min_success_score_sum'] ?? 10)) {
        return $hearts;
    }
    foreach ($ab['hearts'] ?? [] as $h) {
        for ($i = 0; $i < intval($h['count'] ?? 1); $i++) {
            $hearts[] = $h['color'] ?? 'pink';
        }
    }
    return $hearts;
}

function prVol9BeginWaitOwnMemberDiscardDraw(
    array $state,
    string $pid,
    array $source,
    array $ab,
    array $ctx = []
): array {
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Member';
    $needDiscard = intval($ab['discard'] ?? 1);
    if ($needDiscard > 0 && count($p['hand'] ?? []) < $needDiscard) {
        throw new Exception("Need $needDiscard card(s) in hand");
    }
    $members = [];
    foreach ($p['stage'] as $slot => $mbr) {
        if (!$mbr || memberIsInWait($mbr)) {
            continue;
        }
        $members[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
    }
    if (empty($members)) {
        throw new Exception('No Stage Member available to Wait');
    }
    $abilityIdx = $ctx['ability_index'] ?? null;
    if (count($members) === 1) {
        return prVol9ApplyWaitOwnMemberThenDiscardDraw(
            $state,
            $pid,
            $members[0]['slot'],
            $source,
            $ab,
            $abilityIdx
        );
    }
    $state['pending_prompt'] = [
        'type'          => 'wait_own_member_discard_draw',
        'step'          => 'pick_member',
        'owner'         => $pid,
        'responder'     => $pid,
        'source_id'     => $source['instance_id'] ?? '',
        'source_name'   => $name,
        'ability_index' => $abilityIdx,
        'stage_members' => $members,
        'prompt'        => 'Choose 1 of your Stage Members to put into Wait.',
        'ability'       => $ab,
    ];
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$name] choose a Member to Wait.");
    return $state;
}

function prVol9ApplyWaitOwnMemberThenDiscardDraw(
    array $state,
    string $pid,
    string $waitSlot,
    array $source,
    array $ab,
    $abilityIdx = null
): array {
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Member';
    if ($waitSlot === '' || empty($p['stage'][$waitSlot]) || memberIsInWait($p['stage'][$waitSlot])) {
        throw new Exception('Choose a valid Stage Member to Wait');
    }
    waitMember($p['stage'][$waitSlot], $state);
    $wName = $p['stage'][$waitSlot]['name_en'] ?? $p['stage'][$waitSlot]['name'] ?? 'Member';
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$name] put $wName into Wait.");
    if ($abilityIdx !== null && !empty($ab['once_per_turn'])) {
        $srcId = (string)($source['instance_id'] ?? '');
        foreach ($p['stage'] as $slot => &$mbr) {
            if ($mbr && ($mbr['instance_id'] ?? '') === $srcId) {
                markAbilityUsed($mbr, $abilityIdx);
                $p['stage'][$slot] = $mbr;
                break;
            }
        }
        unset($mbr);
    }
    unset($state['pending_prompt']);
    $need = intval($ab['discard'] ?? 1);
    $draw = intval($ab['draw'] ?? 1);
    if ($need > 0) {
        return startEffectDiscardHandPrompt($state, $pid, $name, $need, '', [
            'source_id'     => $source['instance_id'] ?? '',
            'ability_index' => $abilityIdx,
            'then'          => ['type' => 'draw', 'draw' => $draw],
            'ability'       => $ab,
        ]);
    }
    $drawn = drawCardsForPlayer($state, $pid, $draw);
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$name] drew $drawn.");
    $state['seq']++;
    return finishPromptEffects($state);
}

function prVol9ResolvePrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $type = $prompt['type'] ?? '';
    if ($type === 'mill_fill_wr_optional_live_deck_top') {
        if ($choice === 'skip' || $choice === 'no' || $choice === 'cancel' || $choice === '') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped Live to deck top.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        $pickId = $data['card_id'] ?? $choice;
        $milledIds = array_fill_keys($prompt['milled_ids'] ?? [], true);
        if ($pickId === '' || empty($milledIds[$pickId])) {
            throw new Exception('Choose a Live milled by this effect, or Skip');
        }
        $p = &$state['players'][$owner];
        $picked = null;
        $rest = [];
        foreach ($p['waiting_room'] as $c) {
            if (!$picked && ($c['instance_id'] ?? '') === $pickId && isLiveTypeCard($c)) {
                $picked = $c;
            } else {
                $rest[] = $c;
            }
        }
        if (!$picked) {
            throw new Exception('Live card not found in Waiting Room');
        }
        $p['waiting_room'] = $rest;
        array_unshift($p['main_deck'], $picked);
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . ($prompt['source_name'] ?? 'Member') . '] put '
            . cardDisplayName($picked) . ' on deck top.');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($type === 'optional_opp_wr_members_to_deck_bottom_then_wait') {
        if ($choice === 'skip' || $choice === 'no' || $choice === 'cancel') {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped WR→deck bottom.');
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishPromptEffects($state);
        }
        if ($choice !== 'yes') {
            // Second step: card ids selected
            $ids = $data['card_ids'] ?? $data['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                if ($choice !== '' && $choice !== 'yes') {
                    $ids = array_values(array_filter(array_map('trim', explode(',', $choice))));
                }
            }
            $need = intval($prompt['need'] ?? 3);
            if (count($ids) !== $need) {
                throw new Exception("Select exactly $need opponent Waiting Room Members");
            }
            $opp = $prompt['opp'] ?? (($owner === 'p1') ? 'p2' : 'p1');
            $oppP = &$state['players'][$opp];
            $picked = [];
            $rest = [];
            $want = array_fill_keys($ids, true);
            foreach ($oppP['waiting_room'] as $c) {
                $iid = (string)($c['instance_id'] ?? '');
                if (isset($want[$iid]) && ($c['card_type'] ?? '') === 'メンバー' && !isset($picked[$iid])) {
                    $picked[$iid] = $c;
                } else {
                    $rest[] = $c;
                }
            }
            if (count($picked) !== $need) {
                throw new Exception('Invalid opponent WR Member selection');
            }
            // Preserve pick order for deck bottom (first selected = deepest? Rule: any order — append in pick order so last is bottom-most near end).
            $ordered = [];
            foreach ($ids as $id) {
                if (isset($picked[$id])) {
                    $ordered[] = $picked[$id];
                }
            }
            $oppP['waiting_room'] = $rest;
            $oppP['main_deck'] = array_merge($oppP['main_deck'], $ordered);
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . "] put $need opponent WR Members on deck bottom.");
            unset($state['pending_prompt']);
            // Chain: wait opp member with original blade ≤ max
            $maxBlade = intval($prompt['max_blade'] ?? 3);
            $waitCands = [];
            foreach ($oppP['stage'] as $slot => $mbr) {
                if (!$mbr) {
                    continue;
                }
                $blade = isset($mbr['printed_blade_override'])
                    ? intval($mbr['printed_blade_override'])
                    : intval($mbr['blade'] ?? 0);
                if ($blade <= $maxBlade && !memberIsInWait($mbr)) {
                    $waitCands[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
                }
            }
            if (empty($waitCands)) {
                $state['seq']++;
                return finishPromptEffects($state);
            }
            if (count($waitCands) === 1) {
                $slot = $waitCands[0]['slot'];
                waitMember($oppP['stage'][$slot], $state);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — Waited ' . ($waitCands[0]['name_en'] ?? 'Member') .
                    " (original Blade ≤$maxBlade).");
                $state['seq']++;
                return finishPromptEffects($state);
            }
            $state['pending_prompt'] = [
                'type'          => 'pr_vol9_wait_opp_max_blade',
                'owner'         => $owner,
                'responder'     => $owner,
                'opp'           => $opp,
                'max_blade'     => $maxBlade,
                'candidates'    => $waitCands,
                'source_name'   => $prompt['source_name'] ?? 'Member',
                'prompt'        => "Choose 1 opponent Stage Member (original Blade ≤$maxBlade) to Wait.",
                'ability'       => $prompt['ability'] ?? [],
            ];
            $state['seq']++;
            return $state;
        }
        // choice === yes → ask for multi-pick (client sends card_ids)
        $state['pending_prompt'] = array_merge($prompt, [
            'step'   => 'pick',
            'prompt' => 'Select ' . intval($prompt['need'] ?? 3)
                . ' opponent WR Members (order = deck bottom order).',
        ]);
        $state['seq']++;
        return $state;
    }

    if ($type === 'pr_vol9_wait_opp_max_blade') {
        $opp = $prompt['opp'] ?? (($owner === 'p1') ? 'p2' : 'p1');
        $slot = $data['slot'] ?? $choice;
        $valid = [];
        foreach ($prompt['candidates'] ?? [] as $c) {
            if (($c['slot'] ?? '') !== '') {
                $valid[$c['slot']] = true;
            }
        }
        if ($slot === '' || empty($valid[$slot]) || empty($state['players'][$opp]['stage'][$slot])) {
            throw new Exception('Choose a valid opponent Stage Member');
        }
        waitMember($state['players'][$opp]['stage'][$slot], $state);
        $mName = $state['players'][$opp]['stage'][$slot]['name_en']
            ?? $state['players'][$opp]['stage'][$slot]['name'] ?? 'Member';
        $state = addLog($state, $state['players'][$owner]['name'] .
            " — Waited $mName (original Blade ≤" . intval($prompt['max_blade'] ?? 3) . ').');
        unset($state['pending_prompt']);
        $state['seq']++;
        return finishPromptEffects($state);
    }

    if ($type === 'wait_own_member_discard_draw') {
        $mid = (string)($data['member_id'] ?? '');
        $waitSlot = (string)($data['slot'] ?? '');
        if ($waitSlot === '' && $mid !== '') {
            foreach ($prompt['stage_members'] ?? [] as $c) {
                if (($c['instance_id'] ?? '') === $mid) {
                    $waitSlot = (string)($c['slot'] ?? '');
                    break;
                }
            }
            if ($waitSlot === '') {
                $waitSlot = findMemberSlot($state['players'][$owner], $mid);
            }
        }
        if ($waitSlot === '' && $choice !== '' && !in_array($choice, ['yes', 'no', 'skip'], true)) {
            $waitSlot = $choice;
        }
        $source = [
            'instance_id' => $prompt['source_id'] ?? '',
            'name_en'     => $prompt['source_name'] ?? 'Member',
            'name'        => $prompt['source_name'] ?? 'Member',
        ];
        // Prefer live stage copy for once_per_turn marking.
        $srcId = (string)($prompt['source_id'] ?? '');
        if ($srcId !== '') {
            foreach ($state['players'][$owner]['stage'] as $mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === $srcId) {
                    $source = $mbr;
                    break;
                }
            }
        }
        return prVol9ApplyWaitOwnMemberThenDiscardDraw(
            $state,
            $owner,
            $waitSlot,
            $source,
            $prompt['ability'] ?? [],
            $prompt['ability_index'] ?? null
        );
    }

    return null;
}
