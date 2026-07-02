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
function startPickWrToHandPrompt(
    array &$state,
    string $pid,
    array &$member,
    string $slot,
    int $abilityIdx,
    array $ab,
    array $cfg,
    bool $leaveStage = false
): void {
    $p = &$state['players'][$pid];
    $candidates = wrCandidatesMatching($p, $cfg);
    if (empty($candidates)) {
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
        'prompt'        => 'Choose 1 ' . wrPickFilterLabel($filter) .
            ' card from your Waiting Room to add to your hand.',
        'candidates'    => array_map('cardPromptSummary', $candidates),
        'ability'       => $ab,
        'wr_pick_cfg'   => $cfg,
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
