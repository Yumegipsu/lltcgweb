<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

/** Gifts menu + μ's Premium 10-Pull banner (ticket-gated, μ's Members only, boosted Honoka, SR+ Honoka finale). */
final class MusePremiumGiftTest extends TestCase
{
    private string $uid;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/db.php';
        require_once dirname(__DIR__, 2) . '/gacha.php';
        require_once dirname(__DIR__, 2) . '/gifts.php';
        $this->uid = 'test_muse_' . bin2hex(random_bytes(4));
        tcgEnsureUser($this->uid, ['username' => 'Muse Tester']);
        tcgGiftsEnsureSchema();
        tcgGiftsSeedDefaults();
    }

    public function testMuseTicketAddAndDeduct(): void
    {
        $this->assertSame(0, tcgGetMusePremiumTickets($this->uid));
        tcgAddMusePremiumTickets($this->uid, 2);
        $this->assertSame(2, tcgGetMusePremiumTickets($this->uid));
        tcgDeductMusePremiumTickets($this->uid, TCG_GACHA_MUSE_TICKET_COST);
        $this->assertSame(1, tcgGetMusePremiumTickets($this->uid));
        $this->expectException(\Exception::class);
        tcgDeductMusePremiumTickets($this->uid, 5);
    }

    public function testBuiltInGiftIsSeededOnceForEveryone(): void
    {
        tcgGiftsSeedDefaults(); // idempotent
        $gifts = array_values(array_filter(
            tcgGiftsForUser($this->uid),
            static fn ($g) => $g['id'] === TCG_GIFT_MUSE_CHAMPIONS_ID
        ));
        $this->assertCount(1, $gifts);
        $g = $gifts[0];
        $this->assertFalse($g['claimed']);
        $this->assertSame('gifts.muse10', $g['title_key']);
        $this->assertStringContainsString('[[Love Live Champions]]', $g['body_en']);
        $this->assertStringStartsWith('https://x.com/LoveLiveChamps/status/2114748892055326956', $g['link_url']);
        $this->assertSame([['type' => 'muse_premium_ticket', 'amount' => 1]], $g['rewards']);
        $this->assertSame(TCG_GIFT_MUSE_CHAMPIONS_EXPIRES_AT, $g['expires_at']);
    }

    public function testChampionsGiftStaysAvailableToNewAccountsUntilExpiry(): void
    {
        // New player after the gift was first seeded still sees it (audience=all).
        $newbie = 'test_muse_new_' . bin2hex(random_bytes(3));
        tcgEnsureUser($newbie, ['username' => 'Newbie']);
        $gifts = array_values(array_filter(
            tcgGiftsForUser($newbie, TCG_GIFT_MUSE_CHAMPIONS_EXPIRES_AT - 60),
            static fn ($g) => $g['id'] === TCG_GIFT_MUSE_CHAMPIONS_ID
        ));
        $this->assertCount(1, $gifts);
        $this->assertFalse($gifts[0]['claimed']);

        // After Nov 30 UTC the unclaimed gift disappears for everyone.
        $gone = array_values(array_filter(
            tcgGiftsForUser($newbie, TCG_GIFT_MUSE_CHAMPIONS_EXPIRES_AT),
            static fn ($g) => $g['id'] === TCG_GIFT_MUSE_CHAMPIONS_ID
        ));
        $this->assertSame([], $gone);
    }

    public function testGiftVisibilityAudienceAndExpiry(): void
    {
        $now = 1_800_000_000;
        $this->assertTrue(tcgGiftVisibleToUser(['audience' => 'all', 'expires_at' => null], $this->uid, $now));
        $this->assertTrue(tcgGiftVisibleToUser(['audience' => json_encode([$this->uid, 'x']), 'expires_at' => null], $this->uid, $now));
        $this->assertFalse(tcgGiftVisibleToUser(['audience' => json_encode(['someone_else']), 'expires_at' => null], $this->uid, $now));
        $this->assertFalse(tcgGiftVisibleToUser(['audience' => 'all', 'expires_at' => $now - 1], $this->uid, $now));
        $this->assertTrue(tcgGiftVisibleToUser(['audience' => 'all', 'expires_at' => $now + 60], $this->uid, $now));
        $this->assertSame(gmmktime(0, 0, 0, 12, 1, 2026), TCG_GIFT_MUSE_CHAMPIONS_EXPIRES_AT);
    }

    public function testRewardNormalizationRejectsBadInput(): void
    {
        $ok = tcgGiftNormalizeRewards([
            ['type' => 'muse_premium_ticket', 'amount' => 3],
            ['type' => 'coins', 'amount' => 500],
        ]);
        $this->assertSame(['type' => 'muse_premium_ticket', 'amount' => 3], $ok[0]);
        $this->assertSame(['type' => 'coins', 'amount' => 500], $ok[1]);
        foreach ([[], [['type' => 'nope', 'amount' => 1]], 'x'] as $bad) {
            try {
                tcgGiftNormalizeRewards($bad);
                $this->fail('expected rejection');
            } catch (\Exception $e) {
                $this->assertSame(400, $e->getCode());
            }
        }
    }

    public function testGrantRewardAddsTicketAndOtherCurrencies(): void
    {
        tcgGiftGrantReward($this->uid, ['type' => 'muse_premium_ticket', 'amount' => 1]);
        tcgGiftGrantReward($this->uid, ['type' => 'scouting_ticket_ur', 'amount' => 2]);
        $this->assertSame(1, tcgGetMusePremiumTickets($this->uid));
        $this->assertSame(2, tcgGetScoutingTierTickets($this->uid, 'ur'));
    }

    public function testMusePoolIsMusMembersOnlyWithBoostedHonoka(): void
    {
        $cards = tcgLoadCardsData();
        $map = tcgBuildCardMap($cards);
        $pools = tcgGachaBuildMusePools($cards);
        $this->assertNotEmpty($pools['ur']);
        $this->assertNotEmpty($pools['sr']);
        $this->assertNotEmpty($pools['_w']);
        foreach ($pools['all'] as $no) {
            $c = $map[$no];
            $this->assertSame("μ's", $c['group'], $no);
            $this->assertSame('Member', $c['card_type_en'], $no);
            $this->assertFalse(tcgCardEligibleForPrBoosterPool($c), $no);
        }
        foreach ($pools['_w'] as $no => $w) {
            $this->assertSame(TCG_GACHA_BDAY_BOOST, $w);
            $this->assertTrue(tcgGachaCardMatchesIdol($map[$no], 'honoka'), $no);
        }
    }

    public function testTenPullEndsWithSrPlusHonoka(): void
    {
        $cards = tcgLoadCardsData();
        $map = tcgBuildCardMap($cards);
        $pools = tcgGachaBuildMusePools($cards);
        for ($i = 0; $i < 200; $i++) {
            $rolled = tcgGachaRollPulls(TCG_GACHA_MUSE_PULL_COUNT, $cards, $map, $pools, true);
            $this->assertCount(10, $rolled);
            $last = $rolled[9];
            $this->assertContains($last['tier'], ['sr', 'ur']);
            $this->assertArrayHasKey($last['card_no'], $pools['_w'], 'final pull must be Honoka');
            foreach ($rolled as $row) {
                $this->assertContains($row['card_no'], $pools['all']);
            }
        }
    }

    public function testBannerOnlyResolvesWithATicket(): void
    {
        $cards = tcgLoadCardsData();
        $this->assertNull(tcgGachaMuseBanner($this->uid));
        try {
            tcgGachaResolveBanner($cards, TCG_GACHA_MUSE_BANNER_ID, null, $this->uid);
            $this->fail('banner must be inactive without a ticket');
        } catch (\Exception $e) {
            $this->assertSame(400, $e->getCode());
        }
        tcgAddMusePremiumTickets($this->uid, 1);
        [$pools, $banner] = tcgGachaResolveBanner($cards, TCG_GACHA_MUSE_BANNER_ID, null, $this->uid);
        $this->assertSame('muse_premium', $banner['type']);
        $this->assertSame(1, $banner['tickets']);
        $this->assertNotEmpty($pools['_w']);
        // Guests / other users never see it.
        $this->expectException(\Exception::class);
        tcgGachaResolveBanner($cards, TCG_GACHA_MUSE_BANNER_ID, null, null);
    }

    public function testMuseCurrencyAliases(): void
    {
        $this->assertTrue(tcgGachaIsMuseTicketCurrency('muse_ticket'));
        $this->assertTrue(tcgGachaIsMuseTicketCurrency('muse_premium_ticket'));
        $this->assertFalse(tcgGachaIsMuseTicketCurrency('tickets'));
        $this->assertFalse(tcgGachaIsMuseTicketCurrency('star_gems'));
    }
}
