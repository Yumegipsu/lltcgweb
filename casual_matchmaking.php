<?php
/**
 * Unranked casual PvP matchmaking (random opponent queue).
 * No ELO / ranked record — resign freely without account penalties.
 */
require_once __DIR__ . '/db.php';

const TCG_CASUAL_QUEUE_MAX_WAIT = 300;

function tcgCasualGameFilePath(string $roomId): string {
    return tcgPath('games') . preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId)) . '.json';
}

function tcgLoadAuthBootstrap(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    if (is_file(__DIR__ . '/llr_auth_load.php')) {
        require_once __DIR__ . '/llr_auth_load.php';
        return;
    }
    if (is_file(__DIR__ . '/llr_auth.php')) {
        require_once __DIR__ . '/llr_auth.php';
        return;
    }
    require_once __DIR__ . '/llr_auth_offline.php';
}

if (!function_exists('tcgOptionalAuthUserId')) {
    function tcgOptionalAuthUserId(array $body = []): ?string {
        tcgLoadAuthBootstrap();
        $token = tcgReadAuthTokenFromRequest($body);
        if ($token === '') {
            return null;
        }
        $uid = tcgResolveAuthUserId($token);
        return $uid ? (string)$uid : null;
    }
}

function tcgCasualQueueJoin(string $queueKey, array $body): array {
    $queueKey = tcgNormalizeCasualQueueKey($queueKey);
    if ($queueKey === '') {
        throw new Exception('queue_id required');
    }
    $name = trim((string)($body['name'] ?? 'Player'));
    if ($name === '') {
        $name = 'Player';
    }
    $body['name'] = $name;

    if (!defined('TCG_API_LIB_ONLY')) {
        define('TCG_API_LIB_ONLY', true);
    }
    require_once __DIR__ . '/api.php';
    $cards = json_decode((string)file_get_contents(CARDS_FILE), true);
    if (!is_array($cards)) {
        throw new Exception('Card database unavailable');
    }
    resolveRoomDeckLists($body, $cards);

    $discordId = tcgOptionalAuthUserId($body);
    $db = tcgDb();
    $now = time();
    $joinBody = json_encode($body, JSON_UNESCAPED_UNICODE);
    if ($joinBody === false) {
        throw new Exception('Invalid queue payload');
    }

    tcgCasualPurgeExpiredQueue($now);

    if ($discordId) {
        $db->prepare('DELETE FROM tcg_casual_queue WHERE discord_id = ?')->execute([$discordId]);
    }
    $db->prepare('DELETE FROM tcg_casual_queue WHERE queue_key = ?')->execute([$queueKey]);

    $db->prepare('INSERT INTO tcg_casual_queue (queue_key, discord_id, player_name, join_body, joined_at)
        VALUES (?, ?, ?, ?, ?)')
        ->execute([$queueKey, $discordId, $name, $joinBody, $now]);

    return ['queued' => true, 'joined_at' => $now];
}

function tcgCasualQueueLeave(string $queueKey, ?string $discordId = null): array {
    $queueKey = tcgNormalizeCasualQueueKey($queueKey);
    $db = tcgDb();
    if ($queueKey !== '') {
        $db->prepare('DELETE FROM tcg_casual_queue WHERE queue_key = ?')->execute([$queueKey]);
    }
    if ($discordId) {
        $db->prepare('DELETE FROM tcg_casual_queue WHERE discord_id = ?')->execute([$discordId]);
    }
    return ['queued' => false];
}

function tcgCasualQueueLeaveByKey(string $queueKey): void {
    tcgCasualQueueLeave($queueKey, null);
}

function tcgCasualQueueStatus(string $queueKey): array {
    $queueKey = tcgNormalizeCasualQueueKey($queueKey);
    if ($queueKey === '') {
        return ['status' => 'idle'];
    }

    $match = tcgSanitizeCasualMatchRow(tcgCasualMatchRow($queueKey));
    if ($match) {
        return [
            'status' => 'matched',
            'room_id' => $match['room_id'],
            'player_token' => $match['player_token'],
            'player_id' => $match['player_id'],
        ];
    }

    $db = tcgDb();
    tcgCasualPurgeExpiredQueue(time());
    $stmt = $db->prepare('SELECT joined_at FROM tcg_casual_queue WHERE queue_key = ?');
    $stmt->execute([$queueKey]);
    $q = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($q) {
        return [
            'status' => 'searching',
            'wait_seconds' => time() - intval($q['joined_at']),
        ];
    }

    return ['status' => 'idle'];
}

function tcgCasualMatchRow(string $queueKey): ?array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT * FROM tcg_casual_matches WHERE queue_key = ? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$queueKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function tcgSanitizeCasualMatchRow(array|false|null $row): ?array {
    if (!is_array($row)) {
        return null;
    }
    $roomId = (string)($row['room_id'] ?? '');
    $queueKey = (string)($row['queue_key'] ?? '');
    if ($roomId === '' || $queueKey === '') {
        tcgCasualDeleteMatchRow($queueKey);
        return null;
    }
    $path = tcgCasualGameFilePath($roomId);
    if (!is_file($path)) {
        tcgCasualDeleteMatchRow($queueKey);
        return null;
    }
    $state = json_decode((string)file_get_contents($path), true);
    if (!is_array($state) || ($state['mode'] ?? '') === 'ranked') {
        tcgCasualDeleteMatchRow($queueKey);
        return null;
    }
    if (($state['status'] ?? '') === 'finished') {
        tcgCasualDeleteMatchRow($queueKey);
        return null;
    }
    return $row;
}

function tcgCasualDeleteMatchRow(string $queueKey): void {
    if ($queueKey === '') {
        return;
    }
    tcgDb()->prepare('DELETE FROM tcg_casual_matches WHERE queue_key = ?')->execute([$queueKey]);
}

function tcgCasualRecordMatch(string $roomId, string $p1QueueKey, string $p1Token, string $p2QueueKey, string $p2Token): void {
    $db = tcgDb();
    $now = time();
    $db->prepare('INSERT INTO tcg_casual_matches (queue_key, room_id, player_token, player_id, created_at)
        VALUES (?, ?, ?, ?, ?)')
        ->execute([$p1QueueKey, $roomId, $p1Token, 'p1', $now]);
    $db->prepare('INSERT INTO tcg_casual_matches (queue_key, room_id, player_token, player_id, created_at)
        VALUES (?, ?, ?, ?, ?)')
        ->execute([$p2QueueKey, $roomId, $p2Token, 'p2', $now]);
}

function tcgFindCasualOpponent(string $queueKey): ?array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT * FROM tcg_casual_queue WHERE queue_key != ? ORDER BY joined_at ASC LIMIT 1');
    $stmt->execute([$queueKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function tcgTryCasualMatchmake(string $queueKey): ?array {
    $queueKey = tcgNormalizeCasualQueueKey($queueKey);
    if ($queueKey === '') {
        return null;
    }

    $db = tcgDb();
    $stmt = $db->prepare('SELECT * FROM tcg_casual_queue WHERE queue_key = ?');
    $stmt->execute([$queueKey]);
    $self = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$self) {
        return null;
    }

    $opp = tcgFindCasualOpponent($queueKey);
    if (!$opp) {
        return null;
    }

    $p1Row = intval($self['joined_at']) <= intval($opp['joined_at']) ? $self : $opp;
    $p2Row = $p1Row['queue_key'] === $self['queue_key'] ? $opp : $self;

    $pair = tcgCreateCasualRoomPair($p1Row, $p2Row);
    if (!$pair) {
        return null;
    }

    tcgCasualQueueLeaveByKey((string)$p1Row['queue_key']);
    tcgCasualQueueLeaveByKey((string)$p2Row['queue_key']);

    $isP1 = (string)$p1Row['queue_key'] === $queueKey;
    return [
        'status' => 'matched',
        'room_id' => $pair['room_id'],
        'player_token' => $isP1 ? $pair['p1_token'] : $pair['p2_token'],
        'player_id' => $isP1 ? 'p1' : 'p2',
    ];
}

function tcgCreateCasualRoomPair(array $p1Row, array $p2Row): ?array {
    $body1 = json_decode((string)($p1Row['join_body'] ?? ''), true);
    $body2 = json_decode((string)($p2Row['join_body'] ?? ''), true);
    if (!is_array($body1) || !is_array($body2)) {
        return null;
    }

    if (!defined('TCG_API_LIB_ONLY')) {
        define('TCG_API_LIB_ONLY', true);
    }
    require_once __DIR__ . '/api.php';

    $cards = json_decode((string)file_get_contents(CARDS_FILE), true);
    if (!is_array($cards)) {
        return null;
    }

    try {
        $resolved1 = resolveRoomDeckLists($body1, $cards);
        $resolved2 = resolveRoomDeckLists($body2, $cards);
    } catch (Throwable $e) {
        tcgCasualQueueLeaveByKey((string)($p1Row['queue_key'] ?? ''));
        tcgCasualQueueLeaveByKey((string)($p2Row['queue_key'] ?? ''));
        return null;
    }

    $roomId = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
    $p1Token = generateToken();
    $p2Token = generateToken();

    $p1Name = htmlspecialchars(trim((string)($body1['name'] ?? $p1Row['player_name'] ?? 'Player 1')), ENT_QUOTES);
    $p2Name = htmlspecialchars(trim((string)($body2['name'] ?? $p2Row['player_name'] ?? 'Player 2')), ENT_QUOTES);

    $main1 = buildDeckForRoom($cards['cards'], $resolved1['main_nos'], $body1, 'main_order');
    $energy1 = buildDeckForRoom($cards['cards'], $resolved1['energy_nos'], $body1, 'energy_order');
    shuffle($main1);
    shuffle($energy1);

    $state = initGameState($roomId, [
        'id' => 'p1',
        'token' => $p1Token,
        'name' => $p1Name,
        'deck_choice' => $resolved1['deck_choice'],
        'deck_label' => $resolved1['deck_label'],
        'main_deck' => $main1,
        'energy_deck' => $energy1,
    ]);
    $state['phase_timer_cfg'] = parsePhaseTimerConfigFromBody($body1);

    $main2 = buildDeckForRoom($cards['cards'], $resolved2['main_nos'], $body2, 'main_order');
    $energy2 = buildDeckForRoom($cards['cards'], $resolved2['energy_nos'], $body2, 'energy_order');
    shuffle($main2);
    shuffle($energy2);

    $state = addSecondPlayer($state, [
        'id' => 'p2',
        'token' => $p2Token,
        'name' => $p2Name,
        'deck_choice' => $resolved2['deck_choice'],
        'deck_label' => $resolved2['deck_label'],
        'main_deck' => $main2,
        'energy_deck' => $energy2,
    ], null);

    saveGame($roomId, $state);

    tcgCasualRecordMatch(
        $roomId,
        (string)$p1Row['queue_key'],
        $p1Token,
        (string)$p2Row['queue_key'],
        $p2Token
    );

    return [
        'room_id' => $roomId,
        'p1_token' => $p1Token,
        'p2_token' => $p2Token,
        'p1_queue_key' => (string)$p1Row['queue_key'],
        'p2_queue_key' => (string)$p2Row['queue_key'],
    ];
}

function tcgCasualPurgeExpiredQueue(int $now): void {
    $cutoff = $now - TCG_CASUAL_QUEUE_MAX_WAIT;
    tcgDb()->prepare('DELETE FROM tcg_casual_queue WHERE joined_at < ?')->execute([$cutoff]);
}

function tcgCasualQueuePublicStats(): array {
    $cacheFile = tcgPath('data') . 'casual_queue_stats_cache.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 5) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['waiting'], $cached['in_game'])) {
            return $cached;
        }
    }

    $waiting = (int)tcgDb()->query('SELECT COUNT(*) FROM tcg_casual_queue')->fetchColumn();
    $inGame = tcgCasualActivePvpPlayerCount();
    $stats = ['waiting' => $waiting, 'in_game' => $inGame];
    @file_put_contents($cacheFile, json_encode($stats), LOCK_EX);
    return $stats;
}

/** True when this room is an active unranked human-vs-human casual match (not CPU / replay). */
function tcgCasualHumanPvpRoom(array $state): bool {
    if (($state['mode'] ?? '') === 'ranked' || ($state['mode'] ?? '') === 'replay_view') {
        return false;
    }
    if (!empty($state['cpu_difficulty']) || !empty($state['cpu_solo'])) {
        return false;
    }
    if (($state['status'] ?? '') === 'finished') {
        return false;
    }
    return isPvpMatch($state);
}

/** Live human players in one unranked PvP room (presence-aware; excludes stale abandoned games). */
function tcgCasualLivePlayersInRoom(array $state, string $roomId, int $now): int {
    if (!tcgCasualHumanPvpRoom($state)) {
        return 0;
    }

    $presence = readPresence($roomId);
    $path = tcgCasualGameFilePath($roomId);
    $gameAge = is_file($path) ? ($now - filemtime($path)) : 0;
    $grace = PRESENCE_DISCONNECT_SEC;
    $noShowSec = PRESENCE_NO_SHOW_SEC * 2;

    $live = 0;
    $anyPresenceEver = false;
    foreach (['p1', 'p2'] as $pid) {
        $player = $state['players'][$pid] ?? null;
        if (!$player || isCpuPlayer($player)) {
            continue;
        }
        $token = (string)($player['token'] ?? '');
        if ($token === '') {
            continue;
        }
        $last = intval($presence[$token] ?? 0);
        if ($last > 0) {
            $anyPresenceEver = true;
            if (($now - $last) < $grace) {
                $live++;
            }
        }
    }

    if ($live === 0 && !$anyPresenceEver && $gameAge < $grace) {
        foreach (['p1', 'p2'] as $pid) {
            $player = $state['players'][$pid] ?? null;
            if ($player && !isCpuPlayer($player)) {
                $live++;
            }
        }
        return $live;
    }

    if ($live === 0 && $gameAge < $noShowSec) {
        foreach (['p1', 'p2'] as $pid) {
            $player = $state['players'][$pid] ?? null;
            if (!$player || isCpuPlayer($player)) {
                continue;
            }
            $token = (string)($player['token'] ?? '');
            $last = intval($presence[$token] ?? 0);
            if ($last > 0 && ($now - $last) < 60) {
                $live++;
            }
        }
    }

    return $live;
}

/** Players currently in active unranked human PvP games (friend codes + casual queue). */
function tcgCasualActivePvpPlayerCount(): int {
    if (!defined('TCG_API_LIB_ONLY')) {
        define('TCG_API_LIB_ONLY', true);
    }
    require_once __DIR__ . '/api.php';

    $inGame = 0;
    $now = time();
    $files = glob(GAMES_DIR . '*.json') ?: [];
    foreach ($files as $file) {
        $base = basename($file);
        if (str_starts_with($base, 'lock_') || str_starts_with($base, 'presence_')) {
            continue;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            continue;
        }
        $state = json_decode($raw, true);
        if (!is_array($state)) {
            continue;
        }
        $roomId = (string)($state['room_id'] ?? pathinfo($base, PATHINFO_FILENAME));
        if ($roomId === '') {
            continue;
        }
        $inGame += tcgCasualLivePlayersInRoom($state, $roomId, $now);
    }
    return $inGame;
}

function tcgNormalizeCasualQueueKey(string $key): string {
    $key = trim($key);
    if ($key === '' || strlen($key) > 64) {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
        return '';
    }
    return $key;
}

function apiCasualJoin(array $body): array {
    tcgRateLimitForAction('casual_join', $body);
    $queueKey = (string)($body['queue_id'] ?? '');
    $join = tcgCasualQueueJoin($queueKey, $body);
    $match = tcgTryCasualMatchmake($queueKey);
    $out = [
        'success' => true,
        'queue' => $join,
        'match' => $match,
        'queue_stats' => tcgCasualQueuePublicStats(),
    ];
    if (!$match) {
        $out['casual'] = tcgCasualQueueStatus($queueKey);
    } else {
        $out['casual'] = $match;
    }
    return $out;
}

function apiCasualLeave(array $body): array {
    $queueKey = (string)($body['queue_id'] ?? '');
    $discordId = tcgOptionalAuthUserId($body);
    return [
        'success' => true,
        'queue' => tcgCasualQueueLeave($queueKey, $discordId),
        'queue_stats' => tcgCasualQueuePublicStats(),
    ];
}

function apiCasualStatus(array $body): array {
    $queueKey = (string)($body['queue_id'] ?? '');
    if ($queueKey === '' && isset($_GET['queue_id'])) {
        $queueKey = (string)$_GET['queue_id'];
    }
    $status = tcgCasualQueueStatus($queueKey);
    if (($status['status'] ?? '') === 'searching') {
        $match = tcgTryCasualMatchmake($queueKey);
        if ($match) {
            $status = $match;
        }
    }
    return [
        'success' => true,
        'casual' => $status,
        'queue_stats' => tcgCasualQueuePublicStats(),
    ];
}
