<?php
/**
 * Prompt lifecycle helpers extracted from effects.php.
 */

/** Whether the phase timer should run while this prompt is pending. */
function promptUsesPhaseTimer(array $prompt): bool {
    return in_array($prompt['responder'] ?? '', ['p1', 'p2'], true);
}

/** Coerce client discard_ids payloads to a string[] (PHP 8.2+ count() fatals on strings). */
function normalizeDiscardIds(mixed $raw): array {
    if ($raw === null || $raw === '') {
        return [];
    }
    if (is_string($raw)) {
        return [$raw];
    }
    if (!is_array($raw)) {
        return [];
    }
    return array_values(array_filter(
        $raw,
        static fn($id) => is_string($id) && $id !== ''
    ));
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

/**
 * True when pending_prompt is still the place-prompt that was just answered
 * (or its pick_slot continuation), not a new chained On Enter prompt.
 */
function promptIsCompletedPlaceParent(array $pending, array $answered): bool {
    if (($pending['type'] ?? '') !== ($answered['type'] ?? '')) {
        return false;
    }
    $pSrc = (string)($pending['source_id'] ?? $pending['source_instance_id'] ?? '');
    $aSrc = (string)($answered['source_id'] ?? $answered['source_instance_id'] ?? '');
    if ($pSrc !== '' && $aSrc !== '' && $pSrc !== $aSrc) {
        return false;
    }
    if (isset($answered['ability_index']) || isset($pending['ability_index'])) {
        if (intval($pending['ability_index'] ?? -999) !== intval($answered['ability_index'] ?? -999)) {
            return false;
        }
    }
    return true;
}

/**
 * After placing a Member from a prompt: keep chained On Enter prompts.
 * Pass $answeredPrompt so a leftover parent (same type/source) is cleared instead of
 * reopening the same option UI (issue #48 double/triple prompting).
 */
function returnAfterPlacedMemberEnter(
    array $state,
    bool $finishLiveStart = false,
    ?array $answeredPrompt = null
): array {
    $pending = $state['pending_prompt'] ?? null;
    if (!empty($pending)) {
        $clearParent = false;
        if ($answeredPrompt !== null) {
            $clearParent = promptIsCompletedPlaceParent($pending, $answeredPrompt);
        } elseif (($pending['step'] ?? '') === 'pick_slot') {
            // Legacy safety for callers that omit $answeredPrompt.
            $clearParent = true;
        }
        if ($clearParent) {
            unset($state['pending_prompt']);
            $state['seq']++;
            return $finishLiveStart ? finishLiveStartEffects($state) : finishPromptEffects($state);
        }
        // Different chained prompt (child On Enter, etc.) — keep it.
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
 * Add matching WR card(s) to hand. When count is 1 and any cards qualify, opens pick_wr_to_hand
 * so the player always chooses among eligible Waiting Room cards (never auto-first-match).
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
    // Always prompt for a single WR→hand add when anything qualifies — including one option.
    if ($count === 1) {
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

function surveilArrangePromptText(int $count, bool $returnAll = false): string {
    $n = max(1, $count);
    if ($returnAll) {
        if ($n === 1) {
            return 'Look at the top card of your deck and put it back on top.';
        }
        return "Look at the top {$n} cards of your deck and put them back on top in any order.";
    }
    if ($n === 1) {
        return 'Look at the top card of your deck. You may put it on top of your deck or put it into the Waiting Room.';
    }
    return "Look at the top {$n} cards of your deck. You may put any number of them on top of your deck in any order and put the rest into the Waiting Room.";
}

function startSurveilArrangePrompt(
    array $state,
    string $pid,
    string $name,
    array $looked,
    ?array $chain = null,
    ?string $sourceId = null,
    bool $returnAll = false
): array {
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
        'prompt'        => surveilArrangePromptText(count($looked), $returnAll),
        'looked_cards'  => array_map('cardPromptSummary', $looked),
        'return_all'    => $returnAll,
    ];
    return $state;
}
