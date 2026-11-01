<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

/** Gift PR packs must return rolled cards so the hub can play the pack reveal. */
final class GiftPrPackClaimTest extends TestCase
{
    private string $uid;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/gifts.php';
        require_once dirname(__DIR__, 2) . '/ranked_pr_rewards.php';
        $this->uid = 'test_gift_pr_' . bin2hex(random_bytes(4));
        tcgEnsureUser($this->uid, ['username' => 'Gift PR Tester']);
        tcgGiftsEnsureSchema();
    }

    public function testGrantPrPackReturnsRevealPayload(): void
    {
        $pack = tcgGiftGrantReward($this->uid, ['type' => 'pr_pack', 'amount' => 3]);
        $this->assertIsArray($pack);
        $this->assertCount(3, $pack['cards']);
        $this->assertNotSame('', (string)($pack['cards'][0]['card_no'] ?? ''));
        $this->assertNull(tcgGiftGrantReward($this->uid, ['type' => 'coins', 'amount' => 50]));
    }

    public function testSeasonStyleGiftRewardsIncludePrPackCards(): void
    {
        $giftId = 'test-prpack-' . bin2hex(random_bytes(3));
        $this->assertTrue(tcgGiftsOfferToUser(
            $this->uid,
            $giftId,
            'Season rewards',
            'Claim your end-of-season rewards.',
            [
                ['type' => 'coins', 'amount' => 2500],
                ['type' => 'star_gems', 'amount' => 800],
                ['type' => 'pr_pack', 'amount' => 2],
            ],
        ));
        $gift = null;
        foreach (tcgGiftsForUser($this->uid) as $g) {
            if (($g['id'] ?? '') === $giftId) {
                $gift = $g;
                break;
            }
        }
        $this->assertNotNull($gift);
        $prPacks = [];
        foreach ($gift['rewards'] as $rw) {
            if (!is_array($rw)) {
                continue;
            }
            $granted = tcgGiftGrantReward($this->uid, $rw);
            if (is_array($granted) && !empty($granted['cards'])) {
                $prPacks[] = $granted;
            }
        }
        $this->assertCount(1, $prPacks);
        $this->assertCount(2, $prPacks[0]['cards']);
    }
}
