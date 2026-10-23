<?php
/**
 * Per-user play counters for Stage Member enters and Live Success storage.
 *
 * Cards may declare optional play_track metadata:
 * {
 *   "members": ["Honoka Kosaka"],   // idols credited (stage / optional on Lives)
 *   "unit": "μ's",                  // group / school idol project unit
 *   "subunit": "Printemps",
 *   "live_name": "START:DASH!!"     // Live title (Live cards)
 * }
 * When omitted, tags are derived from name_en / group / subunit / card_no.
 * Energy cards are never counted.
 *
 * Match-primary (Redis) buffers increments on room state as `_play_stat_deltas`
 * and posts them to Hostinger on finish so hub missions see the same counters.
 *
 * live_success = a Live was performed and succeeded (not merely moved into
 * the Success pile by a swap/replace effect).
 */

require_once __DIR__ . '/db.php';

const TCG_PLAY_TRACKER_STAGE = 'stage';
const TCG_PLAY_TRACKER_LIVE_SUCCESS = 'live_success';

const TCG_PLAY_DIM_IDOL = 'idol';
const TCG_PLAY_DIM_UNIT = 'unit';
const TCG_PLAY_DIM_SUBUNIT = 'subunit';
const TCG_PLAY_DIM_LIVE_NAME = 'live_name';
const TCG_PLAY_DIM_CARD = 'card';

function tcgPlayStatsEnsureSchema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $db = tcgDb();
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_play_stats (
        discord_id TEXT NOT NULL,
        tracker TEXT NOT NULL,
        dim TEXT NOT NULL,
        key TEXT NOT NULL,
        count INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL,
        PRIMARY KEY (discord_id, tracker, dim, key),
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_play_stats_user_tracker
        ON tcg_play_stats(discord_id, tracker)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_play_stat_match_applied (
        room_id TEXT NOT NULL PRIMARY KEY,
        applied_at INTEGER NOT NULL
    )');
    $done = true;
}

/** Split compound idol names ("A & B" / "A ＆ B"). */
function tcgSplitIdolNames(string $name): array {
    $name = trim($name);
    if ($name === '') {
        return [];
    }
    $parts = preg_split('/\s*[&＆]\s*/u', $name) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return $out ?: [$name];
}

function tcgCardIsEnergy(array $card): bool {
    $type = (string)($card['card_type'] ?? '');
    $typeEn = strtolower((string)($card['card_type_en'] ?? ''));
    return $type === 'エネルギー' || $typeEn === 'energy';
}

function tcgCardIsMember(array $card): bool {
    $type = (string)($card['card_type'] ?? '');
    $typeEn = strtolower((string)($card['card_type_en'] ?? ''));
    return $type === 'メンバー' || $typeEn === 'member';
}

function tcgCardIsLive(array $card): bool {
    $type = (string)($card['card_type'] ?? '');
    $typeEn = strtolower((string)($card['card_type_en'] ?? ''));
    return $type === 'ライブ' || $typeEn === 'live';
}

/**
 * Resolve trackable tags for a card.
 *
 * @return array{
 *   idols: list<string>,
 *   units: list<string>,
 *   subunits: list<string>,
 *   live_names: list<string>,
 *   card_nos: list<string>
 * }
 */
function cardPlayTrackTags(array $card): array {
    $track = is_array($card['play_track'] ?? null) ? $card['play_track'] : [];
    $name = trim((string)(function_exists('cardNameKey')
        ? cardNameKey($card)
        : ($card['name_en'] ?? $card['name'] ?? '')));
    $group = trim((string)($card['group'] ?? ''));
    $subunit = trim((string)($card['subunit'] ?? ''));
    $cardNo = trim((string)($card['card_no'] ?? ''));

    $idols = [];
    if (isset($track['members']) && is_array($track['members'])) {
        foreach ($track['members'] as $m) {
            $m = trim((string)$m);
            if ($m !== '') {
                $idols[] = $m;
            }
        }
    } elseif (isset($track['member'])) {
        $idols = tcgSplitIdolNames((string)$track['member']);
    } elseif (tcgCardIsMember($card) && $name !== '') {
        $idols = tcgSplitIdolNames($name);
    }

    $units = [];
    if (isset($track['unit']) && trim((string)$track['unit']) !== '') {
        $units[] = trim((string)$track['unit']);
    } elseif (isset($track['units']) && is_array($track['units'])) {
        foreach ($track['units'] as $u) {
            $u = trim((string)$u);
            if ($u !== '') {
                $units[] = $u;
            }
        }
    } elseif ($group !== '') {
        $units[] = $group;
    }

    $subunits = [];
    if (isset($track['subunit']) && trim((string)$track['subunit']) !== '') {
        $subunits[] = trim((string)$track['subunit']);
    } elseif (isset($track['subunits']) && is_array($track['subunits'])) {
        foreach ($track['subunits'] as $s) {
            $s = trim((string)$s);
            if ($s !== '') {
                $subunits[] = $s;
            }
        }
    } elseif ($subunit !== '') {
        $subunits[] = $subunit;
    }

    $liveNames = [];
    if (isset($track['live_name']) && trim((string)$track['live_name']) !== '') {
        $liveNames[] = trim((string)$track['live_name']);
    } elseif (isset($track['live_names']) && is_array($track['live_names'])) {
        foreach ($track['live_names'] as $ln) {
            $ln = trim((string)$ln);
            if ($ln !== '') {
                $liveNames[] = $ln;
            }
        }
    } elseif (tcgCardIsLive($card) && $name !== '') {
        $liveNames[] = $name;
    }

    $cardNos = [];
    if ($cardNo !== '') {
        $cardNos[] = $cardNo;
    }

    return [
        'idols' => array_values(array_unique($idols)),
        'units' => array_values(array_unique($units)),
        'subunits' => array_values(array_unique($subunits)),
        'live_names' => array_values(array_unique($liveNames)),
        'card_nos' => array_values(array_unique($cardNos)),
    ];
}

/**
 * @return list<array{dim: string, key: string}>
 */
function tcgPlayStatIncrementsForCard(string $tracker, array $card): array {
    if (tcgCardIsEnergy($card)) {
        return [];
    }
    if ($tracker === TCG_PLAY_TRACKER_STAGE && !tcgCardIsMember($card)) {
        return [];
    }
    if ($tracker === TCG_PLAY_TRACKER_LIVE_SUCCESS && !tcgCardIsLive($card) && !tcgCardIsMember($card)) {
        // Success storage is Live cards; allow Member only if explicit play_track says so.
        $track = is_array($card['play_track'] ?? null) ? $card['play_track'] : [];
        if (empty($track)) {
            return [];
        }
    }

    $tags = cardPlayTrackTags($card);
    $rows = [];
    foreach ($tags['idols'] as $idol) {
        $rows[] = ['dim' => TCG_PLAY_DIM_IDOL, 'key' => $idol];
    }
    foreach ($tags['units'] as $unit) {
        $rows[] = ['dim' => TCG_PLAY_DIM_UNIT, 'key' => $unit];
    }
    foreach ($tags['subunits'] as $subunit) {
        $rows[] = ['dim' => TCG_PLAY_DIM_SUBUNIT, 'key' => $subunit];
    }
    if ($tracker === TCG_PLAY_TRACKER_LIVE_SUCCESS || tcgCardIsLive($card)) {
        foreach ($tags['live_names'] as $liveName) {
            $rows[] = ['dim' => TCG_PLAY_DIM_LIVE_NAME, 'key' => $liveName];
        }
    }
    foreach ($tags['card_nos'] as $no) {
        $rows[] = ['dim' => TCG_PLAY_DIM_CARD, 'key' => $no];
    }
    return $rows;
}

function tcgBumpPlayStat(string $discordId, string $tracker, string $dim, string $key, int $by = 1): void {
    if ($discordId === '' || $tracker === '' || $dim === '' || $key === '' || $by === 0) {
        return;
    }
    tcgPlayStatsEnsureSchema();
    $db = tcgDb();
    $now = time();
    $db->prepare(
        'INSERT INTO tcg_play_stats (discord_id, tracker, dim, key, count, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT(discord_id, tracker, dim, key) DO UPDATE SET
           count = count + excluded.count,
           updated_at = excluded.updated_at'
    )->execute([$discordId, $tracker, $dim, $key, $by, $now]);
}

/** Persist all derived counters for one card event (direct DB write). */
function tcgRecordPlayStats(string $discordId, string $tracker, array $card): void {
    if ($discordId === '') {
        return;
    }
    foreach (tcgPlayStatIncrementsForCard($tracker, $card) as $row) {
        tcgBumpPlayStat($discordId, $tracker, $row['dim'], $row['key'], 1);
    }
}

function tcgPlayStatsShouldBufferForHostinger(): bool {
    if (!function_exists('tcgMissionShouldWriteOnHostinger')) {
        if (is_file(__DIR__ . '/match_bridge.php')) {
            require_once __DIR__ . '/match_bridge.php';
        }
    }
    return function_exists('tcgMissionShouldWriteOnHostinger') && tcgMissionShouldWriteOnHostinger();
}

/**
 * Accumulate a play-stat delta on room state (for Hostinger bridge).
 *
 * @param array<string,mixed> $state
 */
function tcgNotePlayStatDelta(array &$state, string $discordId, string $tracker, string $dim, string $key, int $by = 1): void {
    if ($discordId === '' || $tracker === '' || $dim === '' || $key === '' || $by === 0) {
        return;
    }
    if (!isset($state['_play_stat_deltas']) || !is_array($state['_play_stat_deltas'])) {
        $state['_play_stat_deltas'] = [];
    }
    if (!isset($state['_play_stat_deltas'][$discordId]) || !is_array($state['_play_stat_deltas'][$discordId])) {
        $state['_play_stat_deltas'][$discordId] = [];
    }
    $bucket = &$state['_play_stat_deltas'][$discordId];
    $found = false;
    foreach ($bucket as &$row) {
        if (($row['tracker'] ?? '') === $tracker
            && ($row['dim'] ?? '') === $dim
            && ($row['key'] ?? '') === $key) {
            $row['count'] = intval($row['count'] ?? 0) + $by;
            $found = true;
            break;
        }
    }
    unset($row);
    if (!$found) {
        $bucket[] = [
            'tracker' => $tracker,
            'dim' => $dim,
            'key' => $key,
            'count' => $by,
        ];
    }
}

/**
 * Export deltas for Hostinger finish payloads.
 *
 * @return array<string, list<array{tracker: string, dim: string, key: string, count: int}>>
 */
function tcgPlayStatDeltasExport(array $state): array {
    $raw = $state['_play_stat_deltas'] ?? null;
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $discordId => $rows) {
        $discordId = trim((string)$discordId);
        if ($discordId === '' || !is_array($rows)) {
            continue;
        }
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tracker = trim((string)($row['tracker'] ?? ''));
            $dim = trim((string)($row['dim'] ?? ''));
            $key = trim((string)($row['key'] ?? ''));
            $count = intval($row['count'] ?? 0);
            if ($tracker === '' || $dim === '' || $key === '' || $count === 0) {
                continue;
            }
            $clean[] = [
                'tracker' => $tracker,
                'dim' => $dim,
                'key' => $key,
                'count' => $count,
            ];
        }
        if ($clean !== []) {
            $out[$discordId] = $clean;
        }
    }
    return $out;
}

/**
 * Apply buffered match deltas once per room (Hostinger account DB).
 * Empty room_id always applies (tests / local without room).
 *
 * @param array<string, list<array{tracker?: string, dim?: string, key?: string, count?: int}>> $deltas
 */
function tcgApplyPlayStatDeltasOnce(?string $roomId, array $deltas): bool {
    if ($deltas === []) {
        return false;
    }
    tcgPlayStatsEnsureSchema();
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($roomId ?? '')) ?? '');
    $db = tcgDb();
    if ($roomId !== '') {
        $ins = $db->prepare('INSERT OR IGNORE INTO tcg_play_stat_match_applied (room_id, applied_at) VALUES (?, ?)');
        $ins->execute([$roomId, time()]);
        if ($ins->rowCount() < 1) {
            return false;
        }
    }
    foreach ($deltas as $discordId => $rows) {
        $discordId = trim((string)$discordId);
        if ($discordId === '' || !is_array($rows)) {
            continue;
        }
        if (function_exists('tcgEnsureUser')) {
            tcgEnsureUser($discordId, []);
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            tcgBumpPlayStat(
                $discordId,
                trim((string)($row['tracker'] ?? '')),
                trim((string)($row['dim'] ?? '')),
                trim((string)($row['key'] ?? '')),
                intval($row['count'] ?? 0)
            );
        }
    }
    return true;
}

/** Guided beginner tutorial (and any mode=tutorial room) must not feed play stats. */
function tcgMatchIsTutorial(array $state): bool {
    if (!empty($state['tutorial_guide']) || !empty($state['is_tutorial'])) {
        return true;
    }
    $mode = strtolower(trim((string)($state['mode'] ?? '')));
    return $mode === 'tutorial';
}

function tcgPlayStatsSeatIsTrackable(array $state, string $pid): ?string {
    if (tcgMatchIsTutorial($state)) {
        return null;
    }
    // Replay seek/convert re-simulates a finished match: it must not credit the players again.
    if (!empty($state['replay_resim'])) {
        return null;
    }
    $player = $state['players'][$pid] ?? null;
    if (!is_array($player)) {
        return null;
    }
    if (!function_exists('tcgMissionSeatIsCpu')) {
        require_once __DIR__ . '/missions.php';
    }
    if (tcgMissionSeatIsCpu($player)) {
        return null;
    }
    if (!function_exists('tcgPlayerDiscordId')) {
        require_once __DIR__ . '/missions.php';
    }
    $discordId = tcgPlayerDiscordId($state, $pid);
    return $discordId !== null && $discordId !== '' ? $discordId : null;
}

/**
 * Same room+seq+instance Stage enter should only count once (guards notify+resolveOnEnter doubles).
 */
function tcgPlayStatStageDedupeHit(array $state, string $pid, array $member): bool {
    $iid = trim((string)($member['instance_id'] ?? ''));
    if ($iid === '') {
        return false;
    }
    static $recent = [];
    $key = (string)($state['room_id'] ?? '')
        . '|' . $pid
        . '|' . $iid
        . '|' . intval($state['seq'] ?? 0);
    if (isset($recent[$key])) {
        return true;
    }
    $recent[$key] = true;
    if (count($recent) > 800) {
        $recent = array_slice($recent, -300, null, true);
    }
    return false;
}

/**
 * Attribute a play event to the signed-in human on this seat (no-op for CPU/guests).
 * Safe to call from rules code even when DB is unavailable (swallows errors).
 *
 * @param array<string,mixed> $state
 */
function tcgTrackPlayerCardEvent(array &$state, string $pid, string $tracker, array $card): void {
    try {
        $discordId = tcgPlayStatsSeatIsTrackable($state, $pid);
        if ($discordId === null) {
            return;
        }
        if (function_exists('mergeCardCatalogFields')) {
            mergeCardCatalogFields($card);
        }
        $increments = tcgPlayStatIncrementsForCard($tracker, $card);
        if ($increments === []) {
            return;
        }
        $buffer = tcgPlayStatsShouldBufferForHostinger();
        foreach ($increments as $row) {
            if ($buffer) {
                tcgNotePlayStatDelta($state, $discordId, $tracker, $row['dim'], $row['key'], 1);
            } else {
                tcgBumpPlayStat($discordId, $tracker, $row['dim'], $row['key'], 1);
            }
        }
    } catch (Throwable $e) {
        // Never break gameplay for analytics.
        if (function_exists('error_log')) {
            error_log('tcg play_stats: ' . $e->getMessage());
        }
    }
}

/** Member entered Stage (hand play, baton, WR put, re-enter, skill place, …). */
function notifyMemberEnteredStage(array &$state, string $pid, array $member): void {
    if (!tcgCardIsMember($member)) {
        return;
    }
    if (tcgPlayStatStageDedupeHit($state, $pid, $member)) {
        return;
    }
    tcgTrackPlayerCardEvent($state, $pid, TCG_PLAY_TRACKER_STAGE, $member);
}

/** Successful Live *performance* (hearts met). Do not call for effect-only Success-pile moves. */
function notifyLiveEnteredSuccess(array &$state, string $pid, array $live): void {
    tcgTrackPlayerCardEvent($state, $pid, TCG_PLAY_TRACKER_LIVE_SUCCESS, $live);
}

function tcgGetPlayStat(string $discordId, string $tracker, string $dim, string $key): int {
    tcgPlayStatsEnsureSchema();
    $stmt = tcgDb()->prepare(
        'SELECT count FROM tcg_play_stats
         WHERE discord_id = ? AND tracker = ? AND dim = ? AND key = ?'
    );
    $stmt->execute([$discordId, $tracker, $dim, $key]);
    $val = $stmt->fetchColumn();
    return $val === false ? 0 : max(0, intval($val));
}

/**
 * @return list<array{dim: string, key: string, count: int}>
 */
function tcgListPlayStats(string $discordId, string $tracker, ?string $dim = null): array {
    tcgPlayStatsEnsureSchema();
    if ($dim !== null && $dim !== '') {
        $stmt = tcgDb()->prepare(
            'SELECT dim, key, count FROM tcg_play_stats
             WHERE discord_id = ? AND tracker = ? AND dim = ?
             ORDER BY count DESC, key ASC'
        );
        $stmt->execute([$discordId, $tracker, $dim]);
    } else {
        $stmt = tcgDb()->prepare(
            'SELECT dim, key, count FROM tcg_play_stats
             WHERE discord_id = ? AND tracker = ?
             ORDER BY dim ASC, count DESC, key ASC'
        );
        $stmt->execute([$discordId, $tracker]);
    }
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'dim' => (string)$row['dim'],
            'key' => (string)$row['key'],
            'count' => max(0, intval($row['count'])),
        ];
    }
    return $rows;
}

/**
 * Catalog-derived keys for play milestones.
 *
 * @return array{idols: list<string>, units: list<string>, subunits: list<string>}
 */
function tcgPlayStatMilestoneKeysFromCatalog(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $idols = [];
    $units = [];
    $subunits = [];
    $path = defined('TCG_CARDS_FILE') ? TCG_CARDS_FILE : (defined('CARDS_FILE') ? CARDS_FILE : '');
    if ($path === '' || !is_file($path)) {
        $cache = ['idols' => [], 'units' => [], 'subunits' => []];
        return $cache;
    }
    $raw = json_decode((string)file_get_contents($path), true);
    $cards = is_array($raw['cards'] ?? null) ? $raw['cards'] : (is_array($raw) ? $raw : []);
    foreach ($cards as $card) {
        if (!is_array($card)) {
            continue;
        }
        $typeEn = strtolower((string)($card['card_type_en'] ?? ''));
        $type = (string)($card['card_type'] ?? '');
        $isMember = $type === 'メンバー' || $typeEn === 'member';
        $isLive = $type === 'ライブ' || $typeEn === 'live';
        if (!$isMember && !$isLive) {
            continue;
        }
        if ($isMember) {
            $name = trim((string)($card['name_en'] ?? $card['name'] ?? ''));
            foreach (tcgSplitIdolNames($name) as $idol) {
                $idols[$idol] = true;
            }
        }
        if ($isLive) {
            $group = trim((string)($card['group'] ?? ''));
            if ($group !== '') {
                $units[$group] = true;
            }
            $subunit = trim((string)($card['subunit'] ?? ''));
            if ($subunit !== '') {
                $subunits[$subunit] = true;
            }
        }
    }
    $idolList = array_keys($idols);
    $unitList = array_keys($units);
    $subunitList = array_keys($subunits);
    natcasesort($idolList);
    natcasesort($unitList);
    natcasesort($subunitList);
    $cache = [
        'idols' => array_values($idolList),
        'units' => array_values($unitList),
        'subunits' => array_values($subunitList),
    ];
    return $cache;
}

/** Player-facing group label (catalog key → display). */
function tcgPlayStatUnitDisplayName(string $unit): string {
    return match ($unit) {
        'Sunshine' => 'Aqours',
        'Superstar' => 'Liella!',
        default => $unit,
    };
}
