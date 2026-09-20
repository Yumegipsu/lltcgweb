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
            $this->assertFalse(tcgCardEligibleForPrBoosterPool($c));
            $r = tcgNormalizePoolRarity((string)($c['rarity'] ?? ''), $no);
            $this->assertNotSame('DUO', $r);
            $this->assertDoesNotMatchRegularExpression('/-DUO$/i', $no);
        }
    }

    public function testNewSetEmbargoOneMonthAfterRelease(): void
    {
        $tz = new \DateTimeZone('Asia/Tokyo');
        $mellow = null;
        foreach (tcgBoosterBoxes() as $box) {
            if (($box['id'] ?? '') === 'bp_mellow') {
                $mellow = $box;
                break;
            }
        }
        $this->assertIsArray($mellow);
        $this->assertSame('2026-08-08', $mellow['release_date']);

        // During embargo window (release day).
        $during = (new \DateTimeImmutable('2026-08-08 12:00:00', $tz))->getTimestamp();
        $this->assertTrue(tcgGachaBpIsNewSetEmbargoed($mellow, $during));

        // Still embargoed just before +1 month.
        $almost = (new \DateTimeImmutable('2026-09-07 23:59:59', $tz))->getTimestamp();
        $this->assertTrue(tcgGachaBpIsNewSetEmbargoed($mellow, $almost));

        // Unlocked at +1 calendar month.
        $unlocked = (new \DateTimeImmutable('2026-09-08 00:00:00', $tz))->getTimestamp();
        $this->assertFalse(tcgGachaBpIsNewSetEmbargoed($mellow, $unlocked));

        // Remains unlocked while still the newest set.
        $later = (new \DateTimeImmutable('2026-12-01 00:00:00', $tz))->getTimestamp();
        $this->assertFalse(tcgGachaBpIsNewSetEmbargoed($mellow, $later));
    }

    public function testPackCatalogUsesEmbargoNotForeverNewest(): void
    {
        $tz = new \DateTimeZone('Asia/Tokyo');
        tcgGachaClearPoolCache();

        $during = (new \DateTimeImmutable('2026-08-20 12:00:00', $tz))->getTimestamp();
        $catDuring = tcgGachaPackCatalog($during);
        $this->assertContains('bp_mellow', array_column($catDuring['excluded'], 'id'));
        $this->assertNotContains('bp_mellow', array_column($catDuring['included'], 'id'));
        $this->assertContains('bp_royal', array_column($catDuring['included'], 'id'));

        $after = (new \DateTimeImmutable('2026-09-19 12:00:00', $tz))->getTimestamp();
        $catAfter = tcgGachaPackCatalog($after);
        $this->assertContains('bp_mellow', array_column($catAfter['included'], 'id'));
        $this->assertNotContains('bp_mellow', array_column($catAfter['excluded'], 'id'));
        $this->assertContains('pb_muse', array_column($catAfter['excluded'], 'id'));
        $this->assertContains('pr_cards', array_column($catAfter['excluded'], 'id'));
    }

    public function testPoolIncludesMellowAfterEmbargo(): void
    {
        $tz = new \DateTimeZone('Asia/Tokyo');
        $cards = tcgLoadCardsData();
        tcgGachaClearPoolCache();
        $after = (new \DateTimeImmutable('2026-09-19 12:00:00', $tz))->getTimestamp();
        $pools = tcgGachaBuildPools($cards, $after);
        $map = tcgBuildCardMap($cards);
        $hit = 0;
        foreach ($pools['all'] as $no) {
            $c = $map[$no] ?? null;
            if (is_array($c) && ($c['booster_pack'] ?? '') === 'ブースターパック MELLOW MOMENT') {
                $hit++;
            }
        }
        $this->assertGreaterThan(0, $hit, 'MELLOW MOMENT should enter the pool one month after release');
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

    public function testMultiGuaranteesAtLeastOneSrPlus(): void
    {
        $cards = tcgLoadCardsData();
        $map = tcgBuildCardMap($cards);
        for ($t = 0; $t < 40; $t++) {
            $pulls = tcgGachaRollPulls(TCG_GACHA_MULTI_COUNT, $cards, $map);
            $srPlus = 0;
            foreach ($pulls as $p) {
                if ($p['tier'] === 'sr' || $p['tier'] === 'ur') {
                    $srPlus++;
                }
            }
            $this->assertGreaterThanOrEqual(1, $srPlus, 'Scout 10+1 must include at least one SR+');
        }
    }

    public function testMultiGuaranteedSrPlusIsFinalCard(): void
    {
        $cards = tcgLoadCardsData();
        $map = tcgBuildCardMap($cards);
        foreach ([TCG_GACHA_MULTI_COUNT, TCG_GACHA_TICKET_MULTI_COUNT] as $count) {
            for ($t = 0; $t < 50; $t++) {
                $pulls = tcgGachaRollPulls($count, $cards, $map);
                $this->assertCount($count, $pulls);
                $last = $pulls[$count - 1];
                $this->assertContains(
                    $last['tier'],
                    ['sr', 'ur'],
                    "Multi pull ({$count}) final card must be SR+"
                );
            }
        }
    }

    public function testTierMappingMatchesScoutBands(): void
    {
        $this->assertSame('n', tcgGachaTierForRarity('N'));
        $this->assertSame('n', tcgGachaTierForRarity('R'));
        $this->assertSame('n', tcgGachaTierForRarity('L'));
        $this->assertSame('n', tcgGachaTierForRarity('PE'));
        $this->assertSame('sr', tcgGachaTierForRarity('P'));
        $this->assertSame('sr', tcgGachaTierForRarity('SRE'));
        $this->assertSame('sr', tcgGachaTierForRarity('PE+'));
        $this->assertSame('ur', tcgGachaTierForRarity('SEC'));
        $this->assertSame('ur', tcgGachaTierForRarity('SECL'));
        $this->assertSame('ur', tcgGachaTierForRarity('LLE'));
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

    public function testStarterBasicEnergyNotInPool(): void
    {
        $cards = tcgLoadCardsData();
        $pools = tcgGachaBuildPools($cards);
        $set = array_fill_keys($pools['all'], true);
        foreach (TCG_STARTER_BASIC_ENERGY_CARD_NOS as $no) {
            $this->assertArrayNotHasKey($no, $set, "basic energy {$no} must not be in gacha");
        }
        foreach ($pools['all'] as $no) {
            $this->assertFalse(
                tcgIsStarterBasicEnergyCard($no),
                "gacha pool must not include starter basic energy {$no}"
            );
        }
    }

    public function testAccessAllowlistOpenToEveryone(): void
    {
        $list = tcgGachaAccessAllowlist();
        $this->assertSame([], $list);
        $this->assertTrue(tcgGachaUserHasAccess('213038604975472640'));
        $this->assertTrue(tcgGachaUserHasAccess('0'));
        $this->assertTrue(tcgGachaUserHasAccess('999'));
    }

    public function testPackCatalogListsIncludedAndExcluded(): void
    {
        $tz = new \DateTimeZone('Asia/Tokyo');
        $after = (new \DateTimeImmutable('2026-09-19 12:00:00', $tz))->getTimestamp();
        $cat = tcgGachaPackCatalog($after);
        $incIds = array_column($cat['included'], 'id');
        $excIds = array_column($cat['excluded'], 'id');
        $this->assertContains('bp_vol1', $incIds);
        $this->assertContains('bp_royal', $incIds);
        $this->assertContains('bp_mellow', $incIds);
        $this->assertContains('starters', $incIds);
        $this->assertContains('pb_muse', $excIds);
        $this->assertContains('pr_cards', $excIds);
        $this->assertNotContains('pb_muse', $incIds);
    }

    public function testComputeRatesUsesLovecaRaritiesAndPerCardOdds(): void
    {
        $cards = tcgLoadCardsData();
        $rates = tcgComputeGachaRates($cards);
        $this->assertNotEmpty($rates['rarity_rates']);
        $this->assertNotEmpty($rates['cards']);
        $this->assertGreaterThan(100, count($rates['cards']));

        $rarities = array_column($rates['rarity_rates'], 'rarity');
        $this->assertContains('N', $rarities);
        $this->assertNotContains('n', $rarities);
        $this->assertNotContains('sr', $rarities);
        $this->assertNotContains('ur', $rarities);
        $this->assertNotContains('SR', $rarities);
        $this->assertNotContains('UR', $rarities);

        $sumRarity = 0.0;
        foreach ($rates['rarity_rates'] as $row) {
            $sumRarity += (float)$row['percent'];
            $this->assertGreaterThan(0, $row['percent']);
            $this->assertGreaterThan(0, $row['count']);
        }
        $this->assertEqualsWithDelta(100.0, $sumRarity, 0.05);

        $sumCards = 0.0;
        foreach ($rates['cards'] as $row) {
            $sumCards += (float)$row['percent'];
            $this->assertNotSame('', $row['card_no']);
            $this->assertNotSame('', $row['rarity']);
            $this->assertContains($row['tier'], ['n', 'sr', 'ur']);
            $this->assertGreaterThan(0, $row['percent']);
        }
        $this->assertEqualsWithDelta(100.0, $sumCards, 0.05);
    }
}
