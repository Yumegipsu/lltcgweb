<?php
/**
 * Match replay: live rooms record baseline + action_log (schema v1 source).
 * Exports / library / replay_view use schema v2 board frames for seek.
 * Opening a v1 file converts once (re-sim) into frames; seek then loads frames only.
 */

const REPLAY_SCHEMA_VERSION = 2;
const REPLAY_SCHEMA_VERSION_MIN = 1;
const REPLAY_LOCK_TIMEOUT = 120.0;
/** Gzip whole payload in SQLite when uncompressed JSON exceeds this (~512 KB). */
const REPLAY_STORAGE_GZIP_BYTES = 524288;

function assertReplayDebugAllowed(array $body): void {
    if (empty($body['debug_mode'])) {
        throw new Exception('Replay requires debug_mode');
    }
}

function assertReplayExportAllowed(array $body, array $state): void {
    if (($state['status'] ?? '') === 'finished') {
        return;
    }
    // Win overlay can appear before status flips to finished on the match host.
    if (!empty($state['winner']) && in_array($state['winner'], ['p1', 'p2'], true)) {
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
    $entry = [
        'index'    => count($state['action_log']) + 1,
        'player'   => $playerId,
        'type'     => $type,
        'data'     => $data,
        'ts'       => time(),
        'game_seq' => intval($state['seq'] ?? 0),
        'turn'     => intval($state['turn'] ?? 0),
    ];
    replayStampActionIdentity($entry, $state);
    $state['action_log'][] = $entry;
    return $state;
}

/** Display name for a recorded card id, searching the player's zones. */
function replayLookupCardName(array $state, string $pid, string $cardId): string {
    if ($cardId === '' || ($pid !== 'p1' && $pid !== 'p2')) {
        return '';
    }
    $p = $state['players'][$pid] ?? null;
    if (!is_array($p)) {
        return '';
    }
    $named = static function ($card): string {
        if (!is_array($card)) {
            return '';
        }
        $name = trim((string)($card['name_en'] ?? $card['name'] ?? ''));
        return $name;
    };
    foreach ($p['stage'] ?? [] as $mbr) {
        if (is_array($mbr) && ($mbr['instance_id'] ?? '') === $cardId) {
            $name = $named($mbr);
            if ($name !== '') {
                return $name;
            }
        }
    }
    foreach (['hand', 'waiting_room', 'main_deck', 'live_zone', 'live_storage', 'energy_zone', 'discard'] as $zone) {
        foreach ($p[$zone] ?? [] as $card) {
            if (is_array($card) && ($card['instance_id'] ?? '') === $cardId) {
                $name = $named($card);
                if ($name !== '') {
                    return $name;
                }
            }
        }
    }
    return '';
}

/**
 * Stamp turn + card name onto a recorded action so the viewer can name
 * play/activate even when re-sim drops the matching log line.
 */
function replayStampActionIdentity(array &$action, array $state): void {
    $pid = (string)($action['player'] ?? '');
    if (!isset($action['turn'])) {
        $action['turn'] = intval($state['turn'] ?? 0);
    }
    $type = (string)($action['type'] ?? '');
    if ($type !== 'play_member' && $type !== 'activate_ability') {
        return;
    }
    $data = is_array($action['data'] ?? null) ? $action['data'] : [];
    if (empty($action['slot']) && !empty($data['slot'])) {
        $action['slot'] = (string)$data['slot'];
    }
    if (!empty($action['card_name'])) {
        return;
    }
    $name = replayLookupCardName($state, $pid, (string)($data['card_id'] ?? ''));
    if ($name !== '') {
        $action['card_name'] = $name;
    }
}

function replayLogMentions(array $state, string $needle): bool {
    if ($needle === '') {
        return false;
    }
    $log = $state['log'] ?? [];
    if (!is_array($log) || $log === []) {
        return false;
    }
    $from = max(0, count($log) - 8);
    for ($i = count($log) - 1; $i >= $from; $i--) {
        $msg = (string)($log[$i]['msg'] ?? '');
        if ($msg !== '' && str_contains($msg, $needle)) {
            return true;
        }
    }
    return false;
}

/**
 * If re-sim cannot apply a recorded play or activate, still show the card
 * and a log line so the viewer does not skip that moment or the turn it belongs to.
 */
function replaySurfaceSkippedPlayOrActivate(array $state, string $pid, string $type, array $data): array {
    if (($pid !== 'p1' && $pid !== 'p2') || ($type !== 'play_member' && $type !== 'activate_ability')) {
        return $state;
    }
    $cardId = (string)($data['card_id'] ?? '');
    if ($cardId === '') {
        return $state;
    }
    $slot = (string)($data['slot'] ?? $data['source_slot'] ?? 'center');
    if ($type === 'play_member') {
        replayEnsureCardInHand($state, $pid, $cardId);
        replayEnsureMemberOnStage($state, $pid, $cardId, $slot);
    } else {
        $onStage = false;
        foreach ($state['players'][$pid]['stage'] ?? [] as $mbr) {
            if (is_array($mbr) && ($mbr['instance_id'] ?? '') === $cardId) {
                $onStage = true;
                break;
            }
        }
        if (!$onStage) {
            replayEnsureMemberOnStage($state, $pid, $cardId, $slot);
        }
    }
    $name = replayLookupCardName($state, $pid, $cardId);
    if ($name === '') {
        $name = trim((string)($data['card_name'] ?? ''));
    }
    if ($name === '') {
        $name = 'Member';
    }
    $player = (string)($state['players'][$pid]['name'] ?? $pid);
    if ($type === 'play_member') {
        $line = $player . ' played ' . $name . ' to ' . $slot . ' area.';
        if (!replayLogMentions($state, 'played ' . $name)) {
            $state = addLog($state, $line, 'action');
        }
    } elseif (!replayLogMentions($state, '[' . $name . ']')) {
        $state = addLog($state, $player . ' — [' . $name . '] activated.', 'action');
    }
    return $state;
}

/** Fields kept on face-up zone cards inside a frame (UI + activate). */
const REPLAY_FRAME_CARD_KEEP = [
    'instance_id', 'card_no', 'card_type', 'card_type_en',
    'name', 'name_en', 'cost', 'hearts', 'blade', 'blade_hearts',
    'abilities', 'score', 'required_hearts', 'active', 'waiting',
    'subunit', 'group', 'special_heart', 'yell_draw_icon',
    'stacked_members', 'stacked_energy', 'rested', 'text',
    'entered_turn', 'abilities_used', 'markers', 'display_order', 'revealed',
];

/** Top-level keys dropped from every board frame (not needed for seek UI). */
const REPLAY_FRAME_DROP_TOP = [
    'action_log', 'replay_baseline', 'replay', 'replay_handoff', 'mode',
    'log', 'ranked', 'phase_timer', 'phase_timer_cfg', 'mulligan_declare',
    '_play_stat_deltas', 'stamp_pop', 'stamp_last_at', 'live_modifiers',
    'presence', 'rate_limit', 'chat',
];

/** Slim a single card blob for frame storage. Face-down decks keep id+no only. */
function slimReplayFrameCard($card, bool $deckStub = false) {
    if (!is_array($card)) {
        return $card;
    }
    if ($deckStub) {
        $out = [];
        if (isset($card['instance_id'])) {
            $out['instance_id'] = $card['instance_id'];
        }
        if (isset($card['card_no'])) {
            $out['card_no'] = $card['card_no'];
        }
        return $out;
    }
    $out = [];
    foreach (REPLAY_FRAME_CARD_KEEP as $k) {
        if (array_key_exists($k, $card)) {
            $out[$k] = $card[$k];
        }
    }
    // Nested stacks under members: recurse with the same keep list.
    if (!empty($out['stacked_members']) && is_array($out['stacked_members'])) {
        $out['stacked_members'] = array_map(
            static fn($c) => slimReplayFrameCard($c, false),
            $out['stacked_members']
        );
    }
    if (!empty($out['stacked_energy']) && is_array($out['stacked_energy'])) {
        $out['stacked_energy'] = array_map(
            static fn($c) => slimReplayFrameCard($c, false),
            $out['stacked_energy']
        );
    }
    return $out;
}

/** Strip secrets / nested replay blobs / bulky catalog fields from a board snapshot. */
function sanitizeReplayFrame(array $state): array {
    $frame = json_decode(json_encode($state), true);
    if (!is_array($frame)) {
        return [];
    }
    foreach (REPLAY_FRAME_DROP_TOP as $k) {
        unset($frame[$k]);
    }
    $deckZones = ['main_deck' => true, 'energy_deck' => true];
    $listZones = [
        'hand' => true, 'waiting_room' => true, 'discard' => true,
        'live_zone' => true, 'live_storage' => true, 'energy_zone' => true,
        'live_success' => true, 'success_lives' => true,
    ];
    foreach (['p1', 'p2'] as $pid) {
        if (!isset($frame['players'][$pid]) || !is_array($frame['players'][$pid])) {
            continue;
        }
        unset($frame['players'][$pid]['token']);
        $p =& $frame['players'][$pid];
        foreach ($p as $zone => &$val) {
            if (!is_array($val)) {
                continue;
            }
            if (isset($deckZones[$zone])) {
                $val = array_map(static fn($c) => slimReplayFrameCard($c, true), $val);
            } elseif (isset($listZones[$zone])) {
                $val = array_map(static fn($c) => slimReplayFrameCard($c, false), $val);
            } elseif ($zone === 'stage') {
                foreach ($val as $slot => $mbr) {
                    if (is_array($mbr)) {
                        $val[$slot] = slimReplayFrameCard($mbr, false);
                    }
                }
            }
        }
        unset($val);
    }
    return $frame;
}

function validateReplayFile(array $replay): void {
    $ver = intval($replay['schema_version'] ?? 0);
    if ($ver < REPLAY_SCHEMA_VERSION_MIN || $ver > REPLAY_SCHEMA_VERSION) {
        throw new Exception('Unsupported replay schema version');
    }
    $actions = $replay['actions'] ?? null;
    if (!is_array($actions)) {
        throw new Exception('Replay missing actions array');
    }
    $frames = $replay['frames'] ?? null;
    if (is_array($frames) && $frames !== []) {
        if (count($frames) !== count($actions) + 1) {
            throw new Exception('Replay frames length must be actions+1');
        }
    } else {
        if (empty($replay['baseline']) || !is_array($replay['baseline'])) {
            throw new Exception('Replay missing baseline state');
        }
    }
    $saver = $replay['meta']['saver_player_id'] ?? '';
    if ($saver !== 'p1' && $saver !== 'p2') {
        throw new Exception('Replay missing saver_player_id');
    }
}

/** Client→Hostinger upload: baseline + actions only (no multi-MB frames). */
function isReplayTransferSlim(array $replay): bool {
    $frames = $replay['frames'] ?? null;
    return !is_array($frames) || $frames === [];
}

/** Strip v2 frames/log for small POST fallback; Hostinger builds v2 once. */
function stripReplayForTransfer(array $replay): array {
    return [
        'schema_version' => 1,
        'meta'           => is_array($replay['meta'] ?? null) ? $replay['meta'] : [],
        'baseline'       => $replay['baseline'] ?? null,
        'actions'        => is_array($replay['actions'] ?? null) ? $replay['actions'] : [],
    ];
}

/**
 * Re-sim baseline+actions once to build cumulative log index for schema-v2 seek.
 * Frames stay slim (no log); each installed step slices full_log[0..log_ends[step]].
 *
 * @return array{full_log: array, log_ends: array<int,int>}
 */
function buildReplayLogIndex(array $baseline, array $actions): array {
    $p1Token = 'replay_log_p1';
    $p2Token = 'replay_log_p2';
    $state = replayRestoreFromBaseline($baseline, 'REPLAYLOG', $p1Token, $p2Token);
    $logEnds = [count($state['log'] ?? [])];
    for ($i = 0; $i < count($actions); $i++) {
        $a = $actions[$i];
        $pid = $a['player'] ?? '';
        $type = $a['type'] ?? '';
        if ($pid !== 'p1' && $pid !== 'p2') {
            throw new Exception('Replay action #' . ($i + 1) . ' has invalid player');
        }
        if ($type === '') {
            throw new Exception('Replay action #' . ($i + 1) . ' missing type');
        }
        replayStampActionIdentity($actions[$i], $state);
        $a = $actions[$i];
        $data = is_array($a['data'] ?? null) ? $a['data'] : [];
        try {
            $state = replayApplyRecordedAction(
                $state,
                $pid,
                $type,
                $data,
                $i + 1
            );
        } catch (Throwable $e) {
            $state = replaySoftSkipPendingPrompt(
                $state,
                'log index #' . ($i + 1) . ' ' . $type . ': ' . $e->getMessage(),
                $type
            );
            $state = replaySurfaceSkippedPlayOrActivate($state, $pid, $type, $data);
        }
        if (empty($state['pending_prompt']) && function_exists('flushAutoOnWaitAbilities')) {
            $state = flushAutoOnWaitAbilities($state);
        }
        $logEnds[] = count($state['log'] ?? []);
    }
    return [
        'full_log' => is_array($state['log'] ?? null) ? $state['log'] : [],
        'log_ends' => $logEnds,
        'actions' => $actions,
    ];
}

/** Ensure v2 payloads carry full_log + log_ends (lazy upgrade for older exports). */
function ensureReplayLogIndex(array $replay): array {
    $actions = is_array($replay['actions'] ?? null) ? $replay['actions'] : [];
    $frames = is_array($replay['frames'] ?? null) ? $replay['frames'] : [];
    $ends = $replay['log_ends'] ?? null;
    $full = $replay['full_log'] ?? null;
    if (is_array($full) && is_array($ends) && count($ends) === count($frames)) {
        return $replay;
    }
    if ($frames === []) {
        return $replay;
    }
    $baseline = $replay['baseline'] ?? $frames[0];
    if (!is_array($baseline)) {
        return $replay;
    }
    $built = buildReplayLogIndex($baseline, $actions);
    $replay['full_log'] = $built['full_log'];
    $replay['log_ends'] = $built['log_ends'];
    if (!empty($built['actions']) && is_array($built['actions'])) {
        $replay['actions'] = $built['actions'];
    }
    return $replay;
}

/** Attach cumulative game log through seek step (frames omit log to save space). */
function replayAttachLogForStep(array $state, array $replayBag, int $step): array {
    $full = $replayBag['full_log'] ?? null;
    $ends = $replayBag['log_ends'] ?? null;
    if (!is_array($full) || !is_array($ends)) {
        return $state;
    }
    $step = max(0, min($step, count($ends) - 1));
    $end = intval($ends[$step] ?? count($full));
    $state['log'] = array_slice($full, 0, max(0, $end));
    return $state;
}

/**
 * One-time re-sim: build board frames[0..N] from baseline + actions.
 * Soft-skips during apply are OK — prefer a seekable frame over aborting.
 */
function convertReplayPayloadToV2(array $replay): array {
    validateReplayFile($replay);
    $actions = is_array($replay['actions'] ?? null) ? $replay['actions'] : [];
    if (intval($replay['schema_version'] ?? 0) >= 2
        && is_array($replay['frames'] ?? null)
        && count($replay['frames']) === count($actions) + 1) {
        $replay['schema_version'] = REPLAY_SCHEMA_VERSION;
        $replay['meta'] = is_array($replay['meta'] ?? null) ? $replay['meta'] : [];
        $replay['meta']['frame_count'] = count($replay['frames']);
        if (empty($replay['baseline']) && !empty($replay['frames'][0])) {
            $replay['baseline'] = $replay['frames'][0];
        }
        return ensureReplayLogIndex($replay);
    }
    $baseline = $replay['baseline'] ?? null;
    if (!is_array($baseline)) {
        throw new Exception('Replay missing baseline state');
    }
    $p1Token = 'replay_cvt_p1';
    $p2Token = 'replay_cvt_p2';
    $state = replayRestoreFromBaseline($baseline, 'REPLAYCV', $p1Token, $p2Token);
    $logEnds = [count($state['log'] ?? [])];
    $frames = [sanitizeReplayFrame($state)];
    for ($i = 0; $i < count($actions); $i++) {
        $a = $actions[$i];
        $pid = $a['player'] ?? '';
        $type = $a['type'] ?? '';
        if ($pid !== 'p1' && $pid !== 'p2') {
            throw new Exception('Replay action #' . ($i + 1) . ' has invalid player');
        }
        if ($type === '') {
            throw new Exception('Replay action #' . ($i + 1) . ' missing type');
        }
        replayStampActionIdentity($actions[$i], $state);
        $a = $actions[$i];
        $data = is_array($a['data'] ?? null) ? $a['data'] : [];
        try {
            $state = replayApplyRecordedAction(
                $state,
                $pid,
                $type,
                $data,
                $i + 1
            );
        } catch (Throwable $e) {
            // Conversion must finish — soft-skip like seek used to.
            $state = replaySoftSkipPendingPrompt(
                $state,
                'convert #' . ($i + 1) . ' ' . $type . ': ' . $e->getMessage(),
                $type
            );
            $state = replaySurfaceSkippedPlayOrActivate($state, $pid, $type, $data);
        }
        if (empty($state['pending_prompt']) && function_exists('flushAutoOnWaitAbilities')) {
            $state = flushAutoOnWaitAbilities($state);
        }
        $frames[] = sanitizeReplayFrame($state);
        $logEnds[] = count($state['log'] ?? []);
    }
    $meta = is_array($replay['meta'] ?? null) ? $replay['meta'] : [];
    $meta['frame_count'] = count($frames);
    return ensureReplayLogIndex([
        'schema_version' => REPLAY_SCHEMA_VERSION,
        'meta' => $meta,
        'baseline' => sanitizeReplayFrame($baseline),
        'actions' => $actions,
        'frames' => $frames,
        'full_log' => is_array($state['log'] ?? null) ? $state['log'] : [],
        'log_ends' => $logEnds,
    ]);
}

function ensureReplayPayloadV2(array $replay): array {
    validateReplayFile($replay);
    $actions = $replay['actions'] ?? [];
    $frames = $replay['frames'] ?? null;
    if (intval($replay['schema_version'] ?? 0) >= 2
        && is_array($frames)
        && count($frames) === count($actions) + 1) {
        return convertReplayPayloadToV2($replay);
    }
    return convertReplayPayloadToV2($replay);
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
    $v1 = [
        'schema_version' => 1,
        'meta' => [
            'saved_at'         => gmdate('c'),
            'saver_player_id'  => $saverPid,
            'saver_name'       => $saverName,
            'room_id'          => $state['room_id'] ?? '',
            'turn'             => intval($state['turn'] ?? 0),
            'phase'            => (string)($state['phase'] ?? ''),
            'game_seq'         => intval($state['seq'] ?? 0),
            'client_version'   => '0.1.6',
            'mode'             => $state['mode'] ?? null,
            'cpu_difficulty'   => $state['cpu_difficulty'] ?? null,
            'timing_source'    => !empty($state['phase_timer']) ? 'phase_timer' : 'action_timestamps',
            'duration_seconds' => replayDurationSeconds($actions),
        ],
        'baseline' => $baseline,
        'actions'  => $actions,
    ];
    return convertReplayPayloadToV2($v1);
}

/** Encode payload for SQLite / disk; gzip when large. */
function replayPayloadEncodeForStorage(array $payload): string {
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new Exception('Could not encode replay payload');
    }
    if (strlen($json) > REPLAY_STORAGE_GZIP_BYTES && function_exists('gzcompress')) {
        $gz = gzcompress($json, 6);
        if ($gz !== false) {
            return 'LLTCG_GZ1:' . base64_encode($gz);
        }
    }
    return $json;
}

/** Decode payload from SQLite / disk (plain JSON or LLTCG_GZ1 gzip). */
function replayPayloadDecodeFromStorage(string $raw): array {
    $raw = trim($raw);
    if (str_starts_with($raw, 'LLTCG_GZ1:')) {
        $b64 = substr($raw, strlen('LLTCG_GZ1:'));
        $gz = base64_decode($b64, true);
        if ($gz === false || !function_exists('gzuncompress')) {
            throw new Exception('Saved replay payload is invalid');
        }
        $json = gzuncompress($gz);
        if ($json === false) {
            throw new Exception('Saved replay payload is invalid');
        }
        $payload = json_decode($json, true);
    } else {
        $payload = json_decode($raw, true);
    }
    if (!is_array($payload)) {
        throw new Exception('Saved replay payload is invalid');
    }
    return $payload;
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
function replaySanitizeViewingState(array $state, array $actions = [], int $step = 0): array {
    foreach (replayTransientPromptStateKeys() as $key) {
        unset($state[$key]);
    }
    unset($state['pending_prompt_meta']);
    $state = replayNormalizePhaseForViewing($state, $actions, $step);
    return $state;
}

/** True when applied actions mean the match already left the coin-flip UI. */
function replayActionsPastCoinFlip(array $actions, int $step): bool {
    $step = max(0, min($step, count($actions)));
    for ($i = 0; $i < $step; $i++) {
        $type = (string)($actions[$i]['type'] ?? '');
        if ($type === '' || $type === 'ack_coin_flip') {
            continue;
        }
        // choose_first_player and everything after must not keep the coin overlay.
        return true;
    }
    return false;
}

/** True when applied actions mean mulligan / main / live has started. */
function replayActionsPastSetup(array $actions, int $step): bool {
    $step = max(0, min($step, count($actions)));
    for ($i = 0; $i < $step; $i++) {
        $type = (string)($actions[$i]['type'] ?? '');
        if (in_array($type, ['ack_coin_flip', 'choose_first_player', ''], true)) {
            continue;
        }
        return true;
    }
    return false;
}

/**
 * After seek, drop leftover coin_flip/setup phases that soft-skips left behind.
 * Viewing is a snapshot — never keep First Player modal on mid-match steps.
 */
function replayNormalizePhaseForViewing(array $state, array $actions, int $step): array {
    $phase = (string)($state['phase'] ?? '');
    $pid = $state['first_player'] ?? $state['active_player'] ?? 'p1';
    if ($pid !== 'p1' && $pid !== 'p2') {
        $pid = 'p1';
    }

    if (replayActionsPastCoinFlip($actions, $step) && ($phase === 'coin_flip' || !empty($state['coin_flip']))) {
        $state = replayForceLeaveCoinFlip($state, $pid);
        $phase = (string)($state['phase'] ?? '');
    }

    if (replayActionsPastSetup($actions, $step) && in_array($phase, ['coin_flip', 'setup', 'waiting', ''], true)) {
        $state = replayForceLeaveCoinFlip($state, $pid);
        $state = replayEnsureMulligansDone($state);
        if (($state['first_player'] ?? null) !== 'p1' && ($state['first_player'] ?? null) !== 'p2') {
            $state['first_player'] = $pid;
        }
        // Prefer an existing gameplay phase from applied actions; otherwise land on main.
        $phase = (string)($state['phase'] ?? '');
        if (in_array($phase, ['coin_flip', 'setup', 'waiting', ''], true)) {
            if (empty($state['players'][$pid]['energy_zone'])) {
                try {
                    $state = startTurn($state);
                } catch (Throwable $e) {
                    $state['phase'] = 'main_first';
                    $state['active_player'] = $pid;
                }
            } else {
                $first = $state['first_player'] ?? $pid;
                $state['phase'] = ($first === $pid) ? 'main_first' : 'main_second';
                $state['active_player'] = $state['active_player'] ?? $first;
            }
        }
    }

    if (($state['phase'] ?? '') !== 'coin_flip') {
        unset($state['coin_flip']);
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

/** Pull a card from off-stage zones (not Live) so a recorded activate can sit on Stage. */
function replayTakeCardForStageRepair(array &$player, string $instanceId): ?array {
    foreach (['main_deck', 'hand', 'waiting_room', 'success_lives', 'energy_deck'] as $zone) {
        foreach ($player[$zone] ?? [] as $i => $c) {
            if (($c['instance_id'] ?? '') === $instanceId) {
                array_splice($player[$zone], $i, 1);
                return $c;
            }
        }
    }
    foreach ($player['energy_zone'] ?? [] as $i => $c) {
        if (($c['instance_id'] ?? '') === $instanceId) {
            unset($c['active'], $c['skip_activate_next_turn']);
            array_splice($player['energy_zone'], $i, 1);
            return $c;
        }
    }
    foreach ($player['stage'] ?? [] as $slot => &$mbr) {
        if (!$mbr || empty($mbr['stacked_members']) || !is_array($mbr['stacked_members'])) {
            continue;
        }
        foreach ($mbr['stacked_members'] as $i => $c) {
            if (($c['instance_id'] ?? '') === $instanceId) {
                array_splice($mbr['stacked_members'], $i, 1);
                return $c;
            }
        }
    }
    unset($mbr);
    return null;
}

/**
 * Clear once-per-turn marks so a recorded activate_ability can re-apply after
 * seek drift (early mark from a fizzled/soft-skipped prompt, or a missed Active Phase clear).
 */
function replayClearAbilityUsedForActivate(array &$state, string $pid, array $data): void {
    $cardId = (string)($data['card_id'] ?? '');
    if ($cardId === '' || ($pid !== 'p1' && $pid !== 'p2')) {
        return;
    }
    $abilityIdx = $data['ability_index'] ?? 0;
    $p = &$state['players'][$pid];
    $clearOn = static function (array &$card) use ($cardId, $abilityIdx): void {
        if (($card['instance_id'] ?? '') !== $cardId) {
            return;
        }
        if (!isset($card['abilities_used']) || !is_array($card['abilities_used'])) {
            return;
        }
        $keys = [];
        if (function_exists('abilityUsedKey')) {
            $keys[] = abilityUsedKey($cardId, $abilityIdx);
            $keys[] = abilityUsedKey($cardId, intval($abilityIdx));
        }
        $keys[] = $cardId . ':' . $abilityIdx;
        $keys[] = $cardId . ':' . intval($abilityIdx);
        foreach ($keys as $k) {
            unset($card['abilities_used'][$k]);
        }
        // Last resort: wipe all once-per-turn marks on this instance so seek continues.
        if (!empty($card['abilities_used'])) {
            foreach (array_keys($card['abilities_used']) as $k) {
                if (str_starts_with((string)$k, $cardId . ':')) {
                    unset($card['abilities_used'][$k]);
                }
            }
        }
        if (empty($card['abilities_used'])) {
            unset($card['abilities_used']);
        }
    };
    if (isset($p['stage']) && is_array($p['stage'])) {
        foreach ($p['stage'] as &$mbr) {
            if (is_array($mbr)) {
                $clearOn($mbr);
            }
        }
        unset($mbr);
    }
    if (isset($p['waiting_room']) && is_array($p['waiting_room'])) {
        foreach ($p['waiting_room'] as &$c) {
            if (is_array($c)) {
                $clearOn($c);
            }
        }
        unset($c);
    }
    if (isset($p['hand']) && is_array($p['hand'])) {
        foreach ($p['hand'] as &$c) {
            if (is_array($c)) {
                $clearOn($c);
            }
        }
        unset($c);
    }
}

function replayEnsureMemberOnStage(array &$state, string $pid, string $cardId, string $preferredSlot = ''): void {
    if ($cardId === '' || ($pid !== 'p1' && $pid !== 'p2')) {
        return;
    }
    $p = &$state['players'][$pid];
    foreach ($p['stage'] ?? [] as $mbr) {
        if ($mbr && ($mbr['instance_id'] ?? '') === $cardId) {
            return;
        }
    }
    $card = replayTakeCardForStageRepair($p, $cardId);
    if (!$card) {
        return;
    }
    $order = ['center', 'left', 'right'];
    if (in_array($preferredSlot, $order, true)) {
        $order = array_values(array_unique(array_merge([$preferredSlot], $order)));
    }
    $slot = null;
    foreach ($order as $s) {
        if (empty($p['stage'][$s])) {
            $slot = $s;
            break;
        }
    }
    if ($slot === null) {
        $slot = in_array($preferredSlot, $order, true) ? $preferredSlot : 'center';
        if (!empty($p['stage'][$slot])) {
            $p['waiting_room'][] = $p['stage'][$slot];
        }
    }
    $p['stage'][$slot] = $card;
}

function replayParseActiveEnergyNeed(string $msg, int $fallback = 1): int {
    if (preg_match('/Need\s+(\d+)\s+active Energy/i', $msg, $m)) {
        return max(1, (int)$m[1]);
    }
    if (stripos($msg, 'Not enough active energy') !== false) {
        return max(1, $fallback);
    }
    return 0;
}

/** Flip Energy already in the zone. Do not steal from deck/hand — that desyncs later turns. */
function replayEnsureActiveEnergy(array &$state, string $pid, int $need): void {
    if ($need <= 0 || ($pid !== 'p1' && $pid !== 'p2')) {
        return;
    }
    $p = &$state['players'][$pid];
    if (!isset($p['energy_zone']) || !is_array($p['energy_zone'])) {
        return;
    }
    $have = countActiveEnergyInZone($p);
    if ($have >= $need) {
        return;
    }
    activateEnergyForPlayer($p, $need - $have);
}

/** Put a recorded Baton Touch target on the exact Stage slot (swap or pull off-stage). */
function replayPlaceMemberOnExactSlot(array &$state, string $pid, string $cardId, string $slot): void {
    $slots = ['center', 'left', 'right'];
    if ($cardId === '' || ($pid !== 'p1' && $pid !== 'p2') || !in_array($slot, $slots, true)) {
        return;
    }
    $p = &$state['players'][$pid];
    $here = $p['stage'][$slot] ?? null;
    if ($here && ($here['instance_id'] ?? '') === $cardId) {
        return;
    }
    foreach ($slots as $s) {
        if ($s === $slot) {
            continue;
        }
        $m = $p['stage'][$s] ?? null;
        if ($m && ($m['instance_id'] ?? '') === $cardId) {
            $p['stage'][$s] = $here;
            $p['stage'][$slot] = $m;
            return;
        }
    }
    $card = replayTakeCardForStageRepair($p, $cardId);
    if (!$card) {
        return;
    }
    if ($here) {
        $p['waiting_room'][] = $here;
    }
    $p['stage'][$slot] = $card;
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
    // Do not yank cards that are already correctly on Stage or in Live storage —
    // live_start_order_sources (and similar) list those instance ids in card_ids (#111).
    foreach ($p['stage'] ?? [] as $mbr) {
        if ($mbr && ($mbr['instance_id'] ?? '') === $cardId) {
            return;
        }
    }
    foreach ($p['live_zone'] ?? [] as $c) {
        if (($c['instance_id'] ?? '') === $cardId) {
            return;
        }
    }
    foreach (['main_deck', 'waiting_room', 'success_lives', 'energy_deck'] as $zone) {
        foreach ($p[$zone] ?? [] as $i => $c) {
            if (($c['instance_id'] ?? '') === $cardId) {
                $p['hand'][] = $c;
                array_splice($p[$zone], $i, 1);
                return;
            }
        }
    }
    foreach ($p['energy_zone'] ?? [] as $i => $c) {
        if (($c['instance_id'] ?? '') === $cardId) {
            unset($c['active'], $c['skip_activate_next_turn']);
            $p['hand'][] = $c;
            array_splice($p['energy_zone'], $i, 1);
            return;
        }
    }
    // Stacked under Stage Members (hand→stack effects often leave the card here after drift).
    foreach ($p['stage'] ?? [] as $slot => &$mbr) {
        if (!$mbr || empty($mbr['stacked_members']) || !is_array($mbr['stacked_members'])) {
            continue;
        }
        foreach ($mbr['stacked_members'] as $i => $c) {
            if (($c['instance_id'] ?? '') === $cardId) {
                $p['hand'][] = $c;
                array_splice($mbr['stacked_members'], $i, 1);
                return;
            }
        }
    }
    unset($mbr);
}

/** Collect every card instance id a recorded resolve_prompt may need. */
function replayPromptCardIdsFromData(array $data): array {
    $ids = [];
    foreach (['card_id', 'pick_id', 'member_id', 'baton_id', 'baton_id2'] as $key) {
        if (!empty($data[$key]) && is_string($data[$key])) {
            $ids[] = $data[$key];
        }
    }
    foreach (['card_ids', 'discard_ids', 'member_ids', 'top_ids', 'wr_ids'] as $key) {
        foreach ($data[$key] ?? [] as $cid) {
            if (is_string($cid) && $cid !== '') {
                $ids[] = $cid;
            }
        }
    }
    // Some older clients stuffed the instance id into choice.
    $choice = $data['choice'] ?? '';
    if (is_string($choice) && str_starts_with($choice, 'card_')) {
        $ids[] = $choice;
    }
    return array_values(array_unique($ids));
}

function replayNormalizePromptData(array $data): array {
    if (empty($data['card_id'])) {
        foreach (['pick_id', 'member_id'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                $data['card_id'] = $data[$key];
                break;
            }
        }
        $choice = $data['choice'] ?? '';
        if (empty($data['card_id']) && is_string($choice) && str_starts_with($choice, 'card_')) {
            $data['card_id'] = $choice;
        }
    }
    return $data;
}

/** Clear a stuck pending prompt so seek can keep applying later recorded actions. */
function replaySoftSkipPendingPrompt(array $state, string $note = '', string $forType = ''): array {
    foreach (replayTransientPromptStateKeys() as $key) {
        unset($state[$key]);
    }
    if ($note !== '') {
        $state = addLog($state, 'Replay seek — skipped unresolved prompt (' . $note . ').');
    }
    // Soft-skipping a post-coin action must not leave the viewer parked on coin_flip.
    if ($forType !== '' && $forType !== 'ack_coin_flip'
        && (($state['phase'] ?? '') === 'coin_flip' || !empty($state['coin_flip']))) {
        $pid = $state['first_player'] ?? $state['active_player'] ?? 'p1';
        if ($pid !== 'p1' && $pid !== 'p2') {
            $pid = 'p1';
        }
        $state = replayForceLeaveCoinFlip($state, $pid);
        if ($forType === 'mulligan') {
            $state['phase'] = 'setup';
        } elseif (!in_array($forType, ['choose_first_player', ''], true)) {
            $state = replayForcePhaseForAction($state, $pid, $forType);
        }
    }
    try {
        $state = finishPromptEffects($state);
    } catch (Throwable $e) {
        // Keep seeking even if resume hooks fail after a soft skip.
    }
    // finishPromptEffects may chain another interactive prompt — drop it so seek
    // stays driven by recorded actions, not live UI validation.
    foreach (replayTransientPromptStateKeys() as $key) {
        unset($state[$key]);
    }
    unset($state['pending_prompt_meta']);
    return $state;
}

function replayPrepareRecordedPlayAction(array $state, string $pid, string $type, array $data): array {
    if ($type === 'set_live_cards') {
        foreach ($data['card_ids'] ?? [] as $cid) {
            replayEnsureCardInHand($state, $pid, (string)$cid);
        }
    } elseif ($type === 'play_member' && !empty($data['card_id'])) {
        replayEnsureCardInHand($state, $pid, (string)$data['card_id']);
        $slot = (string)($data['slot'] ?? 'center');
        if (!empty($data['baton_id'])) {
            replayPlaceMemberOnExactSlot($state, $pid, (string)$data['baton_id'], $slot);
        }
    } elseif ($type === 'activate_ability' && !empty($data['card_id'])) {
        $found = findActivatedAbilitySource($state['players'][$pid], (string)$data['card_id']);
        // Leave WR-only activates in the Waiting Room for the first apply.
        if (($found['zone'] ?? '') !== 'waiting_room') {
            replayEnsureMemberOnStage(
                $state,
                $pid,
                (string)$data['card_id'],
                (string)($data['slot'] ?? $data['source_slot'] ?? '')
            );
        }
    } elseif ($type === 'resolve_prompt' || $type === 'anti_softlock_skip') {
        $owner = $pid;
        $pr = $state['pending_prompt'] ?? null;
        if (is_array($pr) && (($pr['owner'] ?? '') === 'p1' || ($pr['owner'] ?? '') === 'p2')) {
            $owner = (string)$pr['owner'];
        }
        foreach (replayPromptCardIdsFromData($data) as $cid) {
            replayEnsureCardInHand($state, $owner, $cid);
            if ($owner !== $pid) {
                replayEnsureCardInHand($state, $pid, $cid);
            }
        }
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

function replayForceLeaveCoinFlip(array $state, string $pid): array {
    if (($state['phase'] ?? '') !== 'coin_flip') {
        return $state;
    }
    $flip = is_array($state['coin_flip'] ?? null) ? $state['coin_flip'] : [];
    $winner = $flip['winner'] ?? null;
    if ($winner !== 'p1' && $winner !== 'p2') {
        $winner = ($pid === 'p2') ? 'p2' : 'p1';
    }
    $first = $state['first_player'] ?? null;
    if ($first !== 'p1' && $first !== 'p2') {
        $first = $winner;
    }
    $state['first_player'] = $first;
    $state['active_player'] = $first;
    $state['phase'] = 'setup';
    unset($state['coin_flip']);
    return $state;
}

function replayEnsureMulligansDone(array $state): array {
    foreach (['p1', 'p2'] as $id) {
        if (empty($state['players'][$id])) {
            continue;
        }
        $state['players'][$id]['ready_mulligan'] = true;
        if (!isset($state['players'][$id]['mulligan_redrawn'])) {
            $state['players'][$id]['mulligan_redrawn'] = 0;
        }
    }
    return $state;
}

/** Advance a stuck coin/setup snapshot so recorded main/live actions can apply. */
function replayForcePhaseForAction(array $state, string $pid, string $type): array {
    $phase = (string)($state['phase'] ?? '');
    if ($type === 'choose_first_player') {
        if ($phase !== 'coin_flip') {
            $state['phase'] = 'coin_flip';
        }
        $flip = is_array($state['coin_flip'] ?? null) ? $state['coin_flip'] : [];
        $winner = $flip['winner'] ?? $pid;
        if ($winner !== 'p1' && $winner !== 'p2') {
            $winner = $pid;
        }
        $state['coin_flip'] = [
            'winner' => $winner,
            'ready'  => ['p1' => true, 'p2' => true],
            'since'  => intval($flip['since'] ?? time()),
        ];
        return $state;
    }
    if ($type === 'mulligan') {
        $state = replayForceLeaveCoinFlip($state, $pid);
        $state['phase'] = 'setup';
        if (($state['first_player'] ?? null) !== 'p1' && ($state['first_player'] ?? null) !== 'p2') {
            $state['first_player'] = $pid;
            $state['active_player'] = $pid;
        }
        return $state;
    }

    $mainish = in_array($type, ['play_member', 'activate_ability', 'end_main', 'force_own_timeout', 'resolve_prompt'], true);
    $liveish = in_array($type, ['set_live_cards', 'end_live_set', 'confirm_live', 'live_show_ack'], true);
    if (!$mainish && !$liveish) {
        return $state;
    }

    $state = replayForceLeaveCoinFlip($state, $pid);
    $phase = (string)($state['phase'] ?? '');
    if ($phase === 'setup' || $phase === 'waiting' || $phase === '') {
        $state = replayEnsureMulligansDone($state);
        if (($state['first_player'] ?? null) !== 'p1' && ($state['first_player'] ?? null) !== 'p2') {
            $state['first_player'] = $pid;
        }
        $state['active_player'] = $pid;
        $state = startTurn($state);
        $phase = (string)($state['phase'] ?? '');
    }
    if ($mainish && !in_array($phase, ['main_first', 'main_second'], true)) {
        $first = $state['first_player'] ?? $pid;
        $state['phase'] = ($first === $pid) ? 'main_first' : 'main_second';
        $state['active_player'] = $pid;
    }
    if ($liveish && ($state['phase'] ?? '') !== 'live_set') {
        $state['phase'] = 'live_set';
        $state['active_player'] = $pid;
    }
    return $state;
}

function replayPrepareStateForRecordedAction(array $state, string $pid, string $type): array {
    if (in_array($type, ['resolve_prompt', 'anti_softlock_skip'], true)) {
        $pr = $state['pending_prompt'] ?? null;
        if (is_array($pr) && ($pr['responder'] ?? '') !== $pid) {
            unset($state['pending_prompt']);
        }
        $phase = (string)($state['phase'] ?? '');
        // resolve_prompt used to return early and leave seek stuck on coin_flip.
        if (in_array($phase, ['coin_flip', 'setup', 'waiting', ''], true)) {
            $state = replayForcePhaseForAction($state, $pid, 'resolve_prompt');
        }
        return $state;
    }
    $pr = $state['pending_prompt'] ?? null;
    if (is_array($pr) && ($pr['responder'] ?? '') !== $pid) {
        unset($state['pending_prompt']);
    }

    $phase = (string)($state['phase'] ?? '');
    if ($type === 'choose_first_player' && $phase === 'coin_flip') {
        $flip = is_array($state['coin_flip'] ?? null) ? $state['coin_flip'] : [];
        if (empty($flip['ready']['p1']) || empty($flip['ready']['p2'])) {
            $state['coin_flip']['ready']['p1'] = true;
            $state['coin_flip']['ready']['p2'] = true;
        }
    }
    if ($type === 'mulligan' && in_array($phase, ['coin_flip', 'waiting', ''], true)) {
        $state = replayForcePhaseForAction($state, $pid, $type);
    }
    $gameplayTypes = [
        'play_member', 'activate_ability', 'end_main', 'force_own_timeout',
        'set_live_cards', 'end_live_set', 'confirm_live', 'live_show_ack',
        'resolve_prompt',
    ];
    if (in_array($type, $gameplayTypes, true)
        && in_array($phase, ['coin_flip', 'setup', 'waiting', ''], true)) {
        $state = replayForcePhaseForAction($state, $pid, $type);
    }
    return $state;
}

function replayLooksLikePromptInteractionError(string $msg): bool {
    $needles = [
        'Choose a card',
        'Choose 1',
        'Choose cards',
        'Card not in hand',
        'pending skill prompt',
        'No pending prompt',
        'No skill prompt',
        'That card is not',
        'Invalid choice',
        'must pick',
        'Must assign',
        'Not a valid',
        'from your hand',
        'from the Waiting Room',
        'from Waiting Room',
    ];
    foreach ($needles as $needle) {
        if (str_contains($msg, $needle)) {
            return true;
        }
    }
    return false;
}

/** Engine validation that means the replay board drifted — skip rather than abort seek. */
function replayLooksLikeSeekDriftError(string $msg): bool {
    $needles = [
        'Invalid Baton Touch target',
        'cannot be sent to the Waiting Room via Baton Touch',
        'Cannot use Baton Touch when play cost is 0',
        'Cannot enter this Stage area this turn',
        'Cannot replace a Member that was played this turn',
        'Not enough active energy',
        'Member not on stage',
        'Card not found on Stage or in Waiting Room',
        'Card not in hand',
        'Not a member card',
        'Not in LIVE Phase',
        'Already locked in LIVE selection',
        'Live Card storage is full',
        'Live card no longer in storage',
        'Invalid Live card',
        'Choose a Live card',
        'Ability already used this turn',
        'Invalid ability',
        'Not an activated ability',
    ];
    foreach ($needles as $needle) {
        if (str_contains($msg, $needle)) {
            return true;
        }
    }
    if (preg_match('/Need\s+\d+\s+active Energy/i', $msg)) {
        return true;
    }
    return false;
}

/** Best-effort state repair before retrying a desynced recorded action. */
function replayApplyFixForRetry(array $state, string $pid, string $type, array $data, Throwable $e): array {
    $msg = $e->getMessage();
    if (str_contains($msg, 'Not in correct phase')
        || str_contains($msg, 'Not in coin flip')
        || str_contains($msg, 'Not in mulligan')
        || str_contains($msg, 'Wait for the coin flip')) {
        $state = replayForcePhaseForAction($state, $pid, $type);
    }
    if (str_contains($msg, 'Not your turn')) {
        $state['active_player'] = $pid;
        $pr = $state['pending_prompt'] ?? null;
        if (is_array($pr) && !empty($pr['responder'])) {
            $state['active_player'] = (string)$pr['responder'];
        }
    }
    if (str_contains($msg, 'Card not in hand')
        || str_contains($msg, 'Not a member card')
        || str_contains($msg, 'Choose a card')
        || str_contains($msg, 'from your hand')) {
        foreach (replayPromptCardIdsFromData($data) as $cid) {
            replayEnsureCardInHand($state, $pid, $cid);
        }
        if (!empty($data['card_id'])) {
            replayEnsureCardInHand($state, $pid, (string)$data['card_id']);
        }
    }
    if ($type === 'set_live_cards') {
        foreach ($data['card_ids'] ?? [] as $cid) {
            replayEnsureCardInHand($state, $pid, (string)$cid);
        }
    }
    if ($type === 'play_member' && !empty($data['card_id'])) {
        replayEnsureCardInHand($state, $pid, (string)$data['card_id']);
        if (!empty($data['baton_id'])
            && (str_contains($msg, 'Invalid Baton Touch')
                || str_contains($msg, 'Baton Touch'))) {
            replayPlaceMemberOnExactSlot(
                $state,
                $pid,
                (string)$data['baton_id'],
                (string)($data['slot'] ?? 'center')
            );
        }
    }
    if ($type === 'activate_ability' && !empty($data['card_id'])) {
        if (str_contains($msg, 'Member not on stage')
            || str_contains($msg, 'Card not found on Stage or in Waiting Room')) {
            replayEnsureMemberOnStage(
                $state,
                $pid,
                (string)$data['card_id'],
                (string)($data['slot'] ?? $data['source_slot'] ?? '')
            );
        }
        if (str_contains($msg, 'Ability already used this turn')) {
            replayClearAbilityUsedForActivate($state, $pid, $data);
        }
    }
    $energyNeed = replayParseActiveEnergyNeed($msg, 2);
    if ($energyNeed > 0 && in_array($type, ['activate_ability', 'play_member'], true)) {
        replayEnsureActiveEnergy($state, $pid, $energyNeed);
    }
    if ($type === 'resolve_prompt' || $type === 'anti_softlock_skip') {
        $owner = $pid;
        $pr = $state['pending_prompt'] ?? null;
        if (is_array($pr) && (($pr['owner'] ?? '') === 'p1' || ($pr['owner'] ?? '') === 'p2')) {
            $owner = (string)$pr['owner'];
        }
        foreach (replayPromptCardIdsFromData($data) as $cid) {
            replayEnsureCardInHand($state, $owner, $cid);
            if ($owner !== $pid) {
                replayEnsureCardInHand($state, $pid, $cid);
            }
        }
        if (str_contains($msg, 'Not your turn')) {
            if (is_array($pr) && !empty($pr['responder'])) {
                $state['active_player'] = (string)$pr['responder'];
            } else {
                $state['active_player'] = $pid;
            }
        }
    }
    return $state;
}

function replayFinishRecordedAction(array $state, string $pid, string $type, array $data): array {
    if ($type === 'mulligan' && !empty($data['main_deck_order']) && is_array($data['main_deck_order'])) {
        $state = replayRestoreMainDeckOrder($state, $pid, $data['main_deck_order']);
    }
    return $state;
}

function replayTryApplyRecordedActionOnce(array $state, string $pid, string $type, array $data): array {
    try {
        $state = applyAction($state, $pid, $type, $data);
        return replayFinishRecordedAction($state, $pid, $type, $data);
    } catch (Throwable $e) {
        if ($type !== 'resolve_prompt'
            && $type !== 'anti_softlock_skip'
            && !empty($state['pending_prompt'])
            && replayActionBlockedByPendingPrompt($e)) {
            $state = replaySoftSkipPendingPrompt($state, 'cleared for ' . $type, $type);
            $state = applyAction($state, $pid, $type, $data);
            return replayFinishRecordedAction($state, $pid, $type, $data);
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
        if ($type === 'anti_softlock_skip' && str_contains($e->getMessage(), 'No skill prompt')) {
            return $state;
        }
        throw $e;
    }
}

function replayApplyRecordedAction(array $state, string $pid, string $type, array $data, int $index): array {
    if ($type === 'resolve_prompt' || $type === 'anti_softlock_skip') {
        $data = replayNormalizePromptData($data);
    }
    $lastError = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $state = replayPrepareStateForRecordedAction($state, $pid, $type);
        $state = replayPrepareRecordedPlayAction($state, $pid, $type, $data);
        try {
            return replayTryApplyRecordedActionOnce($state, $pid, $type, $data);
        } catch (Throwable $e) {
            $lastError = $e;
            if ($attempt >= 2) {
                break;
            }
            $state = replayApplyFixForRetry($state, $pid, $type, $data, $e);
        }
    }
    $msg = $lastError ? $lastError->getMessage() : 'failed';
    if ($type === 'send_stamp') {
        return $state;
    }
    // Seek must not stop on interactive prompt validation — soft-skip and continue.
    if ($type === 'resolve_prompt'
        || $type === 'anti_softlock_skip'
        || replayLooksLikePromptInteractionError($msg)
        || replayLooksLikeSeekDriftError($msg)) {
        $state = replaySoftSkipPendingPrompt(
            $state,
            '#' . $index . ' ' . $type . ': ' . $msg,
            $type
        );
        return replaySurfaceSkippedPlayOrActivate($state, $pid, $type, $data);
    }
    throw $lastError ?? new Exception('Replay action #' . $index . ' failed');
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

/**
 * Install a schema-v2 frame as the live room board for viewing (no re-sim).
 *
 * @param array $replayBag Slim replay blob kept on the room (frames, actions, …)
 */
function replayInstallFrame(
    array $frame,
    string $roomId,
    string $p1Token,
    string $p2Token,
    array $replayBag,
    string $cpuDiff = 'normal'
): array {
    $state = replayRestoreFromBaseline($frame, $roomId, $p1Token, $p2Token);
    $state['cpu_difficulty'] = $cpuDiff;
    $state['mode'] = 'replay_view';
    $state['cpu_solo'] = true;
    $state['replay'] = $replayBag;
    $actions = is_array($replayBag['actions'] ?? null) ? $replayBag['actions'] : [];
    $step = intval($replayBag['step'] ?? 0);
    $state = replayAttachLogForStep($state, $replayBag, $step);
    $state = replaySanitizeViewingState($state, $actions, $step);
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
        try {
            $state = replayApplyRecordedAction($state, $pid, $type, is_array($a['data'] ?? null) ? $a['data'] : [], $i + 1);
        } catch (Throwable $e) {
            throw new Exception(
                'Replay action #' . ($i + 1) . ' (' . $type . ' / ' . $pid . '): ' . $e->getMessage(),
                0,
                $e
            );
        }
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
    $replay = ensureReplayPayloadV2($replay);

    $actions = $replay['actions'] ?? [];
    $frames = $replay['frames'] ?? [];
    $baseline = $replay['baseline'] ?? ($frames[0] ?? null);
    if (!is_array($baseline) || !is_array($frames) || count($frames) !== count($actions) + 1) {
        throw new Exception('Replay frames missing after convert');
    }
    $actions = replayStampActionsFromFrames($actions, $frames);
    $saverPid = $replay['meta']['saver_player_id'] ?? 'p1';
    $cpuDiff = in_array($replay['meta']['cpu_difficulty'] ?? '', ['easy', 'normal', 'hard', 'expert'], true)
        ? $replay['meta']['cpu_difficulty'] : 'normal';

    $roomId = strtoupper(substr(md5(uniqid('rpl', true)), 0, 6));
    $p1Token = generateToken();
    $p2Token = generateToken();

    $replayBag = [
        'saver_pid' => $saverPid,
        'actions'   => $actions,
        'baseline'  => $baseline,
        'frames'    => $frames,
        'full_log'  => $replay['full_log'] ?? [],
        'log_ends'  => $replay['log_ends'] ?? [],
        'step'      => 0,
        'handoff'   => false,
        'schema_version' => REPLAY_SCHEMA_VERSION,
    ];
    $state = replayInstallFrame($frames[0], $roomId, $p1Token, $p2Token, $replayBag, $cpuDiff);
    $state = addLog($state, 'Replay loaded — ' . count($actions) . ' action(s). Use replay controls to play or seek.');
    $state['seq'] = intval($state['seq'] ?? 0) + 1;

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
        'action_labels' => replayActionLabels($actions),
    ];
}

/** Compact play/activate labels for the replay bar (no full action payloads). */
function replayActionLabels(array $actions): array {
    $out = [];
    foreach ($actions as $a) {
        if (!is_array($a)) {
            $out[] = ['type' => '', 'turn' => 0, 'card_name' => '', 'slot' => '', 'player' => ''];
            continue;
        }
        $data = is_array($a['data'] ?? null) ? $a['data'] : [];
        $out[] = [
            'type' => (string)($a['type'] ?? ''),
            'turn' => intval($a['turn'] ?? 0),
            'card_name' => (string)($a['card_name'] ?? ''),
            'slot' => (string)($a['slot'] ?? ($data['slot'] ?? '')),
            'player' => (string)($a['player'] ?? ''),
        ];
    }
    return $out;
}

/** Fill missing turn/card_name from board frames so older replays still name the card. */
function replayStampActionsFromFrames(array $actions, array $frames): array {
    foreach ($actions as $i => &$action) {
        if (!is_array($action)) {
            continue;
        }
        $after = (isset($frames[$i + 1]) && is_array($frames[$i + 1])) ? $frames[$i + 1] : null;
        $before = (isset($frames[$i]) && is_array($frames[$i])) ? $frames[$i] : null;
        $src = $after ?? $before;
        if ($src && !isset($action['turn']) && isset($src['turn'])) {
            $action['turn'] = intval($src['turn']);
        }
        $type = (string)($action['type'] ?? '');
        if ($type !== 'play_member' && $type !== 'activate_ability') {
            continue;
        }
        if (!empty($action['card_name'])) {
            continue;
        }
        $data = is_array($action['data'] ?? null) ? $action['data'] : [];
        $cid = (string)($data['card_id'] ?? '');
        $pid = (string)($action['player'] ?? '');
        $name = '';
        if ($after) {
            $name = replayLookupCardName($after, $pid, $cid);
        }
        if ($name === '' && $before) {
            $name = replayLookupCardName($before, $pid, $cid);
        }
        if ($name !== '') {
            $action['card_name'] = $name;
        }
    }
    unset($action);
    return $actions;
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
        $frames = $replay['frames'] ?? null;
        $baseline = $replay['baseline'] ?? null;
        $p1Token = $state['players']['p1']['token'] ?? generateToken();
        $p2Token = $state['players']['p2']['token'] ?? generateToken();
        $cpuDiff = $state['cpu_difficulty'] ?? 'normal';

        // Legacy room without frames: materialize once from baseline+actions.
        if (!is_array($frames) || count($frames) !== $maxStep + 1) {
            if (!$baseline) {
                throw new Exception('Replay baseline missing from room');
            }
            $converted = convertReplayPayloadToV2([
                'schema_version' => 1,
                'meta' => [
                    'saver_player_id' => $replay['saver_pid'] ?? 'p1',
                    'cpu_difficulty' => $cpuDiff,
                ],
                'baseline' => $baseline,
                'actions' => $actions,
            ]);
            $frames = $converted['frames'];
            $baseline = $converted['baseline'];
            $replay['full_log'] = $converted['full_log'] ?? [];
            $replay['log_ends'] = $converted['log_ends'] ?? [];
        }

        if (empty($replay['full_log']) || empty($replay['log_ends'])) {
            $indexed = ensureReplayLogIndex([
                'schema_version' => REPLAY_SCHEMA_VERSION,
                'meta' => ['saver_player_id' => $replay['saver_pid'] ?? 'p1'],
                'baseline' => $baseline,
                'actions' => $actions,
                'frames' => $frames,
            ]);
            $replay['full_log'] = $indexed['full_log'] ?? [];
            $replay['log_ends'] = $indexed['log_ends'] ?? [];
        }

        $handoff = $wantsHandoff && $step >= $maxStep;
        if ($handoff) {
            $newState = replayRestoreFromBaseline($frames[$step], $roomId, $p1Token, $p2Token);
            $newState['cpu_difficulty'] = $cpuDiff;
            unset($newState['replay']);
            $newState['mode'] = null;
            $newState['replay_handoff'] = true;
            $newState['cpu_solo'] = true;
            $newState = replayAttachLogForStep($newState, $replay, $step);
            $newState = replaySanitizeViewingState($newState, $actions, $step);
            $newState = addLog(
                $newState,
                'Replay complete — you control ' . ($newState['players'][$playerId]['name'] ?? $playerId)
                . '. CPU plays the opponent.'
            );
            $newState['seq'] = intval($newState['seq'] ?? 0) + 1;
        } else {
            $replayBag = [
                'saver_pid' => $replay['saver_pid'],
                'actions'   => $actions,
                'baseline'  => $baseline,
                'frames'    => $frames,
                'full_log'  => $replay['full_log'] ?? [],
                'log_ends'  => $replay['log_ends'] ?? [],
                'step'      => $step,
                'handoff'   => false,
                'schema_version' => REPLAY_SCHEMA_VERSION,
            ];
            $newState = replayInstallFrame(
                $frames[$step],
                $roomId,
                $p1Token,
                $p2Token,
                $replayBag,
                $cpuDiff
            );
        }

        saveGame($roomId, $newState);

        return [
            'ok'      => true,
            'step'    => $step,
            'total'   => $maxStep,
            'handoff' => $handoff,
            'seq'     => $newState['seq'],
        ];
    }, REPLAY_LOCK_TIMEOUT);
}

function enrichReplayFieldsForClient(array $filtered, array $state): array {
    if (!empty($state['replay']) && is_array($state['replay'])) {
        $actions = $state['replay']['actions'] ?? [];
        $frames = $state['replay']['frames'] ?? null;
        $total = is_array($actions) ? count($actions) : 0;
        if (is_array($frames) && count($frames) > 0) {
            $total = max($total, count($frames) - 1);
        }
        $filtered['replay'] = [
            'step'    => intval($state['replay']['step'] ?? 0),
            'total'   => $total,
            'handoff' => !empty($state['replay']['handoff']),
            'saver_pid' => $state['replay']['saver_pid'] ?? null,
        ];
    }
    if (!empty($state['replay_handoff'])) {
        $filtered['replay_handoff'] = true;
    }
    return $filtered;
}
