<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

/**
 * After a season rolls over, final standings stay readable from history (and unsettled live rows).
 */
final class SeasonPastLeaderboardTest extends TestCase
{
    /** @var list<string> */
    private array $ids = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/season.php';
        require_once dirname(__DIR__, 2) . '/matchmaking.php';
        $GLOBALS['TCG_SEASON_NOW'] = strtotime('2026-10-20 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCG_SEASON_NOW']);
        if ($this->ids === []) {
            return;
        }
        $db = tcgDb();
        foreach ($this->ids as $id) {
            foreach (['tcg_season_history', 'tcg_season_rank', 'tcg_rank', 'tcg_users'] as $t) {
                $db->prepare("DELETE FROM $t WHERE discord_id = ?")->execute([$id]);
            }
        }
    }

    private function user(string $name): string
    {
        $id = 'pastlb_' . $name . '_' . bin2hex(random_bytes(3));
        tcgEnsureUser($id, ['username' => $name]);
        $this->ids[] = $id;
        return $id;
    }

    private function row(string $id, int $step, int $points, int $wins, string $season = '2026-10'): void
    {
        tcgSeasonEnsureSchema(tcgDb());
        tcgDb()->prepare('INSERT OR REPLACE INTO tcg_season_rank
            (discord_id, game_mode, season_id, step, points, peak_step, wins, losses, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)')
            ->execute([$id, TCG_GAME_MODE_STANDARD, $season, $step, $points, $step, $wins, tcgSeasonNow()]);
    }

    public function testPastSeasonListAndBoardSurviveRollover(): void
    {
        $top = $this->user('top');
        $mid = $this->user('mid');
        $low = $this->user('low');
        $this->row($top, 7, 80, 12);
        $this->row($mid, 5, 40, 8);
        $this->row($low, 2, 10, 3);

        // Mid settles into Season 2; top/low have not logged in yet.
        $GLOBALS['TCG_SEASON_NOW'] = strtotime('2026-11-02 12:00:00 UTC');
        tcgSeasonSettle($mid, TCG_GAME_MODE_STANDARD);

        $past = tcgSeasonPastSeasonsList(TCG_GAME_MODE_STANDARD);
        $ids = array_column($past, 'season_id');
        $this->assertContains('2026-10', $ids);
        $this->assertNotContains('2026-11', $ids, 'live season is not listed as past');

        $board = tcgSeasonArchivedBoardRows(TCG_GAME_MODE_STANDARD, '2026-10');
        $this->assertCount(3, $board);
        $this->assertSame($top, (string)$board[0]['discord_id']);
        $this->assertSame(7, (int)$board[0]['step']);
        $this->assertSame($mid, (string)$board[1]['discord_id']);
        $this->assertSame($low, (string)$board[2]['discord_id']);

        // After everyone settles, the board still ranks the same from history alone.
        tcgSeasonSettle($top, TCG_GAME_MODE_STANDARD);
        tcgSeasonSettle($low, TCG_GAME_MODE_STANDARD);
        $board2 = tcgSeasonArchivedBoardRows(TCG_GAME_MODE_STANDARD, '2026-10');
        $this->assertSame(
            [$top, $mid, $low],
            array_map(static fn ($r) => (string)$r['discord_id'], $board2)
        );
        $this->assertTrue(tcgSeasonIdIsArchived('2026-10'));
        $this->assertFalse(tcgSeasonIdIsArchived('2026-11'));
    }
}
