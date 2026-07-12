<?php
/**
 * Live Start effect phase — extracted from effects.php.
 */

/** Stable key for a mandatory Live Start ability that already opened/resolved. */
function liveStartMandatoryResolvedKey(string $pid, string $instanceId, int $abilityIndex): string {
    return $pid . ':' . $instanceId . ':' . $abilityIndex;
}

function isLiveStartMandatoryResolved(array $state, string $pid, string $instanceId, int $abilityIndex): bool {
    $key = liveStartMandatoryResolvedKey($pid, $instanceId, $abilityIndex);
    return !empty(($state['live_start_mandatory_resolved'] ?? [])[$key]);
}

function markLiveStartMandatoryResolved(array $state, string $pid, string $instanceId, int $abilityIndex): array {
    if ($instanceId === '') {
        return $state;
    }
    $key = liveStartMandatoryResolvedKey($pid, $instanceId, $abilityIndex);
    $resolved = $state['live_start_mandatory_resolved'] ?? [];
    $resolved[$key] = true;
    $state['live_start_mandatory_resolved'] = $resolved;
    return $state;
}

/**
 * After a Live Start prompt resolves, finish the interrupted player's remaining
 * mandatory Live Start abilities, then any later players in live_attempt order.
 */
function resumeLiveStartEffectPhase(array $state): array {
    if (($state['phase'] ?? '') !== 'live_start_effects') {
        return finishPromptEffects($state);
    }
    $fromPid = $state['_live_start_resume_from'] ?? null;
    unset($state['_live_start_resume_from']);
    if ($fromPid) {
        $attempting = $state['live_attempt'] ?? [];
        $resume = false;
        foreach ($attempting as $pid) {
            if (!$resume) {
                if ($pid === $fromPid) {
                    $resume = true;
                    // Fall through: re-enter fromPid so Live cards / later Members still resolve.
                } else {
                    continue;
                }
            }
            $state = resolveLiveStartAbilities($state, $pid);
            if (!empty($state['pending_prompt'])) {
                $state['_live_start_resume_from'] = $pid;
                return $state;
            }
        }
    }
    return finishLiveStartEffects($state);
}

function markMemberDualEnterLiveStartFired(array $state, string $pid, string $instanceId): array {
    if ($instanceId === '') {
        return $state;
    }
    $slot = findMemberSlot($state['players'][$pid] ?? [], $instanceId);
    if ($slot === '' || empty($state['players'][$pid]['stage'][$slot])) {
        return $state;
    }
    $state['players'][$pid]['stage'][$slot]['on_enter_or_live_start_fired'] = true;
    return $state;
}

function shouldSkipDualEnterLiveStartAtLiveStart(array $member, array $ab): bool {
    return ($ab['trigger'] ?? '') === 'on_enter_or_live_start'
        && !empty($member['on_enter_or_live_start_fired']);
}

// ─────────────────────────────────────────────
// [Live Start] abilities (before Yell / Performance)
// ─────────────────────────────────────────────

function resolveLiveStartAbilities(array $state, string $pid): array {
    $attempting = $state['live_attempt'] ?? ['p1', 'p2'];
    if (!in_array($pid, $attempting, true)) {
        return $state;
    }
    // Entry auras must run once per player per Live Start phase (resume re-enters this fn).
    $entryFlags = $state['live_start_entry_applied'] ?? [];
    if (empty($entryFlags[$pid])) {
        $state = sBp5ApplyOppLivePenalties($state, $pid);
        $state = spBp2ApplyContinuousOppLiveGrayHeart($state, $pid);
        $entryFlags[$pid] = true;
        $state['live_start_entry_applied'] = $entryFlags;
    }
    $p = $state['players'][$pid];

    foreach ($p['stage'] as $member) {
        if (!$member || !isMemberCard($member)) continue;
        $instanceId = $member['instance_id'] ?? '';
        if (!memberInstanceOnStage($p, $instanceId)) continue;
        if (memberLiveStartAbilitiesNegated($member)) continue;
        $pendingAbs = [];
        foreach ($member['abilities'] ?? [] as $abIdx => $ab) {
            $trigger = $ab['trigger'] ?? '';
            if ($trigger !== 'live_start' && $trigger !== 'on_enter_or_live_start') {
                continue;
            }
            if (isQueuedOptionalLiveStart($ab) || shouldSkipDualEnterLiveStartAtLiveStart($member, $ab)) {
                continue;
            }
            if (isLiveStartMandatoryResolved($state, $pid, $instanceId, intval($abIdx))) {
                continue;
            }
            $pendingAbs[] = [$abIdx, $ab];
        }
        if (empty($pendingAbs)) continue;
        $state = logAbilityChain($state, $pid, $member, 'live_start');
        foreach ($pendingAbs as [$abIdx, $ab]) {
            // Mark before resolve so a prompt-backed ability is not re-opened on resume.
            $state = markLiveStartMandatoryResolved($state, $pid, $instanceId, intval($abIdx));
            $state = resolveAbilityEffect($state, $pid, $member, $ab, [
                'phase' => 'live_start',
                'ability_index' => intval($abIdx),
            ]);
            $state = nBp5NotifyMemberAbilityResolved($state, $pid, $member, 'live_start');
            if (!empty($state['pending_prompt'])) {
                $state['_live_start_resume_from'] = $pid;
                return $state;
            }
        }
    }

    foreach ($p['live_zone'] as $live) {
        if (!$live || !isLiveTypeCard($live)) continue;
        $instanceId = $live['instance_id'] ?? '';
        $pendingAbs = [];
        foreach ($live['abilities'] ?? [] as $abIdx => $ab) {
            $trigger = $ab['trigger'] ?? '';
            if ($trigger !== 'live_start' && $trigger !== 'on_enter_or_live_start') {
                continue;
            }
            if (isQueuedOptionalLiveStart($ab)) {
                continue;
            }
            if (isLiveStartMandatoryResolved($state, $pid, $instanceId, intval($abIdx))) {
                continue;
            }
            $pendingAbs[] = [$abIdx, $ab];
        }
        if (empty($pendingAbs)) continue;
        $state = logAbilityChain($state, $pid, $live, 'live_start');
        foreach ($pendingAbs as [$abIdx, $ab]) {
            $state = markLiveStartMandatoryResolved($state, $pid, $instanceId, intval($abIdx));
            $state = resolveAbilityEffect($state, $pid, $live, $ab, [
                'phase' => 'live_start',
                'ability_index' => intval($abIdx),
            ]);
            if (!empty($state['pending_prompt'])) {
                $state['_live_start_resume_from'] = $pid;
                return $state;
            }
        }
    }

    return $state;
}

function isQueuedOptionalLiveStart(array $ab): bool {
    // Only queue types that honor confirm/discard_ids without opening a second yes/no.
    // Other optional Live Start skills open their native UI once via the mandatory pass
    // (otherwise optional_live_start wraps them and they prompt twice before Performance).
    return in_array($ab['type'] ?? '', [
        'optional_discard_hand',
        'optional_discard_surveil',
        'optional_discard_add_from_wr',
        'optional_pay_energy',
        'optional_discard_named',
        'optional_discard_same_group',
        'optional_discard_prompt',
        'optional_discard_blade_named_extra',
    ], true);
}

function collectOptionalLiveStartAbilities(array $state): array {
    $queue = [];
    $attempting = $state['live_attempt'] ?? ['p1', 'p2'];
    foreach (['p1', 'p2'] as $pid) {
        if (!in_array($pid, $attempting, true)) continue;
        $p = $state['players'][$pid];
        $sources = [];
        foreach ($p['stage'] ?? [] as $member) {
            if ($member && isMemberCard($member) && memberInstanceOnStage($p, $member['instance_id'] ?? '')) {
                $sources[] = $member;
            }
        }
        foreach ($p['live_zone'] ?? [] as $live) {
            if ($live && isLiveTypeCard($live)) {
                $sources[] = $live;
            }
        }
        foreach ($sources as $card) {
            if (isMemberCard($card) && memberLiveStartAbilitiesNegated($card)) {
                continue;
            }
            foreach ($card['abilities'] ?? [] as $idx => $ab) {
                $trigger = $ab['trigger'] ?? '';
                if ($trigger !== 'live_start' && $trigger !== 'on_enter_or_live_start') continue;
                if (isMemberCard($card) && shouldSkipDualEnterLiveStartAtLiveStart($card, $ab)) continue;
                if (!isQueuedOptionalLiveStart($ab)) continue;
                if (!empty($ab['requires_success_lives']) && empty($p['success_lives'])) continue;
                if (!empty($ab['requires_other_stage_member'])
                    && !stageHasOtherMember($p, $card['instance_id'] ?? '')) {
                    continue;
                }
                if (!empty($ab['requires_full_stage']) && !stageIsFull($p)) continue;
                if (($ab['type'] ?? '') === 'optional_return_member_energy'
                    && empty(stageMembersWithStackedEnergy($p))) {
                    continue;
                }
                $queue[] = [
                    'owner'         => $pid,
                    'source_id'     => $card['instance_id'] ?? '',
                    'source_name'   => $card['name_en'] ?? $card['name'] ?? 'Card',
                    'ability_index' => $idx,
                    'ability'       => $ab,
                ];
            }
        }
    }
    return $queue;
}

function liveStartOptionalPromptText(array $ab): string {
    $type = $ab['type'] ?? '';
    if ($type === 'optional_discard_hand') {
        return 'Put ' . intval($ab['discard'] ?? 1) . ' card(s) from your hand into the Waiting Room for this Live Start effect?';
    }
    if ($type === 'optional_discard_surveil') {
        return 'Put ' . intval($ab['discard'] ?? 2) . ' card(s) from your hand into the Waiting Room, then look at and arrange the top ' .
            intval($ab['look'] ?? 3) . ' cards of your deck?';
    }
    if ($type === 'optional_discard_add_from_wr') {
        return 'Put ' . intval($ab['discard'] ?? 1) . ' card(s) from your hand into the Waiting Room to add a μ\'s Live from your Waiting Room?';
    }
    if ($type === 'optional_pay_energy') {
        return 'Pay ' . intval($ab['cost'] ?? 0) . ' Energy for this Live Start effect?';
    }
    if ($type === 'optional_discard_named') {
        if (!empty($ab['exact_total'])) {
            $n = intval($ab['exact_total']);
            return "Put $n matching card(s) from your hand into the Waiting Room for this Live Start effect?";
        }
        return 'You may put any number of matching cards from your hand into the Waiting Room for this Live Start effect?';
    }
    if ($type === 'optional_discard_same_group') {
        $n = intval($ab['discard'] ?? 2);
        return "Put $n cards with the same unit name from your hand into the Waiting Room for this Live Start effect?";
    }
    if ($type === 'optional_wait_subunit_opp_pick_active') {
        $sub = $ab['subunit'] ?? 'Member';
        return "Put 1 $sub Member into Wait: your opponent puts 1 active Member into Wait?";
    }
    if ($type === 'optional_return_member_energy') {
        return 'Return Energy stacked under a Stage Member to your Energy deck for bonus hearts?';
    }
    if ($type === 'optional_discard_blade_named_extra') {
        $named = $ab['named'] ?? 'that Member';
        return 'Put 1 card from your hand into the Waiting Room: gain +'
            . intval($ab['amount'] ?? 1) . ' Blade until Live ends'
            . ($named !== '' ? " (+{$ab['extra_amount']} more if $named)" : '') . '?';
    }
    return $ab['prompt'] ?? 'Use optional Live Start effect?';
}

function liveStartOptionalResolvedKey(string $owner, string $sourceId, int $abilityIndex): string {
    return $owner . ':' . $sourceId . ':' . $abilityIndex;
}

function isLiveStartOptionalResolved(array $state, array $item): bool {
    $resolved = $state['live_start_optional_resolved'] ?? [];
    if (!is_array($resolved)) {
        return false;
    }
    $key = liveStartOptionalResolvedKey(
        $item['owner'] ?? '',
        $item['source_id'] ?? '',
        intval($item['ability_index'] ?? 0)
    );
    return in_array($key, $resolved, true);
}

function markLiveStartOptionalResolved(array $state, string $owner, string $sourceId, int $abilityIndex): array {
    if ($sourceId === '') {
        return $state;
    }
    $key = liveStartOptionalResolvedKey($owner, $sourceId, $abilityIndex);
    $resolved = $state['live_start_optional_resolved'] ?? [];
    if (!is_array($resolved)) {
        $resolved = [];
    }
    if (!in_array($key, $resolved, true)) {
        $resolved[] = $key;
    }
    $state['live_start_optional_resolved'] = $resolved;
    return $state;
}

/**
 * True when optional_live_start Yes caused the ability to open another yes/no
 * for the same source (double confirm before Performance).
 */
function isRedundantOptionalLiveStartYesNoFollowUp(?array $nested, array $wrapper): bool {
    if (!$nested) {
        return false;
    }
    $choices = array_values(array_map('strval', $nested['choices'] ?? []));
    sort($choices);
    if ($choices !== ['no', 'yes']) {
        return false;
    }
    // Nested discard/pay confirms need player input beyond the wrapper Yes.
    if (intval($nested['discard_count'] ?? 0) > 0
        || intval($nested['max_discard'] ?? 0) > 0
        || !empty($nested['needs_pay'])
        || ($nested['type'] ?? '') === 'optional_discard_prompt') {
        return false;
    }
    $wSrc = (string)($wrapper['source_id'] ?? '');
    $nSrc = (string)($nested['source_id'] ?? '');
    if ($wSrc !== '' && $nSrc !== '' && $wSrc !== $nSrc) {
        return false;
    }
    return true;
}

function buildOptionalLiveStartPrompt(array $state, array $item): array {
    $ab = $item['ability'];
    $owner = $item['owner'];
    $ownerP = $state['players'][$owner] ?? [];
    $discardCount = intval($ab['max_discard'] ?? 0) ?: intval($ab['discard'] ?? 0);
    $maxDiscard = intval($ab['max_discard'] ?? 0);
    if (($ab['type'] ?? '') === 'optional_discard_blade_named_extra') {
        $discardCount = 1;
    }
    if (($ab['type'] ?? '') === 'optional_discard_named') {
        if (!empty($ab['exact_total'])) {
            $discardCount = intval($ab['exact_total']);
            $maxDiscard = 0;
        } else {
            $matchCount = countOptionalNamedDiscardMatches($ownerP, $ab, $item['source_id'] ?? '');
            $maxDiscard = $matchCount;
            $discardCount = $matchCount;
        }
    }
    $prompt = [
        'type'          => 'optional_live_start',
        'owner'         => $owner,
        'responder'     => $owner,
        'source_id'     => $item['source_id'],
        'source_name'   => $item['source_name'],
        'ability_index' => $item['ability_index'],
        'prompt'        => liveStartOptionalPromptText($ab),
        'choices'       => ['yes', 'no'],
        'choice_labels' => ['Yes', 'No — Skip'],
        'ability'       => $ab,
        'discard_count' => $discardCount,
        'max_discard'   => $maxDiscard,
        'needs_pay'     => ($ab['type'] ?? '') === 'optional_pay_energy',
        'pay_cost'      => intval($ab['cost'] ?? 0),
    ];
    $queueLeft = count($state['live_start_optional_queue'] ?? []);
    $totalOptional = $queueLeft + 1;
    if ($totalOptional > 1) {
        $prompt['queue_total'] = $totalOptional;
        $prompt['queue_remaining'] = $queueLeft;
        $prompt['prompt'] .= ' (' . ($totalOptional - $queueLeft) . ' of ' . $totalOptional
            . ' optional Live Start effects.)';
    }
    return enrichSelfActivationPrompt($state, $prompt);
}

function finishLiveStartEffects(array $state, bool $advancePerformance = true): array {
    if (!empty($state['pending_prompt'])) {
        $state['phase'] = 'live_start_effects';
        return $state;
    }
    // Member/Live prompts often call finishLiveStartEffects directly; resume mandatory
    // abilities for the interrupted player before optional queue / Performance.
    if (!empty($state['_live_start_resume_from'])) {
        return resumeLiveStartEffectPhase($state);
    }
    if (!array_key_exists('live_start_optional_queue', $state)) {
        $state['live_start_optional_queue'] = collectOptionalLiveStartAbilities($state);
    }
    $queue = $state['live_start_optional_queue'] ?? [];
    while (!empty($queue)) {
        $item = array_shift($queue);
        if (isLiveStartOptionalResolved($state, $item)) {
            $state['live_start_optional_queue'] = $queue;
            continue;
        }
        $ownerP = $state['players'][$item['owner']] ?? null;
        $srcId = $item['source_id'] ?? '';
        $source = $ownerP ? findLiveStartSourceCard($state, $item['owner'], $srcId) : null;
        if (!$source) {
            continue;
        }
        $state['live_start_optional_queue'] = $queue;
        $state['pending_prompt'] = buildOptionalLiveStartPrompt($state, $item);
        $state['phase'] = 'live_start_effects';
        $state = addLog($state, $state['players'][$item['owner']]['name'] .
            ' — [' . $item['source_name'] . '] optional Live Start (choose).');
        return $state;
    }
    unset($state['live_start_optional_queue']);
    unset($state['live_start_optional_resolved']);
    unset($state['live_start_mandatory_resolved']);
    unset($state['live_start_entry_applied']);
    unset($state['_live_start_resume_from']);
    if (($state['phase'] ?? '') === 'live_start_effects' && $advancePerformance && empty($GLOBALS['TUT_PERF_MANUAL_PHASES'])) {
        $state['phase'] = 'live_performance_first';
        $state = addLog($state, '=== Live Show ===');
        $first = $state['first_player'];
        $attempting = $state['live_attempt'] ?? ['p1', 'p2'];
        if (in_array($first, $attempting, true)) {
            $state = resolvePerformancePhase($state, $first);
        } else {
            $state = continuePerformancePhase($state, $first);
        }
    }
    return $state;
}

function actionLiveStartChoice(array $state, string $pid, array $data): array {
    if ($state['phase'] !== 'live_start_effects') throw new Exception('Not resolving Live Start effects');

    $instanceId = $data['card_id'] ?? '';
    $abilityIdx = intval($data['ability_index'] ?? 0);
    $skip = !empty($data['skip']);

    $source = findLiveStartSourceCard($state, $pid, $instanceId);
    if (!$source) throw new Exception('Card not found on Stage or in Live storage');

    $abilities = $source['abilities'] ?? [];
    if (!isset($abilities[$abilityIdx])) throw new Exception('Invalid ability');
    $ab = $abilities[$abilityIdx];

    if (!$skip) {
        $ctx = [
            'discard_ids' => $data['discard_ids'] ?? [],
            'pay'         => !empty($data['pay']),
            'confirm'     => true,
        ];
        $state = resolveAbilityEffect($state, $pid, $source, $ab, $ctx);
    }

    if (empty($state['pending_prompt'])) {
        $state = finishLiveStartEffects($state);
    }
    $state['seq']++;
    return $state;
}

// ─────────────────────────────────────────────
// Live Start effect queue (live_start_effects phase)
// ─────────────────────────────────────────────

function beginLiveStartEffectPhase(array $state, bool $p1Attempt = true, bool $p2Attempt = true): array {
    $state['live_attempt'] = [];
    if ($p1Attempt) $state['live_attempt'][] = 'p1';
    if ($p2Attempt) $state['live_attempt'][] = 'p2';

    $state['live_round_success'] = [];
    foreach (['p1', 'p2'] as $pid) {
        if (!in_array($pid, $state['live_attempt'], true)) {
            $state['live_round_success'][$pid] = false;
        }
    }

    $state = initLiveModifiers($state);
    $state['phase'] = 'live_start_effects';
    if (performanceRoundHasLiveCards($state)) {
        $state = addLog($state, '=== Live Start Effects ===');
    }
    foreach ($state['live_attempt'] as $pid) {
        $state = resolveLiveStartAbilities($state, $pid);
        if (!empty($state['pending_prompt'])) {
            $state['_live_start_resume_from'] = $pid;
            if (!array_key_exists('live_start_optional_queue', $state)) {
                $state['live_start_optional_queue'] = collectOptionalLiveStartAbilities($state);
            }
            return $state;
        }
    }
    if (empty($state['live_start_optional_queue'])) {
        $state['live_start_optional_queue'] = collectOptionalLiveStartAbilities($state);
    }
    return finishLiveStartEffects($state);
}
