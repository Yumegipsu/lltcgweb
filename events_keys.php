<?php
/**
 * Key Event (working name): the first main event type.
 *
 * - Playing normal matches earns Event Keys (not EP).
 * - Keys pay for event matches (Phase 2), which are the only source of Event Points.
 * - Milestones and the prize wheel pay Event Tokens; tokens buy wheel spins.
 * - Keys, tokens and wheel progress live in a per-event wallet, so test events stay fully isolated.
 *
 * Every number here is a default; an event can override any of it through its config JSON.
 */
require_once __DIR__ . '/events.php';

const TCG_EVENT_TYPE_CLASSIC = 'classic';
const TCG_EVENT_TYPE_KEYS = 'key_event';

function tcgEventsIsKeyType(array $event): bool {
    return (string)($event['event_type'] ?? '') === TCG_EVENT_TYPE_KEYS;
}

function tcgEventsKeysEnsureSchema(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_event_wallet (
        event_id TEXT NOT NULL,
        discord_id TEXT NOT NULL,
        keys INTEGER NOT NULL DEFAULT 0,
        tokens INTEGER NOT NULL DEFAULT 0,
        wheel2_left INTEGER NOT NULL DEFAULT 0,
        spins INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (event_id, discord_id),
        FOREIGN KEY (event_id) REFERENCES tcg_events(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_event_wheel_spins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id TEXT NOT NULL,
        discord_id TEXT NOT NULL,
        wheel INTEGER NOT NULL,
        slice_id TEXT NOT NULL,
        reward_type TEXT NOT NULL,
        reward_amount INTEGER NOT NULL DEFAULT 0,
        paid_tokens INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL,
        FOREIGN KEY (event_id) REFERENCES tcg_events(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_event_wheel_spins_event
        ON tcg_event_wheel_spins(event_id, discord_id, id)');
}

/** @return array<string,mixed> */
function tcgEventsKeyDefaults(): array {
    $slice = static fn(string $id, int $weight, string $type, int $amount = 0): array =>
        ['id' => $id, 'weight' => $weight, 'type' => $type, 'amount' => $amount];
    return [
        'keys' => [
            // Reduced, coin-like rates for normal play (coins: PvP 200/100, CPU 80-140 / 40-70).
            'pvp' => ['win' => 4, 'loss' => 2],
            'cpu' => [
                'easy' => ['win' => 2, 'loss' => 1],
                'normal' => ['win' => 2, 'loss' => 1],
                'hard' => ['win' => 2, 'loss' => 1],
                'expert' => ['win' => 3, 'loss' => 1],
            ],
        ],
        'event_match' => ['key_cost' => 5, 'ep_win' => 100, 'ep_loss' => 40, 'cpu_factor' => 0.5],
        // Token coin icons: green below uncommon_from, blue below rare_from, rainbow from there up.
        'token_tiers' => ['uncommon_from' => 50, 'rare_from' => 100],
        'wheel' => [
            'spin_cost' => 10,
            'wheel2_spins' => 3,
            'wheels' => [
                1 => [
                    $slice('a_tok5', 200, 'event_tokens', 5),
                    $slice('a_pack', 3, 'pr_pack', 5),
                    $slice('a_coin200', 200, 'coins', 200),
                    $slice('a_gem100', 30, 'star_gems', 100),
                    $slice('a_key2', 150, 'event_keys', 2),
                    $slice('a_sr1', 7, 'scouting_ticket_sr', 1),
                    $slice('a_coin500', 100, 'coins', 500),
                    $slice('a_wheel2', 25, 'wheel2'),
                    $slice('a_tok10', 80, 'event_tokens', 10),
                    $slice('a_coin2000', 15, 'coins', 2000),
                    $slice('a_gem30', 70, 'star_gems', 30),
                    $slice('a_tok25', 30, 'event_tokens', 25),
                    $slice('a_key5', 50, 'event_keys', 5),
                    $slice('a_ticket1', 40, 'scouting_ticket', 1),
                ],
                2 => [
                    $slice('b_tok25', 180, 'event_tokens', 25),
                    $slice('b_gem1000', 5, 'star_gems', 1000),
                    $slice('b_coin1000', 150, 'coins', 1000),
                    $slice('b_sr2', 20, 'scouting_ticket_sr', 2),
                    $slice('b_key5', 120, 'event_keys', 5),
                    $slice('b_ur1', 10, 'scouting_ticket_ur', 1),
                    $slice('b_gem100', 100, 'star_gems', 100),
                    $slice('b_tok100', 30, 'event_tokens', 100),
                    $slice('b_tok50', 90, 'event_tokens', 50),
                    $slice('b_pack', 15, 'pr_pack', 5),
                    $slice('b_ticket2', 80, 'scouting_ticket', 2),
                    $slice('b_coin500', 50, 'coins', 500),
                    $slice('b_coin3000', 60, 'coins', 3000),
                    $slice('b_sr1', 40, 'scouting_ticket_sr', 1),
                    $slice('b_gem300', 50, 'star_gems', 300),
                ],
            ],
        ],
    ];
}

/**
 * Effective config: defaults, overridden by the event's own JSON. Wheels are replaced whole, never merged.
 *
 * @param array<string,mixed> $event
 * @return array<string,mixed>
 */
function tcgEventsConfig(array $event): array {
    $cfg = tcgEventsKeyDefaults();
    $over = json_decode((string)($event['config_json'] ?? '{}'), true);
    if (!is_array($over) || $over === []) {
        return $cfg;
    }
    $wheels = $over['wheel']['wheels'] ?? null;
    unset($over['wheel']['wheels']);
    $cfg = array_replace_recursive($cfg, $over);
    if (is_array($wheels)) {
        foreach ($wheels as $n => $slices) {
            if (is_array($slices) && $slices !== []) {
                $cfg['wheel']['wheels'][intval($n)] = array_values($slices);
            }
        }
    }
    return $cfg;
}

/** Icon tier for a token amount: common / uncommon / rare. */
function tcgEventsTokenTier(array $cfg, int $amount): string {
    $t = $cfg['token_tiers'] ?? [];
    if ($amount >= intval($t['rare_from'] ?? 100)) {
        return 'rare';
    }
    if ($amount >= intval($t['uncommon_from'] ?? 50)) {
        return 'uncommon';
    }
    return 'common';
}

// ------------------------------------------------------------------ wallet

/** @return array{keys:int,tokens:int,wheel2_left:int,spins:int} */
function tcgEventsWalletGet(string $eventId, string $discordId): array {
    $stmt = tcgDb()->prepare('SELECT keys, tokens, wheel2_left, spins FROM tcg_event_wallet WHERE event_id = ? AND discord_id = ?');
    $stmt->execute([$eventId, $discordId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'keys' => intval($r['keys'] ?? 0),
        'tokens' => intval($r['tokens'] ?? 0),
        'wheel2_left' => intval($r['wheel2_left'] ?? 0),
        'spins' => intval($r['spins'] ?? 0),
    ];
}

/**
 * Add (or, when negative, spend) from a wallet column. A spend that would go below zero changes nothing.
 *
 * @return bool true when applied
 */
function tcgEventsWalletAdd(string $eventId, string $discordId, string $column, int $delta): bool {
    if (!in_array($column, ['keys', 'tokens', 'wheel2_left', 'spins'], true)) {
        throw new InvalidArgumentException('Invalid wallet column');
    }
    $db = tcgDb();
    $db->prepare('INSERT OR IGNORE INTO tcg_event_wallet (event_id, discord_id, updated_at) VALUES (?, ?, ?)')
        ->execute([$eventId, $discordId, time()]);
    $stmt = $db->prepare(
        "UPDATE tcg_event_wallet SET $column = $column + ?, updated_at = ?
         WHERE event_id = ? AND discord_id = ? AND $column + ? >= 0"
    );
    $stmt->execute([$delta, time(), $eventId, $discordId, $delta]);
    return $stmt->rowCount() > 0;
}

// ------------------------------------------------------------------ keys from normal play

/** Keys a human seat earns for a finished normal match (0 when ineligible). Same eligibility as coins. */
function tcgEventsKeysForFinishedMatch(array $event, array $state, string $pid): int {
    if (tcgCoinsForFinishedMatch($state, $pid) <= 0) {
        return 0;
    }
    $cfg = tcgEventsConfig($event)['keys'];
    $won = (($state['winner'] ?? null) === $pid);
    $k = $won ? 'win' : 'loss';
    if (!empty($state['cpu_solo']) || !empty($state['cpu_difficulty'])) {
        $diff = strtolower(trim((string)($state['cpu_difficulty'] ?? 'easy')));
        $row = $cfg['cpu'][$diff] ?? $cfg['cpu']['easy'];
        return max(0, intval($row[$k] ?? 0));
    }
    return max(0, intval($cfg['pvp'][$k] ?? 0));
}

// ------------------------------------------------------------------ event matches (EP source)

/**
 * Record one finished event match for a player: spend the keys, award EP (reduced against a CPU).
 * The real event-match mode (Phase 2) calls this too, so what the test lab simulates is what ships.
 *
 * @param array<string,mixed> $event
 * @return array{ep:int,keys_spent:int,balance:int,milestones:list<array<string,mixed>>}
 */
function tcgEventsRecordEventMatch(array $event, string $discordId, bool $won, bool $vsCpu, bool $chargeKeys = true, string $roomId = ''): array {
    $cfg = tcgEventsConfig($event)['event_match'];
    $cost = $chargeKeys ? max(0, intval($cfg['key_cost'] ?? 0)) : 0;
    if ($cost > 0 && !tcgEventsWalletAdd((string)$event['id'], $discordId, 'keys', -$cost)) {
        throw new Exception('Not enough Event Keys', 400);
    }
    $ep = intval($won ? ($cfg['ep_win'] ?? 0) : ($cfg['ep_loss'] ?? 0));
    if ($vsCpu) {
        $ep = (int)floor($ep * floatval($cfg['cpu_factor'] ?? 0.5));
    }
    $award = tcgEventsAddPoints($event, $discordId, $ep, $roomId);
    return ['ep' => $ep, 'keys_spent' => $cost, 'balance' => $award['balance'], 'milestones' => $award['milestones']];
}

// ------------------------------------------------------------------ prize wheel

/** @param array<string,mixed> $event */
function tcgEventsWheelPublic(array $event): array {
    $cfg = tcgEventsConfig($event);
    $out = ['spin_cost' => intval($cfg['wheel']['spin_cost']), 'wheel2_spins' => intval($cfg['wheel']['wheel2_spins']), 'wheels' => []];
    foreach ($cfg['wheel']['wheels'] as $n => $slices) {
        $list = [];
        foreach ($slices as $s) {
            $amount = intval($s['amount'] ?? 0);
            $list[] = [
                'id' => (string)$s['id'],
                'weight' => max(1, intval($s['weight'] ?? 1)),
                'type' => (string)$s['type'],
                'amount' => $amount,
                'tier' => ($s['type'] ?? '') === 'event_tokens' ? tcgEventsTokenTier($cfg, $amount) : null,
            ];
        }
        $out['wheels'][(string)$n] = $list;
    }
    return $out;
}

/**
 * Pick a slice by weight. $roll (0..total-1) is injectable for tests.
 *
 * @param list<array<string,mixed>> $slices
 * @return array<string,mixed>
 */
function tcgEventsWheelPick(array $slices, ?int $roll = null): array {
    $total = 0;
    foreach ($slices as $s) {
        $total += max(1, intval($s['weight'] ?? 1));
    }
    $roll = $roll ?? random_int(0, $total - 1);
    $acc = 0;
    foreach ($slices as $i => $s) {
        $acc += max(1, intval($s['weight'] ?? 1));
        if ($roll < $acc) {
            return ['index' => (int)$i] + $s;
        }
    }
    $last = array_key_last($slices);
    return ['index' => (int)$last] + $slices[$last];
}

/**
 * Spin the wheel for a player. Wheel 2 spins (won on wheel 1) are free and used first; otherwise a wheel 1 spin
 * costs tokens. The server picks the slice, so the result cannot be steered from the client.
 *
 * @param array<string,mixed> $event
 * @return array<string,mixed>
 */
function tcgEventsWheelSpin(array $event, string $discordId, ?int $forceRoll = null): array {
    if (!tcgEventsIsKeyType($event)) {
        throw new Exception('This event has no prize wheel', 400);
    }
    $eventId = (string)$event['id'];
    $cfg = tcgEventsConfig($event);
    $db = tcgDb();
    // The spend below is one guarded UPDATE (atomic); reward helpers open their own transactions, so no outer one.
    $spent = ['col' => '', 'amount' => 0];
    try {
        $wallet = tcgEventsWalletGet($eventId, $discordId);
        $free = $wallet['wheel2_left'] > 0;
        $cost = $free ? 0 : max(1, intval($cfg['wheel']['spin_cost']));
        $wheelNo = $free ? 2 : 1;
        if ($free) {
            if (!tcgEventsWalletAdd($eventId, $discordId, 'wheel2_left', -1)) {
                throw new Exception('No spins left', 400);
            }
            $spent = ['col' => 'wheel2_left', 'amount' => 1];
        } elseif (!tcgEventsWalletAdd($eventId, $discordId, 'tokens', -$cost)) {
            throw new Exception('Not enough Event Tokens', 400);
        } else {
            $spent = ['col' => 'tokens', 'amount' => $cost];
        }
        $slices = $cfg['wheel']['wheels'][$wheelNo] ?? [];
        if ($slices === []) {
            throw new Exception('Wheel is not configured', 500);
        }
        $slice = tcgEventsWheelPick($slices, $forceRoll);
        $type = (string)$slice['type'];
        $amount = max(0, intval($slice['amount'] ?? 0));
        $pack = null;

        if ($type === 'event_tokens') {
            tcgEventsWalletAdd($eventId, $discordId, 'tokens', $amount);
        } elseif ($type === 'event_keys') {
            tcgEventsWalletAdd($eventId, $discordId, 'keys', $amount);
        } elseif ($type === 'wheel2') {
            $amount = max(1, intval($cfg['wheel']['wheel2_spins']));
            tcgEventsWalletAdd($eventId, $discordId, 'wheel2_left', $amount);
        } elseif ($type === 'pr_pack') {
            if (!empty($event['test_mode'])) {
                tcgEventsDeliverReward($event, $discordId, $type, ['amount' => $amount], 'wheel', (string)$slice['id']);
            } else {
                if (!function_exists('tcgGrantPrPackCards')) {
                    require_once __DIR__ . '/ranked_pr_rewards.php';
                }
                $pack = tcgGrantPrPackCards($discordId, max(1, min(15, $amount)));
            }
        } else {
            tcgEventsDeliverReward($event, $discordId, $type, ['amount' => $amount], 'wheel', (string)$slice['id']);
        }
        tcgEventsWalletAdd($eventId, $discordId, 'spins', 1);
        $db->prepare(
            'INSERT INTO tcg_event_wheel_spins (event_id, discord_id, wheel, slice_id, reward_type, reward_amount, paid_tokens, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$eventId, $discordId, $wheelNo, (string)$slice['id'], $type, $amount, $cost, time()]);
    } catch (Throwable $e) {
        // Give the spin back if anything after paying for it failed.
        if ($spent['col'] !== '') {
            tcgEventsWalletAdd($eventId, $discordId, $spent['col'], $spent['amount']);
        }
        throw $e;
    }
    return [
        'wheel' => $wheelNo,
        'slice_index' => intval($slice['index']),
        'slice_id' => (string)$slice['id'],
        'reward' => [
            'type' => $type,
            'amount' => $amount,
            'tier' => $type === 'event_tokens' ? tcgEventsTokenTier($cfg, $amount) : null,
        ],
        'paid_tokens' => $cost,
        'wallet' => tcgEventsWalletGet($eventId, $discordId),
        'pr_pack' => $pack,
    ];
}

// ------------------------------------------------------------------ default templates

/**
 * Default milestone ladder. EP per event match averages ~70 (win 100 / loss 40), so the 28k top step is a
 * few hundred matches. Rewards alternate between event tokens/keys and the real economy.
 *
 * @return list<array<string,mixed>>
 */
function tcgEventsDefaultMilestones(): array {
    $m = static fn(int $at, string $type, int $amount): array =>
        ['threshold_points' => $at, 'reward_type' => $type, 'reward_payload' => ['amount' => $amount]];
    return [
        $m(100, 'event_tokens', 10),
        $m(250, 'coins', 500),
        $m(500, 'event_keys', 5),
        $m(800, 'star_gems', 100),
        $m(1200, 'event_tokens', 20),
        $m(1700, 'coins', 1000),
        $m(2300, 'scouting_ticket', 1),
        $m(3000, 'event_tokens', 30),
        $m(3800, 'event_keys', 10),
        $m(4700, 'star_gems', 200),
        $m(5700, 'coins', 2000),
        $m(6800, 'event_tokens', 50),
        $m(8000, 'scouting_ticket', 2),
        $m(9300, 'event_keys', 10),
        $m(10700, 'star_gems', 300),
        $m(12200, 'coins', 3000),
        $m(13800, 'event_tokens', 75),
        $m(15500, 'scouting_ticket_sr', 1),
        $m(17300, 'event_keys', 15),
        $m(19200, 'star_gems', 500),
        $m(21200, 'event_tokens', 100),
        $m(23300, 'coins', 5000),
        $m(25500, 'scouting_ticket_sr', 2),
        $m(28000, 'pr_pack', 5),
    ];
}

/**
 * Default end-of-event placements: 1-10 individually, then top 50, top 100, and everyone who played.
 *
 * @return list<array<string,mixed>>
 */
function tcgEventsDefaultRankRewards(): array {
    $r = static fn(int $from, int $to, string $type, int $amount): array =>
        ['rank_from' => $from, 'rank_to' => $to, 'reward_type' => $type, 'reward_payload' => ['amount' => $amount]];
    $rows = [];
    $top = [
        1 => [3000, 3, 2], 2 => [2000, 2, 2], 3 => [1500, 1, 2], 4 => [1000, 0, 2], 5 => [800, 0, 1],
        6 => [600, 0, 1], 7 => [500, 0, 1], 8 => [400, 0, 1], 9 => [300, 0, 1], 10 => [250, 0, 1],
    ];
    foreach ($top as $place => [$gems, $ur, $sr]) {
        $rows[] = $r($place, $place, 'star_gems', $gems);
        if ($ur > 0) {
            $rows[] = $r($place, $place, 'scouting_ticket_ur', $ur);
        }
        $rows[] = $r($place, $place, 'scouting_ticket_sr', $sr);
    }
    $rows[] = $r(11, 50, 'star_gems', 200);
    $rows[] = $r(11, 50, 'scouting_ticket', 3);
    $rows[] = $r(51, 100, 'star_gems', 100);
    $rows[] = $r(51, 100, 'scouting_ticket', 2);
    $rows[] = $r(101, 100000, 'coins', 1000);
    $rows[] = $r(101, 100000, 'scouting_ticket', 1);
    return $rows;
}

/** @param array<string,mixed> $body */
function tcgApiEventsDefaultTemplate(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEventsRequireOwner($uid);
    return [
        'success' => true,
        'milestones' => tcgEventsDefaultMilestones(),
        'rank_rewards' => tcgEventsDefaultRankRewards(),
        'config' => tcgEventsKeyDefaults(),
    ];
}

/** @param array<string,mixed> $body */
function tcgApiEventsWheelSpin(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEventsTickStatuses();
    $event = tcgEventsAssertVisible(tcgEventsGetRow(trim((string)($body['event_id'] ?? ''))), $uid);
    if ((string)$event['status'] !== 'active') {
        throw new Exception('This event is not running', 400);
    }
    $res = tcgEventsWheelSpin($event, $uid);
    return ['success' => true] + $res;
}
