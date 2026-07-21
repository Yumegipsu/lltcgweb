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
}
