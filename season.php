<?php
/**
 * Monthly seasonal ladder beside lifetime Elo.
 *
 * Season 1 starts 2026-10-01 00:00 UTC. One ladder for standard ranked only.
 * Rollover is lazy (next account or ranked request). Rewards use the peak step.
 * Pink S is the live top 10 who have filled Green S, not a permanent promotion.
 *
 * Ladder points are independent of all-time Elo. Match deltas use each player's
 * current seasonal step only (same-step ≈ 16; upsets vs higher steps pay more).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/game_mode.php';

const TCG_SEASON_EPOCH = 1790812800; // 2026-10-01 00:00:00 UTC
const TCG_SEASON_POINTS = 100;
const TCG_SEASON_GREEN_S_STEP = 6;
const TCG_SEASON_PINK_S_STEP = 7;
/** Players who have filled Green S. Only this many hold Pink S at once. */
const TCG_SEASON_PINK_S_SLOTS = 10;

/** @var list<array{key:string,letter:string,tone:string}> */
const TCG_SEASON_STEPS = [
    ['key' => 'c-green', 'letter' => 'C', 'tone' => 'green'],
    ['key' => 'c-pink', 'letter' => 'C', 'tone' => 'pink'],
    ['key' => 'b-green', 'letter' => 'B', 'tone' => 'green'],
    ['key' => 'b-pink', 'letter' => 'B', 'tone' => 'pink'],
    ['key' => 'a-green', 'letter' => 'A', 'tone' => 'green'],
    ['key' => 'a-pink', 'letter' => 'A', 'tone' => 'pink'],
    ['key' => 's-green', 'letter' => 'S', 'tone' => 'green'],
    ['key' => 's-pink', 'letter' => 'S', 'tone' => 'pink'],
];

/** @var array<int,array{coins:int,gems:int,packs:int}> */
const TCG_SEASON_REWARDS = [
    0 => ['coins' => 400, 'gems' => 150, 'packs' => 0],
    1 => ['coins' => 600, 'gems' => 200, 'packs' => 0],
    2 => ['coins' => 1200, 'gems' => 400, 'packs' => 0],
    3 => ['coins' => 1800, 'gems' => 600, 'packs' => 0],
    4 => ['coins' => 2500, 'gems' => 800, 'packs' => 2],
    5 => ['coins' => 3500, 'gems' => 1000, 'packs' => 3],
    6 => ['coins' => 4500, 'gems' => 1300, 'packs' => 4],
    7 => ['coins' => 6000, 'gems' => 2000, 'packs' => 5],
];

function tcgSeasonNow(): int {
    if (isset($GLOBALS['TCG_SEASON_NOW']) && is_numeric($GLOBALS['TCG_SEASON_NOW'])) {
        return (int)$GLOBALS['TCG_SEASON_NOW'];
    }
    return time();
}

function tcgSeasonEnsureSchema(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_season_rank (
        discord_id TEXT NOT NULL,
        game_mode TEXT NOT NULL,
        season_id TEXT NOT NULL,
        step INTEGER NOT NULL DEFAULT 0,
        points INTEGER NOT NULL DEFAULT 0,
        peak_step INTEGER NOT NULL DEFAULT 0,
        wins INTEGER NOT NULL DEFAULT 0,
        losses INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL,
        PRIMARY KEY (discord_id, game_mode),
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_season_history (
        discord_id TEXT NOT NULL,
        game_mode TEXT NOT NULL,
        season_id TEXT NOT NULL,
        season_number INTEGER NOT NULL,
        step INTEGER NOT NULL,
        points INTEGER NOT NULL,
        peak_step INTEGER NOT NULL,
        wins INTEGER NOT NULL DEFAULT 0,
        losses INTEGER NOT NULL DEFAULT 0,
        coins INTEGER NOT NULL DEFAULT 0,
        star_gems INTEGER NOT NULL DEFAULT 0,
        pr_packs INTEGER NOT NULL DEFAULT 0,
        packs_granted INTEGER NOT NULL DEFAULT 0,
        title_id TEXT,
        closed_at INTEGER NOT NULL,
        PRIMARY KEY (discord_id, game_mode, season_id),
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_season_rank_board
        ON tcg_season_rank(game_mode, season_id, step, points)');
    tcgDbEnsureColumn($db, 'tcg_match_queue', 'season_step', 'INTEGER NOT NULL DEFAULT -1');
}

/**
 * Year and within-year season number for a YYYY-MM id.
 * October 2026 is 2026 Season 1. January 2027 is 2027 Season 1.
 * A full calendar year is seasons 1 through 12.
 *
 * @return array{year:int,number:int}|null
 */
function tcgSeasonLabelParts(string $seasonId): ?array {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $seasonId, $m)) {
        return null;
    }
    $year = (int)$m[1];
    $month = (int)$m[2];
    if ($month < 1 || $month > 12 || $year < 2026) {
        return null;
    }
    if ($year === 2026 && $month < 10) {
        return null;
    }
    $number = ($year === 2026) ? ($month - 9) : $month;
    return ['year' => $year, 'number' => $number];
}

function tcgSeasonNumberFromId(string $seasonId): int {
    $parts = tcgSeasonLabelParts($seasonId);
    return $parts['number'] ?? 0;
}

function tcgSeasonLabel(string $seasonId): string {
    $parts = tcgSeasonLabelParts($seasonId);
    if ($parts === null) {
        return 'Season';
    }
    return $parts['year'] . ' Season ' . $parts['number'];
}

/**
 * @return array{active:bool,id?:string,number?:int,label?:string,starts_at?:int,ends_at?:int,now:int}
 */
function tcgSeasonClockInfo(): array {
    $now = tcgSeasonNow();
    if ($now < TCG_SEASON_EPOCH) {
        return ['active' => false, 'now' => $now];
    }
    $year = (int)gmdate('Y', $now);
    $month = (int)gmdate('n', $now);
    $id = sprintf('%04d-%02d', $year, $month);
    $number = tcgSeasonNumberFromId($id);
    return [
        'active' => true,
        'id' => $id,
        'number' => $number,
        'label' => tcgSeasonLabel($id),
        'starts_at' => gmmktime(0, 0, 0, $month, 1, $year),
        'ends_at' => gmmktime(0, 0, 0, $month + 1, 1, $year),
        'now' => $now,
    ];
}

function tcgSeasonClampDelta(int $delta): int {
    $n = abs($delta);
    if ($n < 8) {
        $n = 8;
    }
    if ($n > 32) {
        $n = 32;
    }
    return $n;
}

/**
 * Points exchanged for a ranked seasonal result from seasonal steps only.
 * Pink S scores as Green S. Same-step matches yield 16; beating a higher
 * seasonal rank yields more (up to 32), beating a lower one less (floor 8).
 */
function tcgSeasonPointDelta(int $winnerStep, int $loserStep): int {
    $w = tcgSeasonClampStep($winnerStep);
    $l = tcgSeasonClampStep($loserStep);
    if ($w >= TCG_SEASON_PINK_S_STEP) {
        $w = TCG_SEASON_GREEN_S_STEP;
    }
    if ($l >= TCG_SEASON_PINK_S_STEP) {
        $l = TCG_SEASON_GREEN_S_STEP;
    }
    $k = 32;
    // Scale 2 across 0–6 steps: ±2 ranks ≈ underdog ~29 / favorite floor 8.
    $expectedW = 1 / (1 + pow(10, ($l - $w) / 2));
    return tcgSeasonClampDelta((int)round($k * (1 - $expectedW)));
}

/**
 * Resolve a player's seasonal step for delta math (0 if no row yet).
 */
function tcgSeasonStepForDelta(string $discordId, string $gameMode): int {
    $row = tcgSeasonLoadRow($discordId, $gameMode);
    if (!$row) {
        return 0;
    }
    $clock = tcgSeasonClockInfo();
    if (!empty($clock['active']) && (string)$row['season_id'] !== (string)$clock['id']) {
        return 0;
    }
    return tcgSeasonClampStep((int)$row['step']);
}

function tcgSeasonClampStep(int $step): int {
    if ($step < 0) {
        return 0;
    }
    if ($step > 7) {
        return 7;
    }
    return $step;
}

/**
 * Win adds points (and promotes through Green S). A loss on C does nothing.
 * B cannot fall below Green B. A and S cannot fall below Green A.
 * At those floor ranks (Green B / Green A), losses also leave the point bar
 * unchanged — same protection as C — so a single loss cannot wipe progress.
 * Pink S is not earned by filling the bar. Points on Green S keep climbing
 * and the top 10 qualified players are assigned Pink S separately.
 *
 * @return array{step:int,points:int}
 */
function tcgSeasonMovePoints(int $step, int $points, int $delta, bool $win): array {
    $step = tcgSeasonClampStep($step);
    if ($step >= TCG_SEASON_PINK_S_STEP) {
        $step = TCG_SEASON_GREEN_S_STEP;
    }
    $points = max(0, $points);
    $delta = tcgSeasonClampDelta($delta);
    if (!$win) {
        // C (0–1): fully protected. Green B (2) / Green A (4): floor ranks —
        // cannot demote further and do not lose bar progress on a loss.
        if ($step <= 1) {
            return ['step' => $step, 'points' => $points];
        }
        $floor = $step <= 3 ? 2 : 4;
        if ($step === $floor) {
            return ['step' => $step, 'points' => $points];
        }
        $points -= $delta;
        while ($points < 0 && $step > $floor) {
            $step--;
            $points += TCG_SEASON_POINTS;
        }
        if ($points < 0) {
            $points = 0;
        }
        return ['step' => $step, 'points' => $points];
    }
    $points += $delta;
    while ($points >= TCG_SEASON_POINTS && $step < TCG_SEASON_GREEN_S_STEP) {
        $points -= TCG_SEASON_POINTS;
        $step++;
    }
    return ['step' => $step, 'points' => $points];
}

/**
 * Pink S is the current top 10 players in this mode who have filled Green S
 * (100 points on that step). A higher score takes the slot and the previous
 * 10th returns to Green S. The Pink S peak is live: only the current holders
 * keep it for the end-of-season reward.
 */
function tcgSeasonAssignPinkSlots(string $gameMode, string $seasonId): void {
    if ($gameMode === '' || $seasonId === '') {
        return;
    }
    $db = tcgDb();
    $banExclude = '';
    if (function_exists('tcgBanEnsureSchema') && function_exists('tcgBanLeaderboardExcludeSql')) {
        tcgBanEnsureSchema();
        $banExclude = tcgBanLeaderboardExcludeSql('discord_id');
    }
    $stmt = $db->prepare('SELECT discord_id FROM tcg_season_rank
        WHERE game_mode = ? AND season_id = ? AND step >= ? AND points >= ?' . $banExclude . '
        ORDER BY points DESC, wins DESC, updated_at ASC, discord_id ASC');
    $stmt->execute([$gameMode, $seasonId, TCG_SEASON_GREEN_S_STEP, TCG_SEASON_POINTS]);
    $keep = [];
    while ($id = $stmt->fetchColumn()) {
        $keep[] = (string)$id;
        if (count($keep) >= TCG_SEASON_PINK_S_SLOTS) {
            break;
        }
    }
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE tcg_season_rank
            SET step = ?,
                peak_step = CASE WHEN peak_step > ? THEN ? ELSE peak_step END
            WHERE game_mode = ? AND season_id = ? AND step >= ?')
            ->execute([
                TCG_SEASON_GREEN_S_STEP,
                TCG_SEASON_GREEN_S_STEP,
                TCG_SEASON_GREEN_S_STEP,
                $gameMode,
                $seasonId,
                TCG_SEASON_PINK_S_STEP,
            ]);
        if ($keep !== []) {
            $placeholders = implode(',', array_fill(0, count($keep), '?'));
            $promote = $db->prepare('UPDATE tcg_season_rank
                SET step = ?, peak_step = ?
                WHERE game_mode = ? AND season_id = ? AND discord_id IN (' . $placeholders . ')');
            $promote->execute(array_merge(
                [TCG_SEASON_PINK_S_STEP, TCG_SEASON_PINK_S_STEP, $gameMode, $seasonId],
                $keep
            ));
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function tcgSeasonSoftResetStep(int $finalStep): int {
    return max(0, tcgSeasonClampStep($finalStep) - 3);
}

/** @return array{coins:int,gems:int,packs:int} */
function tcgSeasonRewardForStep(int $step): array {
    $step = tcgSeasonClampStep($step);
    return TCG_SEASON_REWARDS[$step];
}

/** @return array{key:string,letter:string,tone:string} */
function tcgSeasonStepDef(int $step): array {
    return TCG_SEASON_STEPS[tcgSeasonClampStep($step)];
}

function tcgSeasonIconUrl(int $step): string {
    return 'client/img/ranks/' . tcgSeasonStepDef($step)['key'] . '.png';
}

function tcgSeasonLadderSteps(): array {
    $out = [];
    foreach (TCG_SEASON_STEPS as $i => $def) {
        $out[] = [
            'step' => $i,
            'letter' => $def['letter'],
            'tone' => $def['tone'],
            'key' => $def['key'],
            'icon' => tcgSeasonIconUrl($i),
        ];
    }
    return $out;
}

/**
 * Before the first season, everyone sits on the lowest rank so the icon can still show.
 *
 * @return array<string,mixed>
 */
function tcgSeasonPreviewPublic(): array {
    $def = tcgSeasonStepDef(0);
    $clock = tcgSeasonClockInfo();
    $firstStarts = TCG_SEASON_EPOCH;
    $firstEnds = gmmktime(0, 0, 0, 11, 1, 2026);
    return [
        'active' => false,
        'started' => false,
        'has_row' => false,
        'season_id' => '2026-10',
        'season_number' => 1,
        'label' => tcgSeasonLabel('2026-10'),
        'starts_at' => $firstStarts,
        'ends_at' => $firstEnds,
        'now' => (int)($clock['now'] ?? tcgSeasonNow()),
        'step' => 0,
        'points' => 0,
        'progress' => 0,
        'peak_step' => 0,
        'wins' => 0,
        'losses' => 0,
        'letter' => $def['letter'],
        'tone' => $def['tone'],
        'key' => $def['key'],
        'icon' => tcgSeasonIconUrl(0),
        'peak_letter' => $def['letter'],
        'peak_tone' => $def['tone'],
        'peak_icon' => tcgSeasonIconUrl(0),
        'steps' => tcgSeasonLadderSteps(),
    ];
}

function tcgSeasonTitleId(string $seasonId, int $step): string {
    return 'season-' . $seasonId . '-' . tcgSeasonStepDef($step)['key'];
}

function tcgSeasonTitleImageUrl(string $titleId): string {
    return 'client/img/titles/' . $titleId . '.png';
}

function tcgSeasonTitleDefFromId(string $id): ?array {
    if (!preg_match('/^season-(\d{4}-\d{2})-([cbas])-(green|pink)$/', $id, $m)) {
        return null;
    }
    $seasonId = $m[1];
    $number = tcgSeasonNumberFromId($seasonId);
    if ($number < 1) {
        return null;
    }
    $key = $m[2] . '-' . $m[3];
    $step = null;
    foreach (TCG_SEASON_STEPS as $i => $def) {
        if ($def['key'] === $key) {
            $step = $i;
            break;
        }
    }
    if ($step === null) {
        return null;
    }
    $def = tcgSeasonStepDef($step);
    return [
        'id' => $id,
        'tier' => 'season',
        'style' => 'wide',
        'name' => tcgSeasonLabel($seasonId),
        'idol' => '',
        'idol_short' => '',
        'unit' => '',
        'image' => tcgSeasonTitleImageUrl($id),
        'unlock' => 'season',
        'unlock_plays' => 0,
        'letter' => $def['letter'],
        'tone' => $def['tone'],
        'season_id' => $seasonId,
        'season_number' => $number,
        'step' => $step,
        'sort' => 1,
    ];
}

function tcgSeasonTitleOwned(string $discordId, string $titleId): bool {
    if ($discordId === '' || $titleId === '') {
        return false;
    }
    $stmt = tcgDb()->prepare(
        'SELECT 1 FROM tcg_season_history WHERE discord_id = ? AND title_id = ? LIMIT 1'
    );
    $stmt->execute([$discordId, $titleId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Season titles this player has earned (one row per title id).
 *
 * @return list<array<string,mixed>>
 */
function tcgSeasonTitleDefsForUser(string $discordId): array {
    $stmt = tcgDb()->prepare(
        'SELECT title_id FROM tcg_season_history
         WHERE discord_id = ? AND title_id IS NOT NULL AND title_id != ""
         GROUP BY title_id
         ORDER BY season_id DESC, title_id ASC'
    );
    $stmt->execute([$discordId]);
    $out = [];
    while ($id = $stmt->fetchColumn()) {
        $def = tcgSeasonTitleDefFromId((string)$id);
        if ($def) {
            $out[] = $def;
        }
    }
    return $out;
}

/**
 * @param array<string,mixed>|null $row
 * @param array<string,mixed> $clock
 * @return array<string,mixed>
 */
function tcgSeasonFormatPublic(?array $row, array $clock): array {
    if (empty($clock['active'])) {
        return ['active' => false];
    }
    $step = $row ? tcgSeasonClampStep((int)$row['step']) : 0;
    $points = $row ? max(0, (int)$row['points']) : 0;
    $peak = $row ? tcgSeasonClampStep((int)$row['peak_step']) : $step;
    $def = tcgSeasonStepDef($step);
    $peakDef = tcgSeasonStepDef($peak);
    $progress = (int)max(0, min(100, $points));
    return [
        'active' => true,
        'has_row' => $row !== null,
        'season_id' => (string)$clock['id'],
        'season_number' => (int)$clock['number'],
        'label' => (string)$clock['label'],
        'starts_at' => (int)($clock['starts_at'] ?? 0),
        'ends_at' => (int)($clock['ends_at'] ?? 0),
        'now' => (int)($clock['now'] ?? tcgSeasonNow()),
        'step' => $step,
        'points' => $points,
        'progress' => $progress,
        'peak_step' => $peak,
        'wins' => $row ? (int)$row['wins'] : 0,
        'losses' => $row ? (int)$row['losses'] : 0,
        'letter' => $def['letter'],
        'tone' => $def['tone'],
        'key' => $def['key'],
        'icon' => tcgSeasonIconUrl($step),
        'peak_letter' => $peakDef['letter'],
        'peak_tone' => $peakDef['tone'],
        'peak_icon' => tcgSeasonIconUrl($peak),
    ];
}

function tcgSeasonRankedMode(string $gameMode): ?string {
    $mode = tcgNormalizeGameMode($gameMode);
    if ($mode !== TCG_GAME_MODE_STANDARD) {
        return null;
    }
    return $mode;
}

/** @return array<string,mixed>|null */
function tcgSeasonLoadRow(string $discordId, string $gameMode): ?array {
    $stmt = tcgDb()->prepare('SELECT * FROM tcg_season_rank WHERE discord_id = ? AND game_mode = ?');
    $stmt->execute([$discordId, $gameMode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Close a finished season once, pay the peak, and soft-reset into the current month.
 *
 * @return array<string,mixed>|null reward payload when this call paid coins/gems
 */
function tcgSeasonSettle(string $discordId, string $gameMode): ?array {
    $mode = tcgSeasonRankedMode($gameMode);
    if ($mode === null || $discordId === '') {
        return null;
    }
    $clock = tcgSeasonClockInfo();
    if (empty($clock['active'])) {
        return null;
    }
    $row = tcgSeasonLoadRow($discordId, $mode);
    $granted = null;
    if ($row && (string)$row['season_id'] !== (string)$clock['id']) {
        $granted = tcgSeasonCloseRow($discordId, $mode, $row, $clock);
    }
    tcgSeasonFinishPendingPacks($discordId, $mode);
    return $granted;
}

/**
 * @param array<string,mixed> $row
 * @param array<string,mixed> $clock
 * @return array<string,mixed>|null
 */
function tcgSeasonCloseRow(string $discordId, string $gameMode, array $row, array $clock): ?array {
    $oldId = (string)$row['season_id'];
    $finalStep = tcgSeasonClampStep((int)$row['step']);
    $peak = tcgSeasonClampStep(max($finalStep, (int)$row['peak_step']));
    $reward = tcgSeasonRewardForStep($peak);
    $titleId = tcgSeasonTitleId($oldId, $peak);
    $number = tcgSeasonNumberFromId($oldId);
    tcgSeasonEnsureTitleFile($oldId, $peak);
    $resetStep = tcgSeasonSoftResetStep($finalStep);
    $now = tcgSeasonNow();
    $db = tcgDb();
    $paid = false;
    $db->beginTransaction();
    try {
        $have = $db->prepare('SELECT 1 FROM tcg_season_history WHERE discord_id = ? AND game_mode = ? AND season_id = ?');
        $have->execute([$discordId, $gameMode, $oldId]);
        if ($have->fetchColumn()) {
            $db->prepare('UPDATE tcg_season_rank
                SET season_id = ?, step = ?, points = 0, peak_step = ?, wins = 0, losses = 0, updated_at = ?
                WHERE discord_id = ? AND game_mode = ? AND season_id = ?')
                ->execute([$clock['id'], $resetStep, $resetStep, $now, $discordId, $gameMode, $oldId]);
            $db->commit();
            return null;
        }
        $ins = $db->prepare('INSERT INTO tcg_season_history (
            discord_id, game_mode, season_id, season_number, step, points, peak_step,
            wins, losses, coins, star_gems, pr_packs, packs_granted, title_id, closed_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([
            $discordId,
            $gameMode,
            $oldId,
            $number,
            $finalStep,
            max(0, (int)$row['points']),
            $peak,
            (int)$row['wins'],
            (int)$row['losses'],
            $reward['coins'],
            $reward['gems'],
            $reward['packs'],
            $reward['packs'] > 0 ? 0 : 1,
            $titleId,
            $now,
        ]);
        $paid = $ins->rowCount() > 0;
        if ($paid) {
            if (!function_exists('tcgAddCoins')) {
                require_once __DIR__ . '/coins.php';
            }
            if ($reward['coins'] > 0) {
                tcgAddCoins($discordId, $reward['coins']);
            }
            if ($reward['gems'] > 0) {
                tcgAddStarGems($discordId, $reward['gems']);
            }
        }
        $db->prepare('UPDATE tcg_season_rank
            SET season_id = ?, step = ?, points = 0, peak_step = ?, wins = 0, losses = 0, updated_at = ?
            WHERE discord_id = ? AND game_mode = ? AND season_id = ?')
            ->execute([$clock['id'], $resetStep, $resetStep, $now, $discordId, $gameMode, $oldId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'constraint')) {
            $db->prepare('UPDATE tcg_season_rank
                SET season_id = ?, step = ?, points = 0, peak_step = ?, wins = 0, losses = 0, updated_at = ?
                WHERE discord_id = ? AND game_mode = ? AND season_id = ?')
                ->execute([$clock['id'], $resetStep, $resetStep, $now, $discordId, $gameMode, $oldId]);
            return null;
        }
        throw $e;
    }
    if (!$paid) {
        return null;
    }
    return [
        'game_mode' => $gameMode,
        'season_id' => $oldId,
        'season_number' => $number,
        'label' => tcgSeasonLabel($oldId),
        'peak_step' => $peak,
        'coins' => $reward['coins'],
        'gems' => $reward['gems'],
        'pr_packs' => $reward['packs'],
        'title_id' => $titleId,
    ];
}

function tcgSeasonFinishPendingPacks(string $discordId, string $gameMode): void {
    $stmt = tcgDb()->prepare('SELECT season_id, pr_packs FROM tcg_season_history
        WHERE discord_id = ? AND game_mode = ? AND pr_packs > 0 AND packs_granted = 0');
    $stmt->execute([$discordId, $gameMode]);
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$pending) {
        return;
    }
    if (!function_exists('tcgGrantPrPackCards')) {
        require_once __DIR__ . '/ranked_pr_rewards.php';
    }
    foreach ($pending as $hist) {
        $packs = max(0, (int)$hist['pr_packs']);
        if ($packs <= 0) {
            continue;
        }
        // One PR pack whose size is this number (2, 3, 4, or 5 cards).
        $cards = max(1, min(15, $packs));
        try {
            tcgGrantPrPackCards($discordId, $cards);
            tcgDb()->prepare('UPDATE tcg_season_history SET packs_granted = 1
                WHERE discord_id = ? AND game_mode = ? AND season_id = ?')
                ->execute([$discordId, $gameMode, $hist['season_id']]);
        } catch (Throwable $e) {
            error_log('tcgSeasonFinishPendingPacks: ' . $e->getMessage());
        }
    }
}

function tcgSeasonEnsureTitleFile(string $seasonId, int $step): void {
    $titleId = tcgSeasonTitleId($seasonId, $step);
    $path = __DIR__ . '/' . tcgSeasonTitleImageUrl($titleId);
    if (is_file($path)) {
        return;
    }
    $gen = __DIR__ . '/scripts/generate_season_titles.php';
    if (!is_file($gen)) {
        return;
    }
    require_once $gen;
    if (function_exists('tcgSeasonGenerateTitlePng')) {
        tcgSeasonGenerateTitlePng($seasonId, $step, $path);
    }
}

function tcgSeasonBump(string $discordId, string $gameMode, int $delta, bool $win): void {
    $clock = tcgSeasonClockInfo();
    if (empty($clock['active'])) {
        return;
    }
    $row = tcgSeasonLoadRow($discordId, $gameMode);
    $now = tcgSeasonNow();
    if (!$row || (string)$row['season_id'] !== (string)$clock['id']) {
        tcgSeasonSettle($discordId, $gameMode);
        $row = tcgSeasonLoadRow($discordId, $gameMode);
    }
    $step = $row ? (int)$row['step'] : 0;
    $points = $row ? (int)$row['points'] : 0;
    $peak = $row ? (int)$row['peak_step'] : 0;
    $wins = $row ? (int)$row['wins'] : 0;
    $losses = $row ? (int)$row['losses'] : 0;
    $moved = tcgSeasonMovePoints($step, $points, $delta, $win);
    if ($win) {
        $wins++;
    } else {
        $losses++;
    }
    $peak = max($peak, $moved['step']);
    if ($peak > TCG_SEASON_GREEN_S_STEP) {
        $peak = TCG_SEASON_GREEN_S_STEP;
    }
    $db = tcgDb();
    $db->prepare('INSERT INTO tcg_season_rank
        (discord_id, game_mode, season_id, step, points, peak_step, wins, losses, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(discord_id, game_mode) DO UPDATE SET
            season_id = excluded.season_id,
            step = excluded.step,
            points = excluded.points,
            peak_step = excluded.peak_step,
            wins = excluded.wins,
            losses = excluded.losses,
            updated_at = excluded.updated_at')
        ->execute([
            $discordId,
            $gameMode,
            $clock['id'],
            $moved['step'],
            $moved['points'],
            $peak,
            $wins,
            $losses,
            $now,
        ]);
    tcgSeasonAssignPinkSlots($gameMode, (string)$clock['id']);
}

function tcgSeasonApplyResult(string $winnerId, string $loserId, bool $isDraw, string $gameMode): void {
    if ($isDraw) {
        return;
    }
    $mode = tcgSeasonRankedMode($gameMode);
    if ($mode === null || empty(tcgSeasonClockInfo()['active'])) {
        return;
    }
    tcgSeasonSettle($winnerId, $mode);
    tcgSeasonSettle($loserId, $mode);
    $winnerStep = tcgSeasonStepForDelta($winnerId, $mode);
    $loserStep = tcgSeasonStepForDelta($loserId, $mode);
    $delta = tcgSeasonPointDelta($winnerStep, $loserStep);
    tcgSeasonBump($winnerId, $mode, $delta, true);
    tcgSeasonBump($loserId, $mode, $delta, false);
}

/** @return array{step:int,points:int} */
function tcgSeasonQueueProfile(string $discordId, string $gameMode): array {
    $clock = tcgSeasonClockInfo();
    if (empty($clock['active'])) {
        return ['step' => -1, 'points' => 0];
    }
    $mode = tcgSeasonRankedMode($gameMode);
    if ($mode === null) {
        return ['step' => -1, 'points' => 0];
    }
    tcgSeasonSettle($discordId, $mode);
    $row = tcgSeasonLoadRow($discordId, $mode);
    if (!$row || (string)$row['season_id'] !== (string)$clock['id']) {
        return ['step' => 0, 'points' => 0];
    }
    return [
        'step' => tcgSeasonClampStep((int)$row['step']),
        'points' => max(0, min(TCG_SEASON_POINTS, (int)$row['points'])),
    ];
}

/**
 * Steps the searcher will prefer, best first. Empty when the season is off.
 *
 * @return list<int>
 */
function tcgSeasonQueueAcceptSteps(int $step, int $points): array {
    if ($step < 0) {
        return [];
    }
    $step = tcgSeasonClampStep($step);
    $out = [$step];
    if ($points >= 75 && $step < 7) {
        $out[] = $step + 1;
    }
    if ($points <= 25 && $step > 0) {
        $out[] = $step - 1;
    }
    return $out;
}

/** @return array<string,mixed> */
function tcgSeasonPublic(string $discordId, string $gameMode): array {
    $clock = tcgSeasonClockInfo();
    $mode = tcgSeasonRankedMode($gameMode);
    if ($mode === null) {
        return ['active' => false];
    }
    if (empty($clock['active'])) {
        $preview = tcgSeasonPreviewPublic();
        $preview['game_mode'] = $mode;
        return $preview;
    }
    $row = tcgSeasonLoadRow($discordId, $mode);
    if ($row && (string)$row['season_id'] !== (string)$clock['id']) {
        $row = null;
    }
    $public = tcgSeasonFormatPublic($row, $clock);
    $public['game_mode'] = $mode;
    $public['started'] = true;
    $public['steps'] = tcgSeasonLadderSteps();
    return $public;
}

/**
 * @return array{season:array<string,mixed>,seasons:array<string,array<string,mixed>>,season_rewards:list<array<string,mixed>>}
 */
function tcgSeasonBundleForUser(string $discordId): array {
    $mode = TCG_GAME_MODE_STANDARD;
    $grant = tcgSeasonSettle($discordId, $mode);
    $season = tcgSeasonPublic($discordId, $mode);
    return [
        'season' => $season,
        'seasons' => [$mode => $season],
        'season_rewards' => $grant ? [$grant] : [],
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function tcgSeasonHistoryForUser(string $discordId): array {
    $stmt = tcgDb()->prepare('SELECT * FROM tcg_season_history
        WHERE discord_id = ? AND game_mode = ? ORDER BY season_id DESC');
    $stmt->execute([$discordId, TCG_GAME_MODE_STANDARD]);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $final = tcgSeasonClampStep((int)$row['step']);
        $peak = tcgSeasonClampStep((int)$row['peak_step']);
        $finalDef = tcgSeasonStepDef($final);
        $peakDef = tcgSeasonStepDef($peak);
        $number = (int)$row['season_number'];
        $out[] = [
            'season_id' => (string)$row['season_id'],
            'season_number' => $number,
            'label' => tcgSeasonLabel((string)$row['season_id']),
            'game_mode' => (string)$row['game_mode'],
            'step' => $final,
            'points' => (int)$row['points'],
            'letter' => $finalDef['letter'],
            'tone' => $finalDef['tone'],
            'icon' => tcgSeasonIconUrl($final),
            'peak_step' => $peak,
            'peak_letter' => $peakDef['letter'],
            'peak_tone' => $peakDef['tone'],
            'peak_icon' => tcgSeasonIconUrl($peak),
            'wins' => (int)$row['wins'],
            'losses' => (int)$row['losses'],
            'title_id' => $row['title_id'] ?? null,
        ];
    }
    return $out;
}

/**
 * @return array<string,array<string,mixed>>
 */
function tcgSeasonMapForMode(string $gameMode, string $seasonId): array {
    $stmt = tcgDb()->prepare('SELECT * FROM tcg_season_rank WHERE game_mode = ? AND season_id = ?');
    $stmt->execute([$gameMode, $seasonId]);
    $clock = tcgSeasonClockInfo();
    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[(string)$row['discord_id']] = tcgSeasonFormatPublic($row, $clock);
    }
    return $map;
}
