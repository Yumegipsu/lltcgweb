<?php
/**
 * Debug replay: export action sequences from live rooms and step through them in
 * replay_view mode. Client must send debug_mode=true (?debug URL flag).
 */

const REPLAY_SCHEMA_VERSION = 1;

function assertReplayDebugAllowed(array $body): void {
    if (empty($body['debug_mode'])) {
        throw new Exception('Replay requires debug_mode');
    }
}

function assertReplayExportAllowed(array $body, array $state): void {
    if (($state['status'] ?? '') === 'finished') {
        return;
    }
    assertReplayDebugAllowed($body);
}

function replayShouldRecordActions(array $state): bool {
    $mode = $state['mode'] ?? '';
    if ($mode === 'replay_view') {
        return false;
    }
    if (!empty($state['replay_handoff'])) {
        return false;
    }
    return true;
}

function cloneStateForReplayBaseline(array $state): array {
    $copy = json_decode(json_encode($state), true);
    unset(
        $copy['action_log'],
        $copy['replay_baseline'],
        $copy['replay'],
        $copy['replay_handoff']
    );
    return $copy;
}

function captureReplayBaselineIfNeeded(array $state): array {
    if (!replayShouldRecordActions($state)) {
        return $state;
    }
    if (!empty($state['action_log']) || !empty($state['replay_baseline'])) {
        return $state;
    }
    $state['replay_baseline'] = cloneStateForReplayBaseline($state);
    return $state;
}

function appendReplayAction(array $state, string $playerId, string $type, array $data): array {
    if (!replayShouldRecordActions($state)) {
        return $state;
    }
    if ($type === 'mulligan') {
        $deck = $state['players'][$playerId]['main_deck'] ?? [];
        $order = array_column($deck, 'instance_id');
        if ($order !== []) {
            $data['main_deck_order'] = $order;
        }
    }
    if (!isset($state['action_log']) || !is_array($state['action_log'])) {
        $state['action_log'] = [];
    }
    $state['action_log'][] = [
        'index'    => count($state['action_log']) + 1,
        'player'   => $playerId,
        'type'     => $type,
        'data'     => $data,
        'ts'       => time(),
        'game_seq' => intval($state['seq'] ?? 0),
    ];
    return $state;
}

function validateReplayFile(array $replay): void {
    if (intval($replay['schema_version'] ?? 0) !== REPLAY_SCHEMA_VERSION) {
        throw new Exception('Unsupported replay schema version');
    }
    if (empty($replay['baseline']) || !is_array($replay['baseline'])) {
        throw new Exception('Replay missing baseline state');
    }
    if (!isset($replay['actions']) || !is_array($replay['actions'])) {
        throw new Exception('Replay missing actions array');
    }
    $saver = $replay['meta']['saver_player_id'] ?? '';
    if ($saver !== 'p1' && $saver !== 'p2') {
        throw new Exception('Replay missing saver_player_id');
    }
}

function buildReplayExportPayload(array $state, string $saverPid): array {
    $baseline = $state['replay_baseline'] ?? null;
    $actions = $state['action_log'] ?? [];
    if (!$baseline) {
        $baseline = cloneStateForReplayBaseline($state);
    }
    if ($saverPid !== 'p1' && $saverPid !== 'p2') {
        throw new Exception('Invalid saver player');
    }
    $saverName = $state['players'][$saverPid]['name'] ?? $saverPid;
    return [
        'schema_version' => REPLAY_SCHEMA_VERSION,
        'meta' => [
            'saved_at'         => gmdate('c'),
            'saver_player_id'  => $saverPid,
            'saver_name'       => $saverName,
            'room_id'          => $state['room_id'] ?? '',
            'turn'             => intval($state['turn'] ?? 0),
            'phase'            => (string)($state['phase'] ?? ''),
            'game_seq'         => intval($state['seq'] ?? 0),
            'client_version'   => '0.1.1',
            'mode'             => $state['mode'] ?? null,
            'cpu_difficulty'   => $state['cpu_difficulty'] ?? null,
            'timing_source'    => !empty($state['phase_timer']) ? 'phase_timer' : 'action_timestamps',
            'duration_seconds' => replayDurationSeconds($actions),
        ],
        'baseline' => $baseline,
        'actions'  => $actions,
    ];
}

function replayDurationSeconds(array $actions): int {
    $first = null;
    $last = null;
    foreach ($actions as $a) {
        $ts = intval($a['ts'] ?? 0);
        if ($ts <= 0) {
            continue;
        }
        if ($first === null) {
            $first = $ts;
        }
        $last = $ts;
    }
    if ($first === null || $last === null) {
        return 0;
    }
    return max(0, $last - $first);
}

function replayActionBlockedByPendingPrompt(Throwable $e): bool {
    return str_contains($e->getMessage(), 'pending skill prompt');
}

function replayTransientPromptStateKeys(): array {
    return [
        'pending_prompt',
        'surveil_stash',
        '_surveil_chain',
        'look_stash',
        '_look_chain',
    ];
}

/** Strip unresolved prompt UI state — replay viewing is passive playback only. */
function replaySanitizeViewingState(array $state): array {
    foreach (replayTransientPromptStateKeys() as $key) {
        unset($state[$key]);
    }
    return $state;
}

function replayRestoreMainDeckOrder(array $state, string $pid, array $order): array {
    if ($pid !== 'p1' && $pid !== 'p2') {
        return $state;
    }
    $p = &$state['players'][$pid];
    $byId = [];
    foreach ($p['main_deck'] ?? [] as $c) {
        $id = $c['instance_id'] ?? '';
        if ($id !== '') {
            $byId[$id] = $c;
        }
    }
    $ordered = [];
    foreach ($order as $id) {
        if (isset($byId[$id])) {
            $ordered[] = $byId[$id];
            unset($byId[$id]);
        }
    }
    foreach ($byId as $c) {
        $ordered[] = $c;
    }
    $p['main_deck'] = $ordered;
    return $state;
}

function replayExtractCardFromPlayer(array &$player, string $instanceId): ?array {
    foreach (['main_deck', 'hand', 'waiting_room'] as $zone) {
        foreach ($player[$zone] ?? [] as $i => $c) {
            if (($c['instance_id'] ?? '') === $instanceId) {
                array_splice($player[$zone], $i, 1);
                return $c;
            }
        }
    }
    return null;
}

/** Best-effort: put a recorded card back in hand when replay state drifted (legacy exports). */
function replayEnsureCardInHand(array &$state, string $pid, string $cardId): void {
    if ($cardId === '' || ($pid !== 'p1' && $pid !== 'p2')) {
        return;
    }
    $p = &$state['players'][$pid];
    if (findInHand($p['hand'], $cardId) !== false) {
        return;
    }
    foreach (['main_deck', 'waiting_room'] as $zone) {
        foreach ($p[$zone] ?? [] as $i => $c) {
            if (($c['instance_id'] ?? '') === $cardId) {
                $p['hand'][] = $c;
                array_splice($p[$zone], $i, 1);
                return;
            }
        }
    }
}

function replayPrepareRecordedPlayAction(array $state, string $pid, string $type, array $data): array {
    if ($type === 'set_live_cards') {
        foreach ($data['card_ids'] ?? [] as $cid) {
            replayEnsureCardInHand($state, $pid, (string)$cid);
        }
    } elseif ($type === 'play_member' && !empty($data['card_id'])) {
        replayEnsureCardInHand($state, $pid, (string)$data['card_id']);
    }
    return $state;
}

/** Apply surveil_arrange from recorded top/wr ids when stash cards diverged (e.g. mulligan shuffle). */
function replayApplySurveilArrangeFromRecorded(array $state, string $owner, array $data): array {
    $prompt = $state['pending_prompt'] ?? null;
    if (!is_array($prompt) || ($prompt['type'] ?? '') !== 'surveil_arrange') {
        throw new Exception('Replay surveil fallback: no surveil_arrange prompt');
    }
    $topIds = $data['top_ids'] ?? [];
    $wrIds = $data['wr_ids'] ?? [];
    $allIds = array_merge($topIds, $wrIds);
    if ($allIds === []) {
        throw new Exception('Replay surveil fallback: missing card ids');
    }
    $chain = $state['_surveil_chain'] ?? null;
    $arrangeTarget = $chain['target'] ?? $owner;
    $p = &$state['players'][$arrangeTarget];
    $looked = [];
    foreach ($allIds as $id) {
        $card = replayExtractCardFromPlayer($p, $id);
        if ($card) {
            $looked[] = $card;
        }
    }
    if (count($looked) < count($allIds)) {
        foreach ($state['surveil_stash'] ?? [] as $c) {
            $id = $c['instance_id'] ?? '';
            if ($id !== '' && in_array($id, $allIds, true)) {
                $have = array_column($looked, 'instance_id');
                if (!in_array($id, $have, true)) {
                    $looked[] = $c;
                }
            }
        }
    }
    applySurveilArrangement($p, $looked, $topIds, $wrIds);
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

/** Apply surveil_pick_one_* from recorded card_id when look_cards diverged. */
function replayApplySurveilPickOneFromRecorded(array $state, string $owner, array $data): array {
    $prompt = $state['pending_prompt'] ?? null;
    if (!is_array($prompt)) {
        throw new Exception('Replay surveil pick fallback: no pending prompt');
    }
    $pickId = (string)($data['card_id'] ?? $data['choice'] ?? '');
    if ($pickId === '' || $pickId === 'pick') {
        throw new Exception('Choose 1 looked card');
    }
    $ownerP = &$state['players'][$owner];
    $looked = $prompt['look_cards'] ?? $state['surveil_stash'] ?? [];
    $picked = null;
    $rest = [];
    foreach ($looked as $c) {
        if (($c['instance_id'] ?? '') === $pickId) {
            $picked = $c;
        } else {
            $rest[] = $c;
        }
    }
    if (!$picked) {
        $picked = replayExtractCardFromPlayer($ownerP, $pickId);
        if (!$picked) {
            throw new Exception('Choose 1 looked card');
        }
        $rest = array_values(array_filter(
            $looked,
            static fn(array $c): bool => ($c['instance_id'] ?? '') !== $pickId
        ));
    }
    array_unshift($ownerP['main_deck'], $picked);
    if ($rest !== []) {
        $ownerP['waiting_room'] = array_merge($ownerP['waiting_room'] ?? [], $rest);
    }
    unset($state['pending_prompt'], $state['surveil_stash']);
    $state = addLog($state, $state['players'][$owner]['name'] .
        ' — [' . ($prompt['source_name'] ?? 'Member') . '] arranged deck top.');
    $state['seq']++;
    return $state;
}

function replayPrepareStateForRecordedAction(array $state, string $pid, string $type): array {
    if (in_array($type, ['resolve_prompt', 'anti_softlock_skip'], true)) {
        return $state;
    }
    $pr = $state['pending_prompt'] ?? null;
    if (!is_array($pr)) {
        return $state;
    }
    if (($pr['responder'] ?? '') !== $pid) {
        unset($state['pending_prompt']);
    }
    return $state;
}

function replayApplyRecordedAction(array $state, string $pid, string $type, array $data, int $index): array {
    $state = replayPrepareStateForRecordedAction($state, $pid, $type);
    $state = replayPrepareRecordedPlayAction($state, $pid, $type, $data);
    try {
        $state = applyAction($state, $pid, $type, $data);
        if ($type === 'mulligan' && !empty($data['main_deck_order']) && is_array($data['main_deck_order'])) {
            $state = replayRestoreMainDeckOrder($state, $pid, $data['main_deck_order']);
        }
        return $state;
    } catch (Throwable $e) {
        if ($type !== 'resolve_prompt'
            && !empty($state['pending_prompt'])
            && replayActionBlockedByPendingPrompt($e)) {
            // Older saved replays may be missing the prompt-resolution action that happened
            // before the next recorded action. Drop only that replay-local stale prompt.
            unset($state['pending_prompt']);
            $state = applyAction($state, $pid, $type, $data);
            if ($type === 'mulligan' && !empty($data['main_deck_order']) && is_array($data['main_deck_order'])) {
                $state = replayRestoreMainDeckOrder($state, $pid, $data['main_deck_order']);
            }
            return $state;
        }
        if ($type === 'resolve_prompt') {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Must assign every looked card')) {
                return replayApplySurveilArrangeFromRecorded($state, $pid, $data);
            }
            if (str_contains($msg, 'Choose 1 looked card')) {
                return replayApplySurveilPickOneFromRecorded($state, $pid, $data);
            }
            if (str_contains($msg, 'No pending prompt')) {
                return $state;
            }
        }
        throw $e;
    }
}

function replayRestoreFromBaseline(
    array $baseline,
    string $roomId,
    string $p1Token,
    string $p2Token
): array {
    $state = json_decode(json_encode($baseline), true);
    $state['room_id'] = $roomId;
    if (!isset($state['players']['p1']) || !isset($state['players']['p2'])) {
        throw new Exception('Replay baseline missing both players');
    }
    $state['players']['p1']['token'] = $p1Token;
    $state['players']['p2']['token'] = $p2Token;
    unset(
        $state['action_log'],
        $state['replay_baseline'],
        $state['replay'],
        $state['replay_handoff']
    );
    if (($state['status'] ?? '') === 'finished') {
        $state['status'] = 'playing';
        unset($state['winner'], $state['end_reason'], $state['resigned_by']);
    }
    return $state;
}

function replayApplyActionsThrough(array $state, array $actions, int $step): array {
    $step = max(0, min($step, count($actions)));
    for ($i = 0; $i < $step; $i++) {
        $a = $actions[$i];
        $pid = $a['player'] ?? '';
        $type = $a['type'] ?? '';
        if ($pid !== 'p1' && $pid !== 'p2') {
            throw new Exception('Replay action #' . ($i + 1) . ' has invalid player');
        }
        if ($type === '') {
            throw new Exception('Replay action #' . ($i + 1) . ' missing type');
        }
        $state = replayApplyRecordedAction($state, $pid, $type, is_array($a['data'] ?? null) ? $a['data'] : [], $i + 1);
        if (empty($state['pending_prompt'])) {
            $state = flushAutoOnWaitAbilities($state);
        }
    }
    return $state;
}

function apiReplayExport(array $body): array {
    $roomId = strtoupper(trim((string)($body['room_id'] ?? '')));
    $token = (string)($body['token'] ?? '');
    if ($roomId === '' || $token === '') {
        throw new Exception('room_id and token required');
    }
    $state = loadGame($roomId);
    if (!$state) {
        throw new Exception('Room not found');
    }
    $playerId = getPlayerIdByToken($state, $token);
    if (!$playerId) {
        throw new Exception('Invalid player token');
    }
    assertReplayExportAllowed($body, $state);
    if (($state['mode'] ?? '') === 'replay_view') {
        throw new Exception('Cannot export from a replay viewer room');
    }
    $actions = $state['action_log'] ?? [];
    if (count($actions) === 0) {
        throw new Exception('No recorded actions yet — play at least one move after this update');
    }
    return [
        'ok'     => true,
        'replay' => buildReplayExportPayload($state, $playerId),
    ];
}

function apiReplayStart(array $body): array {
    $replay = $body['replay'] ?? null;
    if (!is_array($replay)) {
        throw new Exception('replay object required');
    }
    validateReplayFile($replay);

    $baseline = $replay['baseline'];
    $actions = $replay['actions'] ?? [];
    $saverPid = $replay['meta']['saver_player_id'] ?? 'p1';
    $cpuDiff = in_array($replay['meta']['cpu_difficulty'] ?? '', ['easy', 'normal', 'hard'], true)
        ? $replay['meta']['cpu_difficulty'] : 'normal';

    $roomId = strtoupper(substr(md5(uniqid('rpl', true)), 0, 6));
    $p1Token = generateToken();
    $p2Token = generateToken();

    $state = replayRestoreFromBaseline($baseline, $roomId, $p1Token, $p2Token);
    $state['cpu_difficulty'] = $cpuDiff;
    $state['mode'] = 'replay_view';
    $state['cpu_solo'] = true;
    $state['replay'] = [
        'saver_pid' => $saverPid,
        'actions'   => $actions,
        'baseline'  => $baseline,
        'step'      => 0,
        'handoff'   => false,
    ];
    $state = addLog($state, 'Replay loaded — ' . count($actions) . ' action(s). Use replay controls to play or seek.');
    $state['seq']++;
    $state = replaySanitizeViewingState($state);

    saveGame($roomId, $state);

    $humanToken = ($saverPid === 'p1') ? $p1Token : $p2Token;
    $cpuToken = ($saverPid === 'p1') ? $p2Token : $p1Token;

    return [
        'ok'           => true,
        'room_id'      => $roomId,
        'player_token' => $humanToken,
        'cpu_token'    => $cpuToken,
        'player_id'    => $saverPid,
        'total_steps'  => count($actions),
        'saver_name'   => $replay['meta']['saver_name'] ?? $saverPid,
    ];
}

function apiReplayGoto(array $body): array {
    $roomId = strtoupper(trim((string)($body['room_id'] ?? '')));
    $token = (string)($body['token'] ?? '');
    $step = intval($body['step'] ?? -1);
    $wantsHandoff = !empty($body['handoff']);
    if ($roomId === '' || $token === '') {
        throw new Exception('room_id and token required');
    }
    if ($step < 0) {
        throw new Exception('step required');
    }

    return withLock($roomId, function () use ($roomId, $token, $step, $wantsHandoff) {
        $state = loadGame($roomId);
        if (!$state) {
            throw new Exception('Room not found');
        }
        $playerId = getPlayerIdByToken($state, $token);
        if (!$playerId) {
            throw new Exception('Invalid player token');
        }
        $replay = $state['replay'] ?? null;
        if (!$replay || ($state['mode'] ?? '') !== 'replay_view') {
            throw new Exception('Not in replay mode');
        }
        if (($replay['saver_pid'] ?? '') !== $playerId) {
            throw new Exception('Only the saver perspective can control replay');
        }

        $actions = is_array($replay['actions'] ?? null) ? $replay['actions'] : [];
        $maxStep = count($actions);
        $step = max(0, min($step, $maxStep));
        $baseline = $replay['baseline'] ?? null;
        if (!$baseline) {
            throw new Exception('Replay baseline missing from room');
        }

        $p1Token = $state['players']['p1']['token'] ?? generateToken();
        $p2Token = $state['players']['p2']['token'] ?? generateToken();
        $cpuDiff = $state['cpu_difficulty'] ?? 'normal';

        $newState = replayRestoreFromBaseline($baseline, $roomId, $p1Token, $p2Token);
        $newState['cpu_difficulty'] = $cpuDiff;
        $newState = replayApplyActionsThrough($newState, $actions, $step);
        $newState = replaySanitizeViewingState($newState);

        $handoff = $wantsHandoff && $step >= $maxStep;
        if ($handoff) {
            unset($newState['replay']);
            $newState['mode'] = null;
            $newState['replay_handoff'] = true;
            $newState['cpu_solo'] = true;
            $newState = addLog(
                $newState,
                'Replay complete — you control ' . ($newState['players'][$playerId]['name'] ?? $playerId)
                . '. CPU plays the opponent.'
            );
            $newState['seq']++;
        } else {
            $newState['mode'] = 'replay_view';
            $newState['cpu_solo'] = true;
            $newState['replay'] = [
                'saver_pid' => $replay['saver_pid'],
                'actions'   => $actions,
                'baseline'  => $baseline,
                'step'      => $step,
                'handoff'   => false,
            ];
        }

        saveGame($roomId, $newState);

        return [
            'ok'      => true,
            'step'    => $step,
            'total'   => $maxStep,
            'handoff' => $handoff,
            'seq'     => $newState['seq'],
        ];
    });
}

function enrichReplayFieldsForClient(array $filtered, array $state): array {
    if (!empty($state['replay']) && is_array($state['replay'])) {
        $filtered['replay'] = [
            'step'    => intval($state['replay']['step'] ?? 0),
            'total'   => count($state['replay']['actions'] ?? []),
            'handoff' => !empty($state['replay']['handoff']),
            'saver_pid' => $state['replay']['saver_pid'] ?? null,
        ];
    }
    if (!empty($state['replay_handoff'])) {
        $filtered['replay_handoff'] = true;
    }
    return $filtered;
}
