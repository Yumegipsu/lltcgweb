<?php
/**
 * Public monthly card usage sheet (Smogon-style) and end-of-season feedback.
 *
 * Usage is recorded once per finished standard/ranked match from the two
 * main-deck snapshots. A card counts once per deck ("decks"), so usage % is
 * decks using the card / decks played. Energy cards are not tracked.
 *
 * Feedback opens for the last 7 UTC days of a season (players who played ranked) and goes to the owner inbox.
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

/** @return array<string,array<string,mixed>> card catalog keyed by every card_no spelling */
function tcgSeasonStatsCardMap(): array {
    static $map = null;
    if ($map === null) {
        $map = function_exists('tcgBuildCardMap') && function_exists('tcgLoadCardsData')
            ? tcgBuildCardMap(tcgLoadCardsData())
            : [];
    }
    return $map;
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
        $cardMap = tcgSeasonStatsCardMap();
        foreach ($decks as $pid => $main) {
            $counts = [];
            foreach ($main as $no) {
                $no = trim((string)$no);
                if ($no === '') {
                    continue;
                }
                // One key per card whether the deck stored "+" or the fullwidth "＋".
                $no = (string)($cardMap[$no]['card_no'] ?? $no);
                $counts[$no] = ($counts[$no] ?? 0) + 1;
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
function tcgSeasonStatsSheet(?string $seasonId, string $gameMode = 'standard', int $limit = 50): array {
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
        'members' => [],
        'lives' => [],
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
    $limit = max(1, min(150, $limit));
    $stmt = $db->prepare('SELECT card_no, decks, copies, wins FROM tcg_season_card_usage
        WHERE season_id = ? AND game_mode = ?
        ORDER BY decks DESC, wins DESC, card_no ASC');
    $stmt->execute([$seasonId, $gameMode]);
    $cardMap = function_exists('tcgBuildCardMap') && function_exists('tcgLoadCardsData')
        ? tcgBuildCardMap(tcgLoadCardsData())
        : [];
    // Members and Lives are ranked separately so each is compared within its own type.
    $ranks = ['members' => 0, 'lives' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $no = (string)$r['card_no'];
        $c = $cardMap[$no] ?? [];
        $type = strtolower((string)($c['card_type_en'] ?? ''));
        $section = $type === 'live' ? 'lives' : 'members';
        if ($ranks[$section] >= $limit) {
            continue;
        }
        $decks = (int)$r['decks'];
        $out[$section][] = [
            'rank' => ++$ranks[$section],
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

/** Feedback is collected during the last week of a season. */
const TCG_SEASON_FEEDBACK_WINDOW_SEC = 7 * 86400;

/** Seasons whose form closes before the season ends (season id => UTC close time). */
const TCG_SEASON_FEEDBACK_CLOSES_AT = [
    '2026-11' => 1795996800, // Season 2: open through Nov 29 (closes 2026-11-30 00:00 UTC)
];

/** Temporary milestone rewarded for sending a season's form (see missions.php). */
const TCG_SEASON_FEEDBACK_MILESTONES = [
    '2026-10' => 'ms_season_feedback_2026_10',
    '2026-11' => 'ms_season_feedback_2026_11',
];

function tcgSeasonFeedbackMilestoneId(string $seasonId): string {
    return TCG_SEASON_FEEDBACK_MILESTONES[$seasonId] ?? '';
}

/** True when the player has played at least one ranked match in this season (any mode). */
function tcgSeasonFeedbackParticipated(string $discordId, string $seasonId): bool {
    if ($discordId === '' || $seasonId === '') {
        return false;
    }
    $q = tcgDb()->prepare('SELECT 1 FROM tcg_season_rank
        WHERE discord_id = ? AND season_id = ? AND (wins + losses) > 0 LIMIT 1');
    $q->execute([$discordId, $seasonId]);
    return (bool)$q->fetchColumn();
}

/**
 * open: inside the feedback window. eligible: this player played ranked this season.
 * The form is usable when both hold; body is the player's earlier answer (to edit it).
 *
 * @return array<string,mixed>
 */
function tcgSeasonFeedbackWindow(?string $discordId = null): array {
    $clock = tcgSeasonClockInfo();
    if (empty($clock['active'])) {
        return ['open' => false, 'eligible' => false, 'season_id' => '', 'label' => '', 'opens_at' => 0,
            'closes_at' => 0, 'days_left' => 0, 'submitted' => false, 'body' => ''];
    }
    $opens = (int)$clock['ends_at'] - TCG_SEASON_FEEDBACK_WINDOW_SEC;
    $closes = (int)(TCG_SEASON_FEEDBACK_CLOSES_AT[(string)$clock['id']] ?? $clock['ends_at']);
    $submitted = false;
    $body = '';
    $eligible = false;
    if ($discordId !== null && $discordId !== '') {
        $db = tcgDb();
        tcgSeasonStatsEnsureSchema($db);
        $q = $db->prepare('SELECT body FROM tcg_season_feedback WHERE season_id = ? AND discord_id = ?');
        $q->execute([(string)$clock['id'], $discordId]);
        $found = $q->fetchColumn();
        $submitted = $found !== false;
        $body = $submitted ? (string)$found : '';
        $eligible = $submitted || tcgSeasonFeedbackParticipated($discordId, (string)$clock['id']);
    }
    $now = (int)$clock['now'];
    return [
        'open' => $now >= $opens && $now < $closes,
        'eligible' => $eligible,
        'season_id' => (string)$clock['id'],
        'label' => (string)$clock['label'],
        'opens_at' => $opens,
        'closes_at' => $closes,
        'days_left' => max(0, (int)ceil(($closes - $now) / 86400)),
        'submitted' => $submitted,
        'body' => $body,
        // Temporary per-season milestone that rewards sending the form (see missions.php).
        'milestone' => tcgSeasonFeedbackMilestoneId((string)$clock['id']),
    ];
}

/** @param array<string,mixed> $body */
function tcgApiSeasonFeedbackStatus(array $body): array {
    $uid = tcgRequireAuthUser($body);
    return ['success' => true, 'feedback' => tcgSeasonFeedbackWindow($uid)];
}

/** @param array<string,mixed> $body */
function tcgApiSeasonStats(array $body): array {
    $mode = function_exists('tcgNormalizeRankedGameMode')
        ? tcgNormalizeRankedGameMode($body['game_mode'] ?? $_GET['game_mode'] ?? 'standard')
        : 'standard';
    $sid = (string)($body['season_id'] ?? $_GET['season_id'] ?? '');
    $sheet = tcgSeasonStatsSheet($sid, $mode, (int)($body['limit'] ?? $_GET['limit'] ?? 50));
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
    return tcgSeasonFeedbackSave($uid, (string)($body['body'] ?? ''));
}

/** @return array<string,mixed> */
function tcgSeasonFeedbackSave(string $uid, string $rawText): array {
    $win = tcgSeasonFeedbackWindow($uid);
    if (!$win['open']) {
        throw new Exception('Season feedback is not open right now', 400);
    }
    if (!$win['eligible']) {
        throw new Exception('Play at least one ranked match this season to leave feedback', 403);
    }
    $text = trim($rawText);
    if ($text === '') {
        throw new Exception('Feedback is empty', 400);
    }
    if (function_exists('mb_substr')) {
        $text = mb_substr($text, 0, TCG_SEASON_FEEDBACK_MAX_LEN);
    } else {
        $text = substr($text, 0, TCG_SEASON_FEEDBACK_MAX_LEN);
    }
    $db = tcgDb();
    $nameQ = $db->prepare('SELECT username FROM tcg_users WHERE discord_id = ?');
    $nameQ->execute([$uid]);
    $name = (string)($nameQ->fetchColumn() ?: '');
    if ($name === '' && function_exists('tcgAuthUserProfile')) {
        $name = (string)(tcgAuthUserProfile($uid)['username'] ?? '');
    }
    tcgSeasonStatsEnsureSchema($db);
    $now = time();
    $db->prepare('INSERT INTO tcg_season_feedback (season_id, discord_id, username, body, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(season_id, discord_id) DO UPDATE SET
            body = excluded.body, username = excluded.username, updated_at = excluded.updated_at, read_at = NULL')
        ->execute([$win['season_id'], $uid, $name, $text, $now, $now]);
    // Temporary per-season milestone (SR+ Ticket); a no-op once that season is over or already completed.
    $completions = [];
    if (tcgSeasonFeedbackMilestoneId((string)$win['season_id']) !== '') {
        if (!function_exists('tcgMissionOnSeasonFeedback')) {
            require_once __DIR__ . '/missions.php';
        }
        $completions = tcgMissionOnSeasonFeedback($uid, (string)$win['season_id']);
    }
    return [
        'success' => true,
        'feedback' => tcgSeasonFeedbackWindow($uid),
        'mission_completions' => $completions,
        'missions' => function_exists('tcgMissionSummaryForUser') ? tcgMissionSummaryForUser($uid) : null,
    ];
}

/**
 * Owner inbox. The newest season that has feedback is the main view; older seasons are
 * the archive (`archive: true`). mark_read only clears the rows of the view being shown.
 *
 * @param array<string,mixed> $body
 */
function tcgApiSeasonFeedbackList(array $body): array {
    $uid = tcgRequireAuthUser($body);
    if (!function_exists('tcgSocialIsOwner')) {
        require_once __DIR__ . '/social.php';
    }
    if (!tcgSocialIsOwner($uid)) {
        throw new Exception('Admin only', 403);
    }
    return tcgSeasonFeedbackInbox(!empty($body['archive']), !empty($body['mark_read']));
}

/** @return array<string,mixed> */
function tcgSeasonFeedbackInbox(bool $archive, bool $markRead): array {
    $db = tcgDb();
    tcgSeasonStatsEnsureSchema($db);
    $current = (string)($db->query('SELECT MAX(season_id) FROM tcg_season_feedback')->fetchColumn() ?: '');
    $where = $archive ? 'season_id <> ?' : 'season_id = ?';
    if ($markRead) {
        $db->prepare("UPDATE tcg_season_feedback SET read_at = ? WHERE read_at IS NULL AND $where")
            ->execute([time(), $current]);
    }
    $q = $db->prepare("SELECT id, season_id, discord_id, username, body, created_at, updated_at, read_at
        FROM tcg_season_feedback WHERE $where ORDER BY season_id DESC, updated_at DESC LIMIT 500");
    $q->execute([$current]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['label'] = tcgSeasonLabel((string)$r['season_id']);
    }
    unset($r);
    $u = $db->prepare("SELECT COUNT(*) FROM tcg_season_feedback WHERE read_at IS NULL AND $where");
    $u->execute([$current]);
    $arch = $db->prepare('SELECT season_id, COUNT(*) AS n FROM tcg_season_feedback
        WHERE season_id <> ? GROUP BY season_id ORDER BY season_id DESC');
    $arch->execute([$current]);
    $archiveSeasons = [];
    foreach ($arch->fetchAll(PDO::FETCH_ASSOC) ?: [] as $a) {
        $archiveSeasons[] = ['season_id' => (string)$a['season_id'], 'label' => tcgSeasonLabel((string)$a['season_id']),
            'count' => (int)$a['n']];
    }
    return [
        'success' => true,
        'archive' => $archive,
        'season_id' => $current,
        'label' => $current !== '' ? tcgSeasonLabel($current) : '',
        'feedback' => $rows,
        'unread' => (int)$u->fetchColumn(),
        'archive_seasons' => $archiveSeasons,
        'archive_count' => array_sum(array_column($archiveSeasons, 'count')),
    ];
}
