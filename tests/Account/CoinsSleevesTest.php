<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

final class CoinsSleevesTest extends TestCase
{
    private string $discordId;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/coins.php';
        require_once dirname(__DIR__, 2) . '/sleeve_shop.php';
        require_once dirname(__DIR__, 2) . '/missions.php';
        $this->discordId = 'test_coins_' . bin2hex(random_bytes(4));
        tcgEnsureUser($this->discordId, ['username' => 'Coin Tester']);
    }

    private function catalogSleeveId(): string
    {
        $items = tcgLoadSleeveCatalog();
        $this->assertNotEmpty($items, 'sleeves_catalog.json must have items');
        return (string)$items[0]['id'];
    }

    public function testNaturalFinishPvpAwardsWinLoss(): void
    {
        $win = tcgCoinsForFinishedMatch([
            'status' => 'finished',
            'end_reason' => '',
            'winner' => 'p1',
            'players' => [
                'p1' => ['discord_id' => $this->discordId],
                'p2' => ['discord_id' => 'opp'],
            ],
        ], 'p1');
        $this->assertSame(200, $win);

        $loss = tcgCoinsForFinishedMatch([
            'status' => 'finished',
            'end_reason' => '',
            'winner' => 'p2',
            'players' => [
                'p1' => ['discord_id' => $this->discordId],
                'p2' => ['discord_id' => 'opp'],
            ],
        ], 'p1');
        $this->assertSame(100, $loss);
    }

    /** Natural 3-Success wins use end_reason=game (api.php); must still pay coins. */
    public function testNaturalFinishEndReasonGameAwardsCoins(): void
    {
        $win = tcgCoinsForFinishedMatch([
            'status' => 'finished',
            'end_reason' => 'game',
            'winner' => 'p1',
            'players' => [
                'p1' => ['discord_id' => $this->discordId],
                'p2' => ['discord_id' => 'opp'],
            ],
        ], 'p1');
        $this->assertSame(200, $win);

        $room = 'G' . strtoupper(bin2hex(random_bytes(3)));
        $state = [
            'status' => 'finished',
            'end_reason' => 'game',
            'room_id' => $room,
            'winner' => 'p1',
            'players' => [
                'p1' => ['discord_id' => $this->discordId],
                'p2' => ['discord_id' => 'opp_g_' . $this->discordId],
            ],
        ];
        tcgEnsureUser('opp_g_' . $this->discordId, ['username' => 'OppG']);
        $grants = tcgCoinsOnGameFinished($state);
        $this->assertCount(2, $grants);
        $this->assertSame(200, tcgGetCoins($this->discordId));
    }

    public function testResignAndDisconnectAwardZero(): void
    {
        foreach (['resign', 'disconnect'] as $reason) {
            $n = tcgCoinsForFinishedMatch([
                'status' => 'finished',
                'end_reason' => $reason,
                'winner' => 'p1',
                'players' => [
                    'p1' => ['discord_id' => $this->discordId],
                    'p2' => ['discord_id' => 'opp'],
                ],
            ], 'p1');
            $this->assertSame(0, $n, $reason);
        }
    }

    public function testCpuDifficultyTiers(): void
    {
        $base = [
            'status' => 'finished',
            'end_reason' => '',
            'winner' => 'p1',
            'cpu_solo' => true,
            'players' => [
                'p1' => ['discord_id' => $this->discordId],
                'p2' => ['is_cpu' => true],
            ],
        ];
        $this->assertSame(80, tcgCoinsForFinishedMatch(array_merge($base, ['cpu_difficulty' => 'easy']), 'p1'));
        $this->assertSame(100, tcgCoinsForFinishedMatch(array_merge($base, ['cpu_difficulty' => 'normal']), 'p1'));
        $this->assertSame(120, tcgCoinsForFinishedMatch(array_merge($base, ['cpu_difficulty' => 'hard']), 'p1'));
        $this->assertSame(140, tcgCoinsForFinishedMatch(array_merge($base, ['cpu_difficulty' => 'expert']), 'p1'));
        $this->assertSame(70, tcgCoinsForFinishedMatch(array_merge($base, [
            'cpu_difficulty' => 'expert',
            'winner' => 'p2',
        ]), 'p1'));
    }

    public function testCoinGrantIdempotentPerRoom(): void
    {
        $room = 'T' . strtoupper(bin2hex(random_bytes(3)));
        $state = [
            'status' => 'finished',
            'end_reason' => '',
            'room_id' => $room,
            'winner' => 'p1',
            'players' => [
                'p1' => ['discord_id' => $this->discordId],
                'p2' => ['discord_id' => 'opp_' . $this->discordId],
            ],
        ];
        tcgEnsureUser('opp_' . $this->discordId, ['username' => 'Opp']);
        $first = tcgCoinsOnGameFinished($state);
        $this->assertNotEmpty($first);
        $bal = tcgGetCoins($this->discordId);
        $this->assertSame(200, $bal);
        $second = tcgCoinsOnGameFinished($state);
        $this->assertSame([], $second);
        $this->assertSame(200, tcgGetCoins($this->discordId));
    }

    public function testBuyRequiresCoinsAndOwns(): void
    {
        $id = $this->catalogSleeveId();
        $this->assertFalse(tcgOwnsSleeve($this->discordId, $id));
        try {
            tcgDeductCoins($this->discordId, TCG_SLEEVE_SHOP_PRICE);
            $this->fail('expected Not enough Coins');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Coins', $e->getMessage());
        }
        tcgAddCoins($this->discordId, TCG_SLEEVE_SHOP_PRICE);
        tcgDeductCoins($this->discordId, TCG_SLEEVE_SHOP_PRICE);
        tcgGrantOwnedSleeve($this->discordId, $id, 'shop');
        $this->assertTrue(tcgOwnsSleeve($this->discordId, $id));
        $this->assertContains($id, tcgOwnedSleevesNeedingIntro($this->discordId));
        tcgMarkSleeveEquipIntroSeen($this->discordId, $id);
        $this->assertTrue(tcgSleeveEquipIntroSeen($this->discordId, $id));
    }

    /** Tournament deposit opens a tx then calls deduct — must not nest beginTransaction. */
    public function testDeductCoinsInsideOpenTransaction(): void
    {
        tcgAddCoins($this->discordId, 500);
        $db = tcgDb();
        $db->beginTransaction();
        try {
            tcgDeductCoins($this->discordId, 100);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $this->assertSame(400, tcgGetCoins($this->discordId));
    }

    public function testFreeClaimAllowanceOnce(): void
    {
        $id = $this->catalogSleeveId();
        tcgSetFreeSleeveClaims($this->discordId, 1);
        $this->assertSame(1, tcgGetFreeSleeveClaims($this->discordId));
        tcgGrantOwnedSleeve($this->discordId, $id, 'free');
        tcgSetFreeSleeveClaims($this->discordId, 0);
        $this->assertSame(0, tcgGetFreeSleeveClaims($this->discordId));
        $this->assertTrue(tcgOwnsSleeve($this->discordId, $id));
    }

    public function testLoginDaysBootstrapCapsAtTen(): void
    {
        $db = tcgDb();
        $old = time() - (40 * 86400);
        $db->prepare('UPDATE tcg_users SET created_at = ?, login_days = 0, login_days_bootstrapped = 0,
            login_days_last_date = NULL WHERE discord_id = ?')
            ->execute([$old, $this->discordId]);
        $days = tcgTouchLoginDays($this->discordId);
        $this->assertSame(10, $days);
        $again = tcgTouchLoginDays($this->discordId);
        $this->assertSame(10, $again);
    }
}
