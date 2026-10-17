<?php

declare(strict_types=1);

namespace LLTCG\Tests\Ranked;

use PHPUnit\Framework\TestCase;

final class RankedPrRewardTest extends TestCase
{
    private string $discordId;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/ranked_pr_rewards.php';
        $this->discordId = 'test_ranked_pr_' . bin2hex(random_bytes(4));
        tcgEnsureUser($this->discordId, ['username' => 'Ranked PR Test']);
    }

    public function testSavedSealsPreferenceSurvivesADatabaseThatSkippedBootstrap(): void
    {
        $db = tcgDb();
        $db->exec('ALTER TABLE tcg_users DROP COLUMN dupe_reward');
        $db->exec("DELETE FROM tcg_schema_meta WHERE key = 'dupe_reward_column_20261017'");
        tcgDbRunMigrationOnce($db, 'dupe_reward_column_20261017', static function ($pdo): void {
            tcgDbMigrateDupeRewardColumn($pdo);
        });

        tcgSetDupeRewardPref($this->discordId, 'seals');
        $this->assertSame('seals', tcgGetDupeRewardPref($this->discordId));

        $data = json_decode((string) file_get_contents(CARDS_FILE), true) ?: [];
        $map = tcgBuildCardMap($data);
        $pools = tcgBuildBoxPools($data, tcgPrCardPoolBox());
        $counts = [];
        foreach ($pools['PR'] ?? [] as $entry) {
            $no = is_array($entry) ? (string) ($entry['card_no'] ?? '') : (string) $entry;
            if ($no !== '') {
                $counts[$no] = tcgGetDeckMaxCopies($map[$no] ?? null, $no);
            }
        }
        $this->assertNotEmpty($counts);
        tcgUpsertCollectionCounts($this->discordId, $counts);

        $pack = tcgGrantPrPackCards($this->discordId, 2);
        $this->assertSame('seals', $pack['dupe_reward']);
        $this->assertSame(0, $pack['star_gems_earned']);
        foreach ($pack['cards'] as $card) {
            $this->assertSame('PR', $card['seal_tier']);
            $this->assertSame(10, $card['seals']);
        }
    }

    public function testPrCardsNotInBoosterBoxes(): void
    {
        $ids = array_column(tcgBoosterBoxes(), 'id');
        $this->assertNotContains('pr_cards', $ids);
        $this->assertSame('pr_cards', tcgPrCardPoolBox()['id']);
    }

    public function testGrantAddsCardToCollection(): void
    {
        $before = tcgGetCollectionMap($this->discordId);
        $beforeTotal = array_sum($before);

        $reward = tcgGrantRankedWinPrReward($this->discordId);
        $this->assertArrayNotHasKey('skipped', $reward);
        $this->assertSame(TCG_RANKED_PR_PACK_SIZE, $reward['pack_size'] ?? 0);
        $this->assertCount(TCG_RANKED_PR_PACK_SIZE, $reward['cards'] ?? []);
        $this->assertNotEmpty($reward['card_no']);

        $after = tcgGetCollectionMap($this->discordId);
        $newCards = count(array_filter($reward['cards'] ?? [], static fn($c) => empty($c['converted'])));
        $converted = count(array_filter($reward['cards'] ?? [], static fn($c) => !empty($c['converted'])));
        if ($converted === TCG_RANKED_PR_PACK_SIZE) {
            $this->assertSame($beforeTotal, array_sum($after));
        } else {
            $this->assertGreaterThanOrEqual($beforeTotal + $newCards, array_sum($after));
        }
    }

    public function testDuplicateAtMaxCopiesGrantsGems(): void
    {
        $cardsData = json_decode((string)file_get_contents(CARDS_FILE), true) ?: [];
        $pools = tcgBuildBoxPools($cardsData, tcgPrCardPoolBox());
        $cardNo = ($pools['PR'][0] ?? $pools['PR+'][0] ?? null);
        $this->assertNotNull($cardNo);

        $cardMap = tcgBuildCardMap($cardsData);
        $max = tcgGetDeckMaxCopies($cardMap[$cardNo] ?? null, $cardNo);
        tcgUpsertCollectionCounts($this->discordId, [$cardNo => $max]);

        $beforeGems = tcgGetStarGems($this->discordId);
        $gemResult = tcgApplyBoosterPullWithGems($this->discordId, [$cardNo], $cardMap);

        $this->assertTrue($gemResult['pulls'][0]['converted'] ?? false);
        $this->assertGreaterThan(0, $gemResult['star_gems_earned']);
        $this->assertSame($beforeGems + $gemResult['star_gems_earned'], tcgGetStarGems($this->discordId));
    }

    public function testDailyCapBlocksSixthReward(): void
    {
        for ($i = 0; $i < TCG_RANKED_PR_DAILY_LIMIT; $i++) {
            $reward = tcgGrantRankedWinPrReward($this->discordId);
            $this->assertArrayNotHasKey('skipped', $reward, "grant $i should succeed");
        }

        $sixth = tcgGrantRankedWinPrReward($this->discordId);
        $this->assertTrue($sixth['skipped'] ?? false);
        $this->assertSame('daily_cap', $sixth['reason'] ?? '');
        $this->assertSame(0, $sixth['daily']['remaining'] ?? -1);
    }

    public function testApplyOnFinishOnlyAwardsWinner(): void
    {
        $winnerId = $this->discordId;
        $loserId = 'test_ranked_pr_loser_' . bin2hex(random_bytes(4));
        tcgEnsureUser($loserId, ['username' => 'Loser']);

        $state = [
            'mode' => 'ranked',
            'status' => 'finished',
            'winner' => 'p1',
            'players' => [
                'p1' => ['discord_id' => $winnerId, 'name' => 'Winner'],
                'p2' => ['discord_id' => $loserId, 'name' => 'Loser'],
            ],
            'ranked' => [
                'p1_discord_id' => $winnerId,
                'p2_discord_id' => $loserId,
                'applied' => true,
            ],
        ];

        tcgApplyRankedPrRewardOnFinish($state);

        $this->assertTrue($state['ranked']['pr_reward_applied'] ?? false);
        $this->assertSame('p1', $state['ranked']['pr_reward']['player_id'] ?? null);
        $this->assertNotNull(tcgRankedPrRewardForPlayer($state, 'p1'));
        $this->assertNull(tcgRankedPrRewardForPlayer($state, 'p2'));

        $winnerAllow = tcgRankedPrDailyAllowance($winnerId);
        $loserAllow = tcgRankedPrDailyAllowance($loserId);
        $this->assertSame(1, $winnerAllow['awarded_today']);
        $this->assertSame(TCG_RANKED_PR_DAILY_LIMIT - 1, $winnerAllow['remaining']);
        $this->assertSame(0, $loserAllow['awarded_today'], 'loser must not consume daily ranked PR');
        $this->assertSame(TCG_RANKED_PR_DAILY_LIMIT, $loserAllow['remaining']);
    }

    public function testFilterStateHidesWinnerPrRewardFromLoser(): void
    {
        require_once dirname(__DIR__, 2) . '/api.php';

        $winnerId = $this->discordId;
        $loserId = 'test_ranked_pr_loser_filter_' . bin2hex(random_bytes(4));
        tcgEnsureUser($loserId, ['username' => 'LoserFilter']);

        $winnerToken = 'winner-token-' . bin2hex(random_bytes(4));
        $loserToken = 'loser-token-' . bin2hex(random_bytes(4));
        $state = [
            'mode' => 'ranked',
            'status' => 'finished',
            'winner' => 'p1',
            'seq' => 1,
            'phase' => 'main_first',
            'turn' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'discord_id' => $winnerId,
                    'name' => 'Winner',
                    'token' => $winnerToken,
                    'hand' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'waiting_room' => [],
                    'stage' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'discord_id' => $loserId,
                    'name' => 'Loser',
                    'token' => $loserToken,
                    'hand' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'waiting_room' => [],
                    'stage' => [],
                    'success_lives' => [],
                ],
            ],
            'ranked' => [
                'p1_discord_id' => $winnerId,
                'p2_discord_id' => $loserId,
                'applied' => true,
                'pr_reward_applied' => true,
                'pr_reward' => [
                    'player_id' => 'p1',
                    'reward' => [
                        'card_no' => 'TEST-PR',
                        'daily' => ['remaining' => 4, 'limit' => 5, 'awarded_today' => 1],
                    ],
                ],
            ],
            // Simulate a leaked top-level copy that must not reach the loser.
            'ranked_pr_reward' => [
                'card_no' => 'TEST-PR',
                'daily' => ['remaining' => 4, 'limit' => 5, 'awarded_today' => 1],
            ],
        ];

        $forLoser = filterStateForPlayer($state, $loserToken);
        $this->assertArrayNotHasKey('ranked_pr_reward', $forLoser);
        $this->assertArrayNotHasKey('pr_reward', $forLoser['ranked'] ?? []);

        $forWinner = filterStateForPlayer($state, $winnerToken);
        $this->assertNotNull($forWinner['ranked_pr_reward'] ?? null);
        $this->assertSame('p1', $forWinner['ranked']['pr_reward']['player_id'] ?? null);
    }

    public function testWebhookFakeStateMustBeFinishedToGrantPr(): void
    {
        require_once dirname(__DIR__, 2) . '/matchmaking.php';

        $winnerId = $this->discordId;
        $loserId = 'test_ranked_pr_webhook_' . bin2hex(random_bytes(4));
        tcgEnsureUser($loserId, ['username' => 'WebhookLoser']);
        $roomId = 'T' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        $db = tcgDb();
        $db->prepare('INSERT INTO tcg_ranked_matches
            (match_id, room_id, p1_id, p2_id, p1_token, p2_token, status, created_at, game_mode, pr_rewarded)
            VALUES (?, ?, ?, ?, ?, ?, "pending", ?, ?, 0)')
            ->execute([
                'M' . $roomId,
                $roomId,
                $winnerId,
                $loserId,
                'tok1',
                'tok2',
                time(),
                'standard',
            ]);

        $before = tcgRankedPrDailyAllowance($winnerId);
        $out = tcgApplyRankedResultFromWebhook([
            'room_id' => $roomId,
            'winner' => 'p1',
            'p1_discord_id' => $winnerId,
            'p2_discord_id' => $loserId,
            'game_mode' => 'standard',
        ]);

        $this->assertTrue($out['success'] ?? false);
        $this->assertTrue($out['pr_reward_applied'] ?? false);
        $this->assertArrayHasKey('pr_reward', $out);
        $this->assertSame('p1', $out['pr_reward']['player_id'] ?? null);
        $reward = $out['pr_reward']['reward'] ?? [];
        $this->assertArrayNotHasKey('skipped', $reward);
        $this->assertSame($before['awarded_today'] + 1, tcgRankedPrDailyAllowance($winnerId)['awarded_today']);

        // Hostinger webhook must mark daily ranked mission (VPS replica writes are invisible).
        require_once dirname(__DIR__, 2) . '/missions.php';
        $this->assertNotEmpty($out['mission_completions'] ?? []);
        $missionIds = array_column($out['mission_completions'], 'id');
        $this->assertContains('daily_ranked_match', $missionIds);
        $def = tcgMissionDefById('daily_ranked_match');
        $period = tcgMissionPeriodKey($def);
        $this->assertTrue(tcgMissionIsCompleted($winnerId, 'daily_ranked_match', $period));

        // Idempotent: second call must not grant another pack.
        $out2 = tcgApplyRankedResultFromWebhook([
            'room_id' => $roomId,
            'winner' => 'p1',
            'p1_discord_id' => $winnerId,
            'p2_discord_id' => $loserId,
            'game_mode' => 'standard',
        ]);
        $this->assertTrue($out2['already_applied'] ?? false);
        $this->assertSame($before['awarded_today'] + 1, tcgRankedPrDailyAllowance($winnerId)['awarded_today']);
    }

    public function testWebhookGroupWinMissionNeedsDeckSnapshot(): void
    {
        require_once dirname(__DIR__, 2) . '/matchmaking.php';
        require_once dirname(__DIR__, 2) . '/missions.php';
        require_once dirname(__DIR__, 2) . '/deck_validate.php';

        $winnerId = $this->discordId;
        $loserId = 'test_ranked_group_' . bin2hex(random_bytes(4));
        tcgEnsureUser($loserId, ['username' => 'GroupLoser']);
        $roomId = 'G' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        $cards = json_decode((string)file_get_contents(CARDS_FILE), true) ?: [];
        $niji = $cards['starter_decks']['nijigasaki']['main_deck'] ?? [];
        $this->assertNotEmpty($niji);

        $db = tcgDb();
        $db->prepare('INSERT INTO tcg_ranked_matches
            (match_id, room_id, p1_id, p2_id, p1_token, p2_token, status, created_at, game_mode, pr_rewarded)
            VALUES (?, ?, ?, ?, ?, ?, "pending", ?, ?, 0)')
            ->execute([
                'M' . $roomId,
                $roomId,
                $winnerId,
                $loserId,
                'tok1',
                'tok2',
                time(),
                'standard',
            ]);

        // Without deck snapshot: group win must NOT complete (regression of overflow bug).
        $outNoDeck = tcgApplyRankedResultFromWebhook([
            'room_id' => $roomId,
            'winner' => 'p1',
            'p1_discord_id' => $winnerId,
            'p2_discord_id' => $loserId,
            'game_mode' => 'standard',
        ]);
        $idsNoDeck = array_column($outNoDeck['mission_completions'] ?? [], 'id');
        $this->assertNotContains('ms_win_nijigasaki', $idsNoDeck);
        $this->assertFalse(tcgMissionIsCompleted($winnerId, 'ms_win_nijigasaki', ''));

        // With deck snapshot on a second room: group win completes.
        $roomId2 = 'G' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $db->prepare('INSERT INTO tcg_ranked_matches
            (match_id, room_id, p1_id, p2_id, p1_token, p2_token, status, created_at, game_mode, pr_rewarded)
            VALUES (?, ?, ?, ?, ?, ?, "pending", ?, ?, 0)')
            ->execute([
                'M' . $roomId2,
                $roomId2,
                $winnerId,
                $loserId,
                'tok1',
                'tok2',
                time(),
                'standard',
            ]);

        $out = tcgApplyRankedResultFromWebhook([
            'room_id' => $roomId2,
            'winner' => 'p1',
            'p1_discord_id' => $winnerId,
            'p2_discord_id' => $loserId,
            'game_mode' => 'standard',
            'p1_deck_snapshot' => ['main_nos' => $niji, 'energy_nos' => []],
            'p2_deck_snapshot' => ['main_nos' => $niji, 'energy_nos' => []],
        ]);
        $ids = array_column($out['mission_completions'] ?? [], 'id');
        $this->assertContains('ms_win_nijigasaki', $ids);
        $this->assertTrue(tcgMissionIsCompleted($winnerId, 'ms_win_nijigasaki', ''));
    }

    public function testWebhookRetroGrantsPrWhenEloAlreadyApplied(): void
    {
        require_once dirname(__DIR__, 2) . '/matchmaking.php';

        $winnerId = $this->discordId;
        $loserId = 'test_ranked_pr_retro_' . bin2hex(random_bytes(4));
        tcgEnsureUser($loserId, ['username' => 'RetroLoser']);
        $roomId = 'R' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        $db = tcgDb();
        $db->prepare('INSERT INTO tcg_ranked_matches
            (match_id, room_id, p1_id, p2_id, p1_token, p2_token, status, created_at, game_mode, winner_pid, pr_rewarded)
            VALUES (?, ?, ?, ?, ?, ?, "done", ?, ?, ?, 0)')
            ->execute([
                'M' . $roomId,
                $roomId,
                $winnerId,
                $loserId,
                'tok1',
                'tok2',
                time(),
                'standard',
                'p1',
            ]);

        $before = tcgRankedPrDailyAllowance($winnerId);
        $out = tcgApplyRankedResultFromWebhook([
            'room_id' => $roomId,
            'winner' => 'p1',
            'p1_discord_id' => $winnerId,
            'p2_discord_id' => $loserId,
            'game_mode' => 'standard',
        ]);

        $this->assertTrue($out['already_applied'] ?? false);
        $this->assertArrayHasKey('pr_reward', $out);
        $this->assertTrue($out['pr_reward_applied'] ?? false);
        $this->assertSame($before['awarded_today'] + 1, tcgRankedPrDailyAllowance($winnerId)['awarded_today']);
    }

    public function testRetryableSkipDoesNotPersistApplied(): void
    {
        $state = [
            'mode' => 'ranked',
            'status' => 'finished',
            'winner' => 'p2',
            'ranked' => ['applied' => true],
        ];
        tcgStoreRankedPrRewardOnState($state, 'p2', [
            'skipped' => true,
            'reason' => 'empty_pool',
        ]);
        $this->assertFalse($state['ranked']['pr_reward_applied'] ?? true);
        $this->assertTrue(tcgRankedPrRewardNeedsHostingerRetry($state));

        tcgStoreRankedPrRewardOnState($state, 'p2', [
            'skipped' => true,
            'reason' => 'grant_failed',
        ]);
        $this->assertFalse($state['ranked']['pr_reward_applied'] ?? true);
        $this->assertTrue(tcgRankedPrRewardNeedsHostingerRetry($state));
    }

    public function testDailyCapSkipPersistsAppliedAndDoesNotRetry(): void
    {
        $state = [
            'mode' => 'ranked',
            'status' => 'finished',
            'winner' => 'p2',
            'ranked' => ['applied' => true],
        ];
        tcgStoreRankedPrRewardOnState($state, 'p1', [
            'skipped' => true,
            'reason' => 'daily_cap',
        ]);
        $this->assertTrue($state['ranked']['pr_reward_applied'] ?? false);
        $this->assertFalse(tcgRankedPrRewardNeedsHostingerRetry($state));
    }

    public function testStuckEmptyPoolFlagStillRetries(): void
    {
        $state = [
            'mode' => 'ranked',
            'status' => 'finished',
            'winner' => 'p2',
            'ranked' => [
                'applied' => true,
                'pr_reward_applied' => true,
                'pr_reward' => [
                    'player_id' => 'p2',
                    'reward' => ['skipped' => true, 'reason' => 'empty_pool'],
                ],
            ],
        ];
        $this->assertTrue(tcgRankedPrRewardNeedsHostingerRetry($state));

        $state['ranked']['pr_reward']['reward'] = [
            'pack_size' => 3,
            'cards' => [['card_no' => 'PL!N-PR-001-PR']],
            'card_no' => 'PL!N-PR-001-PR',
        ];
        $this->assertFalse(tcgRankedPrRewardNeedsHostingerRetry($state));
    }

    public function testDrawDoesNotRequestPrRetry(): void
    {
        $state = [
            'mode' => 'ranked',
            'status' => 'finished',
            'winner' => null,
            'ranked' => ['applied' => true],
        ];
        $this->assertFalse(tcgRankedPrRewardNeedsHostingerRetry($state));
    }
}
