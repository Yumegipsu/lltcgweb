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
 * mandatory Live Start abilities for the current performer only.
 */
function resumeLiveStartEffectPhase(array $state): array {
    if (($state['phase'] ?? '') !== 'live_start_effects') {
        return finishPromptEffects($state);
    }
    $fromPid = $state['_live_start_resume_from'] ?? $state['_live_start_perf_pid'] ?? null;
    unset($state['_live_start_resume_from']);
    if ($fromPid) {
        $state = resolveLiveStartAbilities($state, $fromPid);
        if (!empty($state['pending_prompt'])) {
            $state['_live_start_resume_from'] = $fromPid;
            return $state;
        }
    }
    return finishLiveStartEffects($state);
}

/**
 * [On Enter]/[Live Start] skills fire at each timing independently.
 * Do not suppress Live Start after On Enter earlier in the turn.
 */
function markMemberDualEnterLiveStartFired(array $state, string $pid, string $instanceId): array {
    return $state;
}

function shouldSkipDualEnterLiveStartAtLiveStart(array $member, array $ab): bool {
    return false;
}

/**
 * Pending Live Start ability rows for one source card.
 *
 * @return list<array{0:string,1:int,2:array}> [kind, abilityIndex, ability]
 */
function pendingLiveStartAbilitiesForSource(array $state, string $pid, array $source): array {
    $instanceId = (string)($source['instance_id'] ?? '');
    if ($instanceId === '') {
        return [];
    }
    if (isMemberCard($source)) {
        if (!memberInstanceOnStage($state['players'][$pid] ?? [], $instanceId)) {
            return [];
        }
        if (memberLiveStartAbilitiesNegated($source)) {
            return [];
        }
    }
    $pendingAbs = [];
    foreach ($source['abilities'] ?? [] as $abIdx => $ab) {
        $trigger = $ab['trigger'] ?? '';
        if ($trigger !== 'live_start' && $trigger !== 'on_enter_or_live_start') {
            continue;
        }
        if (isMemberCard($source) && shouldSkipDualEnterLiveStartAtLiveStart($source, $ab)) {
            continue;
        }
        if (isQueuedOptionalLiveStart($ab)) {
            if (isLiveStartOptionalResolved($state, [
                'owner' => $pid,
                'source_id' => $instanceId,
                'ability_index' => intval($abIdx),
            ])) {
                continue;
            }
            if (!optionalLiveStartAbilityEligible($state, $pid, $source, $ab)) {
                continue;
            }
            $pendingAbs[] = ['optional', intval($abIdx), $ab];
            continue;
        }
        if (isLiveStartMandatoryResolved($state, $pid, $instanceId, intval($abIdx))) {
            continue;
        }
        $pendingAbs[] = ['mandatory', intval($abIdx), $ab];
    }
    return $pendingAbs;
}

/**
 * Stage / Live sources that still have unresolved [Live Start] work (L→R default).
 *
 * @return list<array> prompt candidate summaries with zone (+ slot for Members)
 */
function collectLiveStartOrderSources(array $state, string $pid): array {
    $sources = [];
    $p = $state['players'][$pid] ?? [];
    foreach (['left', 'center', 'right'] as $slot) {
        $member = $p['stage'][$slot] ?? null;
        if (!$member || !isMemberCard($member)) {
            continue;
        }
        mergeCardCatalogFields($member);
        if (pendingLiveStartAbilitiesForSource($state, $pid, $member) === []) {
            continue;
        }
        $sources[] = array_merge(cardPromptSummary($member), [
            'zone' => 'stage',
            'slot' => $slot,
        ]);
    }
    $lives = [];
    foreach ($p['live_zone'] ?? [] as $i => $live) {
        if (!$live || !isLiveTypeCard($live)) {
            continue;
        }
        mergeCardCatalogFields($live);
        if (pendingLiveStartAbilitiesForSource($state, $pid, $live) === []) {
            continue;
        }
        $fallback = is_int($i) ? $i : 0;
        $lives[] = [
            'slot' => liveZoneSlotOf($live, $fallback),
            'card' => $live,
        ];
    }
    usort($lives, static fn(array $a, array $b): int => $a['slot'] <=> $b['slot']);
    foreach ($lives as $row) {
        $sources[] = array_merge(cardPromptSummary($row['card']), [
            'zone' => 'live',
            'slot' => $row['slot'],
        ]);
    }
    return $sources;
}

// ─────────────────────────────────────────────
// [Live Start] abilities (before Yell / Performance)
// ─────────────────────────────────────────────

function resolveLiveStartAbilities(array $state, string $pid): array {
    $attempting = $state['live_attempt'] ?? ['p1', 'p2'];
    if (!in_array($pid, $attempting, true)) {
        return $state;
    }
    // Official Live Start is for performers attempting a Live — Member-bluff-only
    // storage must not fire Stage [Live Start] (e.g. Kaho blade draw/discard).
    if (function_exists('playerShouldResolveLiveStart')
        && !playerShouldResolveLiveStart($state, $pid)) {
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
    // Hydrate Stage / Live copies so Live Start still fires when hand/storage
    // cards were stripped of abilities/score/required_hearts (issue #66 SUKI).
    foreach ($state['players'][$pid]['stage'] as &$stageMbr) {
        if ($stageMbr) {
            mergeCardCatalogFields($stageMbr);
        }
    }
    unset($stageMbr);
    foreach ($state['players'][$pid]['live_zone'] as &$zoneLive) {
        if ($zoneLive) {
            mergeCardCatalogFields($zoneLive);
        }
    }
    unset($zoneLive);

    // Optionals are opened inline in chosen/L→R order with mandatories (#78). Empty queue
    // sentinel stops finishLiveStartEffects from re-collecting a deferred list.
    // Do not wipe a pre-seeded queue (tests / legacy resume paths).
    if (!array_key_exists('live_start_optional_queue', $state)) {
        $state['live_start_optional_queue'] = [];
    }

    $orderMap = is_array($state['_live_start_order'] ?? null) ? $state['_live_start_order'] : [];
    $orderIds = $orderMap[$pid] ?? null;
    if (!is_array($orderIds)) {
        $orderSources = collectLiveStartOrderSources($state, $pid);
        if (count($orderSources) > 1) {
            $state['pending_prompt'] = [
                'type'          => 'live_start_order_sources',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => 'Live Start',
                'prompt'        => 'Choose the order to activate Live Start abilities (first → last).',
                'candidates'    => $orderSources,
                'pick_count'    => count($orderSources),
                'order_all'     => true,
            ];
            $state['phase'] = 'live_start_effects';
            $state['_live_start_resume_from'] = $pid;
            $state['seq'] = intval($state['seq'] ?? 0) + 1;
            return $state;
        }
        $orderIds = array_values(array_map(
            static fn(array $s): string => (string)($s['instance_id'] ?? ''),
            $orderSources
        ));
        $orderMap[$pid] = $orderIds;
        $state['_live_start_order'] = $orderMap;
    }

    $byId = [];
    foreach (liveStartSourcesLeftToRight($state, $pid) as $source) {
        $iid = (string)($source['instance_id'] ?? '');
        if ($iid !== '') {
            $byId[$iid] = $source;
        }
    }
    // Prefer player-chosen order; fall back to any leftover L→R sources not listed.
    $ordered = [];
    foreach ($orderIds as $rawId) {
        $iid = (string)$rawId;
        if ($iid !== '' && isset($byId[$iid])) {
            $ordered[] = $byId[$iid];
            unset($byId[$iid]);
        }
    }
    foreach ($byId as $source) {
        $ordered[] = $source;
    }

    foreach ($ordered as $source) {
        $instanceId = (string)($source['instance_id'] ?? '');
        if ($instanceId === '') {
            continue;
        }
        $pendingAbs = pendingLiveStartAbilitiesForSource($state, $pid, $source);
        if ($pendingAbs === []) {
            continue;
        }
        $state = logAbilityChain($state, $pid, $source, 'live_start');
        foreach ($pendingAbs as [$kind, $abIdx, $ab]) {
            if ($kind === 'optional') {
                $item = [
                    'owner'         => $pid,
                    'source_id'     => $instanceId,
                    'source_name'   => $source['name_en'] ?? $source['name'] ?? 'Card',
                    'ability_index' => $abIdx,
                    'ability'       => $ab,
                ];
                $state['pending_prompt'] = buildOptionalLiveStartPrompt($state, $item);
                $state['phase'] = 'live_start_effects';
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $item['source_name'] . '] optional Live Start (choose).');
                $state['_live_start_resume_from'] = $pid;
                return $state;
            }
            // Mark before resolve so a prompt-backed ability is not re-opened on resume.
            $state = markLiveStartMandatoryResolved($state, $pid, $instanceId, $abIdx);
            $state = resolveAbilityEffect($state, $pid, $source, $ab, [
                'phase' => 'live_start',
                'ability_index' => $abIdx,
            ]);
            if (isMemberCard($source)) {
                $state = nBp5NotifyMemberAbilityResolved($state, $pid, $source, 'live_start');
            }
            if (!empty($state['pending_prompt'])) {
                $state['_live_start_resume_from'] = $pid;
                return $state;
            }
            if (function_exists('bp7FlushPendingAllyWaits')) {
                $state = bp7FlushPendingAllyWaits($state);
                if (!empty($state['pending_prompt'])) {
                    $state['_live_start_resume_from'] = $pid;
                    return $state;
                }
            }
        }
    }

    if (function_exists('bp7FlushPendingAllyWaits')) {
        $state = bp7FlushPendingAllyWaits($state);
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

/**
 * Stage left→center→right, then Live storage by live_slot (owner's left→right).
 * Used so mandatory + optional Live Starts share one spatial order (#78).
 *
 * @return list<array>
 */
function liveStartSourcesLeftToRight(array $state, string $pid): array {
    $p = $state['players'][$pid] ?? [];
    $sources = [];
    foreach (['left', 'center', 'right'] as $slot) {
        $member = $p['stage'][$slot] ?? null;
        if (!$member || !isMemberCard($member)) {
            continue;
        }
        if (!memberInstanceOnStage($p, $member['instance_id'] ?? '')) {
            continue;
        }
        mergeCardCatalogFields($member);
        $sources[] = $member;
    }
    $lives = [];
    foreach ($p['live_zone'] ?? [] as $i => $live) {
        if (!$live || !isLiveTypeCard($live)) {
            continue;
        }
        mergeCardCatalogFields($live);
        $fallback = is_int($i) ? $i : 0;
        $lives[] = [
            'slot' => liveZoneSlotOf($live, $fallback),
            'card' => $live,
        ];
    }
    usort($lives, static fn(array $a, array $b): int => $a['slot'] <=> $b['slot']);
    foreach ($lives as $row) {
        $sources[] = $row['card'];
    }
    return $sources;
}

function optionalLiveStartAbilityEligible(array $state, string $pid, array $card, array $ab): bool {
    $p = $state['players'][$pid] ?? [];
    if (!empty($ab['requires_success_lives']) && empty($p['success_lives'])) {
        return false;
    }
    if (!empty($ab['requires_other_stage_member'])
        && !stageHasOtherMember($p, $card['instance_id'] ?? '')) {
        return false;
    }
    if (!empty($ab['requires_full_stage']) && !stageIsFull($p)) {
        return false;
    }
    if (($ab['type'] ?? '') === 'optional_return_member_energy'
        && empty(stageMembersWithStackedEnergy($p))) {
        return false;
    }
    if (($ab['type'] ?? '') === 'optional_discard_hand'
        && ($ab['filter'] ?? '') === 'live') {
        $hasLive = false;
        foreach ($p['hand'] ?? [] as $hc) {
            if (isLiveTypeCard($hc)) {
                $hasLive = true;
                break;
            }
        }
        if (!$hasLive) {
            return false;
        }
    }
    // Umi PL!-bp3-004 etc.: do not offer discard→WR Live if the cost or target is impossible.
    if (($ab['type'] ?? '') === 'optional_discard_add_from_wr') {
        $needDiscard = max(1, intval($ab['discard'] ?? 1));
        if (count($p['hand'] ?? []) < $needDiscard) {
            return false;
        }
        $cfg = function_exists('wrPickCfgFromAbility')
            ? wrPickCfgFromAbility($ab)
            : [
                'group' => (string)($ab['group'] ?? ''),
                'filter' => (string)($ab['filter'] ?? 'live'),
            ];
        $needAdd = max(1, intval($ab['count'] ?? 1));
        if (function_exists('wrPickMatchCount')) {
            if (wrPickMatchCount($p, $cfg, $needAdd) < $needAdd) {
                return false;
            }
        } elseif (function_exists('wrCandidatesMatching')
            && count(wrCandidatesMatching($p, $cfg)) < $needAdd) {
            return false;
        }
    }
    if (!optionalCostAbilityShouldOpen($state, $pid, $ab)) {
        return false;
    }
    return true;
}

function collectOptionalLiveStartAbilities(array $state): array {
    $queue = [];
    $attempting = $state['live_attempt'] ?? ['p1', 'p2'];
    // When interleaving Live Starts per performer, only queue the current one.
    $scopePid = $state['_live_start_perf_pid'] ?? null;
    foreach (['p1', 'p2'] as $pid) {
        if (!in_array($pid, $attempting, true)) continue;
        if ($scopePid !== null && $pid !== $scopePid) continue;
        if (function_exists('playerShouldResolveLiveStart')
            && !playerShouldResolveLiveStart($state, $pid)) {
            continue;
        }
        foreach (liveStartSourcesLeftToRight($state, $pid) as $card) {
            if (isMemberCard($card) && memberLiveStartAbilitiesNegated($card)) {
                continue;
            }
            foreach ($card['abilities'] ?? [] as $idx => $ab) {
                $trigger = $ab['trigger'] ?? '';
                if ($trigger !== 'live_start' && $trigger !== 'on_enter_or_live_start') continue;
                if (isMemberCard($card) && shouldSkipDualEnterLiveStartAtLiveStart($card, $ab)) continue;
                if (!isQueuedOptionalLiveStart($ab)) continue;
                if (!optionalLiveStartAbilityEligible($state, $pid, $card, $ab)) continue;
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

/** Allow COMPASS (etc.) to re-open an optional Live Start already answered this round. */
function clearLiveStartOptionalResolved(array $state, string $owner, string $sourceId, int $abilityIndex): array {
    if ($sourceId === '') {
        return $state;
    }
    $key = liveStartOptionalResolvedKey($owner, $sourceId, $abilityIndex);
    $resolved = $state['live_start_optional_resolved'] ?? [];
    if (!is_array($resolved) || $resolved === []) {
        return $state;
    }
    // markLiveStartOptionalResolved stores a list of key strings.
    $filtered = [];
    foreach ($resolved as $k => $entry) {
        if (is_string($k) && $k === $key) {
            continue; // legacy map form
        }
        if ((string)$entry === $key) {
            continue;
        }
        if (is_string($k) && !is_int($k)) {
            $filtered[$k] = $entry;
        } else {
            $filtered[] = $entry;
        }
    }
    $state['live_start_optional_resolved'] = array_is_list($filtered)
        ? array_values($filtered)
        : $filtered;
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
    // Keep per-ability resolved markers until the full Live round ends so the
    // second performer's Live Start cannot re-queue already-answered optionals,
    // and mid-performance Live Starts do not replay the first performer's skills.
    unset($state['_live_start_resume_from']);
    // Heart-dependent Live Starts (Zenhoui Kyun♡) after optional Member buffs (#73).
    $state = flushDeferredMpExtraHeartsLiveStart($state);
    if (!empty($state['pending_prompt'])) {
        $state['phase'] = 'live_start_effects';
        return $state;
    }

    $perfPid = $state['_live_start_perf_pid'] ?? null;
    if ($perfPid) {
        $done = $state['_live_start_done'] ?? [];
        $done[$perfPid] = true;
        $state['_live_start_done'] = $done;
        if (is_array($state['_live_start_order'] ?? null)) {
            unset($state['_live_start_order'][$perfPid]);
            if ($state['_live_start_order'] === []) {
                unset($state['_live_start_order']);
            }
        }
    }

    // With sequential live_show: initial Live Start stage waits for client ack before
    // first Yell. Mid-performance (2nd performer) Live Starts continue into Yell now.
    $inPerfLiveShow = !empty($state['live_show'])
        && ($state['live_show']['stage'] ?? '') === 'performance';
    $shouldAdvanceYell = empty($state['live_show']) || $inPerfLiveShow;

    if (($state['phase'] ?? '') === 'live_start_effects'
        && $advancePerformance
        && empty($GLOBALS['TUT_PERF_MANUAL_PHASES'])
        && $shouldAdvanceYell) {
        $first = $state['first_player'] ?? 'p1';
        $attempting = $state['live_attempt'] ?? ['p1', 'p2'];
        // Prefer explicit performer; else first attempting player (tests that skip begin*).
        $yellPid = $perfPid;
        if ($yellPid === null || $yellPid === '') {
            foreach (liveAttemptOrder($state, in_array('p1', $attempting, true), in_array('p2', $attempting, true)) as $cand) {
                $yellPid = $cand;
                break;
            }
            $yellPid = $yellPid ?? $first;
            $state['_live_start_perf_pid'] = $yellPid;
            $done = $state['_live_start_done'] ?? [];
            $done[$yellPid] = true;
            $state['_live_start_done'] = $done;
        }
        if ($yellPid === $first) {
            $state['phase'] = 'live_performance_first';
            if (empty($state['live_show'])) {
                $state = addLog($state, '=== Live Show ===');
            }
        } else {
            $state['phase'] = 'live_performance_second';
        }
        if (in_array($yellPid, $attempting, true)) {
            $state = resolvePerformancePhase($state, $yellPid);
        } else {
            $state = continuePerformanceYellPhase($state, $yellPid);
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

/**
 * Order attempting players as first_player then second (official Performance order).
 *
 * @return list<string>
 */
function liveAttemptOrder(array $state, bool $p1Attempt = true, bool $p2Attempt = true): array {
    $first = $state['first_player'] ?? 'p1';
    $second = ($first === 'p1') ? 'p2' : 'p1';
    $order = [];
    foreach ([$first, $second] as $pid) {
        if ($pid === 'p1' && !$p1Attempt) {
            continue;
        }
        if ($pid === 'p2' && !$p2Attempt) {
            continue;
        }
        $order[] = $pid;
    }
    return $order;
}

/**
 * Resolve Live Starts for one performer, then Yell for that same player.
 * Used for the second performer after the first has already yelled.
 */
function beginLiveStartForPerformer(array $state, string $pid): array {
    $attempting = $state['live_attempt'] ?? [];
    if (!in_array($pid, $attempting, true)) {
        return resolvePerformancePhase($state, $pid);
    }
    // Official 8.3.4 → 8.3.8: non-Live cards leave storage before Live Start resolves
    // (Fanfare!!! / Mira-Cra Park WR counts, etc.). Idempotent if already discarded.
    if (function_exists('discardLiveZoneMembersToWaitingRoom')) {
        $state = discardLiveZoneMembersToWaitingRoom($state, $pid);
    }
    $state['_live_start_perf_pid'] = $pid;
    $state['phase'] = 'live_start_effects';
    // Keep optional/mandatory resolved markers so already-answered skills do not replay.
    unset($state['live_start_optional_queue']);
    // Member-bluff-only seats still yell/skip — but do not open Live Start skills.
    if (function_exists('playerAttemptingLivePerformance')
        && !playerAttemptingLivePerformance($state, $pid)) {
        return finishLiveStartEffects($state);
    }
    if (performanceRoundHasLiveCards($state)) {
        $state = addLog($state, '=== Live Start Effects (' .
            ($state['players'][$pid]['name'] ?? $pid) . ') ===');
    }
    $state = resolveLiveStartAbilities($state, $pid);
    if (!empty($state['pending_prompt'])) {
        $state['_live_start_resume_from'] = $pid;
        return $state;
    }
    return finishLiveStartEffects($state);
}

function beginLiveStartEffectPhase(array $state, bool $p1Attempt = true, bool $p2Attempt = true): array {
    $state['live_attempt'] = liveAttemptOrder($state, $p1Attempt, $p2Attempt);

    $state['live_round_success'] = [];
    foreach (['p1', 'p2'] as $pid) {
        if (!in_array($pid, $state['live_attempt'], true)) {
            $state['live_round_success'][$pid] = false;
        }
    }

    $state = initLiveModifiers($state);
    $state['phase'] = 'live_start_effects';
    $state['_live_start_done'] = [];
    unset(
        $state['live_start_optional_resolved'],
        $state['live_start_mandatory_resolved'],
        $state['live_start_entry_applied'],
        $state['live_start_optional_queue']
    );
    if (performanceRoundHasLiveCards($state)) {
        $state = addLog($state, '=== Live Start Effects ===');
    }

    $perfPid = $state['live_attempt'][0] ?? null;
    if ($perfPid === null) {
        return finishLiveStartEffects($state);
    }
    // Official 8.3: only the current performer's Live Starts before their Yell.
    // 8.3.4: that performer's Member bluffs are already in WR before Live Start.
    if (function_exists('discardLiveZoneMembersToWaitingRoom')) {
        $state = discardLiveZoneMembersToWaitingRoom($state, $perfPid);
    }
    $state['_live_start_perf_pid'] = $perfPid;
    $state = resolveLiveStartAbilities($state, $perfPid);
    if (!empty($state['pending_prompt'])) {
        $state['_live_start_resume_from'] = $perfPid;
        return $state;
    }
    return finishLiveStartEffects($state);
}
