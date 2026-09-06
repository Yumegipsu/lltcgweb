<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PHPUnit\Framework\TestCase;

/**
 * Scheduling with PR pack prize must escrow after the tournament row exists
 * (ledger FK → tcg_tournaments). Issue #159.
 */
final class TournamentPrPackCreateTest extends TestCase
{
    /** @var list<string> */
    private array $tournamentIds = [];

    /** @var list<string> */
    private array $userIds = [];

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

        tcgDbEnsureColumn(tcgDb(), 'tcg_users', 'coins', 'INTEGER NOT NULL DEFAULT 0');
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
        foreach ($this->userIds as $uid) {
            $db->prepare('DELETE FROM tcg_users WHERE discord_id = ?')->execute([$uid]);
        }
    }

    private function ensureUser(string $uid, int $coins = 5000): void
    {
        $this->userIds[] = $uid;
        $now = time();
        $db = tcgDb();
        $db->prepare(
            'INSERT OR REPLACE INTO tcg_users (discord_id, username, avatar_url, starter_deck, created_at, updated_at)
             VALUES (?, ?, NULL, "muse", ?, ?)'
        )->execute([$uid, $uid, $now, $now]);
        $db->prepare('UPDATE tcg_users SET coins = ? WHERE discord_id = ?')->execute([$coins, $uid]);
    }

    public function testCreateWithPrPackPrizeEscrowsAndSchedules(): void
    {
        $host = '900000000000000001';
        $this->ensureUser($host, 5000);
        $token = tcgLocalIssueToken($host);
        $before = tcgGetCoins($host);

        $res = tcgApiTournamentCreate([
            'token' => $token,
            'title' => 'PR Pack Schedule Smoke',
            'start_at' => time() + 600,
            'checkin_mins' => 10,
            'min_players' => 2,
            'max_players' => 10,
            'entry_fee_coins' => 0,
            'game_mode' => 'standard',
            'pr_pack_prize' => true,
            'settings' => [
                'format' => 'single_elim',
                'best_of' => 1,
                'fog' => 'hidden_hands',
                'rules_template' => 'standard',
            ],
        ]);

        $this->assertTrue(!empty($res['success']));
        $tid = (string)($res['tournament']['id'] ?? '');
        $this->assertNotSame('', $tid);
        $this->tournamentIds[] = $tid;

        $this->assertSame($before - TCG_TOURNAMENT_PR_PACK_COST, tcgGetCoins($host));
        $this->assertTrue(!empty($res['tournament']['pr_pack']['enabled']));
        $this->assertSame('escrowed', (string)($res['tournament']['pr_pack']['status'] ?? ''));

        $stmt = tcgDb()->prepare(
            'SELECT kind, amount FROM tcg_tournament_ledger
             WHERE tournament_id = ? AND idempotency_key = ?'
        );
        $stmt->execute([$tid, 'prpack:' . $tid]);
        $led = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($led);
        $this->assertSame('host_pr_pack_escrow', (string)($led['kind'] ?? ''));
        $this->assertSame(TCG_TOURNAMENT_PR_PACK_COST, (int)($led['amount'] ?? 0));
    }

    public function testCreateWithPrPackRejectsLowMaxPlayers(): void
    {
        $host = '900000000000000002';
        $this->ensureUser($host, 5000);
        $token = tcgLocalIssueToken($host);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('PR pack prize requires max players');
        tcgApiTournamentCreate([
            'token' => $token,
            'title' => 'PR Pack Too Small',
            'start_at' => time() + 600,
            'min_players' => 2,
            'max_players' => 8,
            'pr_pack_prize' => true,
            'settings' => ['format' => 'single_elim'],
        ]);
    }
}
