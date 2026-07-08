<?php
/**
 * Prompt lifecycle helpers extracted from effects.php.
 */

/** Whether the phase timer should run while this prompt is pending. */
function promptUsesPhaseTimer(array $prompt): bool {
    return in_array($prompt['responder'] ?? '', ['p1', 'p2'], true);
}

function promptTimerKey(?array $prompt): string {
    if (!$prompt) {
        return '';
    }
    return implode('|', [
        $prompt['type'] ?? '',
        $prompt['responder'] ?? '',
        $prompt['step'] ?? '',
        $prompt['source_id'] ?? '',
        $prompt['prompt'] ?? '',
    ]);
}

function finishPromptEffects(array $state): array {
    $phase = $state['phase'] ?? '';
    if ($phase === 'live_success_effects') {
        return finishLiveSuccessEffects($state);
    }
    if ($phase === 'live_start_effects') {
        return finishLiveStartEffects($state);
    }
    return $state;
}

/** After placing a Member from a prompt: keep chained On Enter prompts. */
function returnAfterPlacedMemberEnter(array $state, bool $finishLiveStart = false): array {
    $parentPrompt = $state['pending_prompt'] ?? null;
    if (!empty($parentPrompt) && ($parentPrompt['step'] ?? '') === 'pick_slot') {
        unset($state['pending_prompt']);
        $state['seq']++;
        return $finishLiveStart ? finishLiveStartEffects($state) : finishPromptEffects($state);
    }
    if (!empty($state['pending_prompt'])) {
        $state['seq']++;
        return $state;
    }
    unset($state['pending_prompt']);
    $state['seq']++;
    return $finishLiveStart ? finishLiveStartEffects($state) : finishPromptEffects($state);
}

/**
 * Player chooses which Waiting Room card to add to hand (never auto-first-match).
 * Sets pending_prompt pick_wr_to_hand or pick_wr_leave_stage_add.
 */
function wrPickExtraFiltersFromCfg(array $cfg): array {
    $extra = [];
    foreach ([
        'min_score',
        'min_live_score',
        'subunit',
        'min_required_hearts',
        'min_required_heart_color',
        'max_live_score',
    ] as $key) {
        if (!array_key_exists($key, $cfg) || $cfg[$key] === '' || $cfg[$key] === null) {
            continue;
        }
        $extra[$key] = $cfg[$key];
    }
    return $extra;
}

/**
 * Add matching WR card(s) to hand. When count is 1 and multiple cards qualify, opens pick_wr_to_hand.
 *
 * @return int|null Cards added immediately, or null when a pick prompt was opened.
 */
function addFromWaitingRoomWithChoice(
    array &$state,
    string $pid,
    array $source,
    array $ab,
    array $ctx,
    array $cfg,
    int $count = 1,
    bool $leaveStage = false
): ?int {
    if ($count < 1) {
        return 0;
    }
    $p = &$state['players'][$pid];
    $candidates = wrCandidatesMatching($p, $cfg);
    if (empty($candidates)) {
        return 0;
    }
    if ($count === 1 && count($candidates) > 1) {
        $slot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
        if ($slot === null) {
            $slot = 'center';
        }
        $member = !empty($p['stage'][$slot]) ? $p['stage'][$slot] : $source;
        $abilityIdx = intval($ctx['ability_index'] ?? $ctx['ability_idx'] ?? 0);
        startPickWrToHandPrompt($state, $pid, $member, $slot, $abilityIdx, $ab, $cfg, $leaveStage, $count);
        return null;
    }
    $maxCost = isset($cfg['max_cost']) ? intval($cfg['max_cost']) : null;
    return addFromWaitingRoomFiltered(
        $p,
        $cfg['group'] ?? '',
        $cfg['filter'] ?? 'member',
        $count,
        $maxCost,
        wrPickExtraFiltersFromCfg($cfg)
    );
}

function startPickWrToHandPrompt(
    array &$state,
    string $pid,
    array &$member,
    string $slot,
    int $abilityIdx,
    array $ab,
    array $cfg,
    bool $leaveStage = false,
    int $count = 1
): void {
    $p = &$state['players'][$pid];
    $candidates = wrCandidatesMatching($p, $cfg);
    if ($count > 0 && empty($candidates)) {
        throw new Exception('No matching card in Waiting Room');
    }
    $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
    $filter = $cfg['filter'] ?? 'member';
    if (!empty($ab['once_per_turn'])) {
        markAbilityUsed($member, $abilityIdx);
    }
    $p['stage'][$slot] = $member;
    $promptType = $leaveStage ? 'pick_wr_leave_stage_add' : 'pick_wr_to_hand';
    $state['pending_prompt'] = [
        'type'          => $promptType,
        'owner'         => $pid,
        'responder'     => $pid,
        'source_id'     => $member['instance_id'] ?? '',
        'source_slot'   => $slot,
        'source_name'   => $mName,
        'ability_index' => $abilityIdx,
        'prompt'        => 'Choose ' . max(0, $count) . ' ' . wrPickFilterLabel($filter) .
            ' card from your Waiting Room to add to your hand.',
        'candidates'    => array_map('cardPromptSummary', $candidates),
        'ability'       => $ab,
        'wr_pick_cfg'   => $cfg,
        'pick_count'    => $count,
    ];
}

function startEffectDiscardHandPrompt(
    array $state,
    string $pid,
    string $name,
    int $count,
    string $msg = '',
    array $extra = []
): array {
    if ($count < 1) {
        return $state;
    }
    $prompt = array_merge([
        'type'        => 'effect_discard_hand',
        'owner'       => $pid,
        'responder'   => $pid,
        'source_name' => $name,
        'count'       => $count,
        'prompt'      => $msg !== '' ? $msg : (
            $count === 1
                ? 'Choose a card to send to the Waiting Room.'
                : "Choose $count cards to send to the Waiting Room."
        ),
        'pick_mode'   => 'hand_discard',
    ], $extra);
    if (!empty($prompt['ability'])) {
        $prompt = enrichAbilityContextPrompt($state, $prompt);
    }
    $state['pending_prompt'] = $prompt;
    $state = logEffectStep($state, $pid, $name,
        'choose ' . $count . ' card(s) to put into the Waiting Room.');
    $state['seq']++;
    return $state;
}

function finishAfterBranchChoicePrompt(array $state, array $prompt): array {
    if (($state['phase'] ?? '') === 'live_start_effects' || !empty($prompt['live_start'])) {
        return resumeLiveStartEffectPhase($state);
    }
    return finishPromptEffects($state);
}

function surveilArrangePromptText(int $count): string {
    $n = max(1, $count);
    if ($n === 1) {
        return 'Look at the top card of your deck. You may put it on top of your deck or put it into the Waiting Room.';
    }
    return "Look at the top {$n} cards of your deck. You may put any number of them on top of your deck in any order and put the rest into the Waiting Room.";
}

function startSurveilArrangePrompt(array $state, string $pid, string $name, array $looked, ?array $chain = null, ?string $sourceId = null): array {
    $state['surveil_stash'] = $looked;
    if ($chain !== null) {
        $state['_surveil_chain'] = $chain;
    }
    $state['pending_prompt'] = [
        'type'          => 'surveil_arrange',
        'owner'         => $pid,
        'responder'     => $pid,
        'source_id'     => $sourceId ?? ($chain['source_id'] ?? ''),
        'source_name'   => $name,
        'prompt'        => surveilArrangePromptText(count($looked)),
        'looked_cards'  => array_map('cardPromptSummary', $looked),
    ];
    return $state;
}
