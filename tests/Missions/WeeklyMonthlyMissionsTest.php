<?php

declare(strict_types=1);

namespace LLTCG\Tests\Missions;

use PHPUnit\Framework\TestCase;

/** Weekly / monthly missions + the new milestones: tracking, chaining, claiming. */
final class WeeklyMonthlyMissionsTest extends TestCase
{
    private string $uid;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/missions.php';
        require_once dirname(__DIR__, 2) . '/seals.php';
        require_once dirname(__DIR__, 2) . '/sleeve_shop.php';
        require_once dirname(__DIR__, 2) . '/playmat_shop.php';
        $this->uid = 'test_wm_' . bin2hex(random_bytes(4));
        tcgEnsureUser($this->uid, ['username' => 'WM Test']);
    }

    private function week(): string
    {
        return tcgMissionWeekKey();
    }

    private function month(): string
    {
        return tcgMissionMonthKey();
    }

    private function rankedState(string $room, string $mode = 'standard', ?string $opp = null): array
    {
        $opp ??= 'test_wm_opp_' . bin2hex(random_bytes(3));
        tcgEnsureUser($opp, ['username' => 'Opp']);
        return [
            'room_id' => $room,
            'mode' => 'ranked',
            'status' => 'finished',
            'winner' => 'p1',
            'turn' => 5,
            'ranked' => ['p1_discord_id' => $this->uid, 'p2_discord_id' => $opp, 'game_mode' => $mode],
            'players' => [
                'p1' => ['name' => 'Me', 'discord_id' => $this->uid],
                'p2' => ['name' => 'Opp', 'discord_id' => $opp],
            ],
        ];
    }

    public function testPeriodKeysAndDefinitions(): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $this->week());
        $this->assertSame('1', (new \DateTimeImmutable($this->week(), new \DateTimeZone('Asia/Tokyo')))->format('N'), 'weeks start on Monday');
        $this->assertGreaterThan(time(), tcgMissionWeekEndsAt());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $this->month());
        foreach (array_merge(TCG_MISSION_WEEKLY_SUB_IDS, TCG_MISSION_MONTHLY_SUB_IDS, ['weekly_complete_all', 'monthly_complete_all']) as $id) {
            $def = tcgMissionDefById($id);
            $this->assertNotNull($def, $id);
            $this->assertNotEmpty($def['rewards'], $id);
        }
        $this->assertSame($this->week(), tcgMissionPeriodKey(tcgMissionDefById('weekly_login_7')));
        $this->assertSame($this->month(), tcgMissionPeriodKey(tcgMissionDefById('monthly_pink_c')));
    }

    public function testRankedRandomizedCreditsWeeklyOnlyForRandomMode(): void
    {
        tcgMissionOnGameFinished($this->rankedState('R1' . bin2hex(random_bytes(2)), 'standard'));
        $this->assertFalse(tcgMissionIsCompleted($this->uid, 'weekly_ranked_random', $this->week()));
        tcgMissionOnGameFinished($this->rankedState('R2' . bin2hex(random_bytes(2)), 'randomized'));
        $this->assertTrue(tcgMissionIsCompleted($this->uid, 'weekly_ranked_random', $this->week()));
    }

    public function testRankedCountersCompleteWeeklyTenAndMonthlyThirtyWithoutDoubleCounting(): void
    {
        $retried = $this->rankedState('RETRY1');
        tcgMissionOnGameFinished($retried);
        tcgMissionOnGameFinished($retried); // finish hook retried for the same room
        $this->assertSame(1, tcgMissionCounterGet($this->uid, 'ranked', $this->week()));
        $this->assertSame(1, tcgMissionCounterGet($this->uid, 'ranked', $this->month()));

        for ($i = 2; $i <= 10; $i++) {
            tcgMissionOnGameFinished($this->rankedState('RC' . $i . bin2hex(random_bytes(2))));
        }
        $this->assertTrue(tcgMissionIsCompleted($this->uid, 'weekly_ranked_10', $this->week()));
        $this->assertFalse(tcgMissionIsCompleted($this->uid, 'monthly_ranked_30', $this->month()));
        for ($i = 11; $i <= 30; $i++) {
            tcgMissionOnGameFinished($this->rankedState('RC' . $i . bin2hex(random_bytes(2))));
        }
        $this->assertSame(30, tcgMissionCounterGet($this->uid, 'ranked', $this->month()));
        $this->assertTrue(tcgMissionIsCompleted($this->uid, 'monthly_ranked_30', $this->month()));
    }

    public function testUnrankedMatchesDoNotCountAsRanked(): void
    {
        $state = $this->rankedState('UNR1');
        $state['mode'] = 'casual';
        tcgMissionOnGameFinished($state);
        $this->assertSame(0, tcgMissionCounterGet($this->uid, 'ranked', $this->week()));
    }

    public function testTournamentMatchCompletesMonthlyParticipation(): void
    {
        $state = $this->rankedState('TOUR1');
        $state['mode'] = 'tournament';
        tcgMissionOnGameFinished($state);
        $this->assertTrue(tcgMissionIsCompleted($this->uid, 'monthly_tournament', $this->month()));
        $this->assertSame(0, tcgMissionCounterGet($this->uid, 'ranked', $this->week()), 'tournament games are not ranked games');
    }

    public function testShopPurchaseAndStickerExchangeAreNoLongerWeeklyMissions(): void
    {
        $this->assertNull(tcgMissionDefById('weekly_shop_buy'));
        $this->assertNull(tcgMissionDefById('weekly_sticker_exchange'));
        $this->assertSame(['weekly_ranked_random', 'weekly_ranked_10', 'weekly_login_7'], TCG_MISSION_WEEKLY_SUB_IDS);
        $this->assertSame(3, tcgMissionDefById('weekly_complete_all')['threshold']);
        $this->assertSame([], tcgMissionOnShopPurchase($this->uid));
        $this->assertSame([], tcgMissionOnStickerExchange($this->uid));
    }

    public function testFinishingTheThreeWeeklyMissionsCompletesTheSet(): void
    {
        foreach (TCG_MISSION_WEEKLY_SUB_IDS as $id) {
            tcgMissionMarkCompleted($this->uid, $id);
        }
        $this->assertTrue(tcgMissionIsCompleted($this->uid, 'weekly_complete_all', $this->week()));
    }

    public function testPeriodicCatchUpCreditsAFinishedWeeklySet(): void
    {
        // Rows completed before the set shrank: the bonus is still owed on the next check.
        $db = tcgDb();
        foreach (TCG_MISSION_WEEKLY_SUB_IDS as $id) {
            $db->prepare('INSERT OR IGNORE INTO tcg_mission_progress (discord_id, mission_id, period_key, completed_at) VALUES (?, ?, ?, ?)')
                ->execute([$this->uid, $id, $this->week(), time()]);
        }
        $this->assertFalse(tcgMissionIsCompleted($this->uid, 'weekly_complete_all', $this->week()));
        tcgMissionCheckPeriodic($this->uid);
        $this->assertTrue(tcgMissionIsCompleted($this->uid, 'weekly_complete_all', $this->week()));
    }

    public function testLoginSevenDaysNeedsEveryDayOfTheWeek(): void
    {
        $tz = new \DateTimeZone('Asia/Tokyo');
        $monday = new \DateTimeImmutable($this->week(), $tz);
        $db = tcgDb();
        for ($i = 0; $i < 6; $i++) {
            $db->prepare('INSERT OR IGNORE INTO tcg_login_days_log (discord_id, day) VALUES (?, ?)')
                ->execute([$this->uid, $monday->modify("+$i days")->format('Y-m-d')]);
        }
        $this->assertFalse(tcgMissionIsCompleted($this->uid, 'weekly_login_7', $this->week()));
        $db->prepare('INSERT OR IGNORE INTO tcg_login_days_log (discord_id, day) VALUES (?, ?)')
            ->execute([$this->uid, $monday->modify('+6 days')->format('Y-m-d')]);
        tcgMissionCheckPeriodic($this->uid);
        $this->assertTrue(tcgMissionIsCompleted($this->uid, 'weekly_login_7', $this->week()));
        // Last week's days never count toward this week.
        $this->assertSame(7, tcgMissionLoginDaysThisWeek($this->uid));
    }

    public function testCompletingEveryWeeklyChainsIntoCompleteAllAndMonthlyCounter(): void
    {
        $week = $this->week();
        foreach (TCG_MISSION_WEEKLY_SUB_IDS as $id) {
            $this->assertFalse(tcgMissionIsCompleted($this->uid, 'weekly_complete_all', $week), "before $id");
            tcgMissionMarkCompleted($this->uid, $id);
        }
        $this->assertTrue(tcgMissionIsCompleted($this->uid, 'weekly_complete_all', $week));
        $this->assertSame(1, tcgMissionCounterGet($this->uid, 'weekly_all', $this->month()));
        // Re-marking is idempotent: the monthly counter stays at 1.
        tcgMissionMarkCompleted($this->uid, 'weekly_complete_all');
        $this->assertSame(1, tcgMissionCounterGet($this->uid, 'weekly_all', $this->month()));
    }

    public function testFourWeeklyClearsCompleteMonthlyAndAllMonthliesCompleteTheLastOne(): void
    {
        $month = $this->month();
        tcgMissionCounterBump($this->uid, 'weekly_all', $month, 3);
        $this->assertFalse(tcgMissionIsCompleted($this->uid, 'monthly_weekly_4', $month));
        foreach (TCG_MISSION_WEEKLY_SUB_IDS as $id) {
            tcgMissionMarkCompleted($this->uid, $id);
        }
        $this->assertTrue(tcgMissionIsCompleted($this->uid, 'monthly_weekly_4', $month), '4th all-weekly clear');
        foreach (['monthly_tournament', 'monthly_ranked_30', 'monthly_pink_c'] as $id) {
            $this->assertFalse(tcgMissionIsCompleted($this->uid, 'monthly_complete_all', $month));
            tcgMissionMarkCompleted($this->uid, $id);
        }
        $this->assertTrue(tcgMissionIsCompleted($this->uid, 'monthly_complete_all', $month));
    }

    public function testPinkCSeasonRankCompletesMonthly(): void
    {
        require_once dirname(__DIR__, 2) . '/season.php';
        $clock = tcgSeasonClockInfo();
        if (empty($clock['active'])) {
            $this->markTestSkipped('season not active');
        }
        $this->assertSame([], tcgMissionCheckSeasonRank($this->uid));
        tcgDb()->prepare('INSERT OR REPLACE INTO tcg_season_rank
            (discord_id, game_mode, season_id, step, points, peak_step, wins, losses, updated_at)
            VALUES (?, "standard", ?, 0, 10, 1, 1, 0, ?)')
            ->execute([$this->uid, $clock['id'], time()]);
        $this->assertContains('monthly_pink_c', array_column(tcgMissionCheckSeasonRank($this->uid), 'id'));
        // A row from an older season does not count.
        $other = 'test_wm_old_' . bin2hex(random_bytes(3));
        tcgEnsureUser($other, ['username' => 'Old']);
        tcgDb()->prepare('INSERT OR REPLACE INTO tcg_season_rank
            (discord_id, game_mode, season_id, step, points, peak_step, wins, losses, updated_at)
            VALUES (?, "standard", "2020-01", 3, 10, 3, 1, 0, ?)')
            ->execute([$other, time()]);
        $this->assertSame([], tcgMissionCheckSeasonRank($other));
    }

    public function testCosmeticOwnershipMilestones(): void
    {
        $db = tcgDb();
        for ($i = 0; $i < 9; $i++) {
            $db->prepare('INSERT INTO tcg_owned_sleeves (discord_id, sleeve_id, acquired_at, source, equip_intro_seen) VALUES (?, ?, ?, "test", 1)')
                ->execute([$this->uid, 'test-sleeve-' . $i, time()]);
        }
        $this->assertSame([], tcgMissionCheckOwnedCosmetics($this->uid));
        $db->prepare('INSERT INTO tcg_owned_sleeves (discord_id, sleeve_id, acquired_at, source, equip_intro_seen) VALUES (?, ?, ?, "test", 1)')
            ->execute([$this->uid, 'test-sleeve-9', time()]);
        $this->assertContains('ms_sleeves_10', array_column(tcgMissionCheckOwnedCosmetics($this->uid), 'id'));
        $this->assertFalse(tcgMissionIsCompleted($this->uid, 'ms_playmats_10', ''));
        for ($i = 0; $i < 10; $i++) {
            $db->prepare('INSERT INTO tcg_owned_playmats (discord_id, playmat_id, acquired_at, source) VALUES (?, ?, ?, "test")')
                ->execute([$this->uid, 'test-mat-' . $i, time()]);
        }
        $this->assertContains('ms_playmats_10', array_column(tcgMissionCheckOwnedCosmetics($this->uid), 'id'));
    }

    public function testGachaScoutMilestones(): void
    {
        $done = tcgMissionOnGachaScout($this->uid, false);
        $this->assertSame(['ms_gacha_scout_1'], array_column($done, 'id'));
        $this->assertFalse(tcgMissionIsCompleted($this->uid, 'ms_gacha_birthday', ''));
        $done = tcgMissionOnGachaScout($this->uid, true);
        $this->assertSame(['ms_gacha_birthday'], array_column($done, 'id'));
        for ($i = 0; $i < 7; $i++) {
            tcgMissionOnGachaScout($this->uid, false);
        }
        $this->assertFalse(tcgMissionIsCompleted($this->uid, 'ms_gacha_scout_10', ''));
        $this->assertSame(['ms_gacha_scout_10'], array_column(tcgMissionOnGachaScout($this->uid, false), 'id'));
        $this->assertSame(10, tcgMissionCounterGet($this->uid, 'gacha_scouts', ''));
    }

    public function testFriendTwentyMilestoneUsesFriendCount(): void
    {
        $def = tcgMissionDefById('ms_friend_20');
        $this->assertSame(20, $def['threshold']);
        $this->assertSame('coins', $def['reward_type']);
        $this->assertSame(500, $def['reward']);
    }

    public function testClaimPaysEveryRewardOnceAndRejectsDoubleClaim(): void
    {
        tcgMissionMarkCompleted($this->uid, 'monthly_tournament'); // SR ticket + 500 coins
        $coins0 = tcgGetCoins($this->uid);
        $res = tcgMissionClaim($this->uid, 'monthly_tournament');
        $this->assertSame(1, $res['scouting_tickets_sr']);
        $this->assertSame($coins0 + 500, $res['coins']);
        $this->assertSame(500, $res['coins_gained']);
        $this->assertSame('claimed', $res['mission']['status']);
        try {
            tcgMissionClaim($this->uid, 'monthly_tournament');
            $this->fail('second claim must fail');
        } catch (\Exception $e) {
            $this->assertStringContainsString('already claimed', $e->getMessage());
        }
        $this->assertSame(1, tcgGetScoutingTierTickets($this->uid, 'sr'));
    }

    public function testClaimGrantsEachRewardKind(): void
    {
        $cases = [
            'weekly_ranked_random' => fn() => $this->assertSame(100, tcgGetStarGems($this->uid) - $this->gems0),
            'weekly_ranked_10' => fn() => $this->assertSame(10, tcgSealBalances($this->uid)['n']),
            'weekly_login_7' => fn() => $this->assertSame(10, tcgGetScoutingTickets($this->uid)),
            'weekly_complete_all' => fn() => $this->assertSame(1, tcgGetScoutingTierTickets($this->uid, 'sr')),
            'monthly_complete_all' => fn() => $this->assertSame(1, tcgGetScoutingTierTickets($this->uid, 'ur')),
        ];
        $this->gems0 = tcgGetStarGems($this->uid);
        foreach ($cases as $id => $check) {
            tcgMissionMarkCompleted($this->uid, $id);
            tcgMissionClaim($this->uid, $id);
            $check();
        }
        // Combined rewards.
        tcgMissionMarkCompleted($this->uid, 'ms_gacha_birthday');
        $t0 = tcgGetScoutingTickets($this->uid);
        $g0 = tcgGetStarGems($this->uid);
        tcgMissionClaim($this->uid, 'ms_gacha_birthday');
        $this->assertSame($t0 + 5, tcgGetScoutingTickets($this->uid));
        $this->assertSame($g0 + 50, tcgGetStarGems($this->uid));
        tcgMissionMarkCompleted($this->uid, 'ms_playmats_10');
        $c0 = tcgGetCoins($this->uid);
        $g0 = tcgGetStarGems($this->uid);
        tcgMissionClaim($this->uid, 'ms_playmats_10');
        $this->assertSame($c0 + 3000, tcgGetCoins($this->uid));
        $this->assertSame($g0 + 1000, tcgGetStarGems($this->uid));
        tcgMissionMarkCompleted($this->uid, 'ms_gacha_scout_10');
        $t0 = tcgGetScoutingTickets($this->uid);
        $c0 = tcgGetCoins($this->uid);
        tcgMissionClaim($this->uid, 'ms_gacha_scout_10');
        $this->assertSame($t0 + 10, tcgGetScoutingTickets($this->uid));
        $this->assertSame($c0 + 1000, tcgGetCoins($this->uid));
    }

    private int $gems0 = 0;

    public function testListIncludesWeeklyMonthlyWithProgressAndReset(): void
    {
        tcgMissionOnRankedFinished($this->uid, $this->rankedState('LIST1'));
        $list = tcgMissionListForUser($this->uid);
        $byId = [];
        foreach ($list as $m) {
            $byId[$m['id']] = $m;
        }
        foreach (['weekly_ranked_random', 'weekly_complete_all', 'monthly_pink_c', 'monthly_complete_all', 'ms_sleeves_10', 'ms_gacha_birthday', 'ms_friend_20'] as $id) {
            $this->assertArrayHasKey($id, $byId, $id);
        }
        $this->assertSame('weekly', $byId['weekly_ranked_10']['type']);
        $this->assertSame(1, $byId['weekly_ranked_10']['progress']);
        $this->assertSame(10, $byId['weekly_ranked_10']['threshold']);
        $this->assertGreaterThan(time(), $byId['weekly_ranked_10']['period_ends_at']);
        $this->assertSame('monthly', $byId['monthly_ranked_30']['type']);
        $this->assertSame(1, $byId['monthly_ranked_30']['progress']);
        $this->assertSame('ticket_sr', $byId['monthly_tournament']['rewards'][0]['type']);
        $this->assertSame(0, $byId['ms_friend_20']['progress']);
        $this->assertArrayNotHasKey('period_ends_at', $byId['ms_friend_20']);
    }

    public function testClaimableCountIncludesCompletedWeekly(): void
    {
        $before = tcgMissionClaimableCount($this->uid);
        tcgMissionMarkCompleted($this->uid, 'weekly_login_7');
        $this->assertSame($before + 1, tcgMissionClaimableCount($this->uid));
    }

    private function seedRankedMatch(string $room, string $mode, int $createdAt, string $status = 'done'): void
    {
        tcgDb()->prepare('INSERT INTO tcg_ranked_matches (match_id, room_id, p1_id, p2_id, p1_token, p2_token, status, created_at, game_mode)
            VALUES (?, ?, ?, "someone", "t1", "t2", ?, ?, ?)')
            ->execute(['M' . $room, $room, $this->uid, $status, $createdAt, $mode]);
    }

    public function testHistoryBackfillCreditsThisPeriodsRankedMatchesOnceAndNeverDoubleCountsLiveRooms(): void
    {
        $now = time();
        $this->seedRankedMatch('H1' . bin2hex(random_bytes(2)), 'standard', $now - 60);
        $this->seedRankedMatch('H2' . bin2hex(random_bytes(2)), 'randomized', $now - 120);
        $this->seedRankedMatch('H3' . bin2hex(random_bytes(2)), 'standard', $now - 180, 'pending'); // unfinished: ignored
        $this->seedRankedMatch('H4' . bin2hex(random_bytes(2)), 'standard', 86400 * 365); // an old season: ignored
        $live = 'HLIVE' . bin2hex(random_bytes(2));
        $this->seedRankedMatch($live, 'standard', $now - 30);
        tcgMissionOnGameFinished($this->rankedState($live)); // the live hook already counted this room
        $this->assertSame(1, tcgMissionCounterGet($this->uid, 'ranked', $this->week()));

        tcgMissionBackfillPeriodHistory($this->uid);
        $this->assertSame(3, tcgMissionCounterGet($this->uid, 'ranked', $this->week()), 'two backfilled + the live room once');
        $this->assertSame(3, tcgMissionCounterGet($this->uid, 'ranked', $this->month()));
        $this->assertTrue(tcgMissionIsCompleted($this->uid, 'weekly_ranked_random', $this->week()));

        // Second pass in the same month changes nothing; a retried finish for a backfilled room neither.
        tcgMissionBackfillPeriodHistory($this->uid);
        $this->assertSame(3, tcgMissionCounterGet($this->uid, 'ranked', $this->week()));
        tcgMissionOnGameFinished($this->rankedState($live));
        $this->assertSame(3, tcgMissionCounterGet($this->uid, 'ranked', $this->week()));
    }

    public function testHistoryBackfillCompletesWeeklyTenFromExistingMatches(): void
    {
        $now = time();
        for ($i = 0; $i < 10; $i++) {
            $this->seedRankedMatch('HT' . $i . bin2hex(random_bytes(2)), 'standard', $now - 60 - $i);
        }
        tcgMissionCheckPeriodic($this->uid);
        $this->assertTrue(tcgMissionIsCompleted($this->uid, 'weekly_ranked_10', $this->week()));
        $list = tcgMissionListForUser($this->uid);
        $byId = array_column($list, null, 'id');
        $this->assertSame('completed', $byId['weekly_ranked_10']['status']);
        $this->assertGreaterThanOrEqual(1, tcgMissionClaimableCount($this->uid));
    }

    public function testHistoryBackfillCreditsShopPurchaseTournamentAndLoginDay(): void
    {
        $db = tcgDb();
        $db->prepare('INSERT INTO tcg_owned_playmats (discord_id, playmat_id, acquired_at, source) VALUES (?, "retro-mat", ?, "shop")')
            ->execute([$this->uid, time() - 30]);
        $db->prepare('UPDATE tcg_users SET login_days_last_date = ? WHERE discord_id = ?')->execute([tcgTodayJst(), $this->uid]);
        require_once dirname(__DIR__, 2) . '/tournament_lib.php';
        tcgDbEnsureTournamentSchema($db);
        tcgDbEnsureColumn($db, 'tcg_tournament_entrants', 'elim_reason', "TEXT NOT NULL DEFAULT ''");
        $tid = 'RETRO' . strtoupper(bin2hex(random_bytes(2)));
        $db->prepare('INSERT INTO tcg_tournaments (id, host_discord_id, title, start_at, created_at, updated_at) VALUES (?, ?, "T", ?, ?, ?)')
            ->execute([$tid, $this->uid, time() - 3600, time() - 7200, time() - 3600]);
        $db->prepare('INSERT INTO tcg_tournament_entrants (tournament_id, discord_id, status, deck_snapshot, registered_at) VALUES (?, ?, "eliminated", "{}", ?)')
            ->execute([$tid, $this->uid, time() - 7000]);

        tcgMissionBackfillPeriodHistory($this->uid);
        $this->assertTrue(tcgMissionIsCompleted($this->uid, 'monthly_tournament', $this->month()));
        $this->assertSame(1, tcgMissionLoginDaysThisWeek($this->uid));
    }

    public function testHistoryBackfillIgnoresRegisteredOnlyTournamentEntrantsAndNonShopCosmetics(): void
    {
        $db = tcgDb();
        $db->prepare('INSERT INTO tcg_owned_sleeves (discord_id, sleeve_id, acquired_at, source, equip_intro_seen) VALUES (?, "gift-sleeve", ?, "free_milestone", 1)')
            ->execute([$this->uid, time() - 30]);
        require_once dirname(__DIR__, 2) . '/tournament_lib.php';
        tcgDbEnsureTournamentSchema($db);
        tcgDbEnsureColumn($db, 'tcg_tournament_entrants', 'elim_reason', "TEXT NOT NULL DEFAULT ''");
        $tid = 'RETRX' . strtoupper(bin2hex(random_bytes(2)));
        $db->prepare('INSERT INTO tcg_tournaments (id, host_discord_id, title, start_at, created_at, updated_at) VALUES (?, ?, "T", ?, ?, ?)')
            ->execute([$tid, $this->uid, time() - 3600, time() - 7200, time() - 3600]);
        $db->prepare('INSERT INTO tcg_tournament_entrants (tournament_id, discord_id, status, deck_snapshot, registered_at) VALUES (?, ?, "registered", "{}", ?)')
            ->execute([$tid, $this->uid, time() - 7000]);
        tcgMissionBackfillPeriodHistory($this->uid);
        $this->assertFalse(tcgMissionIsCompleted($this->uid, 'monthly_tournament', $this->month()));
    }
}
