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

/** Persist all derived counters for one card event. */
function tcgRecordPlayStats(string $discordId, string $tracker, array $card): void {
    if ($discordId === '') {
        return;
    }
    foreach (tcgPlayStatIncrementsForCard($tracker, $card) as $row) {
        tcgBumpPlayStat($discordId, $tracker, $row['dim'], $row['key'], 1);
    }
}

function tcgPlayStatsSeatIsTrackable(array $state, string $pid): ?string {
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
 * Attribute a play event to the signed-in human on this seat (no-op for CPU/guests).
 * Safe to call from rules code even when DB is unavailable (swallows errors).
 */
function tcgTrackPlayerCardEvent(array $state, string $pid, string $tracker, array $card): void {
    try {
        $discordId = tcgPlayStatsSeatIsTrackable($state, $pid);
        if ($discordId === null) {
            return;
        }
        if (function_exists('mergeCardCatalogFields')) {
            mergeCardCatalogFields($card);
        }
        tcgRecordPlayStats($discordId, $tracker, $card);
    } catch (Throwable $e) {
        // Never break gameplay for analytics.
        if (function_exists('error_log')) {
            error_log('tcg play_stats: ' . $e->getMessage());
        }
    }
}

/** Member entered Stage (hand play, baton, WR put, re-enter, skill place, …). */
function notifyMemberEnteredStage(array $state, string $pid, array $member): void {
    if (!tcgCardIsMember($member)) {
        return;
    }
    tcgTrackPlayerCardEvent($state, $pid, TCG_PLAY_TRACKER_STAGE, $member);
}

/** Live (or tracked card) entered Success Live storage. */
function notifyLiveEnteredSuccess(array $state, string $pid, array $live): void {
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
