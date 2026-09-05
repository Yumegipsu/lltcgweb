<?php
/**
 * Match-earned Coins currency (sleeve shop).
 */
require_once __DIR__ . '/db.php';

const TCG_SLEEVE_SHOP_PRICE = 1000;
const TCG_PLAYMAT_SHOP_PRICE = 3000;

/** @var array{win: int, loss: int} */
const TCG_COIN_PVP = ['win' => 200, 'loss' => 100];

/** @var array<string, array{win: int, loss: int}> */
const TCG_COIN_CPU = [
    'easy' => ['win' => 80, 'loss' => 40],
    'normal' => ['win' => 100, 'loss' => 50],
    'hard' => ['win' => 120, 'loss' => 60],
    'expert' => ['win' => 140, 'loss' => 70],
];

function tcgGetCoins(string $discordId): int {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT coins FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $val = $stmt->fetchColumn();
    return $val === false ? 0 : max(0, intval($val));
}

function tcgAddCoins(string $discordId, int $amount): int {
    if ($amount <= 0) {
        return tcgGetCoins($discordId);
    }
    $db = tcgDb();
    $db->prepare('UPDATE tcg_users SET coins = COALESCE(coins, 0) + ?, updated_at = ? WHERE discord_id = ?')
        ->execute([$amount, time(), $discordId]);
    return tcgGetCoins($discordId);
}

/**
 * Deduct Coins. Safe to call inside an already-open PDO transaction
 * (tournament deposit/register); otherwise opens its own short transaction.
 */
function tcgDeductCoins(string $discordId, int $amount): int {
    if ($amount <= 0) {
        return tcgGetCoins($discordId);
    }
    $run = static function () use ($discordId, $amount): int {
        $db = tcgDb();
        $ownTx = !$db->inTransaction();
        if ($ownTx) {
            $db->beginTransaction();
        }
        try {
            $stmt = $db->prepare('SELECT coins FROM tcg_users WHERE discord_id = ?');
            $stmt->execute([$discordId]);
            $have = max(0, intval($stmt->fetchColumn() ?: 0));
            if ($have < $amount) {
                throw new Exception('Not enough Coins', 400);
            }
            $db->prepare('UPDATE tcg_users SET coins = coins - ?, updated_at = ? WHERE discord_id = ?')
                ->execute([$amount, time(), $discordId]);
            if ($ownTx) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return tcgGetCoins($discordId);
    };

    // Retry only when we own the transaction — outer tournament txs must not retry mid-flight.
    if (tcgDb()->inTransaction()) {
        return $run();
    }
    return tcgDbRetry($run);
}

function tcgCoinsNaturalFinish(array $state): bool {
    if (($state['status'] ?? '') !== 'finished') {
        return false;
    }
    // Natural 3-Success wins set end_reason to "game" (api.php since 041dd4c).
    // Resign / disconnect must still award 0 coins.
    $reason = strtolower(trim((string)($state['end_reason'] ?? '')));
    return $reason === '' || $reason === 'game';
}

/**
 * Coins awarded to a human seat for a finished match (0 if ineligible).
 */
function tcgCoinsForFinishedMatch(array $state, string $pid): int {
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
    $won = (($state['winner'] ?? null) === $pid);
    $key = $won ? 'win' : 'loss';
    $cpuSolo = !empty($state['cpu_solo']) || !empty($state['cpu_difficulty']);
    if ($cpuSolo) {
        $diff = strtolower(trim((string)($state['cpu_difficulty'] ?? 'easy')));
        if (!isset(TCG_COIN_CPU[$diff])) {
            $diff = 'easy';
        }
        return intval(TCG_COIN_CPU[$diff][$key] ?? 0);
    }
    return intval(TCG_COIN_PVP[$key] ?? 0);
}

/**
 * Idempotent per room+discord. Returns list of grants.
 *
 * @return list<array{pid: string, discord_id: string, amount: int, balance: int}>
 */
function tcgCoinsOnGameFinished(array $state): array {
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
    $out = [];
    $db = tcgDb();
    $now = time();
    foreach (['p1', 'p2'] as $pid) {
        $amount = tcgCoinsForFinishedMatch($state, $pid);
        if ($amount <= 0) {
            continue;
        }
        $discordId = tcgPlayerDiscordId($state, $pid);
        if (!$discordId) {
            continue;
        }
        tcgEnsureUser($discordId);
        try {
            $db->prepare('INSERT INTO tcg_coin_grants (room_id, discord_id, amount, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$roomId, $discordId, $amount, $now]);
        } catch (Throwable $e) {
            // Unique constraint — already granted for this room/player.
            continue;
        }
        $balance = tcgAddCoins($discordId, $amount);
        $out[] = [
            'pid' => $pid,
            'discord_id' => $discordId,
            'amount' => $amount,
            'balance' => $balance,
        ];
    }
    return $out;
}
