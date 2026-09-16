<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

final class GachaPoolTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/gacha.php';
    }

    public function testPoolExcludesPremiumMellowPrAndDuo(): void
    {
        $cards = tcgLoadCardsData();
        $pools = tcgGachaBuildPools($cards);
        $this->assertGreaterThan(100, count($pools['all']));
        $this->assertNotEmpty($pools['ur']);
        $this->assertNotEmpty($pools['sr']);
        $this->assertNotEmpty($pools['n']);

        $map = tcgBuildCardMap($cards);
        foreach ($pools['all'] as $no) {
            $c = $map[$no] ?? null;
            $this->assertIsArray($c);
            $pack = (string)($c['booster_pack'] ?? '');
            $this->assertStringNotContainsString('プレミアムブースター', $pack);
            $this->assertNotSame('ブースターパック MELLOW MOMENT', $pack);
            $this->assertFalse(tcgCardEligibleForPrBoosterPool($c));
            $r = tcgNormalizePoolRarity((string)($c['rarity'] ?? ''), $no);
            $this->assertNotSame('DUO', $r);
            $this->assertDoesNotMatchRegularExpression('/-DUO$/i', $no);
        }
    }

    public function testTierWeightsAreSifStyle(): void
    {
        $this->assertSame(10000, TCG_GACHA_WEIGHT_N + TCG_GACHA_WEIGHT_SR + TCG_GACHA_WEIGHT_UR);
        $this->assertLessThanOrEqual(100, TCG_GACHA_WEIGHT_UR); // ≤1%
        $this->assertSame(80, TCG_GACHA_WEIGHT_UR); // 0.8%
        $this->assertSame(20, TCG_GACHA_SINGLE_COST);
        $this->assertSame(200, TCG_GACHA_MULTI_COST);
        $this->assertSame(11, TCG_GACHA_MULTI_COUNT);
    }

    public function testRollMultiReturnsElevenCards(): void
    {
        $cards = tcgLoadCardsData();
        $map = tcgBuildCardMap($cards);
        $pulls = tcgGachaRollPulls(TCG_GACHA_MULTI_COUNT, $cards, $map);
        $this->assertCount(11, $pulls);
        foreach ($pulls as $p) {
            $this->assertNotSame('', $p['card_no']);
            $this->assertContains($p['tier'], ['n', 'sr', 'ur']);
        }
    }

    public function testStarterCardsAppearInPool(): void
    {
        $cards = tcgLoadCardsData();
        $pools = tcgGachaBuildPools($cards);
        $set = array_fill_keys($pools['all'], true);
        $starterNos = tcgGachaStarterCardNos($cards);
        $this->assertNotEmpty($starterNos);
        $hit = 0;
        foreach ($starterNos as $no) {
            if (isset($set[$no])) {
                $hit++;
            }
        }
        $this->assertGreaterThan(10, $hit);
    }

    public function testAccessAllowlistIncludesOwnerOnly(): void
    {
        $list = tcgGachaAccessAllowlist();
        $this->assertSame(['213038604975472640'], $list);
        $this->assertTrue(tcgGachaUserHasAccess('213038604975472640'));
        $this->assertFalse(tcgGachaUserHasAccess('0'));
        $this->assertFalse(tcgGachaUserHasAccess('999'));
    }

    public function testPackCatalogListsIncludedAndExcluded(): void
    {
        $cat = tcgGachaPackCatalog();
        $incIds = array_column($cat['included'], 'id');
        $excIds = array_column($cat['excluded'], 'id');
        $this->assertContains('bp_vol1', $incIds);
        $this->assertContains('bp_royal', $incIds);
        $this->assertContains('starters', $incIds);
        $this->assertContains('bp_mellow', $excIds);
        $this->assertContains('pb_muse', $excIds);
        $this->assertContains('pr_cards', $excIds);
        $this->assertNotContains('bp_mellow', $incIds);
        $this->assertNotContains('pb_muse', $incIds);
    }
}
