<?php
/**
 * Batch 99 — LL gap + PL!N PR promo effect handlers.
 * Included by effects.php.
 */

function batch99EffectTypes(): array {
    return [
        'optional_ema_punch',
        'auto_yell_hearts_if_group_member_count',
        'draw_on_self_or_baton_enter',
        'stack_wr_member_under',
        'inherit_stacked_live_success',
        'hearts_if_combined_stage_members',
        'live_success_score_if_yell_or_hearts_or_moved',
        'live_start_center_wild_if_distinct_groups',
        'live_success_add_wr_not_on_stage_group',
        'hearts_from_discarded_colors',
        'opp_stage_blade_bonus',
        'noop',
    ];
}

function batch99IsEffectType(string $type): bool {
    return in_array($type, batch99EffectTypes(), true);
}

function batch99CountCombinedStageMembers(array $state): int {
    return countStageMembers($state['players']['p1'] ?? [])
        + countStageMembers($state['players']['p2'] ?? []);
}

function batch99CountDistinctGroupsOnStage(array $p): int {
    $groups = [];
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        $g = $mbr['group'] ?? '';
        if ($g !== '') $groups[$g] = true;
    }
    return count($groups);
}

function batch99CountDistinctHeartColorsOnStage(array $p): int {
    $colors = [];
    foreach ($p['stage'] as $mbr) {
        if (!$mbr) continue;
        foreach ($mbr['hearts'] ?? [] as $h) {
            $c = $h['color'] ?? '';
            if ($c !== '' && $c !== 'draw') $colors[$c] = true;
        }
        foreach ($mbr['bonus_hearts'] ?? [] as $c) {
            if ($c !== '' && $c !== 'draw') $colors[$c] = true;
        }
    }
    return count($colors);
}

function batch99StageHasMovedMember(array $p): bool {
    foreach ($p['stage'] as $mbr) {
        if ($mbr && !empty($mbr['moved_this_turn'])) return true;
    }
    return false;
}

function batch99CountYellGroupMembers(array $yellCards, bool $sameGroup): int {
    if (!$sameGroup) {
        return count(array_filter($yellCards, fn($c) => ($c['card_type'] ?? '') === 'メンバー'));
    }
    $byGroup = [];
    foreach ($yellCards as $c) {
        if (($c['card_type'] ?? '') !== 'メンバー') continue;
        $g = $c['group'] ?? '';
        if ($g === '') continue;
        $byGroup[$g] = ($byGroup[$g] ?? 0) + 1;
    }
    return empty($byGroup) ? 0 : max($byGroup);
}

function batch99MemberLiveSuccessAbilities(array $member): array {
    $out = getAbilitiesByTrigger($member, 'live_success');
    $maxCost = null;
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['type'] ?? '') === 'inherit_stacked_live_success') {
            $maxCost = intval($ab['max_cost'] ?? 9);
            break;
        }
    }
    // Only hosts with inherit_stacked_live_success (e.g. PL!N-PR-026) copy stacked
    // [Live Success]. Ren's inherit_stacked_group_abilities is Activated-only (#210).
    if ($maxCost === null) {
        return $out;
    }
    foreach ($member['stacked_members'] ?? [] as $stacked) {
        if (!$stacked) {
            continue;
        }
        if (intval($stacked['cost'] ?? 0) > $maxCost) {
            continue;
        }
        mergeCardCatalogFields($stacked);
        foreach ($stacked['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') === 'live_success') {
                $out[] = $ab;
            }
        }
    }
    return $out;
}

function batch99ApplyContinuousHearts(array $state, string $pid, array $member, string $slot, array $hearts): array {
    foreach ($member['abilities'] ?? [] as $ab) {
        if (($ab['trigger'] ?? '') !== 'continuous') continue;
        if (($ab['type'] ?? '') !== 'hearts_if_combined_stage_members') continue;
        if (batch99CountCombinedStageMembers($state) < intval($ab['min_count'] ?? 6)) continue;
        foreach ($ab['hearts'] ?? [] as $h) {
            for ($i = 0; $i < intval($h['count'] ?? 1); $i++) {
                $hearts[] = $h['color'];
            }
        }
    }
    return $hearts;
}

function batch99OnMemberEntered(array $state, string $pid, array $entered): array {
    $p = &$state['players'][$pid];
    foreach ($p['stage'] as $slot => &$member) {
        if (!$member) continue;
        foreach ($member['abilities'] ?? [] as $idx => $ab) {
            if (($ab['trigger'] ?? '') !== 'auto') continue;
            if (($ab['type'] ?? '') !== 'draw_on_self_or_baton_enter') continue;
            $isSelf = ($member['instance_id'] ?? '') === ($entered['instance_id'] ?? '');
            $isBaton = !empty($entered['entered_via_baton']);
            if (!$isSelf && !$isBaton) continue;
            $max = intval($ab['max_per_turn'] ?? 2);
            $key = '_batch99_draw_baton_' . ($member['instance_id'] ?? $slot);
            $used = intval($p[$key] ?? 0);
            if ($used >= $max) continue;
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $p[$key] = $used + 1;
            $p['stage'][$slot] = $member;
            $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$mName] drew $drawn (Member entered" . ($isBaton ? ' via Baton' : '') . ').');
        }
    }
    unset($member);
    return $state;
}

function batch99ResolveAutoYell(array $state, string $pid, array $yellCards, array $ab, array &$member, int $idx, string $slot): ?array {
    if (($ab['type'] ?? '') !== 'auto_yell_hearts_if_group_member_count') return null;
    if (!empty($ab['once_per_turn']) && isAbilityUsed($member, $idx)) return $state;
    $cnt = batch99CountYellGroupMembers($yellCards, !empty($ab['same_group']));
    if ($cnt < intval($ab['min_count'] ?? 3)) return $state;
    addBonusHeartsToModifier($state, $pid, $ab['hearts'] ?? []);
    markAbilityUsed($member, $idx);
    $state['players'][$pid]['stage'][$slot] = $member;
    $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
    return addLog($state, $state['players'][$pid]['name'] .
        " — [$mName] gained bonus hearts from Yell ($cnt same-group Members).");
}

function batch99ResolveEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    $type = $ab['type'] ?? '';
    if (!batch99IsEffectType($type)) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    switch ($type) {
        case 'optional_ema_punch':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $prev = $state['_prev_turn_live_result'][$opp] ?? 'none';
            if ($prev !== 'failed') break;
            if (!empty($state['pending_prompt'])) break;
            // Card text: "you may ask them" — owner chooses first, then opponent answers.
            $state['pending_prompt'] = [
                'type'          => 'optional_ema_punch_ask',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_slot'   => $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? ''),
                'source_name'   => $name,
                'prompt'        => 'Ask your opponent: "Do you want an Emma Punch?"',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Ask them', 'No — Skip'],
                'ability'       => $ab,
                'optional'      => true,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] may ask for an Emma Punch (opponent failed Live last turn).');
            break;

        case 'stack_wr_member_under':
            if (!empty($state['pending_prompt'])) break;
            $group = $ab['group'] ?? '';
            $maxCost = intval($ab['max_cost'] ?? 9);
            $candidates = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
                    && cardMatchesGroup($c, $group, 'member')
                    && intval($c['cost'] ?? 0) <= $maxCost
            ));
            if (empty($candidates)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no eligible $group Member in Waiting Room to stack.");
                break;
            }
            $state['pending_prompt'] = [
                'type'        => 'batch99_stack_wr_member',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_id'   => $source['instance_id'] ?? '',
                'source_slot' => $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? ''),
                'source_name' => $name,
                'candidates'  => array_map('cardPromptSummary', $candidates),
                'prompt'      => "Place 1 $group Member (cost ≤$maxCost) from your Waiting Room under this Member?",
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] stack WR Member under self.");
            break;

        case 'live_success_score_if_yell_or_hearts_or_moved':
            $ok = false;
            if (countYellLiveCards($ctx['yell_cards'] ?? []) >= intval($ab['min_yell_lives'] ?? 2)) {
                $ok = true;
            }
            if (batch99CountDistinctHeartColorsOnStage($p) >= intval($ab['min_distinct_heart_colors'] ?? 5)) {
                $ok = true;
            }
            if (batch99StageHasMovedMember($p)) {
                $ok = true;
            }
            if ($ok) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (Live Success condition).');
            }
            break;

        case 'live_start_center_wild_if_distinct_groups':
            if (batch99CountDistinctGroupsOnStage($p) < intval($ab['min_distinct_groups'] ?? 3)) break;
            $center = $p['stage']['center'] ?? null;
            if (!$center) break;
            if (!isset($center['bonus_hearts'])) $center['bonus_hearts'] = [];
            $center['bonus_hearts'][] = 'any';
            $p['stage']['center'] = $center;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Center Member gained 1 Wild heart.');
            break;

        case 'live_success_add_wr_not_on_stage_group':
            $stageGroups = [];
            foreach ($p['stage'] as $mbr) {
                if (!$mbr) continue;
                $g = $mbr['group'] ?? '';
                if ($g !== '') $stageGroups[$g] = true;
            }
            $pick = null;
            foreach ($p['waiting_room'] as $c) {
                $g = $c['group'] ?? '';
                if ($g === '' || isset($stageGroups[$g])) continue;
                $pick = $c;
                break;
            }
            if (!$pick) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no WR card with a group not on Stage.");
                break;
            }
            $p['waiting_room'] = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => ($c['instance_id'] ?? '') !== ($pick['instance_id'] ?? '')
            ));
            $p['hand'][] = $pick;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] added ' . ($pick['name_en'] ?? $pick['name']) . ' from WR.');
            break;

        case 'hearts_from_discarded_colors':
            $discarded = $ctx['discarded_cards'] ?? [];
            $colors = [];
            foreach ($discarded as $c) {
                foreach ($c['hearts'] ?? [] as $h) {
                    $col = $h['color'] ?? '';
                    if ($col !== '' && $col !== 'draw') $colors[$col] = true;
                }
            }
            if (!empty($colors)) {
                $state = initLiveModifiers($state);
                foreach (array_keys($colors) as $col) {
                    for ($i = 0; $i < intval($ab['per_color'] ?? 1); $i++) {
                        $state['live_modifiers'][$pid]['bonus_hearts'][] = $col;
                    }
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] gained hearts from discarded card colors.");
            }
            break;

        case 'opp_stage_blade_bonus':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $state = initLiveModifiers($state);
            $amt = intval($ab['amount'] ?? 1);
            $n = applyMemberBladeBonus($state, $opp, [
                'amount' => $amt,
                'max_members' => 99,
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — Emma Punch! $n opponent Stage Member(s) gain +$amt Blade until Live ends.");
            break;

        case 'noop':
            break;
    }
    return $state;
}

function batch99ResolvePrompt(array $state, string $owner, array $prompt, string $choice, array $data): ?array {
    $promptType = $prompt['type'] ?? '';

    if ($promptType === 'optional_ema_punch_ask') {
        if (!isset(['yes' => true, 'no' => true, 'skip' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'no' || $choice === 'skip') {
            unset($state['pending_prompt']);
            $state['seq']++;
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Emma Verde') . '] skipped asking for an Emma Punch.');
            return finishPromptEffects($state);
        }
        $opp = ($owner === 'p1') ? 'p2' : 'p1';
        $ab = $prompt['ability'] ?? [];
        $name = $prompt['source_name'] ?? 'Emma Verde';
        $textFields = buildOpponentTextAnswerPromptFields($ab);
        $state['pending_prompt'] = [
            'type'          => 'opponent_text_answer',
            'owner'         => $owner,
            'responder'     => $opp,
            'source_id'     => $prompt['source_id'] ?? '',
            'source_slot'   => $prompt['source_slot'] ?? '',
            'source_name'   => $name,
            'prompt'        => $textFields['prompt'],
            'outcome_hints' => $textFields['outcome_hints'],
            'ability'       => $ab,
        ];
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — [' . $name . '] offers an Emma Punch?');
        $state['seq']++;
        return $state;
    }

    if ($promptType !== 'batch99_stack_wr_member') {
        return null;
    }
    $ownerP = &$state['players'][$owner];
    $slot = $prompt['source_slot'] ?? '';
    if ($choice === 'skip' || $choice === 'no') {
        unset($state['pending_prompt']);
        $state['seq']++;
        $state = addLog($state, $state['players'][$owner]['name'] .
            ' — skipped stacking a Member under ' . ($prompt['source_name'] ?? 'Member') . '.');
        return finishPromptEffects($state);
    }
    $pickId = $data['pick_id'] ?? $data['card_id'] ?? '';
    if ($pickId === '') throw new Exception('Choose a Member to stack');
    $stacked = null;
    $ownerP['waiting_room'] = array_values(array_filter(
        $ownerP['waiting_room'],
        function ($c) use ($pickId, &$stacked) {
            if (($c['instance_id'] ?? '') === $pickId) {
                $stacked = $c;
                return false;
            }
            return true;
        }
    ));
    if (!$stacked || $slot === '' || empty($ownerP['stage'][$slot])) {
        throw new Exception('Invalid stack target');
    }
    if (!isset($ownerP['stage'][$slot]['stacked_members'])) {
        $ownerP['stage'][$slot]['stacked_members'] = [];
    }
    $ownerP['stage'][$slot]['stacked_members'][] = $stacked;
    unset($state['pending_prompt']);
    $state['seq']++;
    $state = addLog($state, $state['players'][$owner]['name'] .
        ' — stacked ' . ($stacked['name_en'] ?? $stacked['name']) .
        ' under ' . ($prompt['source_name'] ?? 'Member') . '.');
    return finishPromptEffects($state);
}

function batch99ClassifyTextAnswer(array $ab, string $raw): ?string {
    $prompt = trim($ab['prompt'] ?? '');
    if ($prompt !== 'Do you want an Emma Punch?') {
        return null;
    }
    $s = normalizeAnswerText($raw);
    if (preg_match('/お願い/u', $raw) || preg_match('/onega/i', $s)
        || preg_match('/please/u', $s) || preg_match('/^kudasai$/u', $s)) {
        return 'please';
    }
    return 'other';
}
