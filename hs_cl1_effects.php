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

function hsCl1StageMemberBladeCandidates(array $p, array $ab, string $excludeId = ''): array {
    $group = $ab['group'] ?? '';
    $subunit = $ab['subunit'] ?? '';
    $minCost = intval($ab['min_cost'] ?? 0);
    $candidates = [];
    foreach ($p['stage'] as $slot => $mbr) {
        if (!$mbr) continue;
        if ($excludeId !== '' && ($mbr['instance_id'] ?? '') === $excludeId) continue;
        if ($group !== '' && ($mbr['group'] ?? '') !== $group) continue;
        if ($subunit !== '' && !cardMatchesSubunit($mbr, $subunit)) continue;
        if ($minCost > 0 && intval($mbr['cost'] ?? 0) < $minCost) continue;
        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
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
    return getLiveTotalScore($state, 'p1') === getLiveTotalScore($state, 'p2');
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
            $state['pending_prompt'] = [
                'type'          => 'look_top_optional_wr',
                'owner'         => $pid,
                'responder'     => $pid,
                'target'        => $pid,
                'source_name'   => $name,
                'top_card'      => cardPromptSummary($top),
                'prompt'        => "Looked at $label on top of your deck. Put it into the Waiting Room?",
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Put in WR', 'No — Leave on top'],
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] looked at deck top ($label).");
            break;

        case 'live_start_pick_stage_member_blade':
            if (!empty($state['pending_prompt'])) break;
            $candidates = hsCl1StageMemberBladeCandidates($p, $ab);
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
                'candidates'    => $candidates,
                'blade_amount'  => $amt,
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
            $yellPool = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            $candidates = array_values(array_filter(
                $yellPool,
                fn($c) => cardMatchesYellPick($c, $ab)
            ));
            if (empty($candidates)) break;
            if (count($candidates) === 1) {
                $p['hand'][] = $candidates[0];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added ' . cardDisplayName($candidates[0]) . ' from Yell to hand (tied score).');
                break;
            }
            $state['pending_prompt'] = [
                'type'        => 'pick_yell_member',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'prompt'      => 'Combined Live Score is tied — choose 1 Yell card to add to your hand.',
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
    $candidates = hsCl1StageMemberBladeCandidates($p, $ab, $member['instance_id'] ?? '');
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
        return $state;
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
            $choices = ['member' => [
                'label' => 'Add 1 Member card from your Waiting Room to your hand.',
                'effect' => ['type' => 'add_from_wr', 'filter' => 'member', 'count' => 1],
            ]];
            $liveZoneCount = count(array_filter($ownerP['live_zone'] ?? []));
            if ($liveZoneCount >= intval($ability['min_live_zone'] ?? 2)) {
                $choices['live'] = [
                    'label' => "Add 1 $group Live card from your Waiting Room to your hand.",
                    'effect' => ['type' => 'add_from_wr', 'group' => $group, 'filter' => 'live', 'count' => 1],
                ];
            }
            $choiceFields = buildPlayerChoicePromptFields(['choices' => $choices]);
            $state['pending_prompt'] = [
                'type'          => 'live_success_pay_choice_wr_add',
                'step'          => 'pick',
                'owner'         => $owner,
                'responder'     => $owner,
                'source_name'   => $prompt['source_name'] ?? 'Live',
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
            $added = addFromWaitingRoomFiltered(
                $ownerP,
                $effect['group'] ?? '',
                $effect['filter'] ?? '',
                intval($effect['count'] ?? 1)
            );
            if ($added < 1) throw new Exception('No matching card in Waiting Room');
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Live') . "] added $added card(s) from Waiting Room.");
            unset($state['pending_prompt']);
            $state['seq']++;
            return finishLiveSuccessEffects($state);
        }
    }

    return null;
}
