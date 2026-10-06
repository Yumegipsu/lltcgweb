<?php
/**
 * Public monthly card usage sheet (Smogon-style) and end-of-season feedback.
 *
 * Usage is recorded once per finished standard/ranked match from the two
 * main-deck snapshots. A card counts once per deck ("decks"), so usage % is
 * decks using the card / decks played. Energy cards are not tracked.
 *
 * Feedback opens on the last UTC day of a season and goes to the owner inbox.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/season.php';

const TCG_SEASON_FEEDBACK_MAX_LEN = 2000;
const TCG_SEASON_STATS_MIN_DECKS = 1;

function tcgSeasonStatsEnsureSchema(PDO $db): void {
    static $done = false;
    if ($done) {
        return;
    }
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_season_card_usage (
        season_id TEXT NOT NULL,
        game_mode TEXT NOT NULL,
        card_no TEXT NOT NULL,
        decks INTEGER NOT NULL DEFAULT 0,
        copies INTEGER NOT NULL DEFAULT 0,
        wins INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (season_id, game_mode, card_no)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_season_usage_totals (
        season_id TEXT NOT NULL,
        game_mode TEXT NOT NULL,
        decks INTEGER NOT NULL DEFAULT 0,
        matches INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (season_id, game_mode)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_season_usage_applied (
        room_id TEXT NOT NULL PRIMARY KEY,
        applied_at INTEGER NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_season_feedback (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        season_id TEXT NOT NULL,
        discord_id TEXT NOT NULL,
        username TEXT NOT NULL DEFAULT \'\',
        body TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL,
        read_at INTEGER,
        UNIQUE (season_id, discord_id)
    )');
    $done = true;
}

/**
 * Record both decks of a finished ranked match. Idempotent per room id.
 *
 * @param array<string,mixed> $state finished room state (same shape as missions)
 */
function tcgSeasonStatsRecordMatch(array $state): bool {
    if (($state['status'] ?? '') !== 'finished' || ($state['mode'] ?? '') !== 'ranked') {
        return false;
    }
    $roomId = trim((string)($state['room_id'] ?? ''));
    $clock = tcgSeasonClockInfo();
    if ($roomId === '' || empty($clock['active'])) {
        return false;
    }
    $gameMode = (string)($state['ranked']['game_mode'] ?? 'standard');
    $winner = $state['winner'] ?? null;
    $decks = [];
    foreach (['p1', 'p2'] as $pid) {
        $player = $state['players'][$pid] ?? null;
        if (!is_array($player) || tcgMissionSeatIsCpu($player)) {
            return false;
        }
        $main = $player['deck_snapshot']['main_nos'] ?? null;
        if (!is_array($main) || $main === []) {
            return false;
        }
        $decks[$pid] = $main;
    }

    $db = tcgDb();
    tcgSeasonStatsEnsureSchema($db);
    $db->beginTransaction();
    try {
        $mark = $db->prepare('INSERT OR IGNORE INTO tcg_season_usage_applied (room_id, applied_at) VALUES (?, ?)');
        $mark->execute([$roomId, time()]);
        if ($mark->rowCount() === 0) {
            $db->rollBack();
            return false;
        }
        $seasonId = (string)$clock['id'];
        $card = $db->prepare('INSERT INTO tcg_season_card_usage (season_id, game_mode, card_no, decks, copies, wins)
            VALUES (?, ?, ?, 1, ?, ?)
            ON CONFLICT(season_id, game_mode, card_no) DO UPDATE SET
                decks = decks + 1, copies = copies + excluded.copies, wins = wins + excluded.wins');
        foreach ($decks as $pid => $main) {
            $counts = [];
            foreach ($main as $no) {
                $no = trim((string)$no);
                if ($no !== '') {
                    $counts[$no] = ($counts[$no] ?? 0) + 1;
                }
            }
            $won = $winner === $pid ? 1 : 0;
            foreach ($counts as $no => $n) {
                $card->execute([$seasonId, $gameMode, (string)$no, $n, $won]);
            }
        }
        $db->prepare('INSERT INTO tcg_season_usage_totals (season_id, game_mode, decks, matches)
            VALUES (?, ?, 2, 1)
            ON CONFLICT(season_id, game_mode) DO UPDATE SET decks = decks + 2, matches = matches + 1')
            ->execute([$seasonId, $gameMode]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('tcgSeasonStatsRecordMatch: ' . $e->getMessage());
        return false;
    }
}

/**
 * Seasons that have usage rows, newest first.
 *
 * @return list<array{season_id:string,label:string}>
 */
function tcgSeasonStatsSeasonList(PDO $db): array {
    $ids = $db->query('SELECT DISTINCT season_id FROM tcg_season_usage_totals ORDER BY season_id DESC')
        ->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $out = [];
    foreach ($ids as $id) {
        $out[] = ['season_id' => (string)$id, 'label' => tcgSeasonLabel((string)$id)];
    }
    return $out;
}

/**
 * Public usage sheet for one season.
 *
 * @return array<string,mixed>
 */
function tcgSeasonStatsSheet(?string $seasonId, string $gameMode = 'standard', int $limit = 100): array {
    $db = tcgDb();
    tcgSeasonStatsEnsureSchema($db);
    $clock = tcgSeasonClockInfo();
    $seasons = tcgSeasonStatsSeasonList($db);
    $seasonId = trim((string)$seasonId);
    if (!preg_match('/^\d{4}-\d{2}$/', $seasonId)) {
        $seasonId = !empty($clock['active']) ? (string)$clock['id'] : '';
    }
    $out = [
        'success' => true,
        'season_id' => $seasonId,
        'label' => $seasonId !== '' ? tcgSeasonLabel($seasonId) : '',
        'current_season_id' => !empty($clock['active']) ? (string)$clock['id'] : '',
        'seasons' => $seasons,
        'decks' => 0,
        'matches' => 0,
        'cards' => [],
    ];
    if ($seasonId === '') {
        return $out;
    }
    $t = $db->prepare('SELECT decks, matches FROM tcg_season_usage_totals WHERE season_id = ? AND game_mode = ?');
    $t->execute([$seasonId, $gameMode]);
    $tot = $t->fetch(PDO::FETCH_ASSOC);
    if (!$tot || (int)$tot['decks'] < TCG_SEASON_STATS_MIN_DECKS) {
        return $out;
    }
    $totalDecks = (int)$tot['decks'];
    $out['decks'] = $totalDecks;
    $out['matches'] = (int)$tot['matches'];
    $limit = max(1, min(300, $limit));
    $stmt = $db->prepare('SELECT card_no, decks, copies, wins FROM tcg_season_card_usage
        WHERE season_id = ? AND game_mode = ?
        ORDER BY decks DESC, wins DESC, card_no ASC LIMIT ' . $limit);
    $stmt->execute([$seasonId, $gameMode]);
    $cardMap = function_exists('tcgBuildCardMap') && function_exists('tcgLoadCardsData')
        ? tcgBuildCardMap(tcgLoadCardsData())
        : [];
    $rank = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $no = (string)$r['card_no'];
        $c = $cardMap[$no] ?? [];
        $decks = (int)$r['decks'];
        $out['cards'][] = [
            'rank' => ++$rank,
            'card_no' => $no,
            'name_en' => (string)($c['name_en'] ?? $no),
            'name' => (string)($c['name'] ?? ''),
            'card_type_en' => (string)($c['card_type_en'] ?? ''),
            'decks' => $decks,
            'usage_pct' => round(100 * $decks / $totalDecks, 2),
            'avg_copies' => $decks > 0 ? round((int)$r['copies'] / $decks, 2) : 0,
            'win_pct' => $decks > 0 ? round(100 * (int)$r['wins'] / $decks, 1) : 0,
        ];
    }
    return $out;
}

/** @return array{open:bool,season_id:string,label:string,opens_at:int,closes_at:int,submitted:bool} */
function tcgSeasonFeedbackWindow(?string $discordId = null): array {
    $clock = tcgSeasonClockInfo();
    if (empty($clock['active'])) {
        return ['open' => false, 'season_id' => '', 'label' => '', 'opens_at' => 0, 'closes_at' => 0, 'submitted' => false];
    }
    $closes = (int)$clock['ends_at'];
    $opens = $closes - 86400;
    $submitted = false;
    if ($discordId !== null && $discordId !== '') {
        $db = tcgDb();
        tcgSeasonStatsEnsureSchema($db);
        $q = $db->prepare('SELECT 1 FROM tcg_season_feedback WHERE season_id = ? AND discord_id = ?');
        $q->execute([(string)$clock['id'], $discordId]);
        $submitted = (bool)$q->fetchColumn();
    }
    return [
        'open' => (int)$clock['now'] >= $opens && (int)$clock['now'] < $closes,
        'season_id' => (string)$clock['id'],
        'label' => (string)$clock['label'],
        'opens_at' => $opens,
        'closes_at' => $closes,
        'submitted' => $submitted,
    ];
}

/** @param array<string,mixed> $body */
function tcgApiSeasonStats(array $body): array {
    $mode = function_exists('tcgNormalizeRankedGameMode')
        ? tcgNormalizeRankedGameMode($body['game_mode'] ?? $_GET['game_mode'] ?? 'standard')
        : 'standard';
    $sid = (string)($body['season_id'] ?? $_GET['season_id'] ?? '');
    $sheet = tcgSeasonStatsSheet($sid, $mode, (int)($body['limit'] ?? $_GET['limit'] ?? 100));
    $uid = '';
    try {
        $uid = tcgRequireAuthUser($body);
    } catch (Throwable $e) {
        $uid = '';
    }
    $sheet['feedback'] = tcgSeasonFeedbackWindow($uid);
    return $sheet;
}

/** @param array<string,mixed> $body */
function tcgApiSeasonFeedbackSubmit(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $win = tcgSeasonFeedbackWindow($uid);
    if (!$win['open']) {
        throw new Exception('Season feedback opens on the last day of the season', 400);
    }
    $text = trim((string)($body['body'] ?? ''));
    if ($text === '') {
        throw new Exception('Feedback is empty', 400);
    }
    if (function_exists('mb_substr')) {
        $text = mb_substr($text, 0, TCG_SEASON_FEEDBACK_MAX_LEN);
    } else {
        $text = substr($text, 0, TCG_SEASON_FEEDBACK_MAX_LEN);
    }
    $profile = tcgAuthUserProfile($uid);
    $name = (string)($profile['username'] ?? '');
    $db = tcgDb();
    tcgSeasonStatsEnsureSchema($db);
    $now = time();
    $db->prepare('INSERT INTO tcg_season_feedback (season_id, discord_id, username, body, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(season_id, discord_id) DO UPDATE SET
            body = excluded.body, username = excluded.username, updated_at = excluded.updated_at, read_at = NULL')
        ->execute([$win['season_id'], $uid, $name, $text, $now, $now]);
    return ['success' => true, 'feedback' => tcgSeasonFeedbackWindow($uid)];
}

/** Owner inbox. @param array<string,mixed> $body */
function tcgApiSeasonFeedbackList(array $body): array {
    $uid = tcgRequireAuthUser($body);
    if (!function_exists('tcgSocialIsOwner')) {
        require_once __DIR__ . '/social.php';
    }
    if (!tcgSocialIsOwner($uid)) {
        throw new Exception('Admin only', 403);
    }
    $db = tcgDb();
    tcgSeasonStatsEnsureSchema($db);
    if (!empty($body['mark_read'])) {
        $db->prepare('UPDATE tcg_season_feedback SET read_at = ? WHERE read_at IS NULL')->execute([time()]);
    }
    $rows = $db->query('SELECT id, season_id, discord_id, username, body, created_at, updated_at, read_at
        FROM tcg_season_feedback ORDER BY season_id DESC, updated_at DESC LIMIT 500')
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['label'] = tcgSeasonLabel((string)$r['season_id']);
    }
    unset($r);
    $unread = (int)$db->query('SELECT COUNT(*) FROM tcg_season_feedback WHERE read_at IS NULL')->fetchColumn();
    return ['success' => true, 'feedback' => $rows, 'unread' => $unread];
}
