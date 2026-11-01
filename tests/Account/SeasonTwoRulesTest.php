<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

/**
 * Season 2 ranked rules (feedback on Season 1): no point loss below A Green, A Green is the floor once reached,
 * softer losses from A up, one-time rank-up rewards per tier, and the rates / rewards shown in-game.
 */
final class SeasonTwoRulesTest extends TestCase
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
        $this->at('2026-11-05 12:00:00');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCG_SEASON_NOW']);
        if (!extension_loaded('pdo_sqlite')) {
            return;
        }
        $db = tcgDb();
        foreach ($this->ids as $id) {
            foreach (['tcg_season_rankup', 'tcg_season_history', 'tcg_season_rank', 'tcg_rank', 'tcg_match_queue'] as $t) {
                $db->prepare("DELETE FROM $t WHERE discord_id = ?")->execute([$id]);
            }
            try {
                $db->prepare('DELETE FROM tcg_gift_claims WHERE discord_id = ?')->execute([$id]);
                $db->prepare("DELETE FROM tcg_gifts WHERE id LIKE ?")->execute(['%' . $id . '%']);
            } catch (\Throwable $e) {
                // gifts tables may not exist in older test DBs
            }
            $db->prepare('DELETE FROM tcg_users WHERE discord_id = ?')->execute([$id]);
        }
    }

    private function at(string $utc): void
    {
        $GLOBALS['TCG_SEASON_NOW'] = strtotime($utc . ' UTC');
    }

    private function user(string $name): string
    {
        $id = 'season2_' . $name . '_' . bin2hex(random_bytes(3));
        tcgEnsureUser($id, ['username' => $name]);
        $this->ids[] = $id;
        return $id;
    }

    private function row(string $id, int $step, int $points, int $peak, string $season = '2026-11'): void
    {
        tcgSeasonEnsureSchema(tcgDb());
        tcgDb()->prepare('INSERT OR REPLACE INTO tcg_season_rank
            (discord_id, game_mode, season_id, step, points, peak_step, wins, losses, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?)')
            ->execute([$id, TCG_GAME_MODE_STANDARD, $season, $step, $points, $peak, tcgSeasonNow()]);
    }

    public function testRuleSetFollowsTheSeasonId(): void
    {
        $this->assertSame(1, tcgSeasonRulesVersion('2026-10'));
        $this->assertSame(2, tcgSeasonRulesVersion('2026-11'));
        $this->assertSame(2, tcgSeasonRulesVersion('2027-01'));
        $this->assertSame(2, tcgSeasonRulesVersion(), 'current season (Nov 2026) uses rule set 2');
        $this->at('2026-10-31 23:59:59');
        $this->assertSame(1, tcgSeasonRulesVersion(), 'Season 1 keeps its original rules until it ends');
    }

    public function testNothingIsLostBelowAGreen(): void
    {
        foreach ([0, 1, 2, 3] as $step) {
            $moved = tcgSeasonMovePoints($step, 40, 32, false, 2);
            $this->assertSame(['step' => $step, 'points' => 40], $moved, "step $step loses nothing");
        }
    }

    public function testAGreenIsTheFloorOnceReached(): void
    {
        $moved = tcgSeasonMovePoints(4, 5, 30, false, 2);
        $this->assertSame(['step' => 4, 'points' => 0], $moved, 'cannot drop below A Green');
        $moved = tcgSeasonMovePoints(5, 10, 30, false, 2);
        $this->assertSame(['step' => 4, 'points' => 80], $moved, 'A Pink can fall back to A Green, not further');
        $seasonOne = tcgSeasonMovePoints(4, 5, 30, false, 1);
        $this->assertSame(['step' => 3, 'points' => 75], $seasonOne, 'Season 1 rules are unchanged');
    }

    public function testLossesFromARankUpAreSofter(): void
    {
        // Same-rank match (base 16): A Green 5, A Pink 7, S 10 (Season 1: 10 / 13 / 16).
        $this->assertSame(5, tcgSeasonLossDelta(16, 4, 2));
        $this->assertSame(7, tcgSeasonLossDelta(16, 5, 2));
        $this->assertSame(10, tcgSeasonLossDelta(16, 6, 2));
        $this->assertSame(10, tcgSeasonLossDelta(16, 7, 2), 'Pink S scores as Green S');
        $this->assertSame(19, tcgSeasonLossDelta(32, 6, 2));
        $this->assertSame(16, tcgSeasonLossDelta(16, 6, 1));
        $this->assertSame(0, tcgSeasonLossDelta(32, 3, 2));
    }

    public function testRatesPayloadShowsRewardsAndRates(): void
    {
        $v2 = tcgSeasonRatesPayload('2026-11');
        $this->assertSame(2, $v2['rules_version']);
        $this->assertSame(4, $v2['floor_step']);
        $this->assertCount(8, $v2['steps']);
        $this->assertTrue($v2['steps'][3]['protected']);
        $this->assertFalse($v2['steps'][4]['protected']);
        $this->assertSame(5, $v2['steps'][4]['loss_same']);
        $this->assertSame([10, 20, 40], [$v2['win_min'], $v2['win_same'], $v2['win_max']]);
        $this->assertSame(400, $v2['steps'][0]['season_reward']['coins']);
        $this->assertNull($v2['steps'][0]['rankup_reward']);
        $this->assertSame(500, $v2['steps'][4]['rankup_reward']['coins']);
        $this->assertSame(2, $v2['steps'][7]['rankup_reward']['ticket_sr']);
        $this->assertCount(8, $v2['soft_reset']);
        $this->assertSame(
            [['from' => 0, 'to' => 0], ['from' => 3, 'to' => 0], ['from' => 4, 'to' => 1], ['from' => 7, 'to' => 4]],
            [
                $v2['soft_reset'][0],
                $v2['soft_reset'][3],
                $v2['soft_reset'][4],
                $v2['soft_reset'][7],
            ]
        );

        $v1 = tcgSeasonRatesPayload('2026-10');
        $this->assertSame(1, $v1['rules_version']);
        $this->assertNull($v1['steps'][4]['rankup_reward'], 'no rank-up rewards in Season 1');
        $this->assertSame(10, $v1['steps'][4]['loss_same']);
        $this->assertTrue($v1['steps'][1]['protected']);
        $this->assertFalse($v1['steps'][2]['protected']);
        $this->assertSame($v2['soft_reset'], $v1['soft_reset'], 'soft reset map is the same every season');
    }

    public function testRatesPayloadHasTheFullOpponentChart(): void
    {
        $v2 = tcgSeasonRatesPayload('2026-11')['matrix'];
        $this->assertCount(7, $v2['win']);
        $this->assertCount(7, $v2['win'][0]);
        $this->assertSame(20, $v2['win'][3][3], 'same rank wins 20 (Season 1: 16)');
        $this->assertSame(40, $v2['win'][0][6], 'C beating S pays the maximum');
        $this->assertSame(10, $v2['win'][6][0], 'S beating C pays the minimum');
        $this->assertSame(0, $v2['loss'][3][0], 'B loses nothing in Season 2');
        $this->assertSame(5, $v2['loss'][4][4]);
        $this->assertSame(19, $v2['loss'][6][0], 'S upset by C is the worst case');
        $v1 = tcgSeasonRatesPayload('2026-10')['matrix'];
        $this->assertSame(16, $v1['win'][3][3], 'Season 1 wins are unchanged');
        $this->assertSame(32, $v1['loss'][6][0], 'Season 1 chart keeps the old worst case');
        $this->assertGreaterThan(0, $v1['loss'][2][2], 'B loses points in Season 1');
    }

    public function testSeasonTwoWinsPayMoreWithoutRaisingLosses(): void
    {
        $this->assertSame(20, tcgSeasonPointDelta(4, 4, 2));
        $this->assertSame(16, tcgSeasonPointDelta(4, 4, 1));
        $this->assertSame(10, tcgSeasonPointDelta(6, 0, 2));
        $this->assertSame(40, tcgSeasonPointDelta(0, 6, 2));
        // The loser's loss is taken from the original scale: same-rank A Green still loses 5, not 6.
        $this->assertSame(16, tcgSeasonPointDelta(4, 4, 2, true));
        $this->assertSame(5, tcgSeasonLossDelta(tcgSeasonPointDelta(4, 4, 2, true), 4, 2));
        // A win bar move uses the Season 2 range (Season 1 would clamp 40 to 32).
        $this->assertSame(['step' => 4, 'points' => 40], tcgSeasonMovePoints(4, 0, 40, true, 2));
        $this->assertSame(['step' => 4, 'points' => 32], tcgSeasonMovePoints(4, 0, 40, true, 1));
    }

    public function testSeasonEndRewardsIncludeTicketsFromSeasonTwo(): void
    {
        $expected = [
            0 => [0, 0], 1 => [0, 0], 2 => [0, 0],
            3 => [1, 0], 4 => [1, 0], 5 => [2, 0], 6 => [2, 1], 7 => [2, 2],
        ];
        foreach ($expected as $step => [$sr, $ur]) {
            $r = tcgSeasonRewardForStep($step, '2026-11');
            $this->assertSame([$sr, $ur], [$r['ticket_sr'], $r['ticket_ur']], "step $step");
            $old = tcgSeasonRewardForStep($step, '2026-10');
            $this->assertSame([0, 0], [$old['ticket_sr'], $old['ticket_ur']], "Season 1 step $step has no tickets");
            $this->assertSame($old['coins'], $r['coins'], 'coins / gems / packs are unchanged');
        }
        $payload = tcgSeasonRatesPayload('2026-11');
        $this->assertSame(2, $payload['steps'][7]['season_reward']['ticket_ur']);
        $this->assertSame(0, tcgSeasonRatesPayload('2026-10')['steps'][7]['season_reward']['ticket_ur']);
    }

    public function testClosingSeasonTwoPaysTheTicketsOnceAndSeasonOneDoesNot(): void
    {
        $id = $this->user('closer');
        $this->row($id, 6, 40, 7, '2026-11');
        $this->at('2026-12-05 12:00:00');
        $coins0 = tcgGetCoins($id);
        $granted = tcgSeasonSettle($id, TCG_GAME_MODE_STANDARD);
        $this->assertNotNull($granted);
        $this->assertSame(2, $granted['ticket_sr']);
        $this->assertSame(2, $granted['ticket_ur']);
        $this->assertTrue(!empty($granted['via_gift']));
        $this->assertSame($coins0, tcgGetCoins($id), 'wallet waits for Gifts claim');
        $this->assertSame(0, tcgGetScoutingTierTickets($id, 'sr'));
        require_once dirname(__DIR__, 2) . '/gifts.php';
        foreach (tcgGiftsForUser($id) as $g) {
            if (($g['id'] ?? '') === ($granted['gift_id'] ?? '')) {
                foreach ($g['rewards'] as $rw) {
                    if (is_array($rw)) {
                        tcgGiftGrantReward($id, $rw);
                    }
                }
            }
        }
        $this->assertSame($coins0 + 6000, tcgGetCoins($id));
        $this->assertSame(2, tcgGetScoutingTierTickets($id, 'sr'));
        $this->assertSame(2, tcgGetScoutingTierTickets($id, 'ur'));
        $this->assertNull(tcgSeasonSettle($id, TCG_GAME_MODE_STANDARD), 'settled once');
        $this->assertSame(2, tcgGetScoutingTierTickets($id, 'ur'));

        $one = $this->user('closer1');
        $this->row($one, 6, 40, 7, '2026-10');
        $this->at('2026-11-05 12:00:00');
        $g1 = tcgSeasonSettle($one, TCG_GAME_MODE_STANDARD);
        $this->assertSame(0, $g1['ticket_sr'] + $g1['ticket_ur'], 'Season 1 payout has no tickets');
        $this->assertSame(0, tcgGetScoutingTierTickets($one, 'sr'));
    }

    public function testRankUpRewardIsPaidOncePerTierPerSeason(): void
    {
        $id = $this->user('rankup');
        $coins0 = tcgGetCoins($id);
        $first = tcgSeasonGrantRankUp($id, TCG_GAME_MODE_STANDARD, '2026-11', 5, true);
        $this->assertNotNull($first);
        $this->assertSame(1, $first['ticket_sr']);
        $this->assertTrue(!empty($first['via_gift']));
        $this->assertSame($coins0, tcgGetCoins($id), 'rank-up coins wait for Gifts claim');
        $this->assertSame(0, tcgGetScoutingTierTickets($id, 'sr'));
        $this->assertSame(0, tcgGetScoutingTickets($id));
        require_once dirname(__DIR__, 2) . '/gifts.php';
        $giftId = (string)($first['gift_id'] ?? '');
        foreach (tcgGiftsForUser($id) as $g) {
            if ($g['id'] === $giftId) {
                foreach ($g['rewards'] as $rw) {
                    if (is_array($rw)) {
                        tcgGiftGrantReward($id, $rw);
                    }
                }
            }
        }
        $this->assertSame($coins0 + 750, tcgGetCoins($id));
        $this->assertSame(1, tcgGetScoutingTierTickets($id, 'sr'));
        $this->assertSame(5, tcgGetScoutingTickets($id));
        $this->assertNull(tcgSeasonGrantRankUp($id, TCG_GAME_MODE_STANDARD, '2026-11', 5, true), 'not paid twice');
        $this->assertNotNull(tcgSeasonGrantRankUp($id, TCG_GAME_MODE_STANDARD, '2026-12', 5, true), 'a new season pays again');
        $this->assertNull(tcgSeasonGrantRankUp($id, TCG_GAME_MODE_STANDARD, '2026-10', 5, true), 'Season 1 has no rank-up rewards');
    }

    public function testPromotionInAMatchPaysTheTierReachedAndShowsItInTheResult(): void
    {
        $winner = $this->user('winner');
        $loser = $this->user('loser');
        // 96/100 on Pink C (peak 1): the win promotes to Green B (step 2) and offers that tier once in Gifts.
        $this->row($winner, 1, 96, 1);
        $coins0 = tcgGetCoins($winner);
        $changes = tcgSeasonApplyResult($winner, $loser, false, TCG_GAME_MODE_STANDARD);
        $this->assertNotNull($changes);
        $w = $changes[$winner];
        $this->assertTrue($w['promoted']);
        $this->assertCount(1, $w['rankup_rewards']);
        $this->assertSame(2, $w['rankup_rewards'][0]['step']);
        $this->assertTrue(!empty($w['rankup_rewards'][0]['via_gift']));
        $this->assertSame($coins0, tcgGetCoins($winner));
        $this->assertSame([], tcgSeasonTakePendingRankUps($winner));
        // A loss on a protected rank says so and costs nothing.
        $l = $changes[$loser];
        $this->assertTrue($l['protected']);
        $this->assertSame(0, (int)tcgSeasonLoadRow($loser, TCG_GAME_MODE_STANDARD)['points']);
    }

    public function testPinkSHolderIsPaidOnceAndUnshownPaymentsArePending(): void
    {
        $id = $this->user('pinks');
        $reward = tcgSeasonGrantRankUp($id, TCG_GAME_MODE_STANDARD, '2026-11', 7, false);
        $this->assertSame(2, $reward['ticket_sr']);
        $this->assertTrue(!empty($reward['via_gift']));
        require_once dirname(__DIR__, 2) . '/gifts.php';
        $gifts = array_values(array_filter(
            tcgGiftsForUser($id),
            static fn ($g) => ($g['id'] ?? '') === ($reward['gift_id'] ?? '')
        ));
        $this->assertCount(1, $gifts);
        // Pending toasts are retired — the Gifts badge is the notice.
        $this->assertSame([], tcgSeasonTakePendingRankUps($id));
    }

    public function testLossAtTheFloorKeepsTheRankAndIsNotMarkedProtected(): void
    {
        $winner = $this->user('w2');
        $loser = $this->user('l2');
        $this->row($loser, 4, 3, 4);
        $this->row($winner, 4, 50, 4);
        $changes = tcgSeasonApplyResult($winner, $loser, false, TCG_GAME_MODE_STANDARD);
        $l = $changes[$loser];
        $this->assertFalse($l['protected']);
        $this->assertFalse($l['demoted']);
        $row = tcgSeasonLoadRow($loser, TCG_GAME_MODE_STANDARD);
        $this->assertSame(4, (int)$row['step']);
        $this->assertSame(0, (int)$row['points']);
    }

    public function testSeasonOneMatchesStillUseTheOldCurve(): void
    {
        $this->at('2026-10-20 12:00:00');
        $winner = $this->user('w1');
        $loser = $this->user('l1');
        $this->row($loser, 4, 50, 4, '2026-10');
        $this->row($winner, 4, 50, 4, '2026-10');
        $coins0 = tcgGetCoins($winner);
        $changes = tcgSeasonApplyResult($winner, $loser, false, TCG_GAME_MODE_STANDARD);
        // Rule set 1: same-step base 16, A Green keeps 60% of it as the loss (10), not Season 2's 30% (5).
        $this->assertSame(40, (int)tcgSeasonLoadRow($loser, TCG_GAME_MODE_STANDARD)['points']);
        $this->assertSame([], $changes[$winner]['rankup_rewards']);
        $this->assertSame($coins0, tcgGetCoins($winner));
    }
}
