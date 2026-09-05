<?php
/**
 * Ranked PvP matchmaking queue (SQLite-backed).
 *
 * Pairs players by ELO band (TCG_RATING_BAND) within the same game_mode, creates
 * ranked game rooms via ranked_room.php, and tracks queue/active-game rows for reconnect.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/game_mode.php';

const TCG_QUEUE_MAX_WAIT = 300;
const TCG_RATING_BAND = 150;

function tcgRankRow(string $discordId, string $gameMode = TCG_GAME_MODE_STANDARD): array {
    $gameMode = tcgNormalizeGameMode($gameMode);
    $db = tcgDb();
    $stmt = $db->prepare('SELECT * FROM tcg_rank WHERE discord_id = ? AND game_mode = ?');
    $stmt->execute([$discordId, $gameMode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    $now = time();
    $db->prepare('INSERT INTO tcg_rank (discord_id, game_mode, updated_at) VALUES (?, ?, ?)')
        ->execute([$discordId, $gameMode, $now]);
    return [
        'discord_id' => $discordId,
        'game_mode' => $gameMode,
        'rating' => 1000,
        'wins' => 0,
        'losses' => 0,
        'draws' => 0,
        'games' => 0,
        'updated_at' => $now,
    ];
}

function tcgQueueJoin(string $discordId, string $gameMode = TCG_GAME_MODE_STANDARD): array {
    $gameMode = tcgNormalizeGameMode($gameMode);
    return tcgDbRetry(function () use ($discordId, $gameMode) {
        $rank = tcgRankRow($discordId, $gameMode);
        $db = tcgDb();
        $now = time();
        // One active ranked search at a time across modes.
        $db->prepare('DELETE FROM tcg_match_queue WHERE discord_id = ?')->execute([$discordId]);
        $db->prepare('INSERT INTO tcg_match_queue (discord_id, game_mode, rating, joined_at) VALUES (?, ?, ?, ?)
            ON CONFLICT(discord_id, game_mode) DO UPDATE SET rating = excluded.rating, joined_at = excluded.joined_at')
            ->execute([$discordId, $gameMode, intval($rank['rating']), $now]);
        return [
            'queued' => true,
            'rating' => intval($rank['rating']),
            'joined_at' => $now,
            'game_mode' => $gameMode,
        ];
    });
}

/** Raw pending ranked match row exists (no VPS probe). */
function tcgDiscordIdHasPendingRankedMatch(string $discordId): bool {
    $db = tcgDb();
    $stmt = $db->prepare(
        'SELECT 1 FROM tcg_ranked_matches WHERE status = "pending" AND (p1_id = ? OR p2_id = ?) LIMIT 1'
    );
    $stmt->execute([$discordId, $discordId]);
    return (bool)$stmt->fetchColumn();
}

/** Drop queue rows for anyone who already has a pending ranked seat (stale after buggy joins). */
function tcgPurgeQueuedPlayersWithPendingMatches(): void {
    $db = tcgDb();
    $ids = $db->query(
        'SELECT p1_id AS id FROM tcg_ranked_matches WHERE status = "pending"
         UNION
         SELECT p2_id AS id FROM tcg_ranked_matches WHERE status = "pending"'
    )->fetchAll(PDO::FETCH_COLUMN);
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("DELETE FROM tcg_match_queue WHERE discord_id IN ($placeholders)")->execute($ids);
}

/**
 * Atomically claim both players from the ranked queue before slow VPS seed.
 * Returns false if either seat is gone (another worker paired them) or either
 * already has a pending ranked match.
 */
function tcgClaimRankedQueuePair(string $p1Id, string $p2Id, string $gameMode): bool {
    $gameMode = tcgNormalizeGameMode($gameMode);
    if ($p1Id === '' || $p2Id === '' || $p1Id === $p2Id) {
        return false;
    }
    return (bool)tcgDbRetry(function () use ($p1Id, $p2Id, $gameMode) {
        $db = tcgDb();
        $db->beginTransaction();
        try {
            if (tcgDiscordIdHasPendingRankedMatch($p1Id) || tcgDiscordIdHasPendingRankedMatch($p2Id)) {
                $db->rollBack();
                return false;
            }
            $stmt = $db->prepare(
                'SELECT discord_id FROM tcg_match_queue WHERE discord_id = ? AND game_mode = ?'
            );
            $stmt->execute([$p1Id, $gameMode]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $db->rollBack();
                return false;
            }
            $stmt->execute([$p2Id, $gameMode]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $db->rollBack();
                return false;
            }
            $db->prepare('DELETE FROM tcg_match_queue WHERE discord_id IN (?, ?)')->execute([$p1Id, $p2Id]);
            $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    });
}

function tcgQueueLeave(string $discordId, ?string $gameMode = null): array {
    $db = tcgDb();
    if ($gameMode === null || $gameMode === '') {
        $db->prepare('DELETE FROM tcg_match_queue WHERE discord_id = ?')->execute([$discordId]);
    } else {
        $gameMode = tcgNormalizeGameMode($gameMode);
        $db->prepare('DELETE FROM tcg_match_queue WHERE discord_id = ? AND game_mode = ?')
            ->execute([$discordId, $gameMode]);
    }
    return ['queued' => false];
}

function tcgQueueStatus(string $discordId): array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT joined_at, rating, game_mode FROM tcg_match_queue WHERE discord_id = ? LIMIT 1');
    $stmt->execute([$discordId]);
    $q = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('SELECT * FROM tcg_ranked_matches WHERE (p1_id = ? OR p2_id = ?) AND status = "pending" ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$discordId, $discordId]);
    $match = tcgSanitizeRankedMatchRow($stmt->fetch(PDO::FETCH_ASSOC));

    if ($match) {
        $isP1 = $match['p1_id'] === $discordId;
        $roomId = (string)($match['room_id'] ?? '');
        $localFile = $roomId !== '' && is_file(tcgRankedGameFilePath($roomId));
        return [
            'status' => 'matched',
            'room_id' => $match['room_id'],
            'player_token' => $isP1 ? $match['p1_token'] : $match['p2_token'],
            'player_id' => $isP1 ? 'p1' : 'p2',
            'opponent_id' => $isP1 ? $match['p2_id'] : $match['p1_id'],
            'match_id' => $match['match_id'],
            'game_mode' => tcgNormalizeGameMode($match['game_mode'] ?? TCG_GAME_MODE_STANDARD),
            'match_api' => $localFile ? 'hostinger' : 'overflow',
        ];
    }

    if ($q) {
        $wait = time() - intval($q['joined_at']);
        return [
            'status' => 'searching',
            'rating' => intval($q['rating']),
            'wait_seconds' => $wait,
            'game_mode' => tcgNormalizeGameMode($q['game_mode'] ?? TCG_GAME_MODE_STANDARD),
        ];
    }

    return ['status' => 'idle'];
}

function tcgFindQueueOpponent(string $discordId, int $rating, string $gameMode = TCG_GAME_MODE_STANDARD): ?array {
    $gameMode = tcgNormalizeGameMode($gameMode);
    tcgPurgeQueuedPlayersWithPendingMatches();
    $db = tcgDb();
    $stmt = $db->prepare('SELECT discord_id, rating, joined_at, game_mode FROM tcg_match_queue
        WHERE discord_id != ? AND game_mode = ?
        ORDER BY ABS(rating - ?) ASC, joined_at ASC
        LIMIT 10');
    $stmt->execute([$discordId, $gameMode, $rating]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($candidates)) {
        return null;
    }
    $bandHit = null;
    $fallback = null;
    foreach ($candidates as $c) {
        $oppId = (string)($c['discord_id'] ?? '');
        if ($oppId === '' || $oppId === $discordId) {
            continue;
        }
        // Never pair someone who already has a live/pending ranked seat.
        if (tcgDiscordIdHasPendingRankedMatch($oppId)) {
            tcgQueueLeave($oppId);
            continue;
        }
        if ($fallback === null) {
            $fallback = $c;
        }
        if (abs(intval($c['rating']) - $rating) <= TCG_RATING_BAND) {
            $bandHit = $c;
            break;
        }
    }
    return $bandHit ?? $fallback;
}

function tcgCreateRankedMatchRecord(
    string $roomId,
    string $p1Id,
    string $p2Id,
    string $p1Token,
    string $p2Token,
    string $gameMode = TCG_GAME_MODE_STANDARD
): string {
    $gameMode = tcgNormalizeGameMode($gameMode);
    $db = tcgDb();
    $matchId = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 12));
    $now = time();
    $db->prepare('INSERT INTO tcg_ranked_matches
        (match_id, room_id, p1_id, p2_id, p1_token, p2_token, status, created_at, game_mode)
        VALUES (?, ?, ?, ?, ?, ?, "pending", ?, ?)')
        ->execute([$matchId, $roomId, $p1Id, $p2Id, $p1Token, $p2Token, $now, $gameMode]);
    $db->prepare('DELETE FROM tcg_match_queue WHERE discord_id IN (?, ?)')->execute([$p1Id, $p2Id]);
    return $matchId;
}

function tcgApplyRankResult(
    string $winnerId,
    string $loserId,
    bool $isDraw = false,
    string $gameMode = TCG_GAME_MODE_STANDARD
): void {
    $gameMode = tcgNormalizeGameMode($gameMode);
    $db = tcgDb();
    $now = time();
    if ($isDraw) {
        foreach ([$winnerId, $loserId] as $uid) {
            tcgRankRow($uid, $gameMode);
            $db->prepare('UPDATE tcg_rank SET draws = draws + 1, games = games + 1, updated_at = ?
                WHERE discord_id = ? AND game_mode = ?')
                ->execute([$now, $uid, $gameMode]);
        }
        return;
    }
    $w = tcgRankRow($winnerId, $gameMode);
    $l = tcgRankRow($loserId, $gameMode);
    $wRating = intval($w['rating']);
    $lRating = intval($l['rating']);
    $k = 32;
    $expectedW = 1 / (1 + pow(10, ($lRating - $wRating) / 400));
    $delta = (int)round($k * (1 - $expectedW));
    $db->prepare('UPDATE tcg_rank SET rating = rating + ?, wins = wins + 1, games = games + 1, updated_at = ?
        WHERE discord_id = ? AND game_mode = ?')
        ->execute([$delta, $now, $winnerId, $gameMode]);
    $db->prepare('UPDATE tcg_rank SET rating = MAX(100, rating - ?), losses = losses + 1, games = games + 1, updated_at = ?
        WHERE discord_id = ? AND game_mode = ?')
        ->execute([$delta, $now, $loserId, $gameMode]);
}

function tcgCompleteRankedMatch(string $roomId, ?string $winnerPid = null, ?bool $prRewarded = null): void {
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId) ?? '');
    if ($roomId === '') {
        return;
    }
    $sets = ['status = "done"'];
    $params = [];
    if ($winnerPid !== null && in_array($winnerPid, ['p1', 'p2'], true)) {
        $sets[] = 'winner_pid = ?';
        $params[] = $winnerPid;
    }
    if ($prRewarded !== null) {
        $sets[] = 'pr_rewarded = ?';
        $params[] = $prRewarded ? 1 : 0;
    }
    $params[] = $roomId;
    tcgDb()->prepare(
        'UPDATE tcg_ranked_matches SET ' . implode(', ', $sets) . ' WHERE room_id = ?'
    )->execute($params);
}

function tcgMarkRankedMatchPrRewarded(string $roomId): void {
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId) ?? '');
    if ($roomId === '') {
        return;
    }
    tcgDb()->prepare('UPDATE tcg_ranked_matches SET pr_rewarded = 1 WHERE room_id = ?')
        ->execute([$roomId]);
}

/**
 * Apply Elo/PR from a VPS finish webhook (Hostinger account DB).
 *
 * @param array<string,mixed> $body
 * @return array<string,mixed>
 */
function tcgApplyRankedResultFromWebhook(array $body): array {
    require_once __DIR__ . '/game_mode.php';
    require_once __DIR__ . '/match_bridge.php';
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($body['room_id'] ?? '')) ?? '');
    if ($roomId === '') {
        throw new Exception('room_id required', 400);
    }
    $p1Id = trim((string)($body['p1_discord_id'] ?? ''));
    $p2Id = trim((string)($body['p2_discord_id'] ?? ''));
    if ($p1Id === '' || $p2Id === '') {
        throw new Exception('p1_discord_id and p2_discord_id required', 400);
    }
    $gameMode = tcgNormalizeGameMode($body['game_mode'] ?? TCG_GAME_MODE_STANDARD);
    $winnerPid = $body['winner'] ?? null;
    if ($winnerPid !== null && !in_array($winnerPid, ['p1', 'p2'], true)) {
        $winnerPid = null;
    }

    $db = tcgDb();
    // Ensure new columns exist before selecting them (Hostinger may boot mid-deploy).
    tcgDbEnsureColumn($db, 'tcg_ranked_matches', 'winner_pid', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_ranked_matches', 'pr_rewarded', 'INTEGER NOT NULL DEFAULT 0');
    $stmt = $db->prepare('SELECT status, pr_rewarded, winner_pid FROM tcg_ranked_matches WHERE room_id = ? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$roomId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $alreadyDone = $row && ($row['status'] ?? '') === 'done';
    $prAlready = $alreadyDone && intval($row['pr_rewarded'] ?? 0) === 1;

    // Fake finished state used for PR grants + Hostinger-side mission writes.
    // Overflow/VPS finishes must not rely on the VPS SQLite replica for missions.
    // Deck snapshots are required for group-only win milestones (ms_win_*).
    $p1Snap = tcgMissionNormalizeDeckSnapshot($body['p1_deck_snapshot'] ?? null);
    $p2Snap = tcgMissionNormalizeDeckSnapshot($body['p2_deck_snapshot'] ?? null);
    // Legacy: nested players.{p1,p2}.deck_snapshot if present.
    if ($p1Snap === null && is_array($body['players']['p1']['deck_snapshot'] ?? null)) {
        $p1Snap = tcgMissionNormalizeDeckSnapshot($body['players']['p1']['deck_snapshot']);
    }
    if ($p2Snap === null && is_array($body['players']['p2']['deck_snapshot'] ?? null)) {
        $p2Snap = tcgMissionNormalizeDeckSnapshot($body['players']['p2']['deck_snapshot']);
    }
    $p1Player = [
        'discord_id' => $p1Id,
        'name' => (string)($body['p1_name'] ?? ($body['players']['p1']['name'] ?? '')),
        'deck_choice' => (string)($body['p1_deck_choice'] ?? ($body['players']['p1']['deck_choice'] ?? '')),
    ];
    $p2Player = [
        'discord_id' => $p2Id,
        'name' => (string)($body['p2_name'] ?? ($body['players']['p2']['name'] ?? '')),
        'deck_choice' => (string)($body['p2_deck_choice'] ?? ($body['players']['p2']['deck_choice'] ?? '')),
    ];
    if ($p1Snap !== null) {
        $p1Player['deck_snapshot'] = $p1Snap;
    }
    if ($p2Snap !== null) {
        $p2Player['deck_snapshot'] = $p2Snap;
    }
    $fakeState = [
        'room_id' => $roomId,
        'mode' => 'ranked',
        'status' => 'finished',
        'winner' => $winnerPid,
        'end_reason' => $body['end_reason'] ?? null,
        'resigned_by' => $body['resigned_by'] ?? null,
        'disconnected_player' => $body['disconnected_player'] ?? null,
        'turn' => intval($body['turn'] ?? 0),
        '_mission_peaks' => is_array($body['mission_peaks'] ?? null) ? $body['mission_peaks'] : [],
        'ranked' => [
            'p1_discord_id' => $p1Id,
            'p2_discord_id' => $p2Id,
            'game_mode' => $gameMode,
            'applied' => true,
            'match_api' => 'overflow',
        ],
        'players' => [
            'p1' => $p1Player,
            'p2' => $p2Player,
        ],
    ];

    $playDeltas = is_array($body['play_stat_deltas'] ?? null) ? $body['play_stat_deltas'] : [];
    if ($playDeltas !== []) {
        require_once __DIR__ . '/play_stats.php';
        tcgApplyPlayStatDeltasOnce($roomId, $playDeltas);
    }

    if ($alreadyDone && $prAlready) {
        $out = ['success' => true, 'already_applied' => true, 'room_id' => $roomId];
        try {
            require_once __DIR__ . '/missions.php';
            $missions = tcgMissionOnGameFinished($fakeState);
            if ($missions !== []) {
                $out['mission_completions'] = $missions;
            }
        } catch (Throwable $e) {
            // Idempotent mission writes are best-effort.
        }
        // Coins are idempotent per room+discord — still attempt on already-applied
        // retries so natural finishes that previously skipped (end_reason=game) can grant.
        try {
            require_once __DIR__ . '/coins.php';
            $coinGrants = tcgCoinsOnGameFinished($fakeState);
            if ($coinGrants !== []) {
                $out['coin_grants'] = $coinGrants;
            }
        } catch (Throwable $e) {
            // Coins are best-effort.
        }
        return $out;
    }

    if (!$alreadyDone) {
        if ($winnerPid === 'p1') {
            tcgApplyRankResult($p1Id, $p2Id, false, $gameMode);
        } elseif ($winnerPid === 'p2') {
            tcgApplyRankResult($p2Id, $p1Id, false, $gameMode);
        } else {
            tcgApplyRankResult($p1Id, $p2Id, true, $gameMode);
        }
        tcgCompleteRankedMatch($roomId, is_string($winnerPid) ? $winnerPid : null);
    } elseif ($winnerPid && empty($row['winner_pid'])) {
        tcgCompleteRankedMatch($roomId, $winnerPid);
    }

    $prEntry = null;
    try {
        require_once __DIR__ . '/ranked_pr_rewards.php';
        if (!$winnerPid) {
            // Draws / no winner — no PR pack; mark so retries do not loop.
            tcgMarkRankedMatchPrRewarded($roomId);
        } else {
            // status=finished is required by tcgApplyRankedPrRewardOnFinish (overflow bug: was omitted).
            tcgApplyRankedPrRewardOnFinish($fakeState);
            $prEntry = $fakeState['ranked']['pr_reward'] ?? null;
            if (!empty($fakeState['ranked']['pr_reward_applied'])) {
                // Grant or daily_cap only — retryable empty_pool/grant_failed stays unmarked.
                tcgMarkRankedMatchPrRewarded($roomId);
            }
        }
    } catch (Throwable $e) {
        // Elo already applied.
    }

    $missionCompletions = [];
    try {
        require_once __DIR__ . '/missions.php';
        $missionCompletions = tcgMissionOnGameFinished($fakeState);
    } catch (Throwable $e) {
        // Elo/PR already applied — missions are best-effort on Hostinger.
    }
    try {
        require_once __DIR__ . '/social.php';
        $winnerId = $winnerPid === 'p1' ? $p1Id : ($winnerPid === 'p2' ? $p2Id : null);
        tcgRecordPvpResult($roomId, 'ranked', $p1Id, $p2Id, $winnerId);
    } catch (Throwable $e) {
        // Profile history is best-effort.
    }

    $coinGrants = [];
    try {
        require_once __DIR__ . '/coins.php';
        $coinGrants = tcgCoinsOnGameFinished($fakeState);
    } catch (Throwable $e) {
        // Coins are best-effort.
    }

    $out = ['success' => true, 'room_id' => $roomId];
    if ($alreadyDone) {
        $out['already_applied'] = true;
    }
    if (is_array($prEntry)) {
        $out['pr_reward'] = $prEntry;
        if (!empty($fakeState['ranked']['pr_reward_applied'])) {
            $out['pr_reward_applied'] = true;
        }
    }
    if ($missionCompletions !== []) {
        $out['mission_completions'] = $missionCompletions;
    }
    if ($coinGrants !== []) {
        $out['coin_grants'] = $coinGrants;
    }
    return $out;
}

/** Drop pending ranked rows whose game is missing or already finished (Hostinger file or VPS Redis). */
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
        // New ranked rooms live on VPS Redis — probe before clearing the Hostinger row.
        require_once __DIR__ . '/match_bridge.php';
        $token = (string)($row['p1_token'] ?? '');
        if ($token === '') {
            $token = (string)($row['p2_token'] ?? '');
        }
        $probe = tcgProbeOverflowRankedRoom($roomId, $token);
        if ($probe === 'live' || $probe === 'unknown') {
            return $row;
        }
        tcgCompleteRankedMatch($roomId);
        return null;
    }
    $state = json_decode((string)file_get_contents($path), true);
    if (!is_array($state) || ($state['mode'] ?? '') !== 'ranked') {
        tcgCompleteRankedMatch($roomId);
        return null;
    }
    if (($state['status'] ?? '') === 'finished') {
        if (empty($state['ranked']['applied'])) {
            require_once __DIR__ . '/ranked_room.php';
            tcgOnGameFinished($state);
            file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE));
        }
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

/**
 * Resign or clear a stuck ranked match so the player can return to the hub.
 *
 * Without confirm_resign: only clear the DB row when the room is missing/finished.
 * Never auto-concede a live room (VPS miss / reconnect cleanup used to free-win the opponent).
 * Options "Leave active match" must pass confirm_resign=1.
 * Overflow-seeded rooms resign via the VPS match API (Elo webhook on finish).
 */
function tcgAbandonActiveRankedGame(string $discordId, array $opts = []): array {
    $confirmResign = !empty($opts['confirm_resign']) || !empty($opts['force']);
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
    $localPath = $roomId !== '' ? tcgRankedGameFilePath($roomId) : '';
    $hasLocalFile = $localPath !== '' && is_file($localPath);

    if ($roomId !== '' && $token !== '') {
        if (!defined('TCG_API_LIB_ONLY')) {
            define('TCG_API_LIB_ONLY', true);
        }
        require_once __DIR__ . '/api.php';
        require_once __DIR__ . '/ranked_room.php';
        require_once __DIR__ . '/match_bridge.php';

        if ($hasLocalFile) {
            try {
                $guard = withLock($roomId, function () use ($roomId, $token, $confirmResign) {
                    $state = loadGame($roomId);
                    if (!$state) {
                        return ['missing' => true];
                    }
                    if (($state['status'] ?? '') === 'finished') {
                        if (($state['mode'] ?? '') === 'ranked' && empty($state['ranked']['applied'])) {
                            tcgOnGameFinished($state);
                            saveGame($roomId, $state);
                        }
                        return ['finished' => true];
                    }

                    // Live match: only Options/explicit leave may concede.
                    if (!$confirmResign) {
                        return ['blocked' => true, 'code' => 'match_still_live'];
                    }

                    $playerId = getPlayerIdByToken($state, $token);
                    if (!$playerId) {
                        return ['missing' => true];
                    }
                    $state = applyAction($state, $playerId, 'resign', []);
                    saveGame($roomId, $state);
                    tcgOnGameFinished($state);
                    saveGame($roomId, $state);
                    return ['resigned' => true];
                });
                if (is_array($guard) && !empty($guard['blocked'])) {
                    return [
                        'left' => false,
                        'code' => (string)($guard['code'] ?? 'match_still_live'),
                        'room_id' => $roomId,
                    ];
                }
            } catch (Throwable $e) {
                // Game file missing or lock failed — still clear the ranked row below.
            }
        } else {
            // VPS Redis room (no Hostinger games/*.json).
            $probe = tcgProbeOverflowRankedRoom($roomId, $token);
            if ($probe === 'live' || $probe === 'unknown') {
                if (!$confirmResign) {
                    return [
                        'left' => false,
                        'code' => 'match_still_live',
                        'room_id' => $roomId,
                    ];
                }
                tcgResignRankedRoomOnVps($roomId, $token);
            }
            // missing/finished: clear Hostinger pending row (Elo already applied via webhook if finished).
        }
    }
    tcgCompleteRankedMatch($roomId);

    return ['left' => true, 'room_id' => $roomId];
}

function tcgRankedGameFilePath(string $roomId): string {
    return tcgPath('games') . preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId)) . '.json';
}

/** Last computed stats regardless of age (fallback while another worker recomputes). */
function tcgQueueStatsFromCache(string $cacheFile, string $gameMode, ?int $maxAgeSec = null): ?array {
    if (!is_file($cacheFile)) {
        return null;
    }
    if ($maxAgeSec !== null && (time() - (int)@filemtime($cacheFile)) >= $maxAgeSec) {
        return null;
    }
    $cached = json_decode((string)@file_get_contents($cacheFile), true);
    if (!is_array($cached) || !isset($cached['waiting'], $cached['in_game'])) {
        return null;
    }
    $cached['game_mode'] = $gameMode;
    return $cached;
}

/**
 * Ranked modes (other than $currentMode) that currently have ≥1 player waiting.
 *
 * @return list<string>
 */
function tcgRankedOtherModesWithWaiting(string $currentMode): array {
    require_once __DIR__ . '/game_mode.php';
    $currentMode = tcgNormalizeRankedGameMode($currentMode);
    $db = tcgDb();
    $stmt = $db->query(
        'SELECT q.game_mode AS game_mode, COUNT(*) AS c
         FROM tcg_match_queue q
         WHERE NOT EXISTS (
           SELECT 1 FROM tcg_ranked_matches m
           WHERE m.status = "pending"
             AND (m.p1_id = q.discord_id OR m.p2_id = q.discord_id)
         )
         GROUP BY q.game_mode'
    );
    if ($stmt === false) {
        return [];
    }
    $counts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $m = tcgNormalizeRankedGameMode($row['game_mode'] ?? '');
        $counts[$m] = ($counts[$m] ?? 0) + (int)($row['c'] ?? 0);
    }
    $out = [];
    foreach (tcgRankedGameModeIds() as $m) {
        if ($m === $currentMode) {
            continue;
        }
        if (($counts[$m] ?? 0) > 0) {
            $out[] = $m;
        }
    }
    return $out;
}

/**
 * Public queue stats for the ranked menu (waiting in lobby vs in active ranked games).
 *
 * Hot path for ranked_status polling: it must never block on the VPS or on a long
 * pending-row probe loop, or the client aborts at 12s with "Request timed out".
 */
function tcgQueuePublicStats(?string $gameMode = null): array {
    $gameMode = tcgNormalizeRankedGameMode($gameMode ?? TCG_GAME_MODE_STANDARD);
    $cacheFile = tcgPath('data') . 'queue_stats_cache_' . preg_replace('/[^a-z0-9_]/', '', $gameMode) . '.json';

    // Queue size is a local COUNT(*) — always live. in_game needs the match host,
    // so it is cached longer: polling it every few seconds buried the 0.5 CPU VPS.
    $db = tcgDb();
    tcgPurgeQueuedPlayersWithPendingMatches();
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM tcg_match_queue q
         WHERE q.game_mode = ?
           AND NOT EXISTS (
             SELECT 1 FROM tcg_ranked_matches m
             WHERE m.status = "pending"
               AND (m.p1_id = q.discord_id OR m.p2_id = q.discord_id)
           )'
    );
    $stmt->execute([$gameMode]);
    $waiting = (int)$stmt->fetchColumn();
    $otherModes = tcgRankedOtherModesWithWaiting($gameMode);

    $fresh = tcgQueueStatsFromCache($cacheFile, $gameMode, 15);
    if ($fresh !== null) {
        $fresh['waiting'] = $waiting;
        $fresh['other_modes_waiting'] = $otherModes;
        $fresh['game_mode'] = $gameMode;
        return $fresh;
    }

    // Single-flight: only one request recomputes; the rest serve the last value.
    $lockFile = $cacheFile . '.lock';
    $lock = @fopen($lockFile, 'c');
    $owner = $lock !== false && @flock($lock, LOCK_EX | LOCK_NB);
    if (!$owner) {
        if ($lock !== false) {
            fclose($lock);
        }
        $stale = tcgQueueStatsFromCache($cacheFile, $gameMode);
        if ($stale !== null) {
            $stale['waiting'] = $waiting;
            $stale['other_modes_waiting'] = $otherModes;
            $stale['game_mode'] = $gameMode;
            return $stale;
        }
        // Another request is computing in_game — never stampede the VPS or hang the
        // ranked hub on a cold cache. Waiting is live; in_game fills in on the next poll.
        return [
            'waiting' => $waiting,
            'in_game' => 0,
            'game_mode' => $gameMode,
            'other_modes_waiting' => $otherModes,
        ];
    }

    try {
        $stats = tcgComputeQueuePublicStats($gameMode, $cacheFile, $waiting);
        $stats['other_modes_waiting'] = $otherModes;
        return $stats;
    } finally {
        if ($owner && $lock !== false) {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

function tcgComputeQueuePublicStats(string $gameMode, string $cacheFile, int $waiting): array {
    $db = tcgDb();

    // Match-primary: in_game must match the presence-accurate VPS spectate list.
    // Pending Hostinger rows still include rematch ghosts (probe=live/unknown) and
    // produce odd counts like "5" while only two real matches (4 players) exist.
    require_once __DIR__ . '/match_bridge.php';
    $overflowInGame = tcgFetchOverflowRankedLivePlayerCount($gameMode);
    if ($overflowInGame !== null) {
        $stats = ['waiting' => $waiting, 'in_game' => $overflowInGame, 'game_mode' => $gameMode];
        @file_put_contents($cacheFile, json_encode($stats), LOCK_EX);
        return $stats;
    }

    // Overflow unreachable: reuse the last known in_game rather than probing every
    // pending row over HTTP (that is what pushed ranked_status past the client budget).
    $stale = tcgQueueStatsFromCache($cacheFile, $gameMode, 60);
    if ($stale !== null) {
        $stale['waiting'] = $waiting;
        return $stale;
    }

    $inGame = 0;
    $seen = [];
    $probeDeadline = microtime(true) + 2.0;
    $stmt = $db->prepare(
        'SELECT room_id, p1_id, p2_id, p1_token, p2_token, game_mode
         FROM tcg_ranked_matches WHERE status = "pending" AND game_mode = ?'
    );
    $stmt->execute([$gameMode]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $roomId = $row['room_id'] ?? '';
        $path = tcgRankedGameFilePath($roomId);
        $countPlayers = false;
        if (is_file($path)) {
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
            $countPlayers = true;
        } else {
            if (microtime(true) >= $probeDeadline) {
                // Budget spent: leave the rest for the next refresh instead of stalling.
                break;
            }
            $token = (string)($row['p1_token'] ?? '');
            if ($token === '') {
                $token = (string)($row['p2_token'] ?? '');
            }
            $probe = tcgProbeOverflowRankedRoom((string)$roomId, $token);
            if ($probe === 'finished' || $probe === 'missing') {
                if ($roomId !== '') {
                    tcgCompleteRankedMatch($roomId);
                }
                continue;
            }
            // Fallback only: require a confirmed live probe (not unknown timeouts).
            $countPlayers = ($probe === 'live');
        }
        if (!$countPlayers) {
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

    $stats = ['waiting' => $waiting, 'in_game' => $inGame, 'game_mode' => $gameMode];
    @file_put_contents($cacheFile, json_encode($stats), LOCK_EX);
    return $stats;
}

/** Active ranked game for a logged-in player (reconnect after refresh / new tab). */
function tcgGetActiveRankedGame(string $discordId): ?array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT room_id, p1_id, p2_id, p1_token, p2_token, created_at, game_mode FROM tcg_ranked_matches
        WHERE status = "pending" AND (p1_id = ? OR p2_id = ?) ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$discordId, $discordId]);
    $row = tcgSanitizeRankedMatchRow($stmt->fetch(PDO::FETCH_ASSOC));
    if (!$row) {
        return null;
    }
    $roomId = $row['room_id'] ?? '';
    $isP1 = ($row['p1_id'] ?? '') === $discordId;
    $localFile = $roomId !== '' && is_file(tcgRankedGameFilePath($roomId));
    return [
        'room_id' => $roomId,
        'player_token' => $isP1 ? ($row['p1_token'] ?? '') : ($row['p2_token'] ?? ''),
        'player_id' => $isP1 ? 'p1' : 'p2',
        'mode' => 'ranked',
        'match_api' => $localFile ? 'hostinger' : 'overflow',
        'game_mode' => tcgNormalizeGameMode($row['game_mode'] ?? TCG_GAME_MODE_STANDARD),
    ];
}
