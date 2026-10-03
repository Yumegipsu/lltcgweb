<?php

declare(strict_types=1);

namespace LLTCG\Tests\Booster;

use PHPUnit\Framework\TestCase;

/**
 * Premium booster pack structure: common + member kira + one special insert.
 * Energy / SRE / SRL must not flood packs (no triples; R stays the bulk pull).
 */
final class PremiumBoosterOddsTest extends TestCase
{
    private function loadBooster(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/booster.php';
    }

    private function emptyProgress(): array
    {
        return [
            'pe_pity' => 0,
            'pplus_pity' => 0,
            'sec_pity' => 0,
            'rm_pity' => 0,
            'live_pity' => 0,
            'sre_pity' => 0,
            'packs_in_box' => 0,
            'boxes_opened' => 0,
        ];
    }

    /** @return list<string> energy / SRE / SRL rarities */
    private function specialInsertRarities(): array
    {
        return ['SRE', 'SRL', 'PE', 'PE+', 'RE', 'LLE', 'SECE', 'SECS'];
    }

    public function testPackNeverHasTripleEnergyOrSrl(): void
    {
        $this->loadBooster();
        $cardsData = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $this->assertIsArray($cardsData);

        foreach (['pb_superstar', 'pb_superstar_duo', 'pb_muse', 'pb_niji'] as $boxId) {
            $box = tcgBoosterBoxById($boxId);
            $this->assertNotNull($box, $boxId);
            $pools = tcgBuildBoxPools($cardsData, $box);
            $progress = $this->emptyProgress();
            $specialSet = array_flip($this->specialInsertRarities());

            for ($i = 0; $i < 200; $i++) {
                $allHolo = ($i % 17 === 0);
                $slots = tcgRollPbPack($pools, $progress, 20, $allHolo);
                $this->assertCount(3, $slots, $boxId);
                $specialCount = 0;
                foreach ($slots as $no) {
                    $r = tcgRarityForCardNo((string) $no, $pools);
                    if ($r !== null && isset($specialSet[$r])) {
                        $specialCount++;
                    }
                }
                $this->assertLessThanOrEqual(
                    1,
                    $specialCount,
                    "$boxId pack had $specialCount Energy/SRE/SRL cards: " . implode(',', $slots)
                );
            }
        }
    }

    public function testROutnumbersSrlOverSimulatedDuoBox(): void
    {
        $this->loadBooster();
        $cardsData = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $box = tcgBoosterBoxById('pb_superstar_duo');
        $this->assertNotNull($box);
        $pools = tcgBuildBoxPools($cardsData, $box);
        $progress = $this->emptyProgress();

        $counts = ['R' => 0, 'SRL' => 0, 'N' => 0];
        for ($pack = 0; $pack < 200; $pack++) {
            $slots = tcgRollPbPack($pools, $progress, 20, false);
            foreach ($slots as $no) {
                $r = tcgRarityForCardNo((string) $no, $pools);
                if ($r !== null && isset($counts[$r])) {
                    $counts[$r]++;
                }
            }
        }

        $this->assertGreaterThan(
            $counts['SRL'],
            $counts['R'],
            'R should be more common than SRL: ' . json_encode($counts)
        );
        // SRL should not dominate the box (~old bug: ~80% of holos).
        $total = max(1, $counts['R'] + $counts['N'] + $counts['SRL']);
        $this->assertLessThan(
            $total * 0.35,
            $counts['SRL'],
            'SRL share too high: ' . json_encode($counts)
        );
    }

    public function testSpecialSlotSrlWeightFarBelowOldBulk(): void
    {
        $this->loadBooster();
        $srl = 0;
        $total = 0;
        foreach (tcgPbSpecialHoloSlotRarityWeights(true) as $row) {
            $total += intval($row['w']);
            if (($row['r'] ?? '') === 'SRL') {
                $srl = intval($row['w']);
            }
        }
        $this->assertGreaterThan(0, $total);
        // Old DUO weight was 3500/~4300 ≈ 80%. New special slot keeps SRL well under half.
        $this->assertLessThan(0.25, $srl / $total);
    }

    public function testPityAdvancesOncePerPackAcrossMemberAndSpecial(): void
    {
        $this->loadBooster();
        $cardsData = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $box = tcgBoosterBoxById('pb_niji');
        $pools = tcgBuildBoxPools($cardsData, $box);
        $progress = $this->emptyProgress();
        $progress['pe_pity'] = 5;

        tcgPickPbSlot($pools, $progress, 20, 'member', true, true);
        $afterMember = intval($progress['pe_pity']);
        tcgPickPbSlot($pools, $progress, 20, 'special', false, true);
        $this->assertSame($afterMember, intval($progress['pe_pity']));
    }
}
