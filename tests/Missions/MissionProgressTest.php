<?php

declare(strict_types=1);

namespace LLTCG\Tests\Missions;

use PHPUnit\Framework\TestCase;

final class MissionProgressTest extends TestCase
{
    private string $discordId;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/missions.php';
        $this->discordId = 'test_missions_' . bin2hex(random_bytes(4));
        tcgEnsureUser($this->discordId, ['username' => 'Mission Test']);
    }

    public function testDailyPeriodKeyUsesJstDateFormat(): void
    {
        $def = tcgMissionDefById('daily_ranked_match');
        $this->assertNotNull($def);
        $period = tcgMissionPeriodKey($def);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $period);
        $this->assertSame(tcgTodayJst(), $period);
    }

    public function testRetroactiveRankedMilestonesFromGamesCount(): void
    {
        $db = tcgDb();
        $db->prepare('UPDATE tcg_rank SET games = 10, wins = 5, losses = 5, updated_at = ? WHERE discord_id = ?')
            ->execute([time(), $this->discordId]);

        tcgMissionBackfillRetroactive($this->discordId);

        $period = '';
        $this->assertTrue(tcgMissionIsCompleted($this->discordId, 'ms_ranked_1', $period));
        $this->assertTrue(tcgMissionIsCompleted($this->discordId, 'ms_ranked_5', $period));
        $this->assertTrue(tcgMissionIsCompleted($this->discordId, 'ms_ranked_10', $period));
        $this->assertFalse(tcgMissionIsCompleted($this->discordId, 'ms_ranked_50', $period));
    }

    public function testGroupDeckValidationMainOnlyAndMixedFails(): void
    {
        $cardMap = [
            'MUSE-1' => ['card_type_en' => 'Member', 'group' => "μ's"],
            'MUSE-2' => ['card_type_en' => 'Live', 'group' => "μ's"],
            'MUSE-E' => ['card_type_en' => 'Energy', 'group' => 'Sunshine'],
            'AQ-1' => ['card_type_en' => 'Member', 'group' => 'Sunshine'],
        ];

        $this->assertTrue(tcgDeckMainIsSingleGroup(['MUSE-1', 'MUSE-2', 'MUSE-E'], "μ's", $cardMap));
        $this->assertFalse(tcgDeckMainIsSingleGroup(['MUSE-1', 'AQ-1'], "μ's", $cardMap));
        $this->assertFalse(tcgDeckMainIsSingleGroup([], "μ's", $cardMap));
    }

    public function testGroupWinMilestoneCompletesForSignedInWinner(): void
    {
        $cards = json_decode((string)file_get_contents(CARDS_FILE), true) ?: [];
        $main = $cards['starter_decks']['nijigasaki']['main_deck'] ?? [];
        $this->assertNotEmpty($main);

        $state = [
            'status' => 'finished',
            'mode' => 'unranked',
            'winner' => 'p1',
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'Human',
                    'discord_id' => $this->discordId,
                    'deck_snapshot' => ['main_nos' => $main, 'energy_nos' => []],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'CPU (Easy)',
                    'deck_choice' => 'cpu:easy',
                ],
            ],
        ];

        $completions = tcgMissionOnGameFinished($state);
        $ids = array_column($completions, 'id');
        $this->assertContains('ms_win_nijigasaki', $ids);
        $this->assertTrue(tcgMissionIsCompleted($this->discordId, 'ms_win_nijigasaki', ''));
    }

    public function testResignToCpuDoesNotGrantCpuGroupWinMission(): void
    {
        $cards = json_decode((string)file_get_contents(CARDS_FILE), true) ?: [];
        $liella = $cards['starter_decks']['liella']['main_deck']
            ?? $cards['starter_decks']['superstar']['main_deck']
            ?? [];
        $hasu = $cards['starter_decks']['hasunosora']['main_deck'] ?? [];
        $this->assertNotEmpty($liella);
        $this->assertNotEmpty($hasu);

        // Legacy bug: solo join copied human discord_id onto the CPU seat.
        $state = [
            'status' => 'finished',
            'mode' => 'unranked',
            'winner' => 'p2',
            'end_reason' => 'resign',
            'resigned_by' => 'p1',
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'Human',
                    'discord_id' => $this->discordId,
                    'deck_choice' => 'liella',
                    'deck_snapshot' => ['main_nos' => $liella, 'energy_nos' => []],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'CPU (Easy)',
                    'discord_id' => $this->discordId,
                    'deck_choice' => 'cpu:easy',
                    'deck_snapshot' => ['main_nos' => $hasu, 'energy_nos' => []],
                ],
            ],
        ];

        $completions = tcgMissionOnGameFinished($state);
        $ids = array_column($completions, 'id');
        $this->assertNotContains('ms_win_hasunosora', $ids);
        $this->assertNotContains('ms_win_liella', $ids);
        $this->assertFalse(tcgMissionIsCompleted($this->discordId, 'ms_win_hasunosora', ''));
        $this->assertFalse(tcgMissionIsCompleted($this->discordId, 'ms_win_liella', ''));
    }

    public function testRetractClaimedGroupWinClawsBackGemsFloorZero(): void
    {
        tcgMissionMarkCompleted($this->discordId, 'ms_win_hasunosora');
        tcgMissionClaim($this->discordId, 'ms_win_hasunosora');
        $this->assertSame(1000, tcgGetStarGems($this->discordId));

        // Spend most gems so clawback must floor at 0.
        tcgSoftClawbackStarGems($this->discordId, 900);
        $this->assertSame(100, tcgGetStarGems($this->discordId));

        $result = tcgMissionRetract($this->discordId, 'ms_win_hasunosora');
        $this->assertTrue($result['retracted']);
        $this->assertTrue($result['was_claimed']);
        $this->assertSame(100, $result['gems_clawed']);
        $this->assertSame(0, tcgGetStarGems($this->discordId));
        $this->assertFalse(tcgMissionIsCompleted($this->discordId, 'ms_win_hasunosora', ''));
    }

    public function testClawbackFalseCpuGroupWinsKeepsLegitWins(): void
    {
        $cards = json_decode((string)file_get_contents(CARDS_FILE), true) ?: [];
        $cardMap = tcgBuildCardMap($cards);
        $hasu = $cards['starter_decks']['hasunosora']['main_deck'] ?? [];
        $niji = $cards['starter_decks']['nijigasaki']['main_deck'] ?? [];
        $this->assertNotEmpty($hasu);
        $this->assertNotEmpty($niji);

        // Illegit Hasu completion (as if old CPU-win path granted it).
        tcgMissionMarkCompleted($this->discordId, 'ms_win_hasunosora');
        tcgMissionClaim($this->discordId, 'ms_win_hasunosora');
        // Legit Niji completion.
        tcgMissionMarkCompleted($this->discordId, 'ms_win_nijigasaki');

        $falseState = [
            'status' => 'finished',
            'winner' => 'p2',
            'players' => [
                'p1' => [
                    'discord_id' => $this->discordId,
                    'deck_choice' => 'liella',
                    'deck_snapshot' => ['main_nos' => $niji, 'energy_nos' => []],
                ],
                'p2' => [
                    'name' => 'CPU (Easy)',
                    'discord_id' => $this->discordId,
                    'deck_choice' => 'cpu:easy',
                    'deck_snapshot' => ['main_nos' => $hasu, 'energy_nos' => []],
                ],
            ],
        ];
        $legitState = [
            'status' => 'finished',
            'winner' => 'p1',
            'players' => [
                'p1' => [
                    'discord_id' => $this->discordId,
                    'deck_choice' => 'nijigasaki',
                    'deck_snapshot' => ['main_nos' => $niji, 'energy_nos' => []],
                ],
                'p2' => [
                    'name' => 'CPU (Easy)',
                    'deck_choice' => 'cpu:easy',
                    'deck_snapshot' => ['main_nos' => $hasu, 'energy_nos' => []],
                ],
            ],
        ];

        $falseEv = tcgMissionGroupWinEvidenceFromState($falseState, $this->discordId, $cardMap);
        $legitEv = tcgMissionGroupWinEvidenceFromState($legitState, $this->discordId, $cardMap);
        $this->assertSame('false_cpu', $falseEv['ms_win_hasunosora'] ?? null);
        $this->assertSame('legit', $legitEv['ms_win_nijigasaki'] ?? null);

        // Persist evidence via a fake replay row for the false Hasu win only.
        $payload = json_encode([
            'schema_version' => 1,
            'meta' => ['saver_player_id' => 'p1'],
            'baseline' => $falseState,
            'actions' => [['index' => 1]],
        ], JSON_UNESCAPED_SLASHES);
        tcgDb()->prepare('INSERT INTO tcg_replays (
                discord_id, room_id, saver_player_id, saver_name, opponent_name, winner, end_reason,
                turn, phase, action_count, duration_seconds, payload_json, saved_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $this->discordId, 'TESTROOM', 'p1', 'Human', 'CPU', 'p2', 'resign',
                1, 'finished', 1, 1, $payload, time(),
            ]);

        // Legit niji evidence via second replay where human won.
        $payload2 = json_encode([
            'schema_version' => 1,
            'meta' => ['saver_player_id' => 'p1'],
            'baseline' => $legitState,
            'actions' => [['index' => 1]],
        ], JSON_UNESCAPED_SLASHES);
        tcgDb()->prepare('INSERT INTO tcg_replays (
                discord_id, room_id, saver_player_id, saver_name, opponent_name, winner, end_reason,
                turn, phase, action_count, duration_seconds, payload_json, saved_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $this->discordId, 'TESTROOM2', 'p1', 'Human', 'CPU', 'p1', null,
                1, 'finished', 1, 1, $payload2, time(),
            ]);

        $results = tcgMissionClawbackFalseCpuGroupWins();
        $mine = array_values(array_filter(
            $results,
            fn(array $r): bool => ($r['discord_id'] ?? '') === $this->discordId
        ));
        $ids = array_column($mine, 'mission_id');
        $this->assertContains('ms_win_hasunosora', $ids);
        $this->assertNotContains('ms_win_nijigasaki', $ids);
        $this->assertFalse(tcgMissionIsCompleted($this->discordId, 'ms_win_hasunosora', ''));
        $this->assertTrue(tcgMissionIsCompleted($this->discordId, 'ms_win_nijigasaki', ''));
        $this->assertSame(0, tcgGetStarGems($this->discordId));
    }

    public function testClawbackRetractsGroupWinWithoutAnyEvidence(): void
    {
        tcgMissionMarkCompleted($this->discordId, 'ms_win_muse');
        tcgMissionClaim($this->discordId, 'ms_win_muse');
        $this->assertSame(1000, tcgGetStarGems($this->discordId));

        $results = tcgMissionClawbackFalseCpuGroupWins();
        $mine = array_values(array_filter(
            $results,
            fn(array $r): bool => ($r['discord_id'] ?? '') === $this->discordId
        ));
        $ids = array_column($mine, 'mission_id');
        $this->assertContains('ms_win_muse', $ids);
        $this->assertFalse(tcgMissionIsCompleted($this->discordId, 'ms_win_muse', ''));
        $this->assertSame(0, tcgGetStarGems($this->discordId));
    }

    public function testGroupWinMilestoneSkippedWithoutDiscordId(): void
    {
        $cards = json_decode((string)file_get_contents(CARDS_FILE), true) ?: [];
        $main = $cards['starter_decks']['nijigasaki']['main_deck'] ?? [];
        $this->assertNotEmpty($main);

        $state = [
            'status' => 'finished',
            'mode' => 'unranked',
            'winner' => 'p1',
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'Guest',
                    'deck_snapshot' => ['main_nos' => $main, 'energy_nos' => []],
                ],
            ],
        ];

        $completions = tcgMissionOnGameFinished($state);
        $this->assertSame([], $completions);
        $this->assertFalse(tcgMissionIsCompleted($this->discordId, 'ms_win_nijigasaki', ''));
    }

    public function testClaimGrantsGemsOnceAndRejectsDoubleClaim(): void
    {
        $before = tcgGetStarGems($this->discordId);
        tcgMissionMarkCompleted($this->discordId, 'ms_profile_banner');
        $result = tcgMissionClaim($this->discordId, 'ms_profile_banner');
        $this->assertSame(100, $result['star_gems_gained']);
        $this->assertSame($before + 100, $result['star_gems']);
        $this->assertSame($before + 100, tcgGetStarGems($this->discordId));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Mission reward already claimed');
        tcgMissionClaim($this->discordId, 'ms_profile_banner');
    }

    public function testDailyCompleteAllActiveUntilSubDailiesDone(): void
    {
        $def = tcgMissionDefById('daily_complete_all');
        $this->assertNotNull($def);
        $this->assertSame('active', tcgMissionStatusForDef($this->discordId, $def));

        $period = tcgTodayJst();
        tcgMissionMarkCompleted($this->discordId, 'daily_open_all_boosters', $period);
        tcgMissionMarkCompleted($this->discordId, 'daily_ranked_match', $period);
        tcgMissionMarkCompleted($this->discordId, 'daily_use_stamp', $period);

        $this->assertSame('active', tcgMissionStatusForDef($this->discordId, $def));
        $done = tcgMissionTryCompleteAllDaily($this->discordId);
        $this->assertNotEmpty($done);
        $this->assertSame('completed', tcgMissionStatusForDef($this->discordId, $def));
    }

    public function testLaunchDayRetroactiveDailyBoostersWhenAlreadyExhausted(): void
    {
        require_once dirname(__DIR__, 2) . '/booster.php';
        $today = TCG_MISSION_DAILY_BOOSTERS_BACKFILL_JST;
        $allow = tcgDailyOpenAllowance($this->discordId);
        $limit = intval($allow['limit'] ?? TCG_DAILY_PACK_LIMIT);

        $db = tcgDb();
        $db->prepare('UPDATE tcg_daily_state SET last_open_date = ?, packs_opened_today = ? WHERE discord_id = ?')
            ->execute([$today, $limit, $this->discordId]);

        tcgMissionBackfillDailyBoostersLaunchDay($this->discordId, $today);

        $this->assertTrue(tcgMissionIsCompleted($this->discordId, 'daily_open_all_boosters', $today));

        $otherDay = $today === '2026-07-08' ? '2026-07-09' : '2026-07-08';
        $uid2 = $this->discordId . '_no_backfill';
        tcgEnsureUser($uid2, ['username' => 'No backfill']);
        $db->prepare('UPDATE tcg_daily_state SET last_open_date = ?, packs_opened_today = ? WHERE discord_id = ?')
            ->execute([$otherDay, $limit, $uid2]);
        tcgMissionBackfillDailyBoostersLaunchDay($uid2, $otherDay);
        $this->assertFalse(tcgMissionIsCompleted($uid2, 'daily_open_all_boosters', $otherDay));
    }

    public function testStampMissionCompletesWhenDiscordIdPresent(): void
    {
        $before = tcgMissionIsCompleted($this->discordId, 'daily_use_stamp', tcgTodayJst());
        $this->assertFalse($before);

        $done = tcgMissionOnStampSent($this->discordId);
        $this->assertNotEmpty($done);
        $this->assertSame('daily_use_stamp', $done[0]['id'] ?? null);
        $this->assertTrue(tcgMissionIsCompleted($this->discordId, 'daily_use_stamp', tcgTodayJst()));
    }

    public function testCollectionCardMilestonesCompleteAndGrantStarter(): void
    {
        require_once dirname(__DIR__, 2) . '/booster.php';
        if (!defined('TCG_MAX_DECK_PRESETS')) {
            define('TCG_MAX_DECK_PRESETS', 10);
        }
        if (!defined('CARDS_FILE')) {
            define('CARDS_FILE', dirname(__DIR__, 2) . '/cards.json');
        }
        $cards = tcgReadCardsDataFile();
        $this->assertNotEmpty($cards['starter_decks']['liella'] ?? null);
        $this->assertNotEmpty($cards['starter_decks']['muse'] ?? null);

        tcgGrantStarterDeck($this->discordId, 'liella', $cards);
        $this->assertSame(['liella'], tcgOwnedStarterKeys($this->discordId));

        // Inflate collection qty without needing 400 unique cards.
        $db = tcgDb();
        $db->prepare('INSERT INTO tcg_collection (discord_id, card_no, qty, acquired_at) VALUES (?, ?, ?, ?)
            ON CONFLICT(discord_id, card_no) DO UPDATE SET qty = excluded.qty')
            ->execute([$this->discordId, 'TEST-FILL-CARD', 400, time()]);

        $completions = tcgMissionCheckCollectionThresholds($this->discordId);
        $ids = array_column($completions, 'id');
        $this->assertContains('ms_cards_400', $ids);
        $this->assertTrue(tcgMissionIsCompleted($this->discordId, 'ms_cards_400', ''));
        $this->assertFalse(tcgMissionIsCompleted($this->discordId, 'ms_cards_800', ''));

        try {
            tcgMissionClaim($this->discordId, 'ms_cards_400', null);
            $this->fail('Expected claim without starter to throw');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Choose a starter deck', $e->getMessage());
        }

        $result = tcgMissionClaim($this->discordId, 'ms_cards_400', 'muse');
        $this->assertSame('muse', $result['starter_granted']['starter_deck'] ?? null);
        $this->assertContains('muse', tcgOwnedStarterKeys($this->discordId));
        $this->assertContains('liella', tcgOwnedStarterKeys($this->discordId));
        $this->assertTrue(tcgMissionIsClaimed($this->discordId, 'ms_cards_400', ''));

        // Primary starter stays the initial pick.
        $stmt = $db->prepare('SELECT starter_deck FROM tcg_users WHERE discord_id = ?');
        $stmt->execute([$this->discordId]);
        $this->assertSame('liella', $stmt->fetchColumn());
    }
}
