<?php
/**
 * Unranked casual PvP matchmaking (random opponent queue).
 * No ELO / ranked record — resign freely without account penalties.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/game_mode.php';

const TCG_CASUAL_QUEUE_MAX_WAIT = 300;
const TCG_CASUAL_LOCK_TIMEOUT_MS = 5000;

/**
 * Serialize each complete queue operation (join + match + cleanup).
 *
 * Those steps span several SQLite statements and game-file writes. Allowing
 * multiple PHP workers to interleave them caused lock storms where every
 * request waited for SQLite's 15-second busy timeout. A short outer file lock
 * keeps the critical section atomic and leaves the account DB available.
 *
 * @template T
 * @param callable():T $fn
 * @return T
 */
function tcgWithCasualQueueLock(callable $fn) {
    $path = rtrim(tcgPath('data'), '/\\') . DIRECTORY_SEPARATOR . 'casual_queue.lock';
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Casual queue is temporarily unavailable');
    }

    $deadline = microtime(true) + (TCG_CASUAL_LOCK_TIMEOUT_MS / 1000);
    $locked = false;
    do {
        $locked = flock($handle, LOCK_EX | LOCK_NB);
        if (!$locked) {
            usleep(20000);
        }
    } while (!$locked && microtime(true) < $deadline);

    if (!$locked) {
        fclose($handle);
        throw new RuntimeException('Casual queue is busy; please try again');
    }

    try {
        return $fn();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

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
        // Prefer header / auth_token over body.token (seat token on game actions).
        $candidates = [];
        $hdr = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (is_string($hdr) && stripos($hdr, 'Bearer ') === 0) {
            $hdr = trim(substr($hdr, 7));
        } elseif (is_string($hdr)) {
            $hdr = trim($hdr);
        } else {
            $hdr = '';
        }
        if ($hdr !== '') {
            $candidates[] = $hdr;
        }
        $explicit = trim((string)($body['auth_token'] ?? ''));
        if ($explicit !== '') {
            $candidates[] = $explicit;
        }
        $bodyTok = trim((string)($body['token'] ?? $_GET['token'] ?? ''));
        if ($bodyTok !== '') {
            $candidates[] = $bodyTok;
        }
        foreach ($candidates as $token) {
            $uid = tcgResolveAuthUserId($token);
            if ($uid) {
                return (string)$uid;
            }
        }
        return null;
    }
}

function tcgCasualQueueJoin(string $queueKey, array $body): array {
    require_once __DIR__ . '/game_mode.php';
    $queueKey = tcgNormalizeCasualQueueKey($queueKey);
    if ($queueKey === '') {
        throw new Exception('queue_id required');
    }
    $name = trim((string)($body['name'] ?? 'Player'));
    if ($name === '') {
        $name = 'Player';
    }
    $body['name'] = $name;
    $gameMode = tcgNormalizeGameMode($body['game_mode'] ?? TCG_GAME_MODE_STANDARD);
    $body['game_mode'] = $gameMode;

    if (!defined('TCG_API_LIB_ONLY')) {
        define('TCG_API_LIB_ONLY', true);
    }
    require_once __DIR__ . '/api.php';
    $cards = tcgLoadCardsData();
    if (!is_array($cards) || !isset($cards['cards'])) {
        throw new Exception('Card database unavailable');
    }

    $discordId = tcgOptionalAuthUserId($body);
    if ($gameMode === TCG_GAME_MODE_RANDOMIZED) {
        $body['deck'] = 'random';
        unset($body['deck_group'], $body['experiment_password'], $body['experiment_preset'], $body['main_deck'], $body['energy_deck']);
    } elseif ($gameMode === TCG_GAME_MODE_STARTERS) {
        tcgValidateCasualStartersModeDeck($body, $discordId, $cards);
    } elseif ($gameMode === TCG_GAME_MODE_FREE) {
        tcgValidateCasualFreeModeDeck($body);
    }
    resolveRoomDeckLists($body, $cards);

    $now = time();
    $joinBody = json_encode($body, JSON_UNESCAPED_UNICODE);
    if ($joinBody === false) {
        throw new Exception('Invalid queue payload');
    }

    return tcgDbRetry(function () use ($queueKey, $discordId, $name, $joinBody, $now, $gameMode) {
        $db = tcgDb();
        tcgCasualPurgeExpiredQueue($now);

        if ($discordId) {
            $db->prepare('DELETE FROM tcg_casual_queue WHERE discord_id = ?')->execute([$discordId]);
        }
        $db->prepare('DELETE FROM tcg_casual_queue WHERE queue_key = ?')->execute([$queueKey]);

        $db->prepare('INSERT INTO tcg_casual_queue (queue_key, discord_id, player_name, join_body, joined_at, game_mode)
            VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$queueKey, $discordId, $name, $joinBody, $now, $gameMode]);

        return ['queued' => true, 'joined_at' => $now, 'game_mode' => $gameMode];
    });
}

/** Ensure casual starters-mode join uses an owned starter (signed-in) or catalog starter (guest). */
function tcgValidateCasualStartersModeDeck(array $body, ?string $discordId, array $cards): void {
    require_once __DIR__ . '/booster.php';
    $deck = trim((string)($body['deck'] ?? ''));
    if ($deck === '' || str_starts_with($deck, 'preset') || str_starts_with($deck, 'experiment')) {
        throw new Exception('Starter decks only mode requires a starter deck');
    }
    if ($deck === 'random' || $deck === 'cpu') {
        throw new Exception('Starter decks only mode requires a starter deck');
    }
    $starterKeys = tcgStarterDeckKeys();
    if (!in_array($deck, $starterKeys, true)) {
        throw new Exception('Starter decks only mode requires a starter deck');
    }
    if ($discordId) {
        if (!in_array($deck, tcgOwnedStarterKeys($discordId), true)) {
            throw new Exception('You do not own that starter deck');
        }
    }
}

/** Free Mode: Deck Experiment (password / experiment preset) or saved account preset. */
function tcgValidateCasualFreeModeDeck(array $body): void {
    require_once __DIR__ . '/experiment_decks.php';
    if (tcgBodyUsesExperimentDeck($body) || tcgBodyUsesAccountPresetDeck($body)) {
        return;
    }
    throw new Exception('Free requires a Deck Experiment deck or a saved account deck');
}

function tcgCasualQueueLeave(string $queueKey, ?string $discordId = null): array {
    $queueKey = tcgNormalizeCasualQueueKey($queueKey);
    return tcgDbRetry(function () use ($queueKey, $discordId) {
        $db = tcgDb();
        if ($queueKey !== '') {
            $db->prepare('DELETE FROM tcg_casual_queue WHERE queue_key = ?')->execute([$queueKey]);
        }
        if ($discordId) {
            $db->prepare('DELETE FROM tcg_casual_queue WHERE discord_id = ?')->execute([$discordId]);
        }
        return ['queued' => false];
    });
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
    tcgCasualEnsureApiHelpers();
    $state = null;
    $path = tcgCasualGameFilePath($roomId);
    if (is_file($path)) {
        $state = json_decode((string)file_get_contents($path), true);
    } elseif (function_exists('loadGame')) {
        // Match-primary: casual rooms live in Redis, not games/*.json.
        $state = loadGame($roomId);
    }
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

function tcgFindCasualOpponent(string $queueKey, string $gameMode = TCG_GAME_MODE_STANDARD): ?array {
    require_once __DIR__ . '/game_mode.php';
    $gameMode = tcgNormalizeGameMode($gameMode);
    $db = tcgDb();
    $stmt = $db->prepare('SELECT * FROM tcg_casual_queue WHERE queue_key != ? AND game_mode = ? ORDER BY joined_at ASC LIMIT 1');
    $stmt->execute([$queueKey, $gameMode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function tcgFindCasualOpponentByDiscordId(
    string $challengeDiscordId,
    string $selfQueueKey,
    string $gameMode = TCG_GAME_MODE_STANDARD
): ?array {
    require_once __DIR__ . '/game_mode.php';
    $gameMode = tcgNormalizeGameMode($gameMode);
    $challengeDiscordId = trim($challengeDiscordId);
    if ($challengeDiscordId === '') {
        return null;
    }
    $db = tcgDb();
    $stmt = $db->prepare('SELECT * FROM tcg_casual_queue WHERE discord_id = ? AND queue_key != ? AND game_mode = ? ORDER BY joined_at ASC LIMIT 1');
    $stmt->execute([$challengeDiscordId, $selfQueueKey, $gameMode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function tcgTryCasualMatchmake(string $queueKey, ?string $challengeDiscordId = null): ?array {
    $queueKey = tcgNormalizeCasualQueueKey($queueKey);
    if ($queueKey === '') {
        return null;
    }

    return tcgDbRetry(function () use ($queueKey, $challengeDiscordId) {
        $db = tcgDb();
        $stmt = $db->prepare('SELECT * FROM tcg_casual_queue WHERE queue_key = ?');
        $stmt->execute([$queueKey]);
        $self = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$self) {
            return null;
        }

        $selfMode = tcgNormalizeGameMode($self['game_mode'] ?? TCG_GAME_MODE_STANDARD);
        $opp = null;
        if ($challengeDiscordId !== null && $challengeDiscordId !== '') {
            $opp = tcgFindCasualOpponentByDiscordId($challengeDiscordId, $queueKey, $selfMode);
            if (!$opp) {
                return null;
            }
        } else {
            $opp = tcgFindCasualOpponent($queueKey, $selfMode);
            if (!$opp) {
                return null;
            }
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
    });
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

    $cards = tcgLoadCardsData();
    if (!is_array($cards) || !isset($cards['cards'])) {
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
        'sleeve_id' => tcgNormalizeSleeveId($resolved1['sleeve_id'] ?? ($body1['sleeve_id'] ?? '')),
        'playmat_id' => tcgNormalizePlaymatId($resolved1['playmat_id'] ?? ($body1['playmat_id'] ?? '')),
        'playmat_brightness' => tcgNormalizePlaymatBrightness($resolved1['playmat_brightness'] ?? ($body1['playmat_brightness'] ?? 1.0)),
        'discord_id' => (string)($p1Row['discord_id'] ?? '') ?: null,
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
        'sleeve_id' => tcgNormalizeSleeveId($resolved2['sleeve_id'] ?? ($body2['sleeve_id'] ?? '')),
        'playmat_id' => tcgNormalizePlaymatId($resolved2['playmat_id'] ?? ($body2['playmat_id'] ?? '')),
        'playmat_brightness' => tcgNormalizePlaymatBrightness($resolved2['playmat_brightness'] ?? ($body2['playmat_brightness'] ?? 1.0)),
        'discord_id' => (string)($p2Row['discord_id'] ?? '') ?: null,
    ], null);

    $gameMode = tcgNormalizeGameMode(
        $p1Row['game_mode'] ?? ($body1['game_mode'] ?? TCG_GAME_MODE_STANDARD)
    );
    $state['game_mode'] = $gameMode;

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

function tcgCasualEnsureApiHelpers(): void {
    if (function_exists('isPvpMatch') && function_exists('readPresence') && function_exists('isCpuPlayer')) {
        return;
    }
    if (!defined('TCG_API_LIB_ONLY')) {
        define('TCG_API_LIB_ONLY', true);
    }
    require_once __DIR__ . '/api.php';
}

/**
 * Live unranked human-PvP player count without scanning every games/*.json.
 * Queue-matched rooms only (friend-code games are omitted from the public counter
 * so lobby polls never walk the full games archive on shared hosting).
 *
 * @param string|null $gameMode When set, only count rooms for that PvP mode.
 */
function tcgCasualCountLivePvpPlayers(?string $gameMode = null): int {
    tcgCasualEnsureApiHelpers();
    require_once __DIR__ . '/game_mode.php';

    $wantMode = ($gameMode !== null && $gameMode !== '')
        ? tcgNormalizeGameMode($gameMode)
        : null;
    $now = time();
    $inGame = 0;
    $seenRooms = [];
    $db = tcgDb();

    $stmt = $db->query('SELECT DISTINCT room_id FROM tcg_casual_matches');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $roomId = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($row['room_id'] ?? '')));
        if ($roomId === '' || isset($seenRooms[$roomId])) {
            continue;
        }
        $seenRooms[$roomId] = true;
        $state = null;
        $path = tcgCasualGameFilePath($roomId);
        if (is_file($path)) {
            $state = json_decode((string)file_get_contents($path), true);
        } elseif (function_exists('loadGame')) {
            // Match-primary Redis rooms have no local games/*.json.
            $state = loadGame($roomId);
        }
        if (!is_array($state) || ($state['status'] ?? '') === 'finished') {
            $db->prepare('DELETE FROM tcg_casual_matches WHERE room_id = ?')->execute([$roomId]);
            continue;
        }
        if ($wantMode !== null) {
            $roomMode = tcgNormalizeGameMode($state['game_mode'] ?? TCG_GAME_MODE_STANDARD);
            if ($roomMode !== $wantMode) {
                continue;
            }
        }
        $inGame += tcgCasualLivePlayersInRoom($state, $roomId, $now);
    }

    return $inGame;
}

/**
 * Casual modes (other than $currentMode) that currently have ≥1 player waiting.
 *
 * @return list<string>
 */
function tcgCasualOtherModesWithWaiting(string $currentMode): array {
    require_once __DIR__ . '/game_mode.php';
    $currentMode = tcgNormalizeGameMode($currentMode);
    $db = tcgDb();
    $stmt = $db->query('SELECT game_mode, COUNT(*) AS c FROM tcg_casual_queue GROUP BY game_mode');
    if ($stmt === false) {
        return [];
    }
    $counts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $m = tcgNormalizeGameMode($row['game_mode'] ?? '');
        $counts[$m] = ($counts[$m] ?? 0) + (int)($row['c'] ?? 0);
    }
    $out = [];
    foreach (tcgGameModeIds() as $m) {
        if ($m === $currentMode) {
            continue;
        }
        if (($counts[$m] ?? 0) > 0) {
            $out[] = $m;
        }
    }
    return $out;
}

function tcgCasualQueuePublicStats(?string $gameMode = null): array {
    $stats = tcgCasualQueuePublicStatsCore($gameMode);
    // Players queueing in the Ranked menu, so the Unranked menu can point at them.
    $stats['other_menu_waiting'] = tcgCasualOtherMenuWaiting();
    return $stats;
}

/** Ranked queue size across every mode (excluding players already paired); 0 when unavailable. */
function tcgCasualOtherMenuWaiting(): int {
    try {
        $stmt = tcgDb()->query(
            'SELECT COUNT(*) FROM tcg_match_queue q
             WHERE NOT EXISTS (
               SELECT 1 FROM tcg_ranked_matches m
               WHERE m.status = "pending"
                 AND (m.p1_id = q.discord_id OR m.p2_id = q.discord_id)
             )'
        );
        return $stmt === false ? 0 : (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function tcgCasualQueuePublicStatsCore(?string $gameMode = null): array {
    require_once __DIR__ . '/game_mode.php';
    $gameMode = ($gameMode !== null && $gameMode !== '')
        ? tcgNormalizeGameMode($gameMode)
        : TCG_GAME_MODE_STANDARD;
    $cacheFile = tcgPath('data') . 'casual_queue_stats_cache_'
        . preg_replace('/[^a-z0-9_]/', '', $gameMode) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 15) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['waiting'], $cached['in_game'])) {
            // Always refresh other-mode waiting — GROUP BY is cheap and avoids stale hints.
            $cached['other_modes_waiting'] = tcgCasualOtherModesWithWaiting($gameMode);
            $cached['game_mode'] = $gameMode;
            return $cached;
        }
    }

    $db = tcgDb();
    $stmt = $db->prepare('SELECT COUNT(*) FROM tcg_casual_queue WHERE game_mode = ?');
    $stmt->execute([$gameMode]);
    $waiting = (int)$stmt->fetchColumn();
    $inGame = tcgCasualCountLivePvpPlayers($gameMode);
    $stats = [
        'waiting' => $waiting,
        'in_game' => $inGame,
        'game_mode' => $gameMode,
        'other_modes_waiting' => tcgCasualOtherModesWithWaiting($gameMode),
    ];
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
    $hasLocalFile = is_file($path);
    $gameAge = $hasLocalFile ? ($now - filemtime($path)) : null;
    $grace = PRESENCE_DISCONNECT_SEC;
    $noShowSec = PRESENCE_NO_SHOW_SEC * 2;

    $live = 0;
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
        if ($last > 0 && ($now - $last) < $grace) {
            $live++;
        }
    }

    if ($live > 0) {
        return $live;
    }

    // Local/snapshot file only — Redis rooms without a file must not use age=0 as "fresh".
    if ($hasLocalFile && $gameAge !== null && $gameAge < $grace) {
        foreach (['p1', 'p2'] as $pid) {
            $player = $state['players'][$pid] ?? null;
            if ($player && !isCpuPlayer($player)) {
                $live++;
            }
        }
        return $live;
    }

    if (!$hasLocalFile) {
        $presenceFile = GAMES_DIR . 'presence_'
            . preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId)) . '.json';
        if (is_file($presenceFile) && ($now - filemtime($presenceFile)) < $grace) {
            foreach (['p1', 'p2'] as $pid) {
                $player = $state['players'][$pid] ?? null;
                if ($player && !isCpuPlayer($player)) {
                    $live++;
                }
            }
        }
        return $live;
    }

    if ($gameAge !== null && $gameAge < $noShowSec) {
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
    return tcgCasualCountLivePvpPlayers();
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

function tcgCasualGameModeFromBody(array $body): string {
    require_once __DIR__ . '/game_mode.php';
    return tcgNormalizeGameMode($body['game_mode'] ?? ($_GET['game_mode'] ?? TCG_GAME_MODE_STANDARD));
}

function apiCasualJoin(array $body): array {
    tcgRateLimitForAction('casual_join', $body);
    $gameMode = tcgCasualGameModeFromBody($body);
    $body['game_mode'] = $gameMode;
    $selfDiscordId = tcgOptionalAuthUserId($body);
    if ($selfDiscordId) {
        require_once __DIR__ . '/matchmaking.php';
        // Sanitize finished/missing first, then block on a truly live ranked seat.
        if (tcgGetActiveRankedGame($selfDiscordId) || tcgDiscordIdHasPendingRankedMatch($selfDiscordId)) {
            throw new Exception(
                'You still have an active ranked match. Finish or resign it before joining unranked queue.',
                409
            );
        }
    }
    $out = tcgWithCasualQueueLock(function () use ($body): array {
        $queueKey = (string)($body['queue_id'] ?? '');
        $challengeId = trim((string)($body['challenge_discord_id'] ?? ''));
        $selfDiscordId = tcgOptionalAuthUserId($body);
        if ($challengeId !== '') {
            if (!$selfDiscordId) {
                throw new Exception('Sign in to accept a match challenge', 401);
            }
            if ($challengeId === $selfDiscordId) {
                throw new Exception('You cannot challenge yourself', 400);
            }
        }
        $join = tcgCasualQueueJoin($queueKey, $body);
        if ($challengeId !== '') {
            $match = tcgTryCasualMatchmake($queueKey, $challengeId);
            if (!$match) {
                tcgCasualQueueLeave($queueKey, $selfDiscordId);
                throw new Exception('That player is no longer waiting for an unranked match', 409);
            }
            return [
                'success' => true,
                'queue' => $join,
                'match' => $match,
                'casual' => $match,
            ];
        }
        $match = tcgTryCasualMatchmake($queueKey);
        $payload = [
            'success' => true,
            'queue' => $join,
            'match' => $match,
        ];
        if (!$match) {
            $payload['casual'] = tcgCasualQueueStatus($queueKey);
        } else {
            $payload['casual'] = $match;
        }
        return $payload;
    });
    if (empty($out['match']) && $selfDiscordId) {
        if (!function_exists('tcgPushNotifyFriendsQueued')) {
            require_once __DIR__ . '/push.php';
        }
        tcgPushNotifyFriendsQueued($selfDiscordId, 'unranked', $gameMode);
    }
    $out['queue_stats'] = tcgCasualQueuePublicStats($gameMode);
    $out['game_mode'] = $gameMode;
    return $out;
}

function apiCasualLeave(array $body): array {
    $gameMode = tcgCasualGameModeFromBody($body);
    $out = tcgWithCasualQueueLock(function () use ($body): array {
        $queueKey = (string)($body['queue_id'] ?? '');
        $discordId = tcgOptionalAuthUserId($body);
        return [
            'success' => true,
            'queue' => tcgCasualQueueLeave($queueKey, $discordId),
        ];
    });
    $out['queue_stats'] = tcgCasualQueuePublicStats($gameMode);
    $out['game_mode'] = $gameMode;
    return $out;
}

function apiCasualStatus(array $body): array {
    $gameMode = tcgCasualGameModeFromBody($body);
    $out = tcgWithCasualQueueLock(function () use ($body): array {
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
        ];
    });
    $out['queue_stats'] = tcgCasualQueuePublicStats($gameMode);
    $out['game_mode'] = $gameMode;
    return $out;
}
