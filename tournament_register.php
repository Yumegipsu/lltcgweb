<?php
/**
 * Tournament Mode v1 — register / economy / host tools (part 2).
 * Included from tournament.php after tournament_api.php.
 */

/** @param array<string,mixed> $body */
function tcgApiTournamentEligibleDecks(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? '')));
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    return tcgTournamentEligibleDecksForUser($uid, (string)$row['game_mode']);
}

/** @param array<string,mixed> $body */
function tcgApiTournamentDepositPrize(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? '')));
    $amount = (int)($body['amount'] ?? 0);
    if ($amount <= 0) {
        throw new Exception('amount must be positive', 400);
    }
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    tcgTournamentAssertHost($row, $uid);
    if (in_array((string)$row['status'], ['finished', 'cancelled'], true)) {
        throw new Exception('Tournament already closed', 400);
    }

    $key = 'deposit:' . $id . ':' . $uid . ':' . $amount . ':' . time() . ':' . bin2hex(random_bytes(4));
    return tcgDbRetry(function () use ($uid, $id, $amount, $key) {
        $db = tcgDb();
        $db->beginTransaction();
        try {
            tcgDeductCoins($uid, $amount);
            if (!tcgTournamentLedgerWrite($id, $uid, 'host_deposit', $amount, $key, [])) {
                throw new Exception('Deposit ledger conflict', 409);
            }
            $db->prepare(
                'UPDATE tcg_tournaments SET prize_pool_coins = prize_pool_coins + ?, updated_at = ? WHERE id = ?'
            )->execute([$amount, time(), $id]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $row = tcgTournamentFetch($id);
        return [
            'success' => true,
            'prize_pool_coins' => (int)($row['prize_pool_coins'] ?? 0),
            'coins' => tcgGetCoins($uid),
        ];
    });
}

/** @param array<string,mixed> $body */
function tcgApiTournamentRegister(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $id = strtoupper(trim((string)($body['tournament_id'] ?? '')));
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    if (!in_array((string)$row['status'], ['open', 'checkin'], true)) {
        throw new Exception('Registration closed', 400);
    }

    $stmt = tcgDb()->prepare('SELECT 1 FROM tcg_tournament_entrants WHERE tournament_id = ? AND discord_id = ?');
    $stmt->execute([$id, $uid]);
    if ($stmt->fetchColumn()) {
        throw new Exception('Already registered', 400);
    }

    $countStmt = tcgDb()->prepare('SELECT COUNT(*) FROM tcg_tournament_entrants WHERE tournament_id = ?');
    $countStmt->execute([$id]);
    $count = (int)$countStmt->fetchColumn();
    if ($count >= (int)$row['max_players']) {
        throw new Exception('Tournament is full', 400);
    }

    $fee = (int)$row['entry_fee_coins'];
    $choice = null;
    if (!empty($body['starter'])) {
        $choice = ['starter' => trim((string)$body['starter'])];
    } elseif (isset($body['experiment_slot']) && (int)$body['experiment_slot'] > 0) {
        $choice = ['experiment_slot' => (int)$body['experiment_slot']];
    } elseif (trim((string)($body['experiment_password'] ?? '')) !== '') {
        $choice = ['experiment_password' => trim((string)$body['experiment_password'])];
    } elseif (isset($body['deck_slot']) && (int)$body['deck_slot'] > 0) {
        $choice = ['slot' => (int)$body['deck_slot']];
    }
    $snap = tcgTournamentDeckSnapshotForUser($uid, (string)$row['game_mode'], $choice);
    $settings = tcgTournamentDecodeSettings($row['settings_json'] ?? '{}');
    tcgTournamentAssertRulesTemplate(
        (string)($settings['rules_template'] ?? 'standard'),
        $snap,
        (string)$row['game_mode']
    );
    $now = time();
    // Unique per attempt — fixed entry:/refund_unreg: keys blocked re-register
    // (and a second unregister refund) after a successful leave.
    $ledgerKey = 'entry:' . $id . ':' . $uid . ':' . $now . ':' . bin2hex(random_bytes(4));

    return tcgDbRetry(function () use ($uid, $id, $fee, $snap, $now, $ledgerKey, $body) {
        $db = tcgDb();
        $db->beginTransaction();
        try {
            if ($fee > 0) {
                tcgDeductCoins($uid, $fee);
                if (!tcgTournamentLedgerWrite($id, $uid, 'entry_escrow', $fee, $ledgerKey, [])) {
                    throw new Exception('Already registered', 400);
                }
                $db->prepare(
                    'UPDATE tcg_tournaments SET prize_pool_coins = prize_pool_coins + ?, updated_at = ? WHERE id = ?'
                )->execute([$fee, $now, $id]);
            } else {
                tcgTournamentLedgerWrite($id, $uid, 'entry_escrow', 0, $ledgerKey, []);
            }

            $db->prepare(
                'INSERT INTO tcg_tournament_entrants
                 (tournament_id, discord_id, status, seed, deck_snapshot, paid_coins, registered_at, checked_in_at)
                 VALUES (?, ?, "registered", NULL, ?, ?, ?, NULL)'
            )->execute([$id, $uid, json_encode($snap, JSON_UNESCAPED_UNICODE), $fee, $now]);
            $db->prepare('UPDATE tcg_tournaments SET updated_at = ? WHERE id = ?')->execute([$now, $id]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return tcgApiTournamentGet(array_merge($body, ['tournament_id' => $id]));
    });
}

/** @param array<string,mixed> $body */
function tcgApiTournamentUnregister(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? '')));
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    if (!in_array((string)$row['status'], ['open', 'checkin'], true)) {
        throw new Exception('Cannot unregister after start', 400);
    }

    $stmt = tcgDb()->prepare('SELECT * FROM tcg_tournament_entrants WHERE tournament_id = ? AND discord_id = ?');
    $stmt->execute([$id, $uid]);
    $ent = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ent) {
        throw new Exception('Not registered', 400);
    }

    $paid = (int)($ent['paid_coins'] ?? 0);
    $refundKey = 'refund_unreg:' . $id . ':' . $uid . ':' . time() . ':' . bin2hex(random_bytes(4));

    return tcgDbRetry(function () use ($uid, $id, $paid, $refundKey, $body) {
        $db = tcgDb();
        $db->beginTransaction();
        try {
            if ($paid > 0 && tcgTournamentLedgerWrite($id, $uid, 'refund', $paid, $refundKey, ['reason' => 'unregister'])) {
                tcgAddCoins($uid, $paid);
                $db->prepare(
                    'UPDATE tcg_tournaments SET prize_pool_coins = MAX(0, prize_pool_coins - ?), updated_at = ? WHERE id = ?'
                )->execute([$paid, time(), $id]);
            }
            $db->prepare('DELETE FROM tcg_tournament_entrants WHERE tournament_id = ? AND discord_id = ?')
                ->execute([$id, $uid]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return tcgApiTournamentGet(array_merge($body, ['tournament_id' => $id]));
    });
}

/** @param array<string,mixed> $body */
function tcgApiTournamentCheckin(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? '')));
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    $now = time();
    $opens = (int)$row['start_at'] - ((int)$row['checkin_mins'] * 60);
    if ((string)$row['status'] === 'open' && $now >= $opens) {
        tcgDb()->prepare('UPDATE tcg_tournaments SET status = "checkin", updated_at = ? WHERE id = ? AND status = "open"')
            ->execute([$now, $id]);
        $row = tcgTournamentFetch($id) ?: $row;
    }
    if ((string)$row['status'] !== 'checkin' && !((string)$row['status'] === 'open' && $now >= $opens)) {
        throw new Exception('Check-in is not open yet', 400);
    }
    if ($now > (int)$row['start_at'] + 120) {
        throw new Exception('Check-in window closed', 400);
    }

    $stmt = tcgDb()->prepare('SELECT * FROM tcg_tournament_entrants WHERE tournament_id = ? AND discord_id = ?');
    $stmt->execute([$id, $uid]);
    $ent = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ent) {
        throw new Exception('Register first', 400);
    }
    if ((string)$ent['status'] === 'checked_in') {
        return tcgApiTournamentGet(array_merge($body, ['tournament_id' => $id]));
    }
    if ((string)$ent['status'] !== 'registered') {
        throw new Exception('Cannot check in', 400);
    }

    tcgDb()->prepare(
        'UPDATE tcg_tournament_entrants SET status = "checked_in", checked_in_at = ? WHERE tournament_id = ? AND discord_id = ?'
    )->execute([$now, $id, $uid]);
    tcgDb()->prepare('UPDATE tcg_tournaments SET updated_at = ? WHERE id = ?')->execute([$now, $id]);

    return tcgApiTournamentGet(array_merge($body, ['tournament_id' => $id]));
}
