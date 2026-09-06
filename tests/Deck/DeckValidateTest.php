<?php

declare(strict_types=1);

namespace LLTCG\Tests\Deck;

use PHPUnit\Framework\TestCase;

final class DeckValidateTest extends TestCase
{
    private array $cardMap;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/loveca_points.php';
        require_once dirname(__DIR__, 2) . '/deck_validate.php';
        $data = json_decode((string)file_get_contents(CARDS_FILE), true);
        $this->cardMap = tcgBuildCardMap($data);
    }

    public function testStarterDecksMeetLovecaLimit(): void
    {
        $data = json_decode((string)file_get_contents(CARDS_FILE), true);
        foreach ($data['starter_decks'] ?? [] as $key => $starter) {
            $main = $starter['main_deck'] ?? [];
            $energy = $starter['energy_deck'] ?? [];
            $result = tcgValidateDeckLists($main, $energy, $this->cardMap);
            $this->assertTrue($result['valid'], "$key: " . implode('; ', $result['errors']));
            $this->assertLessThanOrEqual($result['loveca_limit'], $result['loveca_points']);
        }
    }

    public function testStarterNijigasakiDeckIsValid(): void
    {
        $data = json_decode((string)file_get_contents(CARDS_FILE), true);
        $starter = $data['starter_decks']['nijigasaki'] ?? null;
        $this->assertIsArray($starter);
        $main = $starter['main_deck'] ?? [];
        $energy = $starter['energy_deck'] ?? [];
        $result = tcgValidateDeckLists($main, $energy, $this->cardMap);
        $this->assertTrue($result['valid'], implode('; ', $result['errors']));
        $this->assertSame(9, $result['loveca_limit']);
        $this->assertSame(4, $result['loveca_points']);
    }

    public function testOfficialOkLovecaPointSum(): void
    {
        $sample = ['PL!N-bp1-012-R＋', 'PL!N-bp1-012-R＋', 'PL!N-sd1-008-SD'];
        $this->assertSame(8, tcgSumMainDeckLovecaPoints($sample));
    }

    public function testEmmaSd1AllPrintingsShareTwoPoints(): void
    {
        foreach (['PL!N-sd1-008-P', 'PL!N-sd1-008-RM', 'PL!N-sd1-008-SD', 'PL!N-sd1-008-SD2'] as $no) {
            $this->assertSame(2, tcgGetLovecaPointForCardNo($no), $no);
            $this->assertSame(2, intval(($this->cardMap[$no]['loveca_point'] ?? 0)), $no . ' catalog');
        }
    }

    public function testOfficialNgLovecaExample(): void
    {
        $data = json_decode((string)file_get_contents(CARDS_FILE), true);
        $main = $data['starter_decks']['nijigasaki']['main_deck'];
        $energy = $data['starter_decks']['nijigasaki']['energy_deck'];
        foreach ($main as $i => $no) {
            if ($no === 'PL!N-sd1-001-SD') {
                $main[$i] = 'PL!N-bp1-003-R＋';
            }
        }
        while (count(array_filter($main, fn($n) => $n === 'PL!N-bp1-003-R＋')) < 4) {
            $main[array_search('PL!N-sd1-002-SD', $main, true)] = 'PL!N-bp1-003-R＋';
        }
        $result = tcgValidateDeckLists($main, $energy, $this->cardMap);
        $this->assertGreaterThan(9, $result['loveca_points']);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testMuseStarterHasZeroLovecaPoints(): void
    {
        $data = json_decode((string)file_get_contents(CARDS_FILE), true);
        $main = $data['starter_decks']['muse']['main_deck'] ?? [];
        $energy = $data['starter_decks']['muse']['energy_deck'] ?? [];
        $result = tcgValidateDeckLists($main, $energy, $this->cardMap);
        $this->assertSame(0, $result['loveca_points']);
        $this->assertTrue($result['valid'], implode('; ', $result['errors']));
    }

    public function testWrongMainSizeIsInvalid(): void
    {
        $result = tcgValidateDeckLists(['LL-E-001-SD'], [], $this->cardMap);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testIncompleteDraftCanSaveWithoutPlayLegalSize(): void
    {
        $draft = tcgValidateDeckLists(['PL!N-sd1-001-SD'], ['LL-E-001-SD'], $this->cardMap, null, true);
        $this->assertTrue($draft['valid'], implode('; ', $draft['errors']));
        $play = tcgValidateDeckLists(['PL!N-sd1-001-SD'], ['LL-E-001-SD'], $this->cardMap, null, false);
        $this->assertFalse($play['valid']);
    }

    public function testIncompleteDraftStillRejectsOversize(): void
    {
        $main = array_fill(0, 61, 'PL!N-sd1-001-SD');
        $result = tcgValidateDeckLists($main, [], $this->cardMap, null, true);
        $this->assertFalse($result['valid']);
    }

    /** Claimed starters stay legal when collection check is skipped (exchanged cards). */
    public function testStarterListsValidWithoutCollectionOwnership(): void
    {
        $data = json_decode((string) file_get_contents(CARDS_FILE), true);
        $main = $data['starter_decks']['nijigasaki']['main_deck'] ?? [];
        $energy = $data['starter_decks']['nijigasaki']['energy_deck'] ?? [];
        $this->assertNotEmpty($main);
        $emptyCollection = [];
        $withOwned = tcgValidateDeckLists($main, $energy, $this->cardMap, $emptyCollection);
        $this->assertFalse($withOwned['valid'], 'Empty collection must fail ownership checks');
        $catalogOnly = tcgValidateDeckLists($main, $energy, $this->cardMap, null);
        $this->assertTrue($catalogOnly['valid'], implode('; ', $catalogOnly['errors']));
    }

    public function testPlusPrintingSharesFourCopyLimitWithBase(): void
    {
        $base = 'PL!-bp3-004-P';
        $plus = 'PL!-bp3-004-P＋';
        $this->assertArrayHasKey($base, $this->cardMap);
        $this->assertArrayHasKey($plus, $this->cardMap);
        $this->assertSame(tcgDeckCopyIdentity($base), tcgDeckCopyIdentity($plus));
        $main = array_merge(array_fill(0, 3, $base), array_fill(0, 2, $plus));
        $result = tcgValidateDeckLists($main, [], $this->cardMap, null, true);
        $this->assertFalse($result['valid']);
        $this->assertTrue(
            (bool) preg_grep('/Too many copies of .+ including alternate versions/', $result['errors']),
            implode('; ', $result['errors'])
        );
        $ok = array_merge(array_fill(0, 2, $base), array_fill(0, 2, $plus));
        $okResult = tcgValidateDeckLists($ok, [], $this->cardMap, null, true);
        $this->assertTrue($okResult['valid'], implode('; ', $okResult['errors']));
    }

    public function testEnergyPlusPrintingSharesTwelveCopyLimit(): void
    {
        $base = 'PL!-bp3-030-PE';
        $plus = 'PL!-bp3-030-PE＋';
        $this->assertArrayHasKey($base, $this->cardMap);
        $this->assertArrayHasKey($plus, $this->cardMap);
        $energy = array_merge(array_fill(0, 7, $base), array_fill(0, 6, $plus));
        $result = tcgValidateDeckLists([], $energy, $this->cardMap, null, true);
        $this->assertFalse($result['valid']);
        $ok = array_merge(array_fill(0, 6, $base), array_fill(0, 6, $plus));
        $okResult = tcgValidateDeckLists([], $ok, $this->cardMap, null, true);
        $this->assertTrue($okResult['valid'], implode('; ', $okResult['errors']));
    }

    public function testDuoPrintingSharesFourCopyLimitWithRare(): void
    {
        $duo = 'PL!SP-pb2-000-DUO';
        $rare = 'PL!SP-pb2-000-R';
        $this->assertArrayHasKey($duo, $this->cardMap);
        $this->assertArrayHasKey($rare, $this->cardMap);
        $this->assertSame(tcgDeckCopyIdentity($duo), tcgDeckCopyIdentity($rare));
        $main = array_merge(array_fill(0, 3, $duo), array_fill(0, 2, $rare));
        $result = tcgValidateDeckLists($main, [], $this->cardMap, null, true);
        $this->assertFalse($result['valid']);
        $this->assertTrue(
            (bool) preg_grep('/Too many copies of .+ including alternate versions/', $result['errors']),
            implode('; ', $result['errors'])
        );
        $ok = array_merge(array_fill(0, 2, $duo), array_fill(0, 2, $rare));
        $okResult = tcgValidateDeckLists($ok, [], $this->cardMap, null, true);
        $this->assertTrue($okResult['valid'], implode('; ', $okResult['errors']));
    }

    public function testParallelRarityPrintingsShareFourCopyLimit(): void
    {
        $p = 'PL!N-bp1-003-P';
        $sec = 'PL!N-bp1-003-SEC';
        $this->assertArrayHasKey($p, $this->cardMap);
        $this->assertArrayHasKey($sec, $this->cardMap);
        $this->assertSame(tcgDeckCopyIdentity($p), tcgDeckCopyIdentity($sec));
        $main = array_merge(array_fill(0, 3, $p), array_fill(0, 2, $sec));
        $result = tcgValidateDeckLists($main, [], $this->cardMap, null, true);
        $this->assertFalse($result['valid']);
    }
}
