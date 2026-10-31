<?php
/**
 * Tournament spectate helpers — stream-delay ring + spectator totals.
 */
require_once __DIR__ . '/config/paths.php';

function tcgTournamentDelayDir(): string {
    $dir = rtrim(tcgPath('data'), '/\\') . DIRECTORY_SEPARATOR . 'spectate_delay';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function tcgTournamentDelayFile(string $roomId): string {
    $safe = strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId) ?? '');
    return tcgTournamentDelayDir() . DIRECTORY_SEPARATOR . $safe . '.json';
}

/** @return int seconds from room state / tournament blob */
function tcgTournamentStreamDelaySecs(array $state): int {
    $delay = (int)($state['spectate_stream_delay_secs']
        ?? $state['tournament']['stream_delay_secs']
        ?? 0);
    return in_array($delay, [0, 15, 30, 60], true) ? $delay : 0;
}

/**
 * Public pile sizes for spectate (faces stay hidden). Prefer stored *_count when
 * the deck arrays were already stripped for the delay ring.
 *
 * @param array<string, mixed> $p
 */
function tcgTournamentSpectatePileCount(array $p, string $zoneKey): int {
    $arr = $p[$zoneKey] ?? null;
    if (is_array($arr) && count($arr) > 0) {
        return count($arr);
    }
    $countKey = $zoneKey . '_count';
    if (array_key_exists($countKey, $p) && $p[$countKey] !== null && $p[$countKey] !== '') {
        return max(0, (int)$p[$countKey]);
    }
    return is_array($arr) ? count($arr) : 0;
}

/** @param array<string, mixed>|null $p */
function tcgTournamentSpectateHoldPlayer(?array $p): array {
    if (!is_array($p)) {
        return [
            'name' => '',
            'hand' => [],
            'stage' => [],
            'waiting_room' => [],
            'live_zone' => [],
            'clock' => [],
            'score' => 0,
            'main_deck' => [],
            'main_deck_count' => 0,
            'energy_deck' => [],
            'energy_deck_count' => 0,
        ];
    }
    return [
        'id' => $p['id'] ?? null,
        'name' => (string)($p['name'] ?? ''),
        'hand' => [],
        'stage' => [],
        'waiting_room' => [],
        'live_zone' => [],
        'clock' => [],
        'score' => 0,
        'sleeve_id' => $p['sleeve_id'] ?? '',
        'playmat_id' => $p['playmat_id'] ?? '',
        'playmat_brightness' => $p['playmat_brightness'] ?? 1.0,
        'main_deck' => [],
        'main_deck_count' => tcgTournamentSpectatePileCount($p, 'main_deck'),
        'energy_deck' => [],
        'energy_deck_count' => tcgTournamentSpectatePileCount($p, 'energy_deck'),
        'deck_label' => (string)($p['deck_label'] ?? ''),
    ];
}

/**
 * Board with names/cosmetics only — never live zones, logs, or prompts.
 *
 * @return array<string, mixed>
 */
function tcgTournamentSpectateHoldState(array $liveState, int $delay): array {
    return [
        'room_id' => $liveState['room_id'] ?? '',
        'mode' => 'tournament',
        'game_mode' => $liveState['game_mode'] ?? 'standard',
        'status' => 'playing',
        'seq' => 0,
        'phase' => 'spectate_delay',
        'turn' => 0,
        'log' => [],
        'players' => [
            'p1' => tcgTournamentSpectateHoldPlayer(is_array($liveState['players']['p1'] ?? null) ? $liveState['players']['p1'] : null),
            'p2' => tcgTournamentSpectateHoldPlayer(is_array($liveState['players']['p2'] ?? null) ? $liveState['players']['p2'] : null),
        ],
        'tournament' => is_array($liveState['tournament'] ?? null) ? $liveState['tournament'] : [],
        'spectate_hidden_hands' => $liveState['spectate_hidden_hands'] ?? true,
        'spectate_stream_delayed' => true,
        'spectate_stream_waiting' => true,
        'spectate_stream_delay_secs' => $delay,
    ];
}

/**
 * Append a lightweight snapshot for delayed spectate (players unaffected).
 * Keeps ~2 minutes of history at ~2s spacing max.
 */
function tcgTournamentRecordDelayedSnapshot(string $roomId, array $state): void {
    if (($state['mode'] ?? '') !== 'tournament') {
        return;
    }
    $delay = tcgTournamentStreamDelaySecs($state);
    if ($delay <= 0) {
        return;
    }
    $path = tcgTournamentDelayFile($roomId);
    $now = time();
    $ring = [];
    if (is_file($path)) {
        $raw = json_decode((string)@file_get_contents($path), true);
        if (is_array($raw) && isset($raw['entries']) && is_array($raw['entries'])) {
            $ring = $raw['entries'];
        }
    }
    $lastTs = 0;
    if ($ring) {
        $last = $ring[count($ring) - 1];
        $lastTs = (int)($last['ts'] ?? 0);
    }
    if ($lastTs > 0 && ($now - $lastTs) < 2) {
        return;
    }
    $snap = $state;
    foreach (['p1', 'p2'] as $pid) {
        if (!isset($snap['players'][$pid]) || !is_array($snap['players'][$pid])) {
            continue;
        }
        // Keep public pile sizes; strip card faces so delayed spectate cannot leak order.
        $snap['players'][$pid]['main_deck_count'] = tcgTournamentSpectatePileCount($snap['players'][$pid], 'main_deck');
        $snap['players'][$pid]['energy_deck_count'] = tcgTournamentSpectatePileCount($snap['players'][$pid], 'energy_deck');
        unset($snap['players'][$pid]['token'], $snap['players'][$pid]['main_deck'], $snap['players'][$pid]['energy_deck']);
    }
    $ring[] = ['ts' => $now, 'seq' => (int)($state['seq'] ?? 0), 'state' => $snap];
    $keepAfter = $now - max(120, $delay + 30);
    $ring = array_values(array_filter($ring, static fn($e) => (int)($e['ts'] ?? 0) >= $keepAfter));
    if (count($ring) > 90) {
        $ring = array_slice($ring, -90);
    }
    @file_put_contents($path, json_encode([
        'room_id' => strtoupper($roomId),
        'delay_secs' => $delay,
        'entries' => $ring,
    ], JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * For spectators: newest snapshot at least stream_delay_secs old.
 * Never returns live state when delay > 0 — holds until a snapshot is old enough.
 *
 * @return array{state: array, delayed: bool, delay_secs: int, waiting: bool}
 */
function tcgTournamentApplyStreamDelay(string $roomId, array $liveState): array {
    $delay = tcgTournamentStreamDelaySecs($liveState);
    if ($delay <= 0 || ($liveState['mode'] ?? '') !== 'tournament') {
        return ['state' => $liveState, 'delayed' => false, 'delay_secs' => 0, 'waiting' => false];
    }
    $path = tcgTournamentDelayFile($roomId);
    $entries = [];
    if (is_file($path)) {
        $raw = json_decode((string)@file_get_contents($path), true);
        $entries = is_array($raw['entries'] ?? null) ? $raw['entries'] : [];
    }
    $cutoff = time() - $delay;
    $pick = null;
    foreach ($entries as $e) {
        if (!is_array($e)) {
            continue;
        }
        if ((int)($e['ts'] ?? 0) <= $cutoff && isset($e['state']) && is_array($e['state'])) {
            $pick = $e;
        }
    }
    if ($pick === null) {
        $hold = tcgTournamentSpectateHoldState($liveState, $delay);
        return ['state' => $hold, 'delayed' => true, 'delay_secs' => $delay, 'waiting' => true];
    }
    $state = $pick['state'];
    if (!isset($state['spectate_hidden_hands']) && isset($liveState['spectate_hidden_hands'])) {
        $state['spectate_hidden_hands'] = $liveState['spectate_hidden_hands'];
    }
    $state['spectate_stream_delayed'] = true;
    $state['spectate_stream_waiting'] = false;
    $state['spectate_stream_delay_secs'] = $delay;
    return ['state' => $state, 'delayed' => true, 'delay_secs' => $delay, 'waiting' => false];
}

/** Seq the spectator client should key off (0 while holding). */
function tcgTournamentSpectatorViewSeq(string $roomId, array $liveState): int {
    $applied = tcgTournamentApplyStreamDelay($roomId, $liveState);
    if (!empty($applied['waiting'])) {
        return 0;
    }
    return (int)($applied['state']['seq'] ?? 0);
}
