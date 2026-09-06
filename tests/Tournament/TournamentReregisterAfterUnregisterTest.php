<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PHPUnit\Framework\TestCase;

/**
 * Paid register → unregister → re-register must work. Fixed ledger idempotency
 * keys (entry:tid:uid) made the second register throw "Already registered".
 */
final class TournamentReregisterAfterUnregisterTest extends TestCase
{
    /** @var list<string> */
    private array $tournamentIds = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        putenv('TCG_TOURNAMENTS_ENABLED=1');
        putenv('TCG_TOURNAMENT_ALLOWLIST=');
        require_once dirname(__DIR__, 2) . '/db.php';
        require_once dirname(__DIR__, 2) . '/coins.php';
        require_once dirname(__DIR__, 2) . '/llr_auth_local.php';
        require_once dirname(__DIR__, 2) . '/tournament.php';

        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/017_tournaments.sql');
        try {
            tcgDb()->exec($sql);
        } catch (\Throwable $e) {
            // already applied
        }
    }

    protected function tearDown(): void
    {
        $db = tcgDb();
        foreach ($this->tournamentIds as $tid) {
            $db->prepare('DELETE FROM tcg_tournament_ledger WHERE tournament_id = ?')->execute([$tid]);
            $db->prepare('DELETE FROM tcg_tournament_matches WHERE tournament_id = ?')->execute([$tid]);
            $db->prepare('DELETE FROM tcg_tournament_entrants WHERE tournament_id = ?')->execute([$tid]);
            $db->prepare('DELETE FROM tcg_tournaments WHERE id = ?')->execute([$tid]);
        }
    }

    private function ensureUser(string $uid, int $coins = 5000): void
    {
        $now = time();
        $db = tcgDb();
        $db->prepare(
            'INSERT OR REPLACE INTO tcg_users (discord_id, username, avatar_url, starter_deck, created_at, updated_at)
             VALUES (?, ?, NULL, "muse", ?, ?)'
        )->execute([$uid, $uid, $now, $now]);
        try {
            $db->prepare('UPDATE tcg_users SET coins = ? WHERE discord_id = ?')->execute([$coins, $uid]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function testPaidUnregisterThenReregisterSucceeds(): void
    {
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $host = '900000000000000002';
        $player = '900000000000000001';
        $tid = 'RR' . $suffix;
        $this->tournamentIds[] = $tid;
        $this->ensureUser($host, 5000);
        $this->ensureUser($player, 5000);

        $db = tcgDb();
        $now = time();
        $db->prepare(
            'INSERT INTO tcg_tournaments
             (id, host_discord_id, title, status, game_mode, start_at, checkin_mins,
              min_players, max_players, entry_fee_coins, prize_pool_coins, settings_json, created_at, updated_at)
             VALUES (?, ?, "Rejoin", "open", "randomized", ?, 10, 2, 8, 100, 0, "{}", ?, ?)'
        )->execute([$tid, $host, $now + 3600, $now, $now]);

        $token = tcgLocalIssueToken($player);
        $body = ['token' => $token, 'tournament_id' => $tid];

        tcgApiTournamentRegister($body);
        $this->assertSame(4900, tcgGetCoins($player));

        tcgApiTournamentUnregister($body);
        $this->assertSame(5000, tcgGetCoins($player));

        $stmt = $db->prepare('SELECT 1 FROM tcg_tournament_entrants WHERE tournament_id = ? AND discord_id = ?');
        $stmt->execute([$tid, $player]);
        $this->assertFalse((bool) $stmt->fetchColumn());

        tcgApiTournamentRegister($body);
        $this->assertSame(4900, tcgGetCoins($player));

        $stmt->execute([$tid, $player]);
        $this->assertTrue((bool) $stmt->fetchColumn());

        // Second leave must also refund (old refund_unreg:tid:uid key would block this).
        tcgApiTournamentUnregister($body);
        $this->assertSame(5000, tcgGetCoins($player));
    }

    public function testLegacyFixedEntryKeyWouldBlockReregister(): void
    {
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $uid = 'tt_legacy_' . $suffix;
        $tid = 'LG' . $suffix;
        $this->tournamentIds[] = $tid;
        $this->ensureUser($uid, 1000);

        $db = tcgDb();
        $now = time();
        $db->prepare(
            'INSERT INTO tcg_tournaments
             (id, host_discord_id, title, status, game_mode, start_at, checkin_mins,
              min_players, max_players, entry_fee_coins, prize_pool_coins, settings_json, created_at, updated_at)
             VALUES (?, ?, "Legacy", "open", "randomized", ?, 10, 2, 8, 100, 0, "{}", ?, ?)'
        )->execute([$tid, $uid, $now + 3600, $now, $now]);

        $key = 'entry:' . $tid . ':' . $uid;
        $this->assertTrue(tcgTournamentLedgerWrite($tid, $uid, 'entry_escrow', 100, $key, []));
        $this->assertFalse(
            tcgTournamentLedgerWrite($tid, $uid, 'entry_escrow', 100, $key, []),
            'Reuse of fixed entry:tid:uid key must collide'
        );
    }
}
