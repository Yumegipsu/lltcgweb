<?php
/**
 * Ranked PvP matchmaking queue (SQLite-backed).
 *
 * Pairs players by ELO band (TCG_RATING_BAND), creates ranked game rooms via
 * ranked_room.php, and tracks queue/active-game rows for reconnect.
 */
require_once __DIR__ . '/db.php';

const TCG_QUEUE_MAX_WAIT = 300;
const TCG_RATING_BAND = 150;

function tcgRankRow(string $discordId): array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT * FROM tcg_rank WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    $now = time();
    $db->prepare('INSERT INTO tcg_rank (discord_id, updated_at) VALUES (?, ?)')->execute([$discordId, $now]);
    return [
        'discord_id' => $discordId,
        'rating' => 1000,
        'wins' => 0,
        'losses' => 0,
        'draws' => 0,
        'games' => 0,
        'updated_at' => $now,
    ];
}

function tcgQueueJoin(string $discordId): array {
    $rank = tcgRankRow($discordId);
    $db = tcgDb();
    $now = time();
    $db->prepare('INSERT INTO tcg_match_queue (discord_id, rating, joined_at) VALUES (?, ?, ?)
        ON CONFLICT(discord_id) DO UPDATE SET rating = excluded.rating, joined_at = excluded.joined_at')
        ->execute([$discordId, intval($rank['rating']), $now]);
    return ['queued' => true, 'rating' => intval($rank['rating']), 'joined_at' => $now];
}

function tcgQueueLeave(string $discordId): array {
    tcgDb()->prepare('DELETE FROM tcg_match_queue WHERE discord_id = ?')->execute([$discordId]);
    return ['queued' => false];
}

function tcgQueueStatus(string $discordId): array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT joined_at, rating FROM tcg_match_queue WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $q = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('SELECT * FROM tcg_ranked_matches WHERE (p1_id = ? OR p2_id = ?) AND status = "pending" ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$discordId, $discordId]);
    $match = tcgSanitizeRankedMatchRow($stmt->fetch(PDO::FETCH_ASSOC));

    if ($match) {
        $isP1 = $match['p1_id'] === $discordId;
        return [
            'status' => 'matched',
            'room_id' => $match['room_id'],
            'player_token' => $isP1 ? $match['p1_token'] : $match['p2_token'],
            'player_id' => $isP1 ? 'p1' : 'p2',
            'opponent_id' => $isP1 ? $match['p2_id'] : $match['p1_id'],
            'match_id' => $match['match_id'],
        ];
    }

    if ($q) {
        $wait = time() - intval($q['joined_at']);
        return [
            'status' => 'searching',
            'rating' => intval($q['rating']),
            'wait_seconds' => $wait,
        ];
    }

    return ['status' => 'idle'];
}

function tcgFindQueueOpponent(string $discordId, int $rating): ?array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT discord_id, rating, joined_at FROM tcg_match_queue
        WHERE discord_id != ?
        ORDER BY ABS(rating - ?) ASC, joined_at ASC
        LIMIT 10');
    $stmt->execute([$discordId, $rating]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($candidates)) {
        return null;
    }
    foreach ($candidates as $c) {
        if (abs(intval($c['rating']) - $rating) <= TCG_RATING_BAND) {
            return $c;
        }
    }
    return $candidates[0];
}

function tcgCreateRankedMatchRecord(string $roomId, string $p1Id, string $p2Id, string $p1Token, string $p2Token): string {
    $db = tcgDb();
    $matchId = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 12));
    $now = time();
    $db->prepare('INSERT INTO tcg_ranked_matches
        (match_id, room_id, p1_id, p2_id, p1_token, p2_token, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, "pending", ?)')
        ->execute([$matchId, $roomId, $p1Id, $p2Id, $p1Token, $p2Token, $now]);
    $db->prepare('DELETE FROM tcg_match_queue WHERE discord_id IN (?, ?)')->execute([$p1Id, $p2Id]);
    return $matchId;
}

function tcgApplyRankResult(string $winnerId, string $loserId, bool $isDraw = false): void {
    $db = tcgDb();
    $now = time();
    if ($isDraw) {
        foreach ([$winnerId, $loserId] as $uid) {
            $db->prepare('UPDATE tcg_rank SET draws = draws + 1, games = games + 1, updated_at = ? WHERE discord_id = ?')
                ->execute([$now, $uid]);
        }
        return;
    }
    $w = tcgRankRow($winnerId);
    $l = tcgRankRow($loserId);
    $wRating = intval($w['rating']);
    $lRating = intval($l['rating']);
    $k = 32;
    $expectedW = 1 / (1 + pow(10, ($lRating - $wRating) / 400));
    $delta = (int)round($k * (1 - $expectedW));
    $db->prepare('UPDATE tcg_rank SET rating = rating + ?, wins = wins + 1, games = games + 1, updated_at = ? WHERE discord_id = ?')
        ->execute([$delta, $now, $winnerId]);
    $db->prepare('UPDATE tcg_rank SET rating = MAX(100, rating - ?), losses = losses + 1, games = games + 1, updated_at = ? WHERE discord_id = ?')
        ->execute([$delta, $now, $loserId]);
}

function tcgCompleteRankedMatch(string $roomId): void {
    tcgDb()->prepare('UPDATE tcg_ranked_matches SET status = "done" WHERE room_id = ?')->execute([$roomId]);
}

/** Drop pending ranked rows whose game file is missing or already finished. */
function tcgSanitizeRankedMatchRow(array|false|null $row): ?array {
    if (!is_array($row)) {
        return null;
    }
    $roomId = $row['room_id'] ?? '';
    if ($roomId === '') {
        return null;
    }
    $path = tcgRankedGameFilePath($roomId);
    if (!is_file($path)) {
        tcgCompleteRankedMatch($roomId);
        return null;
    }
    $state = json_decode((string)file_get_contents($path), true);
    if (!is_array($state) || ($state['mode'] ?? '') !== 'ranked') {
        tcgCompleteRankedMatch($roomId);
        return null;
    }
    if (($state['status'] ?? '') === 'finished') {
        tcgCompleteRankedMatch($roomId);
        return null;
    }
    if (tcgRankedMatchRowIsStale($roomId, $state, $row)) {
        tcgCompleteRankedMatch($roomId);
        return null;
    }
    return $row;
}

/** Clear abandoned ranked rows (no ELO change) so players can queue again. */
function tcgRankedMatchRowIsStale(string $roomId, array $state, array $row): bool {
    $path = tcgRankedGameFilePath($roomId);
    if (!is_file($path)) {
        return true;
    }
    $now = time();
    $fileAge = $now - filemtime($path);
    $created = intval($row['created_at'] ?? 0);
    $matchAge = $created > 0 ? ($now - $created) : $fileAge;

    if ($matchAge >= 6 * 3600) {
        return true;
    }
    if ($fileAge >= 45 * 60) {
        return true;
    }

    $p1Token = $state['players']['p1']['token'] ?? '';
    $p2Token = $state['players']['p2']['token'] ?? '';
    $presenceFile = tcgPath('games') . 'presence_' . preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId)) . '.json';
    if (!is_file($presenceFile)) {
        return $matchAge >= 5 * 60;
    }
    $presence = json_decode((string)file_get_contents($presenceFile), true);
    if (!is_array($presence)) {
        return $matchAge >= 5 * 60;
    }
    $last1 = intval($presence[$p1Token] ?? 0);
    $last2 = intval($presence[$p2Token] ?? 0);
    $latest = max($last1, $last2);
    if ($latest === 0) {
        return $matchAge >= 5 * 60;
    }
    return ($now - $latest) >= 10 * 60 && $fileAge >= 5 * 60;
}

/** Resign or clear a stuck ranked match so the player can return to the hub. */
function tcgAbandonActiveRankedGame(string $discordId): array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT room_id, p1_id, p2_id, p1_token, p2_token FROM tcg_ranked_matches
        WHERE status = "pending" AND (p1_id = ? OR p2_id = ?) ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$discordId, $discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['left' => false];
    }

    $roomId = $row['room_id'] ?? '';
    $isP1 = ($row['p1_id'] ?? '') === $discordId;
    $token = $isP1 ? ($row['p1_token'] ?? '') : ($row['p2_token'] ?? '');

    if ($roomId !== '' && $token !== '') {
        if (!defined('TCG_API_LIB_ONLY')) {
            define('TCG_API_LIB_ONLY', true);
        }
        require_once __DIR__ . '/api.php';
        require_once __DIR__ . '/ranked_room.php';

        try {
            withLock($roomId, function () use ($roomId, $token) {
                $state = loadGame($roomId);
                if (!$state || ($state['status'] ?? '') === 'finished') {
                    return null;
                }
                $playerId = getPlayerIdByToken($state, $token);
                if (!$playerId) {
                    return null;
                }
                $state = applyAction($state, $playerId, 'resign', []);
                saveGame($roomId, $state);
                tcgOnGameFinished($state);
                saveGame($roomId, $state);
                return $state;
            });
        } catch (Throwable $e) {
            // Game file missing or lock failed — still clear the ranked row below.
        }
    }
    tcgCompleteRankedMatch($roomId);

    return ['left' => true, 'room_id' => $roomId];
}

function tcgRankedGameFilePath(string $roomId): string {
    return tcgPath('games') . preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId)) . '.json';
}

/** Public queue stats for the ranked menu (waiting in lobby vs in active ranked games). */
function tcgQueuePublicStats(): array {
    $cacheFile = tcgPath('data') . 'queue_stats_cache.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 5) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['waiting'], $cached['in_game'])) {
            return $cached;
        }
    }

    $db = tcgDb();
    $waiting = (int)$db->query('SELECT COUNT(*) FROM tcg_match_queue')->fetchColumn();

    $inGame = 0;
    $seen = [];
    $stmt = $db->query('SELECT room_id, p1_id, p2_id FROM tcg_ranked_matches WHERE status = "pending"');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $roomId = $row['room_id'] ?? '';
        $path = tcgRankedGameFilePath($roomId);
        if (!is_file($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            continue;
        }
        $state = json_decode($raw, true);
        if (!is_array($state) || ($state['mode'] ?? '') !== 'ranked') {
            continue;
        }
        if (($state['status'] ?? '') === 'finished') {
            if ($roomId !== '') {
                tcgCompleteRankedMatch($roomId);
            }
            continue;
        }
        foreach (['p1_id', 'p2_id'] as $col) {
            $uid = $row[$col] ?? '';
            if ($uid && !isset($seen[$uid])) {
                $seen[$uid] = true;
                $inGame++;
            }
        }
    }

    $stats = ['waiting' => $waiting, 'in_game' => $inGame];
    @file_put_contents($cacheFile, json_encode($stats), LOCK_EX);
    return $stats;
}

/** Active ranked game for a logged-in player (reconnect after refresh / new tab). */
function tcgGetActiveRankedGame(string $discordId): ?array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT room_id, p1_id, p2_id, p1_token, p2_token, created_at FROM tcg_ranked_matches
        WHERE status = "pending" AND (p1_id = ? OR p2_id = ?) ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$discordId, $discordId]);
    $row = tcgSanitizeRankedMatchRow($stmt->fetch(PDO::FETCH_ASSOC));
    if (!$row) {
        return null;
    }
    $roomId = $row['room_id'] ?? '';
    $isP1 = ($row['p1_id'] ?? '') === $discordId;
    return [
        'room_id' => $roomId,
        'player_token' => $isP1 ? ($row['p1_token'] ?? '') : ($row['p2_token'] ?? ''),
        'player_id' => $isP1 ? 'p1' : 'p2',
        'mode' => 'ranked',
    ];
}
