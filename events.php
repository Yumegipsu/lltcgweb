<?php
/**
 * Time-limited Events: Event Points (EP), milestones, ranking rewards.
 * Hostinger SQLite only. EP curves mirror coins for ranked PvP + CPU.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/coins.php';

/** Hub Events tab gate — flip to true when player UI should open. */
const TCG_EVENTS_HUB_ENABLED = false;

/** Rewards that only exist inside Key Events (event wallet / PR pack gift). */
const TCG_EVENT_KEY_REWARD_TYPES = ['event_keys', 'event_tokens', 'pr_pack'];

const TCG_EVENT_REWARD_TYPES = ['coins', 'star_gems', 'scouting_ticket', 'scouting_ticket_sr', 'scouting_ticket_ur', 'card', 'sleeve', 'playmat'];

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
        ended_processed_at INTEGER,
        test_mode INTEGER NOT NULL DEFAULT 0,
        clock_offset INTEGER NOT NULL DEFAULT 0,
        clock_speed INTEGER NOT NULL DEFAULT 1,
        clock_anchor INTEGER NOT NULL DEFAULT 0,
        event_type TEXT NOT NULL DEFAULT \'classic\',
        config_json TEXT NOT NULL DEFAULT \'{}\'
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
    // Test events (owner-only sandbox): rewards are written here instead of being granted.
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_event_test_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id TEXT NOT NULL,
        discord_id TEXT NOT NULL,
        source TEXT NOT NULL,
        source_ref TEXT NOT NULL DEFAULT \'\',
        reward_type TEXT NOT NULL,
        reward_payload TEXT NOT NULL DEFAULT \'{}\',
        virtual_at INTEGER NOT NULL,
        created_at INTEGER NOT NULL,
        FOREIGN KEY (event_id) REFERENCES tcg_events(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_event_test_log_event
        ON tcg_event_test_log(event_id, id)');
    // Databases created before test events existed.
    static $columnsChecked = false;
    if (!$columnsChecked) {
        $columnsChecked = true;
        tcgDbEnsureColumn($db, 'tcg_events', 'test_mode', 'INTEGER NOT NULL DEFAULT 0');
        tcgDbEnsureColumn($db, 'tcg_events', 'clock_offset', 'INTEGER NOT NULL DEFAULT 0');
        tcgDbEnsureColumn($db, 'tcg_events', 'clock_speed', 'INTEGER NOT NULL DEFAULT 1');
        tcgDbEnsureColumn($db, 'tcg_events', 'clock_anchor', 'INTEGER NOT NULL DEFAULT 0');
        tcgDbEnsureColumn($db, 'tcg_events', 'event_type', "TEXT NOT NULL DEFAULT 'classic'");
        tcgDbEnsureColumn($db, 'tcg_events', 'config_json', "TEXT NOT NULL DEFAULT '{}'");
    }
    if (function_exists('tcgEventsKeysEnsureSchema')) {
        tcgEventsKeysEnsureSchema($db);
    }
}

/**
 * Current time for an event. Test events run on their own virtual clock (offset + optional speed-up);
 * everything else, and callers without an event, use the real clock.
 *
 * @param array<string,mixed>|null $event
 */
function tcgEventsNowTs(?array $event = null): int {
    $real = time();
    if ($event !== null && !empty($event['test_mode'])) {
        return tcgEventsVirtualNow($event, $real);
    }
    return $real;
}

/** @param array<string,mixed> $event */
function tcgEventsVirtualNow(array $event, int $real): int {
    $offset = intval($event['clock_offset'] ?? 0);
    $speed = max(1, intval($event['clock_speed'] ?? 1));
    $anchor = intval($event['clock_anchor'] ?? 0);
    if ($speed > 1 && $anchor > 0) {
        $offset += ($speed - 1) * max(0, $real - $anchor);
    }
    return $real + $offset;
}

/** @return array<string,mixed>|null */
function tcgEventsGetRow(string $eventId): ?array {
    $stmt = tcgDb()->prepare('SELECT * FROM tcg_events WHERE id = ?');
    $stmt->execute([$eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function tcgEventsIsPuppetId(string $discordId): bool {
    return str_starts_with($discordId, 'puppet_');
}

function tcgEventsViewerIsOwner(string $discordId): bool {
    if (!function_exists('tcgSocialIsOwner')) {
        require_once __DIR__ . '/social.php';
    }
    return tcgSocialIsOwner($discordId);
}

/**
 * Hand out a reward. Test events never touch real inventories: the reward is written to the test log instead.
 *
 * @param array<string,mixed> $event
 * @param array<string,mixed> $payload
 */
function tcgEventsDeliverReward(array $event, string $discordId, string $type, array $payload, string $source, string $ref = ''): void {
    $isTest = !empty($event['test_mode']);
    // Event keys and tokens live in the per-event wallet: credited for real in test events too (the wheel needs
    // them), and also written to the test log so the reward list stays complete.
    if ($type === 'event_keys' || $type === 'event_tokens') {
        tcgEventsWalletAdd((string)$event['id'], $discordId, $type === 'event_keys' ? 'keys' : 'tokens', max(1, intval($payload['amount'] ?? 0)));
        if (!$isTest) {
            return;
        }
    } elseif ($type === 'pr_pack' && !$isTest) {
        // PR packs are claimed from the Gifts menu so the pack reveal plays.
        $giftId = 'evt-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$event['id'] . '-' . $source . '-' . $ref . '-' . $discordId);
        if (!function_exists('tcgGiftsOfferToUser')) {
            require_once __DIR__ . '/gifts.php';
        }
        tcgGiftsOfferToUser(
            $discordId, substr($giftId, 0, 80), 'Event reward: PR pack', (string)($event['name'] ?? 'Event'),
            [['type' => 'pr_pack', 'amount' => max(1, min(15, intval($payload['amount'] ?? 5)))]]
        );
        return;
    }
    if (!$isTest) {
        tcgEventsGrantReward($discordId, $type, $payload);
        return;
    }
    tcgDb()->prepare(
        'INSERT INTO tcg_event_test_log (event_id, discord_id, source, source_ref, reward_type, reward_payload, virtual_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        (string)$event['id'], $discordId, $source, $ref, $type, json_encode($payload),
        tcgEventsNowTs($event), time(),
    ]);
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
    if (!in_array($type, TCG_EVENT_REWARD_TYPES, true) && !in_array($type, TCG_EVENT_KEY_REWARD_TYPES, true)) {
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
        case 'scouting_ticket_sr':
        case 'scouting_ticket_ur':
        case 'event_keys':
        case 'event_tokens':
            $amount = max(1, intval($payload['amount'] ?? 0));
            return ['amount' => $amount];
        case 'pr_pack':
            // amount = cards in the pack (gift rule: 1-15).
            return ['amount' => max(1, min(15, intval($payload['amount'] ?? 5)))];
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
        case 'scouting_ticket_sr':
            tcgAddScoutingTierTickets($discordId, 'sr', max(1, intval($payload['amount'] ?? 0)));
            break;
        case 'scouting_ticket_ur':
            tcgAddScoutingTierTickets($discordId, 'ur', max(1, intval($payload['amount'] ?? 0)));
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
        WHERE status = 'scheduled' AND starts_at <= ? AND ends_at > ? AND test_mode = 0")
        ->execute([$now, $now, $now]);
    $stmt = $db->prepare("SELECT id FROM tcg_events
        WHERE status IN ('scheduled','active') AND ends_at <= ? AND test_mode = 0");
    $stmt->execute([$now]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($ids as $id) {
        $db->prepare("UPDATE tcg_events SET status = 'ended', updated_at = ? WHERE id = ?")
            ->execute([$now, $id]);
        tcgEventsSettleEnded((string)$id);
    }
    // Test events follow their own virtual clock (and can move backwards).
    $tests = $db->query('SELECT * FROM tcg_events WHERE test_mode = 1')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($tests as $ev) {
        tcgEventsTickTestEvent($ev);
    }
}

/** @param array<string,mixed> $ev */
function tcgEventsTickTestEvent(array $ev): void {
    $vnow = tcgEventsNowTs($ev);
    if ($vnow < intval($ev['starts_at'])) {
        $status = 'scheduled';
    } elseif ($vnow < intval($ev['ends_at'])) {
        $status = 'active';
    } else {
        $status = 'ended';
    }
    if ($status !== (string)$ev['status']) {
        tcgDb()->prepare('UPDATE tcg_events SET status = ?, updated_at = ? WHERE id = ?')
            ->execute([$status, time(), (string)$ev['id']]);
    }
    if ($status === 'ended') {
        tcgEventsSettleEnded((string)$ev['id']);
    }
}

/**
 * Events that count real matches: public events only. Test events never receive points from real play.
 *
 * @return list<array<string,mixed>>
 */
function tcgEventsActive(): array {
    tcgEventsTickStatuses();
    $db = tcgDb();
    $now = tcgEventsNowTs();
    $stmt = $db->prepare("SELECT * FROM tcg_events
        WHERE status = 'active' AND starts_at <= ? AND ends_at > ? AND test_mode = 0
        ORDER BY starts_at ASC");
    $stmt->execute([$now, $now]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Events a given viewer may see: public ones, plus active test events for the owner only.
 *
 * @return list<array<string,mixed>>
 */
function tcgEventsActiveFor(string $viewerId): array {
    $active = tcgEventsActive();
    if (!tcgEventsViewerIsOwner($viewerId)) {
        return $active;
    }
    $tests = tcgDb()->query("SELECT * FROM tcg_events WHERE test_mode = 1 AND status = 'active' ORDER BY starts_at ASC")
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_merge($active, $tests);
}

/** Test events are invisible (404) to everyone but the owner. */
function tcgEventsAssertVisible(?array $event, string $viewerId): array {
    if (!$event || (!empty($event['test_mode']) && !tcgEventsViewerIsOwner($viewerId))) {
        throw new Exception('Event not found', 404);
    }
    return $event;
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
    if (!empty($state['event_match']) && is_array($state['event_match'])) {
        // Event matches pay EP only (the keys were spent on the ticket) and count however they end.
        return tcgEventsMatchOnFinished($state);
    }
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
        $discordId = tcgPlayerDiscordId($state, $pid);
        if (!$discordId) {
            continue;
        }
        foreach ($active as $ev) {
            $eventId = (string)($ev['id'] ?? '');
            if ($eventId === '') {
                continue;
            }
            if (tcgEventsIsKeyType($ev)) {
                // Key Events: normal matches of any mode pay Event Keys, never Event Points.
                $keys = tcgEventsKeysForFinishedMatch($ev, $state, $pid);
                if ($keys <= 0) {
                    continue;
                }
                tcgEnsureUser($discordId);
                try {
                    $db->prepare(
                        'INSERT INTO tcg_event_point_grants (room_id, discord_id, event_id, amount, created_at)
                         VALUES (?, ?, ?, ?, ?)'
                    )->execute([$roomId, $discordId, $eventId, $keys, $now]);
                } catch (Throwable $e) {
                    continue; // already granted
                }
                tcgEventsWalletAdd($eventId, $discordId, 'keys', $keys);
                $out[] = [
                    'pid' => $pid,
                    'discord_id' => $discordId,
                    'event_id' => $eventId,
                    'amount' => 0,
                    'keys' => $keys,
                    'keys_balance' => tcgEventsWalletGet($eventId, $discordId)['keys'],
                    'balance' => null,
                    'milestones' => [],
                ];
                continue;
            }
            if ($amount <= 0) {
                continue;
            }
            tcgEnsureUser($discordId);
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
    $event = tcgEventsGetRow($eventId) ?? ['id' => $eventId];
    $stmt = $db->prepare(
        'SELECT id, threshold_points, reward_type, reward_payload FROM tcg_event_milestones
         WHERE event_id = ? AND threshold_points <= ? ORDER BY threshold_points ASC'
    );
    $stmt->execute([$eventId, $points]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $claimed = [];
    $now = tcgEventsNowTs($event);
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
            tcgEventsDeliverReward($event, $discordId, (string)$row['reward_type'], $payload, 'milestone', (string)$row['threshold_points']);
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
    $now = tcgEventsNowTs($event);
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
                tcgEventsDeliverReward($event, $discordId, (string)$br['reward_type'], $payload, 'rank', '#' . $place);
            } catch (Throwable $e) {
                error_log('event rank grant failed: ' . $e->getMessage());
            }
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
        'ended_processed_at' => ($row['ended_processed_at'] ?? null) !== null ? intval($row['ended_processed_at']) : null,
        'test_mode' => !empty($row['test_mode']),
        'event_type' => (string)($row['event_type'] ?? 'classic'),
    ];
    if (function_exists('tcgEventsIsKeyType') && tcgEventsIsKeyType($row)) {
        $cfg = tcgEventsConfig($row);
        $base['config'] = [
            'keys' => $cfg['keys'],
            'event_match' => $cfg['event_match'],
            'wheel' => tcgEventsWheelPublic($row),
            'token_tiers' => $cfg['token_tiers'],
        ];
        $base['config_overrides'] = json_decode((string)($row['config_json'] ?? '{}'), true) ?: new stdClass();
    }
    if (!empty($row['test_mode'])) {
        $vnow = tcgEventsNowTs($row);
        $base['clock'] = [
            'speed' => max(1, intval($row['clock_speed'] ?? 1)),
            'offset' => intval($row['clock_offset'] ?? 0),
            'virtual_now' => $vnow,
            'virtual_now_jst' => tcgEventsFormatJstDatetime($vnow),
        ];
    }
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
    $existing = $id !== '' ? tcgEventsGetRow($id) : null;
    // A test event keeps its own virtual clock; the flag can only be set when creating the event.
    $testMode = $existing ? !empty($existing['test_mode']) : !empty($body['test_mode']);
    $eventType = $existing ? (string)($existing['event_type'] ?? 'classic')
        : (($body['event_type'] ?? '') === 'key_event' ? 'key_event' : 'classic');
    $configJson = null;
    if (array_key_exists('config', $body)) {
        $cfgIn = is_array($body['config']) ? $body['config'] : [];
        $encoded = json_encode($cfgIn, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > 60000) {
            throw new Exception('config too large', 400);
        }
        $configJson = $cfgIn === [] ? '{}' : $encoded;
    }
    $statusNow = $existing && $testMode ? tcgEventsNowTs($existing) : $now;
    $status = 'scheduled';
    if ($statusNow >= $startsAt && $statusNow < $endsAt) {
        $status = 'active';
    } elseif ($statusNow >= $endsAt) {
        $status = 'ended';
    }

    if ($id === '') {
        $id = tcgEventsNewId();
        $db->prepare(
            'INSERT INTO tcg_events (id, name, banner_url, starts_at, ends_at, status, created_at, updated_at, test_mode, event_type, config_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $name, $banner, $startsAt, $endsAt, $status, $now, $now, $testMode ? 1 : 0, $eventType, $configJson ?? '{}']);
    } else {
        $db->prepare(
            'UPDATE tcg_events SET name = ?, banner_url = ?, starts_at = ?, ends_at = ?, status = ?, updated_at = ?
             WHERE id = ?'
        )->execute([$name, $banner, $startsAt, $endsAt, $status, $now, $id]);
        if ($configJson !== null) {
            $db->prepare('UPDATE tcg_events SET config_json = ? WHERE id = ?')->execute([$configJson, $id]);
        }
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
    $active = tcgEventsActiveFor($uid);
    $events = [];
    $hasTest = false;
    foreach ($active as $ev) {
        $ser = tcgEventsSerializeEvent($ev, true);
        $ser['my_points'] = tcgEventsGetPoints((string)$ev['id'], $uid);
        if (tcgEventsIsKeyType($ev)) {
            $ser['wallet'] = tcgEventsWalletGet((string)$ev['id'], $uid);
        }
        $hasTest = $hasTest || !empty($ev['test_mode']);
        $events[] = $ser;
    }
    // Key Events about to start (a week ahead): the hub teases them. The owner also sees scheduled test events.
    $upcoming = [];
    $isOwner = tcgEventsViewerIsOwner($uid);
    $rows = tcgDb()->query("SELECT * FROM tcg_events WHERE status = 'scheduled' AND event_type = 'key_event' ORDER BY starts_at ASC")
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $isTest = !empty($row['test_mode']);
        if ($isTest ? !$isOwner : (intval($row['starts_at']) - time() > 7 * 86400)) {
            continue;
        }
        $ser = tcgEventsSerializeEvent($row, true);
        $ser['starts_in'] = max(0, intval($row['starts_at']) - tcgEventsNowTs($row));
        $upcoming[] = $ser;
    }
    return [
        'success' => true,
        // The owner gets the Events tab while a test event is running, even when the public tab is still off.
        'hub_enabled' => TCG_EVENTS_HUB_ENABLED || $hasTest,
        'events' => $events,
        'upcoming' => $upcoming,
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
    $viewer = tcgRequireAuthUser($body);
    tcgEventsTickStatuses();
    $eventId = trim((string)($body['event_id'] ?? $_GET['event_id'] ?? ''));
    if ($eventId === '') {
        $active = tcgEventsActiveFor($viewer);
        if ($active === []) {
            return ['success' => true, 'event_id' => null, 'rows' => []];
        }
        $eventId = (string)$active[0]['id'];
    } else {
        tcgEventsAssertVisible(tcgEventsGetRow($eventId), $viewer);
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
        $active = tcgEventsActiveFor($uid);
        if ($active === []) {
            return ['success' => true, 'event' => null];
        }
        $eventId = (string)$active[0]['id'];
    }
    $db = tcgDb();
    $ev = tcgEventsAssertVisible(tcgEventsGetRow($eventId), $uid);
    $ser = tcgEventsSerializeEvent($ev, true);
    $points = tcgEventsGetPoints($eventId, $uid);
    $claimed = $db->prepare(
        'SELECT milestone_id FROM tcg_event_milestone_claims WHERE event_id = ? AND discord_id = ?'
    );
    $claimed->execute([$eventId, $uid]);
    $claimedIds = array_map('intval', $claimed->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (tcgEventsIsKeyType($ev)) {
        $ser['wallet'] = tcgEventsWalletGet($eventId, $uid);
    }
    return [
        'success' => true,
        'hub_enabled' => TCG_EVENTS_HUB_ENABLED,
        'event' => $ser,
        'my_points' => $points,
        'claimed_milestone_ids' => $claimedIds,
    ];
}

// ------------------------------------------------------------------ Test lab (owner only, test events only)

/**
 * Add Event Points to a player of a test event and claim milestones, exactly like a finished match would.
 *
 * @param array<string,mixed> $event
 * @return array{balance:int,milestones:list<array<string,mixed>>}
 */
function tcgEventsAddPoints(array $event, string $discordId, int $amount, string $roomId = ''): array {
    $db = tcgDb();
    $eventId = (string)$event['id'];
    $now = tcgEventsNowTs($event);
    tcgEnsureUser($discordId);
    if ($roomId !== '') {
        $db->prepare('INSERT INTO tcg_event_point_grants (room_id, discord_id, event_id, amount, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$roomId, $discordId, $eventId, $amount, $now]);
    }
    $db->prepare(
        'INSERT INTO tcg_event_points (event_id, discord_id, points, updated_at) VALUES (?, ?, ?, ?)
         ON CONFLICT(event_id, discord_id) DO UPDATE SET points = points + excluded.points, updated_at = excluded.updated_at'
    )->execute([$eventId, $discordId, $amount, $now]);
    $balance = tcgEventsGetPoints($eventId, $discordId);
    return ['balance' => $balance, 'milestones' => tcgEventsClaimMilestonesForPoints($eventId, $discordId, $balance)];
}

/** @return list<string> puppet ids created */
function tcgEventsEnsurePuppets(int $count): array {
    $count = max(1, min(50, $count));
    $ids = [];
    for ($i = 1; $i <= $count; $i++) {
        $id = sprintf('puppet_%02d', $i);
        tcgEnsureUser($id, ['username' => sprintf('Puppet %02d', $i)]);
        $ids[] = $id;
    }
    return $ids;
}

/**
 * A finished-match state for one human seat, scored by the real EP code (same curve as coins).
 *
 * @return array<string,mixed>
 */
function tcgEventsSimulatedMatchState(string $kind, string $result, string $cpuDifficulty = 'normal'): array {
    $won = $result === 'win';
    $state = [
        'status' => 'finished',
        'end_reason' => 'game',
        'winner' => $won ? 'p1' : 'p2',
        'players' => ['p1' => ['id' => 'p1'], 'p2' => ['id' => 'p2']],
    ];
    if ($kind === 'cpu') {
        $state['cpu_solo'] = true;
        $state['cpu_difficulty'] = $cpuDifficulty;
        $state['players']['p2']['is_cpu'] = true;
    } else {
        $state['mode'] = 'ranked';
    }
    return $state;
}

/** @return array<string,mixed> */
function tcgEventsTestLabState(array $event, string $viewerId): array {
    $db = tcgDb();
    $eventId = (string)$event['id'];
    $pts = $db->prepare(
        'SELECT ep.discord_id, ep.points, u.username FROM tcg_event_points ep
         LEFT JOIN tcg_users u ON u.discord_id = ep.discord_id
         WHERE ep.event_id = ? ORDER BY ep.points DESC, ep.updated_at ASC'
    );
    $pts->execute([$eventId]);
    $board = [];
    $place = 0;
    foreach ($pts->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $place++;
        $board[] = [
            'rank' => $place,
            'discord_id' => (string)$r['discord_id'],
            'username' => (string)($r['username'] ?? ''),
            'points' => intval($r['points']),
            'is_puppet' => tcgEventsIsPuppetId((string)$r['discord_id']),
            'is_you' => (string)$r['discord_id'] === $viewerId,
        ];
    }
    $puppets = $db->query("SELECT discord_id, username FROM tcg_users WHERE discord_id LIKE 'puppet\_%' ESCAPE '\\' ORDER BY discord_id")
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $logStmt = $db->prepare(
        'SELECT l.*, u.username FROM tcg_event_test_log l LEFT JOIN tcg_users u ON u.discord_id = l.discord_id
         WHERE l.event_id = ? ORDER BY l.id DESC LIMIT 500'
    );
    $logStmt->execute([$eventId]);
    $log = [];
    foreach ($logStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
        $payload = json_decode((string)$l['reward_payload'], true);
        $log[] = [
            'id' => intval($l['id']),
            'discord_id' => (string)$l['discord_id'],
            'username' => (string)($l['username'] ?? ''),
            'source' => (string)$l['source'],
            'source_ref' => (string)$l['source_ref'],
            'reward_type' => (string)$l['reward_type'],
            'reward_payload' => is_array($payload) ? $payload : [],
            'virtual_at_jst' => tcgEventsFormatJstDatetime(intval($l['virtual_at'])),
        ];
    }
    return [
        'event' => tcgEventsSerializeEvent($event, true),
        'leaderboard' => $board,
        'wallets' => (static function () use ($db, $eventId): array {
            $w = $db->prepare('SELECT discord_id, keys, tokens, wheel2_left, spins FROM tcg_event_wallet WHERE event_id = ?');
            $w->execute([$eventId]);
            $o = [];
            foreach ($w->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $o[(string)$r['discord_id']] = ['keys' => intval($r['keys']), 'tokens' => intval($r['tokens']), 'wheel2_left' => intval($r['wheel2_left']), 'spins' => intval($r['spins'])];
            }
            return $o;
        })(),
        'puppets' => array_map(static fn($p) => ['discord_id' => (string)$p['discord_id'], 'username' => (string)$p['username']], $puppets),
        'log' => $log,
        'you' => $viewerId,
    ];
}

/**
 * Owner-only sandbox actions for a test event: puppets, simulated matches, EP awards, clock, reset, reward log.
 *
 * @param array<string,mixed> $body
 * @return array<string,mixed>
 */
function tcgApiEventsTestLab(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEventsRequireOwner($uid);
    tcgEventsEnsureSchema();
    $eventId = trim((string)($body['event_id'] ?? ''));
    $event = tcgEventsGetRow($eventId);
    if (!$event) {
        throw new Exception('Event not found', 404);
    }
    if (empty($event['test_mode'])) {
        throw new Exception('Test lab only works on test events', 400);
    }
    $db = tcgDb();
    $action = (string)($body['action'] ?? 'state');
    $result = [];

    $target = static function () use ($body, $uid): string {
        $t = trim((string)($body['discord_id'] ?? ''));
        if ($t === '' || (!tcgEventsIsPuppetId($t) && $t !== $uid)) {
            throw new Exception('Target must be a puppet or your own account', 400);
        }
        return $t;
    };

    switch ($action) {
        case 'state':
            break;
        case 'puppets_create':
            $result['created'] = tcgEventsEnsurePuppets(intval($body['count'] ?? 5));
            break;
        case 'award': {
            $amount = intval($body['points'] ?? 0);
            if ($amount === 0 || abs($amount) > 1000000) {
                throw new Exception('points must be a non-zero number', 400);
            }
            $result['award'] = tcgEventsAddPoints($event, $target(), $amount);
            break;
        }
        case 'simulate_match': {
            $kind = ($body['kind'] ?? 'ranked') === 'cpu' ? 'cpu' : 'ranked';
            $res = ($body['result'] ?? 'win') === 'loss' ? 'loss' : 'win';
            $diff = strtolower((string)($body['cpu_difficulty'] ?? 'normal'));
            $state = tcgEventsSimulatedMatchState($kind, $res, $diff);
            $amount = tcgEventPointsForFinishedMatch($state, 'p1');
            if ($amount <= 0) {
                throw new Exception('That match is worth no Event Points', 400);
            }
            $room = 'SIM' . strtoupper(bin2hex(random_bytes(4)));
            $award = tcgEventsAddPoints($event, $target(), $amount, $room);
            $award['amount'] = $amount;
            $result['simulated'] = $award;
            break;
        }
        case 'grant_wallet': {
            if (!tcgEventsIsKeyType($event)) {
                throw new Exception('Not a Key Event', 400);
            }
            $who = $target();
            foreach (['keys' => 'keys', 'tokens' => 'tokens'] as $field => $col) {
                $n = intval($body[$field] ?? 0);
                if ($n !== 0 && abs($n) <= 1000000) {
                    tcgEventsWalletAdd($eventId, $who, $col, $n);
                }
            }
            break;
        }
        case 'simulate_normal_match': {
            if (!tcgEventsIsKeyType($event)) {
                throw new Exception('Not a Key Event', 400);
            }
            $kind = ($body['kind'] ?? 'ranked') === 'cpu' ? 'cpu' : 'ranked';
            $res = ($body['result'] ?? 'win') === 'loss' ? 'loss' : 'win';
            $state = tcgEventsSimulatedMatchState($kind, $res, strtolower((string)($body['cpu_difficulty'] ?? 'normal')));
            $keys = tcgEventsKeysForFinishedMatch($event, $state, 'p1');
            $who = $target();
            tcgEventsWalletAdd($eventId, $who, 'keys', $keys);
            $result['simulated_keys'] = $keys;
            break;
        }
        case 'simulate_event_match': {
            if (!tcgEventsIsKeyType($event)) {
                throw new Exception('Not a Key Event', 400);
            }
            $won = ($body['result'] ?? 'win') !== 'loss';
            $result['event_match'] = tcgEventsRecordEventMatch($event, $target(), $won, !empty($body['vs_cpu']));
            break;
        }
        case 'spin': {
            $who = $target();
            $result['spin'] = tcgEventsWheelSpin($event, $who);
            break;
        }
        case 'simulate_spins': {
            // Run many spins for one player (topping up tokens as needed) and tally what came out: odds tuning.
            $who = $target();
            $n = max(1, min(1000, intval($body['count'] ?? 100)));
            $tally = [];
            $paid = 0;
            for ($i = 0; $i < $n; $i++) {
                $wallet = tcgEventsWalletGet($eventId, $who);
                $cost = max(1, intval(tcgEventsConfig($event)['wheel']['spin_cost']));
                if ($wallet['wheel2_left'] < 1 && $wallet['tokens'] < $cost) {
                    tcgEventsWalletAdd($eventId, $who, 'tokens', $cost);
                }
                $r = tcgEventsWheelSpin($event, $who);
                $paid += $r['paid_tokens'];
                $key = 'w' . $r['wheel'] . ' ' . $r['reward']['type'] . ($r['reward']['amount'] ? ' x' . $r['reward']['amount'] : '');
                $tally[$key] = ($tally[$key] ?? 0) + 1;
            }
            arsort($tally);
            $result['spin_tally'] = ['spins' => $n, 'tokens_paid' => $paid, 'results' => $tally];
            break;
        }
        case 'clock': {
            $real = time();
            // Fold any running speed-up into the offset first so the changes below stay exact.
            $offset = tcgEventsVirtualNow($event, $real) - $real;
            $speed = max(1, intval($event['clock_speed'] ?? 1));
            $anchor = $real;
            $mode = (string)($body['mode'] ?? 'jump');
            if ($mode === 'jump') {
                $offset += intval($body['seconds'] ?? 0);
            } elseif ($mode === 'jump_to') {
                $starts = intval($event['starts_at']);
                $ends = intval($event['ends_at']);
                $targetTs = match ((string)($body['target'] ?? '')) {
                    'before_start' => $starts - 300,
                    'start' => $starts,
                    'before_end' => $ends - 300,
                    'end' => $ends + 1,
                    default => throw new Exception('Unknown jump target', 400),
                };
                $offset += $targetTs - ($real + $offset);
            } elseif ($mode === 'speed') {
                $speed = intval($body['speed'] ?? 1);
                if (!in_array($speed, [1, 60, 600, 3600], true)) {
                    throw new Exception('speed must be 1, 60, 600 or 3600', 400);
                }
            } elseif ($mode === 'reset') {
                $offset = 0;
                $speed = 1;
                $anchor = 0;
            } else {
                throw new Exception('Unknown clock mode', 400);
            }
            $db->prepare('UPDATE tcg_events SET clock_offset = ?, clock_speed = ?, clock_anchor = ?, updated_at = ? WHERE id = ?')
                ->execute([$offset, $speed, $anchor, $real, $eventId]);
            tcgEventsTickStatuses();
            break;
        }
        case 'reset': {
            foreach (['tcg_event_points', 'tcg_event_point_grants', 'tcg_event_milestone_claims', 'tcg_event_rank_grants', 'tcg_event_test_log', 'tcg_event_wallet', 'tcg_event_wheel_spins'] as $t) {
                $db->prepare("DELETE FROM $t WHERE event_id = ?")->execute([$eventId]);
            }
            $db->prepare('UPDATE tcg_events SET ended_processed_at = NULL, updated_at = ? WHERE id = ?')->execute([time(), $eventId]);
            if (!empty($body['reset_clock'])) {
                $db->prepare('UPDATE tcg_events SET clock_offset = 0, clock_speed = 1, clock_anchor = 0 WHERE id = ?')->execute([$eventId]);
            }
            tcgEventsTickStatuses();
            break;
        }
        default:
            throw new Exception('Unknown test lab action', 400);
    }

    $event = tcgEventsGetRow($eventId) ?? $event;
    return ['success' => true] + $result + tcgEventsTestLabState($event, $uid);
}

require_once __DIR__ . '/events_keys.php';
require_once __DIR__ . '/events_match.php';
