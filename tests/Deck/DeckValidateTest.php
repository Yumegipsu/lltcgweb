<?php

declare(strict_types=1);

namespace LLTCG\Tests\Deck;

use PHPUnit\Framework\TestCase;

final class DeckValidateTest extends TestCase
{
    private array $cardMap;

    protected function setUp(): void
    {
        $data = json_decode((string)file_get_contents(CARDS_FILE), true);
        $this->cardMap = tcgBuildCardMap($data);
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
    }

    public function testWrongMainSizeIsInvalid(): void
    {
        $result = tcgValidateDeckLists(['LL-E-001-SD'], [], $this->cardMap);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }
}
