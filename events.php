<?php
/**
 * Time-limited Events: Event Points (EP), milestones, ranking rewards.
 * Hostinger SQLite only. EP curves mirror coins for ranked PvP + CPU.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/coins.php';

/** Hub Events tab gate — flip to true when player UI should open. */
const TCG_EVENTS_HUB_ENABLED = false;

const TCG_EVENT_REWARD_TYPES = ['coins', 'star_gems', 'scouting_ticket', 'card', 'sleeve', 'playmat'];

function tcgEventsEnsureSchema(?PDO $db = null): void {
    $db = $db ?? tcgDb();
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_events (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        banner_url TEXT NOT NULL DEFAULT \'\',
        starts_at INTEGER NOT NULL,
        ends_at INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT \'scheduled\',
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL,
        ended_processed_at INTEGER
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_events_status_window
        ON tcg_events(status, starts_at, ends_at)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_event_milestones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id TEXT NOT NULL,
        threshold_points INTEGER NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        reward_type TEXT NOT NULL,
        reward_payload TEXT NOT NULL DEFAULT \'{}\',
        FOREIGN KEY (event_id) REFERENCES tcg_events(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_event_milestones_event
        ON tcg_event_milestones(event_id, threshold_points)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_event_rank_rewards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id TEXT NOT NULL,
        rank_from INTEGER NOT NULL,
        rank_to INTEGER NOT NULL,
        reward_type TEXT NOT NULL,
        reward_payload TEXT NOT NULL DEFAULT \'{}\',
        FOREIGN KEY (event_id) REFERENCES tcg_events(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_event_rank_rewards_event
        ON tcg_event_rank_rewards(event_id, rank_from)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_event_points (
        event_id TEXT NOT NULL,
        discord_id TEXT NOT NULL,
        points INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL,
        PRIMARY KEY (event_id, discord_id),
        FOREIGN KEY (event_id) REFERENCES tcg_events(id) ON DELETE CASCADE,
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_event_points_lb
        ON tcg_event_points(event_id, points DESC)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_event_point_grants (
        room_id TEXT NOT NULL,
        discord_id TEXT NOT NULL,
        event_id TEXT NOT NULL,
        amount INTEGER NOT NULL,
        created_at INTEGER NOT NULL,
        PRIMARY KEY (room_id, discord_id, event_id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_event_milestone_claims (
        event_id TEXT NOT NULL,
        discord_id TEXT NOT NULL,
        milestone_id INTEGER NOT NULL,
        created_at INTEGER NOT NULL,
        PRIMARY KEY (event_id, discord_id, milestone_id),
        FOREIGN KEY (event_id) REFERENCES tcg_events(id) ON DELETE CASCADE,
        FOREIGN KEY (milestone_id) REFERENCES tcg_event_milestones(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_event_rank_grants (
        event_id TEXT NOT NULL,
        discord_id TEXT NOT NULL,
        rank_place INTEGER NOT NULL,
        created_at INTEGER NOT NULL,
        PRIMARY KEY (event_id, discord_id),
        FOREIGN KEY (event_id) REFERENCES tcg_events(id) ON DELETE CASCADE
    )');
}

function tcgEventsNowTs(): int {
    return time();
}

/**
 * Parse admin datetime-local (YYYY-MM-DDTHH:MM) as JST wall clock → unix.
 */
function tcgEventsParseJstDatetime(string $raw): int {
    $raw = trim(str_replace(' ', 'T', $raw));
    if ($raw === '') {
        throw new Exception('Datetime required', 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $raw)) {
        throw new Exception('Invalid datetime (use YYYY-MM-DDTHH:MM JST)', 400);
    }
    if (strlen($raw) === 16) {
        $raw .= ':00';
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $raw, new DateTimeZone('Asia/Tokyo'));
    if (!$dt) {
        throw new Exception('Invalid datetime', 400);
    }
    return $dt->getTimestamp();
}

function tcgEventsFormatJstDatetime(int $ts): string {
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Asia/Tokyo'));
    return $dt->format('Y-m-d\TH:i');
}

function tcgEventsRequireOwner(string $discordId): void {
    if (!function_exists('tcgSocialIsOwner')) {
        require_once __DIR__ . '/social.php';
    }
    if (!tcgSocialIsOwner($discordId)) {
        throw new Exception('Admin only', 403);
    }
}

function tcgEventsNormalizeRewardType(string $type): string {
    $type = strtolower(trim($type));
    if (!in_array($type, TCG_EVENT_REWARD_TYPES, true)) {
        throw new Exception('Invalid reward_type', 400);
    }
    return $type;
}

/**
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function tcgEventsNormalizeRewardPayload(string $type, array $payload): array {
    switch ($type) {
        case 'coins':
        case 'star_gems':
        case 'scouting_ticket':
            $amount = max(1, intval($payload['amount'] ?? 0));
            return ['amount' => $amount];
        case 'card':
            $cardNo = trim((string)($payload['card_no'] ?? ''));
            if ($cardNo === '') {
                throw new Exception('card_no required', 400);
            }
            $qty = max(1, intval($payload['qty'] ?? 1));
            return ['card_no' => $cardNo, 'qty' => $qty];
        case 'sleeve':
            $id = trim((string)($payload['sleeve_id'] ?? ''));
            if ($id === '') {
                throw new Exception('sleeve_id required', 400);
            }
            return ['sleeve_id' => $id];
        case 'playmat':
            $id = trim((string)($payload['playmat_id'] ?? ''));
            if ($id === '') {
                throw new Exception('playmat_id required', 400);
            }
            return ['playmat_id' => $id];
        default:
            throw new Exception('Invalid reward_type', 400);
    }
}

function tcgEventsGrantReward(string $discordId, string $type, array $payload): void {
    tcgEnsureUser($discordId);
    switch ($type) {
        case 'coins':
            tcgAddCoins($discordId, max(1, intval($payload['amount'] ?? 0)));
            break;
        case 'star_gems':
            tcgAddStarGems($discordId, max(1, intval($payload['amount'] ?? 0)));
            break;
        case 'scouting_ticket':
            tcgAddScoutingTickets($discordId, max(1, intval($payload['amount'] ?? 0)));
            break;
        case 'card':
            $cardNo = (string)($payload['card_no'] ?? '');
            $qty = max(1, intval($payload['qty'] ?? 1));
            $nos = array_fill(0, $qty, $cardNo);
            tcgAddCardsToCollection($discordId, $nos);
            break;
        case 'sleeve':
            if (!function_exists('tcgGrantOwnedSleeve')) {
                require_once __DIR__ . '/sleeve_shop.php';
            }
            tcgGrantOwnedSleeve($discordId, (string)$payload['sleeve_id'], 'event');
            break;
        case 'playmat':
            if (!function_exists('tcgGrantOwnedPlaymat')) {
                require_once __DIR__ . '/playmat_shop.php';
            }
            tcgGrantOwnedPlaymat($discordId, (string)$payload['playmat_id'], 'event');
            break;
        default:
            throw new Exception('Invalid reward_type', 400);
    }
}

/**
 * Flip scheduled→active→ended from wall-clock unix (JST-authored starts_at/ends_at).
 * Settles ranking rewards when an event newly ends.
 */
function tcgEventsTickStatuses(): void {
    tcgEventsEnsureSchema();
    $db = tcgDb();
    $now = tcgEventsNowTs();
    $db->prepare("UPDATE tcg_events SET status = 'active', updated_at = ?
        WHERE status = 'scheduled' AND starts_at <= ? AND ends_at > ?")
        ->execute([$now, $now, $now]);
    $stmt = $db->prepare("SELECT id FROM tcg_events
        WHERE status IN ('scheduled','active') AND ends_at <= ?");
    $stmt->execute([$now]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($ids as $id) {
        $db->prepare("UPDATE tcg_events SET status = 'ended', updated_at = ? WHERE id = ?")
            ->execute([$now, $id]);
        tcgEventsSettleEnded((string)$id);
    }
}

/** @return list<array<string,mixed>> */
function tcgEventsActive(): array {
    tcgEventsTickStatuses();
    $db = tcgDb();
    $now = tcgEventsNowTs();
    $stmt = $db->prepare("SELECT * FROM tcg_events
        WHERE status = 'active' AND starts_at <= ? AND ends_at > ?
        ORDER BY starts_at ASC");
    $stmt->execute([$now, $now]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tcgEventsIsRankedMatch(array $state): bool {
    if (($state['mode'] ?? '') === 'ranked') {
        return true;
    }
    return is_array($state['ranked'] ?? null) && $state['ranked'] !== [];
}

function tcgEventsIsCpuMatch(array $state): bool {
    if (!empty($state['cpu_solo']) || !empty($state['cpu_difficulty'])) {
        return true;
    }
    $mode = strtolower((string)($state['mode'] ?? ''));
    return $mode === 'cpu' || str_contains($mode, 'cpu');
}

/**
 * EP for a human seat — same amounts as coins, but only ranked PvP or CPU.
 */
function tcgEventPointsForFinishedMatch(array $state, string $pid): int {
    if (!tcgCoinsNaturalFinish($state)) {
        return 0;
    }
    if ($pid !== 'p1' && $pid !== 'p2') {
        return 0;
    }
    $player = $state['players'][$pid] ?? null;
    if (!is_array($player)) {
        return 0;
    }
    if (function_exists('tcgMissionSeatIsCpu') && tcgMissionSeatIsCpu($player)) {
        return 0;
    }
    $cpu = tcgEventsIsCpuMatch($state);
    $ranked = tcgEventsIsRankedMatch($state);
    if (!$cpu && !$ranked) {
        return 0;
    }
    // Reuse coin amount tables (identical curves).
    return tcgCoinsForFinishedMatch($state, $pid);
}

/**
 * Idempotent EP grants for all active events. Also claims newly reached milestones.
 *
 * @return list<array{pid:string,discord_id:string,event_id:string,amount:int,balance:int,milestones:list}>
 */
function tcgEventPointsOnGameFinished(array $state): array {
    if (!tcgCoinsNaturalFinish($state)) {
        return [];
    }
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($state['room_id'] ?? '')) ?? '');
    if ($roomId === '') {
        return [];
    }
    if (!function_exists('tcgPlayerDiscordId')) {
        require_once __DIR__ . '/missions.php';
    }
    $active = tcgEventsActive();
    if ($active === []) {
        return [];
    }
    $out = [];
    $db = tcgDb();
    $now = tcgEventsNowTs();
    foreach (['p1', 'p2'] as $pid) {
        $amount = tcgEventPointsForFinishedMatch($state, $pid);
        if ($amount <= 0) {
            continue;
        }
        $discordId = tcgPlayerDiscordId($state, $pid);
        if (!$discordId) {
            continue;
        }
        tcgEnsureUser($discordId);
        foreach ($active as $ev) {
            $eventId = (string)($ev['id'] ?? '');
            if ($eventId === '') {
                continue;
            }
            try {
                $db->prepare(
                    'INSERT INTO tcg_event_point_grants (room_id, discord_id, event_id, amount, created_at)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$roomId, $discordId, $eventId, $amount, $now]);
            } catch (Throwable $e) {
                continue; // already granted
            }
            $db->prepare(
                'INSERT INTO tcg_event_points (event_id, discord_id, points, updated_at)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT(event_id, discord_id) DO UPDATE SET
                   points = points + excluded.points,
                   updated_at = excluded.updated_at'
            )->execute([$eventId, $discordId, $amount, $now]);
            $balStmt = $db->prepare(
                'SELECT points FROM tcg_event_points WHERE event_id = ? AND discord_id = ?'
            );
            $balStmt->execute([$eventId, $discordId]);
            $balance = max(0, intval($balStmt->fetchColumn() ?: 0));
            $milestones = tcgEventsClaimMilestonesForPoints($eventId, $discordId, $balance);
            $out[] = [
                'pid' => $pid,
                'discord_id' => $discordId,
                'event_id' => $eventId,
                'amount' => $amount,
                'balance' => $balance,
                'milestones' => $milestones,
            ];
        }
    }
    return $out;
}

/**
 * @return list<array{milestone_id:int,threshold:int,reward_type:string}>
 */
function tcgEventsClaimMilestonesForPoints(string $eventId, string $discordId, int $points): array {
    $db = tcgDb();
    $stmt = $db->prepare(
        'SELECT id, threshold_points, reward_type, reward_payload FROM tcg_event_milestones
         WHERE event_id = ? AND threshold_points <= ? ORDER BY threshold_points ASC'
    );
    $stmt->execute([$eventId, $points]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $claimed = [];
    $now = tcgEventsNowTs();
    foreach ($rows as $row) {
        $mid = intval($row['id']);
        try {
            $db->prepare(
                'INSERT INTO tcg_event_milestone_claims (event_id, discord_id, milestone_id, created_at)
                 VALUES (?, ?, ?, ?)'
            )->execute([$eventId, $discordId, $mid, $now]);
        } catch (Throwable $e) {
            continue;
        }
        $payload = json_decode((string)($row['reward_payload'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        try {
            tcgEventsGrantReward($discordId, (string)$row['reward_type'], $payload);
        } catch (Throwable $e) {
            error_log('event milestone grant failed: ' . $e->getMessage());
        }
        $claimed[] = [
            'milestone_id' => $mid,
            'threshold' => intval($row['threshold_points']),
            'reward_type' => (string)$row['reward_type'],
        ];
    }
    return $claimed;
}

function tcgEventsSettleEnded(string $eventId): void {
    $db = tcgDb();
    $ev = $db->prepare('SELECT * FROM tcg_events WHERE id = ?');
    $ev->execute([$eventId]);
    $event = $ev->fetch(PDO::FETCH_ASSOC);
    if (!$event || !empty($event['ended_processed_at'])) {
        return;
    }
    $now = tcgEventsNowTs();
    $lock = $db->prepare(
        'UPDATE tcg_events SET ended_processed_at = ?, updated_at = ?
         WHERE id = ? AND ended_processed_at IS NULL'
    );
    $lock->execute([$now, $now, $eventId]);
    if ($lock->rowCount() < 1) {
        return;
    }

    $lb = $db->prepare(
        'SELECT discord_id, points FROM tcg_event_points WHERE event_id = ? AND points > 0
         ORDER BY points DESC, updated_at ASC'
    );
    $lb->execute([$eventId]);
    $rows = $lb->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rewards = $db->prepare(
        'SELECT * FROM tcg_event_rank_rewards WHERE event_id = ? ORDER BY rank_from ASC'
    );
    $rewards->execute([$eventId]);
    $brackets = $rewards->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $place = 0;
    foreach ($rows as $row) {
        $place++;
        $discordId = (string)$row['discord_id'];
        try {
            $db->prepare(
                'INSERT INTO tcg_event_rank_grants (event_id, discord_id, rank_place, created_at)
                 VALUES (?, ?, ?, ?)'
            )->execute([$eventId, $discordId, $place, $now]);
        } catch (Throwable $e) {
            continue;
        }
        foreach ($brackets as $br) {
            $from = intval($br['rank_from']);
            $to = intval($br['rank_to']);
            if ($place < $from || $place > $to) {
                continue;
            }
            $payload = json_decode((string)($br['reward_payload'] ?? '{}'), true);
            if (!is_array($payload)) {
                $payload = [];
            }
            try {
                tcgEventsGrantReward($discordId, (string)$br['reward_type'], $payload);
            } catch (Throwable $e) {
                error_log('event rank grant failed: ' . $e->getMessage());
            }
            break;
        }
    }
}

function tcgEventsNewId(): string {
    return strtoupper(bin2hex(random_bytes(6)));
}

/** @param array<string,mixed> $body */
function tcgApiEventsAdminList(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEventsRequireOwner($uid);
    tcgEventsTickStatuses();
    $db = tcgDb();
    $rows = $db->query('SELECT * FROM tcg_events ORDER BY starts_at DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = tcgEventsSerializeEvent($r, true);
    }
    return ['success' => true, 'events' => $out, 'hub_enabled' => TCG_EVENTS_HUB_ENABLED];
}

/** @param array<string,mixed> $row */
function tcgEventsSerializeEvent(array $row, bool $withRewards = false): array {
    $id = (string)$row['id'];
    $base = [
        'id' => $id,
        'name' => (string)$row['name'],
        'banner_url' => (string)($row['banner_url'] ?? ''),
        'starts_at' => intval($row['starts_at']),
        'ends_at' => intval($row['ends_at']),
        'starts_at_jst' => tcgEventsFormatJstDatetime(intval($row['starts_at'])),
        'ends_at_jst' => tcgEventsFormatJstDatetime(intval($row['ends_at'])),
        'status' => (string)$row['status'],
        'created_at' => intval($row['created_at'] ?? 0),
        'ended_processed_at' => $row['ended_processed_at'] !== null ? intval($row['ended_processed_at']) : null,
    ];
    if (!$withRewards) {
        return $base;
    }
    $db = tcgDb();
    $ms = $db->prepare(
        'SELECT id, threshold_points, sort_order, reward_type, reward_payload
         FROM tcg_event_milestones WHERE event_id = ? ORDER BY threshold_points ASC, sort_order ASC'
    );
    $ms->execute([$id]);
    $milestones = [];
    foreach ($ms->fetchAll(PDO::FETCH_ASSOC) ?: [] as $m) {
        $payload = json_decode((string)$m['reward_payload'], true);
        $milestones[] = [
            'id' => intval($m['id']),
            'threshold_points' => intval($m['threshold_points']),
            'sort_order' => intval($m['sort_order']),
            'reward_type' => (string)$m['reward_type'],
            'reward_payload' => is_array($payload) ? $payload : [],
        ];
    }
    $rr = $db->prepare(
        'SELECT id, rank_from, rank_to, reward_type, reward_payload
         FROM tcg_event_rank_rewards WHERE event_id = ? ORDER BY rank_from ASC'
    );
    $rr->execute([$id]);
    $ranks = [];
    foreach ($rr->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $payload = json_decode((string)$r['reward_payload'], true);
        $ranks[] = [
            'id' => intval($r['id']),
            'rank_from' => intval($r['rank_from']),
            'rank_to' => intval($r['rank_to']),
            'reward_type' => (string)$r['reward_type'],
            'reward_payload' => is_array($payload) ? $payload : [],
        ];
    }
    $base['milestones'] = $milestones;
    $base['rank_rewards'] = $ranks;
    return $base;
}

/** @param array<string,mixed> $body */
function tcgApiEventsAdminUpsert(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEventsRequireOwner($uid);
    tcgEventsEnsureSchema();
    $db = tcgDb();
    $now = tcgEventsNowTs();
    $id = trim((string)($body['id'] ?? ''));
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        throw new Exception('name required', 400);
    }
    $banner = trim((string)($body['banner_url'] ?? ''));
    if ($banner !== '' && !preg_match('#^https://#i', $banner)) {
        throw new Exception('banner_url must be https://', 400);
    }
    $startsAt = isset($body['starts_at']) && is_numeric($body['starts_at'])
        ? intval($body['starts_at'])
        : tcgEventsParseJstDatetime((string)($body['starts_at_jst'] ?? ''));
    $endsAt = isset($body['ends_at']) && is_numeric($body['ends_at'])
        ? intval($body['ends_at'])
        : tcgEventsParseJstDatetime((string)($body['ends_at_jst'] ?? ''));
    if ($endsAt <= $startsAt) {
        throw new Exception('ends_at must be after starts_at', 400);
    }
    $status = 'scheduled';
    if ($now >= $startsAt && $now < $endsAt) {
        $status = 'active';
    } elseif ($now >= $endsAt) {
        $status = 'ended';
    }

    if ($id === '') {
        $id = tcgEventsNewId();
        $db->prepare(
            'INSERT INTO tcg_events (id, name, banner_url, starts_at, ends_at, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $name, $banner, $startsAt, $endsAt, $status, $now, $now]);
    } else {
        $db->prepare(
            'UPDATE tcg_events SET name = ?, banner_url = ?, starts_at = ?, ends_at = ?, status = ?, updated_at = ?
             WHERE id = ?'
        )->execute([$name, $banner, $startsAt, $endsAt, $status, $now, $id]);
        if ($db->query("SELECT changes()")->fetchColumn() == 0) {
            // verify exists
            $chk = $db->prepare('SELECT 1 FROM tcg_events WHERE id = ?');
            $chk->execute([$id]);
            if (!$chk->fetchColumn()) {
                throw new Exception('Event not found', 404);
            }
        }
    }

    if (isset($body['milestones']) && is_array($body['milestones'])) {
        $db->prepare('DELETE FROM tcg_event_milestones WHERE event_id = ?')->execute([$id]);
        $ins = $db->prepare(
            'INSERT INTO tcg_event_milestones (event_id, threshold_points, sort_order, reward_type, reward_payload)
             VALUES (?, ?, ?, ?, ?)'
        );
        $sort = 0;
        foreach ($body['milestones'] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $type = tcgEventsNormalizeRewardType((string)($m['reward_type'] ?? ''));
            $payload = tcgEventsNormalizeRewardPayload($type, is_array($m['reward_payload'] ?? null) ? $m['reward_payload'] : $m);
            $threshold = max(1, intval($m['threshold_points'] ?? $m['threshold'] ?? 0));
            $ins->execute([$id, $threshold, intval($m['sort_order'] ?? $sort), $type, json_encode($payload)]);
            $sort++;
        }
    }

    if (isset($body['rank_rewards']) && is_array($body['rank_rewards'])) {
        $db->prepare('DELETE FROM tcg_event_rank_rewards WHERE event_id = ?')->execute([$id]);
        $ins = $db->prepare(
            'INSERT INTO tcg_event_rank_rewards (event_id, rank_from, rank_to, reward_type, reward_payload)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($body['rank_rewards'] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $type = tcgEventsNormalizeRewardType((string)($r['reward_type'] ?? ''));
            $payload = tcgEventsNormalizeRewardPayload($type, is_array($r['reward_payload'] ?? null) ? $r['reward_payload'] : $r);
            $from = max(1, intval($r['rank_from'] ?? 1));
            $to = max($from, intval($r['rank_to'] ?? $from));
            $ins->execute([$id, $from, $to, $type, json_encode($payload)]);
        }
    }

    tcgEventsTickStatuses();
    $row = $db->prepare('SELECT * FROM tcg_events WHERE id = ?');
    $row->execute([$id]);
    $ev = $row->fetch(PDO::FETCH_ASSOC);
    return ['success' => true, 'event' => tcgEventsSerializeEvent($ev ?: ['id' => $id], true)];
}

/** @param array<string,mixed> $body */
function tcgApiEventsAdminDelete(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEventsRequireOwner($uid);
    $id = trim((string)($body['id'] ?? ''));
    if ($id === '') {
        throw new Exception('id required', 400);
    }
    tcgDb()->prepare('DELETE FROM tcg_events WHERE id = ?')->execute([$id]);
    return ['success' => true];
}

/** @param array<string,mixed> $body */
function tcgApiEventsAdminForceTick(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEventsRequireOwner($uid);
    tcgEventsTickStatuses();
    return ['success' => true];
}

/** @param array<string,mixed> $body */
function tcgApiEventsActiveSummary(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEventsTickStatuses();
    $active = tcgEventsActive();
    $events = [];
    foreach ($active as $ev) {
        $ser = tcgEventsSerializeEvent($ev, true);
        $ser['my_points'] = tcgEventsGetPoints((string)$ev['id'], $uid);
        $events[] = $ser;
    }
    return [
        'success' => true,
        'hub_enabled' => TCG_EVENTS_HUB_ENABLED,
        'events' => $events,
    ];
}

function tcgEventsGetPoints(string $eventId, string $discordId): int {
    $stmt = tcgDb()->prepare(
        'SELECT points FROM tcg_event_points WHERE event_id = ? AND discord_id = ?'
    );
    $stmt->execute([$eventId, $discordId]);
    return max(0, intval($stmt->fetchColumn() ?: 0));
}

/** @param array<string,mixed> $body */
function tcgApiEventsLeaderboard(array $body): array {
    tcgRequireAuthUser($body);
    tcgEventsTickStatuses();
    $eventId = trim((string)($body['event_id'] ?? $_GET['event_id'] ?? ''));
    if ($eventId === '') {
        $active = tcgEventsActive();
        if ($active === []) {
            return ['success' => true, 'event_id' => null, 'rows' => []];
        }
        $eventId = (string)$active[0]['id'];
    }
    $limit = min(100, max(1, intval($body['limit'] ?? $_GET['limit'] ?? 50)));
    $db = tcgDb();
    $stmt = $db->prepare(
        'SELECT ep.discord_id, ep.points, u.username, u.avatar_url
         FROM tcg_event_points ep
         LEFT JOIN tcg_users u ON u.discord_id = ep.discord_id
         WHERE ep.event_id = ? AND ep.points > 0
         ORDER BY ep.points DESC, ep.updated_at ASC
         LIMIT ?'
    );
    $stmt->execute([$eventId, $limit]);
    $rows = [];
    $place = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $place++;
        $rows[] = [
            'rank' => $place,
            'discord_id' => (string)$r['discord_id'],
            'username' => (string)($r['username'] ?? 'Player'),
            'avatar_url' => $r['avatar_url'] ?? null,
            'points' => intval($r['points']),
        ];
    }
    return ['success' => true, 'event_id' => $eventId, 'rows' => $rows];
}

/** @param array<string,mixed> $body */
function tcgApiEventsMyProgress(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEventsTickStatuses();
    $eventId = trim((string)($body['event_id'] ?? ''));
    if ($eventId === '') {
        $active = tcgEventsActive();
        if ($active === []) {
            return ['success' => true, 'event' => null];
        }
        $eventId = (string)$active[0]['id'];
    }
    $db = tcgDb();
    $stmt = $db->prepare('SELECT * FROM tcg_events WHERE id = ?');
    $stmt->execute([$eventId]);
    $ev = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ev) {
        throw new Exception('Event not found', 404);
    }
    $ser = tcgEventsSerializeEvent($ev, true);
    $points = tcgEventsGetPoints($eventId, $uid);
    $claimed = $db->prepare(
        'SELECT milestone_id FROM tcg_event_milestone_claims WHERE event_id = ? AND discord_id = ?'
    );
    $claimed->execute([$eventId, $uid]);
    $claimedIds = array_map('intval', $claimed->fetchAll(PDO::FETCH_COLUMN) ?: []);
    return [
        'success' => true,
        'hub_enabled' => TCG_EVENTS_HUB_ENABLED,
        'event' => $ser,
        'my_points' => $points,
        'claimed_milestone_ids' => $claimedIds,
    ];
}
