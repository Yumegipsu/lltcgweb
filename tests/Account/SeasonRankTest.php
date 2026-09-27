<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

final class SeasonRankTest extends TestCase
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
        require_once dirname(__DIR__, 2) . '/titles.php';
        unset($GLOBALS['TCG_SEASON_NOW']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCG_SEASON_NOW']);
        if ($this->ids === []) {
            return;
        }
        $db = tcgDb();
        foreach ($this->ids as $id) {
            $db->prepare('DELETE FROM tcg_match_queue WHERE discord_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM tcg_season_history WHERE discord_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM tcg_season_rank WHERE discord_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM tcg_rank WHERE discord_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM tcg_users WHERE discord_id = ?')->execute([$id]);
        }
    }

    private function user(string $name): string
    {
        $id = 'season_' . $name . '_' . bin2hex(random_bytes(3));
        tcgEnsureUser($id, ['username' => $name]);
        $this->ids[] = $id;
        return $id;
    }

    private function at(string $utc): void
    {
        $GLOBALS['TCG_SEASON_NOW'] = strtotime($utc . ' UTC');
    }

    public function testPointsGainAndLossFloors(): void
    {
        $up = tcgSeasonMovePoints(0, 90, 16, true);
        $this->assertSame(1, $up['step']);
        $this->assertSame(6, $up['points']);

        $cLoss = tcgSeasonMovePoints(1, 40, 32, false);
        $this->assertSame(1, $cLoss['step']);
        $this->assertSame(40, $cLoss['points']);

        $bLoss = tcgSeasonMovePoints(2, 10, 32, false);
        $this->assertSame(2, $bLoss['step']);
        $this->assertSame(0, $bLoss['points']);

        $bDrop = tcgSeasonMovePoints(3, 5, 20, false);
        $this->assertSame(2, $bDrop['step']);
        $this->assertSame(85, $bDrop['points']);

        $aLoss = tcgSeasonMovePoints(4, 10, 32, false);
        $this->assertSame(4, $aLoss['step']);
        $this->assertSame(0, $aLoss['points']);

        $this->assertSame(8, tcgSeasonClampDelta(1));
        $this->assertSame(32, tcgSeasonClampDelta(40));
        $this->assertSame(4, tcgSeasonSoftResetStep(7));

        $filled = tcgSeasonMovePoints(6, 90, 32, true);
        $this->assertSame(6, $filled['step']);
        $this->assertSame(122, $filled['points']);

        $stillPinkScore = tcgSeasonMovePoints(7, 140, 32, false);
        $this->assertSame(6, $stillPinkScore['step']);
        $this->assertSame(108, $stillPinkScore['points']);
    }

    public function testBeforeOctoberDoesNotWriteSeason(): void
    {
        $this->at('2026-09-15 12:00:00');
        $winner = $this->user('earlyW');
        $loser = $this->user('earlyL');
        tcgApplyRankResult($winner, $loser, false, TCG_GAME_MODE_STANDARD);
        $this->assertNull(tcgSeasonLoadRow($winner, TCG_GAME_MODE_STANDARD));
        $public = tcgSeasonPublic($winner, TCG_GAME_MODE_STANDARD);
        $this->assertFalse($public['active']);
        $this->assertFalse($public['started']);
        $this->assertSame(0, $public['step']);
        $this->assertSame('c-green', $public['key']);
        $this->assertSame('2026 Season 1', $public['label']);
        $this->assertCount(8, $public['steps']);
    }

    public function testWinMovesSeasonAndLossOnCDoesNot(): void
    {
        $this->at('2026-10-15 12:00:00');
        $winner = $this->user('octW');
        $loser = $this->user('octL');
        tcgApplyRankResult($winner, $loser, false, TCG_GAME_MODE_STANDARD);
        $w = tcgSeasonLoadRow($winner, TCG_GAME_MODE_STANDARD);
        $l = tcgSeasonLoadRow($loser, TCG_GAME_MODE_STANDARD);
        $this->assertNotNull($w);
        $this->assertNotNull($l);
        $this->assertSame('2026-10', $w['season_id']);
        $this->assertSame(0, (int)$w['step']);
        $this->assertGreaterThanOrEqual(8, (int)$w['points']);
        $this->assertLessThanOrEqual(32, (int)$w['points']);
        $this->assertSame(1, (int)$w['wins']);
        $this->assertSame(0, (int)$l['step']);
        $this->assertSame(0, (int)$l['points']);
        $this->assertSame(1, (int)$l['losses']);
        $this->assertGreaterThan(1000, (int)tcgRankRow($winner, TCG_GAME_MODE_STANDARD)['rating']);
        $this->assertSame(1000, (int)tcgRankRow($winner, TCG_GAME_MODE_STARTERS)['rating']);
    }

    public function testSoftResetPaysPeakOnce(): void
    {
        $this->at('2026-10-20 12:00:00');
        $id = $this->user('peak');
        $now = tcgSeasonNow();
        tcgDb()->prepare('INSERT INTO tcg_season_rank
            (discord_id, game_mode, season_id, step, points, peak_step, wins, losses, updated_at)
            VALUES (?, ?, ?, 7, 40, 7, 8, 2, ?)')
            ->execute([$id, TCG_GAME_MODE_STANDARD, '2026-10', $now]);
        $this->at('2026-11-02 12:00:00');
        $grant = tcgSeasonSettle($id, TCG_GAME_MODE_STANDARD);
        $this->assertNotNull($grant);
        $this->assertSame(6000, $grant['coins']);
        $this->assertSame(2000, $grant['gems']);
        $this->assertSame(5, $grant['pr_packs']);
        $this->assertSame(6000, tcgGetCoins($id));
        $this->assertGreaterThanOrEqual(2000, tcgGetStarGems($id));
        $live = tcgSeasonLoadRow($id, TCG_GAME_MODE_STANDARD);
        $this->assertSame('2026-11', $live['season_id']);
        $this->assertSame(4, (int)$live['step']);
        $this->assertSame(0, (int)$live['points']);
        $this->assertSame(0, (int)$live['wins']);
        $hist = tcgDb()->prepare('SELECT peak_step, pr_packs, packs_granted, title_id FROM tcg_season_history
            WHERE discord_id = ? AND game_mode = ? AND season_id = ?');
        $hist->execute([$id, TCG_GAME_MODE_STANDARD, '2026-10']);
        $row = $hist->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(7, (int)$row['peak_step']);
        $this->assertSame(5, (int)$row['pr_packs']);
        $this->assertSame(1, (int)$row['packs_granted']);
        $owned = tcgDb()->prepare('SELECT COALESCE(SUM(qty), 0) FROM tcg_collection WHERE discord_id = ?');
        $owned->execute([$id]);
        $this->assertSame(5, (int)$owned->fetchColumn());
        $this->assertSame('season-2026-10-s-pink', $row['title_id']);
        $def = tcgTitleDefById('season-2026-10-s-pink');
        $this->assertNotNull($def);
        $this->assertSame('2026 Season 1', $def['name']);
        $this->assertSame('2026 Season 1', $grant['label']);
        $this->assertTrue(tcgTitleIsUnlocked($id, $def));
        $this->assertFalse(tcgTitleIsUnlocked($id, tcgTitleDefById('title_m_0001_01001_0001')));
        $again = tcgSeasonSettle($id, TCG_GAME_MODE_STANDARD);
        $this->assertNull($again);
        $this->assertSame(6000, tcgGetCoins($id));
    }

    public function testPinkSIsTopTenAndCanBeDisplaced(): void
    {
        $this->at('2026-10-20 12:00:00');
        $now = tcgSeasonNow();
        $ids = [];
        $ins = tcgDb()->prepare('INSERT INTO tcg_season_rank
            (discord_id, game_mode, season_id, step, points, peak_step, wins, losses, updated_at)
            VALUES (?, ?, ?, 6, ?, 6, ?, 0, ?)');
        for ($i = 0; $i < 11; $i++) {
            $id = $this->user('pink' . $i);
            $ids[] = $id;
            $ins->execute([$id, TCG_GAME_MODE_STANDARD, '2026-10', 200 - ($i * 10), 30 - $i, $now + $i]);
        }
        tcgSeasonAssignPinkSlots(TCG_GAME_MODE_STANDARD, '2026-10');
        for ($i = 0; $i < 10; $i++) {
            $row = tcgSeasonLoadRow($ids[$i], TCG_GAME_MODE_STANDARD);
            $this->assertSame(7, (int)$row['step']);
            $this->assertSame(7, (int)$row['peak_step']);
        }
        $eleventh = tcgSeasonLoadRow($ids[10], TCG_GAME_MODE_STANDARD);
        $this->assertSame(6, (int)$eleventh['step']);
        $this->assertSame(6, (int)$eleventh['peak_step']);

        tcgDb()->prepare('UPDATE tcg_season_rank SET points = 115, updated_at = ? WHERE discord_id = ?')
            ->execute([$now + 50, $ids[10]]);
        tcgSeasonAssignPinkSlots(TCG_GAME_MODE_STANDARD, '2026-10');
        $entered = tcgSeasonLoadRow($ids[10], TCG_GAME_MODE_STANDARD);
        $this->assertSame(7, (int)$entered['step']);
        $this->assertSame(7, (int)$entered['peak_step']);
        $pushed = tcgSeasonLoadRow($ids[9], TCG_GAME_MODE_STANDARD);
        $this->assertSame(6, (int)$pushed['step']);
        $this->assertSame(6, (int)$pushed['peak_step']);
        $this->assertSame(110, (int)$pushed['points']);
        $count = tcgDb()->prepare('SELECT COUNT(*) FROM tcg_season_rank WHERE game_mode = ? AND season_id = ? AND step = 7');
        $count->execute([TCG_GAME_MODE_STANDARD, '2026-10']);
        $this->assertSame(10, (int)$count->fetchColumn());
    }

    public function testSeasonLadderIsStandardOnly(): void
    {
        $this->at('2026-10-15 12:00:00');
        $winner = $this->user('modeW');
        $loser = $this->user('modeL');
        tcgApplyRankResult($winner, $loser, false, TCG_GAME_MODE_STARTERS);
        tcgApplyRankResult($winner, $loser, false, TCG_GAME_MODE_RANDOMIZED);
        $this->assertNull(tcgSeasonLoadRow($winner, TCG_GAME_MODE_STARTERS));
        $this->assertNull(tcgSeasonLoadRow($winner, TCG_GAME_MODE_RANDOMIZED));
        $this->assertNull(tcgSeasonLoadRow($winner, TCG_GAME_MODE_STANDARD));
        $this->assertFalse(tcgSeasonPublic($winner, TCG_GAME_MODE_STARTERS)['active']);
        $this->assertSame(-1, tcgSeasonQueueProfile($winner, TCG_GAME_MODE_STARTERS)['step']);

        tcgApplyRankResult($winner, $loser, false, TCG_GAME_MODE_STANDARD);
        $this->assertNotNull(tcgSeasonLoadRow($winner, TCG_GAME_MODE_STANDARD));
        $this->assertNull(tcgSeasonLoadRow($winner, TCG_GAME_MODE_STARTERS));
        $bundle = tcgSeasonBundleForUser($winner);
        $this->assertSame([TCG_GAME_MODE_STANDARD], array_keys($bundle['seasons']));
        $this->assertNull(tcgSeasonSettle($winner, TCG_GAME_MODE_STARTERS));
    }

    public function testQueuePrefersSameStepInsideEloBand(): void
    {
        $this->at('2026-10-18 12:00:00');
        $searcher = $this->user('search');
        $same = $this->user('same');
        $closer = $this->user('closer');
        $now = tcgSeasonNow();
        $db = tcgDb();
        $ins = $db->prepare('INSERT INTO tcg_season_rank
            (discord_id, game_mode, season_id, step, points, peak_step, wins, losses, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?)');
        $ins->execute([$searcher, TCG_GAME_MODE_STANDARD, '2026-10', 4, 50, 4, $now]);
        $ins->execute([$same, TCG_GAME_MODE_STANDARD, '2026-10', 4, 10, 4, $now]);
        $ins->execute([$closer, TCG_GAME_MODE_STANDARD, '2026-10', 2, 10, 2, $now]);
        tcgQueueJoin($searcher, TCG_GAME_MODE_STANDARD);
        tcgQueueJoin($same, TCG_GAME_MODE_STANDARD);
        tcgQueueJoin($closer, TCG_GAME_MODE_STANDARD);
        $db->prepare('UPDATE tcg_match_queue SET rating = ? WHERE discord_id = ?')->execute([1140, $same]);
        $db->prepare('UPDATE tcg_match_queue SET rating = ? WHERE discord_id = ?')->execute([1000, $closer]);
        $opp = tcgFindQueueOpponent($searcher, 1000, TCG_GAME_MODE_STANDARD);
        $this->assertNotNull($opp);
        $this->assertSame($same, $opp['discord_id']);

        $db->prepare('UPDATE tcg_season_rank SET points = 10 WHERE discord_id = ?')->execute([$searcher]);
        $down = $this->user('down');
        $far = $this->user('far');
        $ins->execute([$down, TCG_GAME_MODE_STANDARD, '2026-10', 3, 0, 3, $now]);
        $ins->execute([$far, TCG_GAME_MODE_STANDARD, '2026-10', 6, 0, 6, $now]);
        tcgQueueLeave($same);
        tcgQueueLeave($closer);
        tcgQueueJoin($down, TCG_GAME_MODE_STANDARD);
        tcgQueueJoin($far, TCG_GAME_MODE_STANDARD);
        $db->prepare('UPDATE tcg_match_queue SET rating = ? WHERE discord_id = ?')->execute([1000, $down]);
        $db->prepare('UPDATE tcg_match_queue SET rating = ? WHERE discord_id = ?')->execute([1020, $far]);
        $near = tcgFindQueueOpponent($searcher, 1000, TCG_GAME_MODE_STANDARD);
        $this->assertNotNull($near);
        $this->assertSame($down, $near['discord_id']);
    }

    public function testSeasonLabelResetsEachCalendarYear(): void
    {
        $this->assertSame('2026 Season 1', tcgSeasonLabel('2026-10'));
        $this->assertSame('2026 Season 2', tcgSeasonLabel('2026-11'));
        $this->assertSame('2026 Season 3', tcgSeasonLabel('2026-12'));
        $this->assertSame('2027 Season 1', tcgSeasonLabel('2027-01'));
        $this->assertSame('2027 Season 12', tcgSeasonLabel('2027-12'));
        $this->assertSame('2028 Season 1', tcgSeasonLabel('2028-01'));
        $this->at('2027-01-15 12:00:00');
        $clock = tcgSeasonClockInfo();
        $this->assertSame('2027-01', $clock['id']);
        $this->assertSame(1, $clock['number']);
        $this->assertSame('2027 Season 1', $clock['label']);
        $this->at('2027-12-15 12:00:00');
        $dec = tcgSeasonClockInfo();
        $this->assertSame(12, $dec['number']);
        $this->assertSame('2027 Season 12', $dec['label']);
    }
}
