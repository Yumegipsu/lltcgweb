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
        $this->assertNotEmpty($reward['card_no']);

        $after = tcgGetCollectionMap($this->discordId);
        if (!empty($reward['converted'])) {
            $this->assertSame($beforeTotal, array_sum($after));
        } else {
            $this->assertGreaterThan($beforeTotal, array_sum($after));
            $this->assertSame(1, $after[$reward['card_no']] ?? 0);
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
}
