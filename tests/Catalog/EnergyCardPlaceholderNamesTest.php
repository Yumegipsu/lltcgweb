<?php

declare(strict_types=1);

namespace LLTCG\Tests\Catalog;

use PHPUnit\Framework\TestCase;

/** Issue #162 — Energy cards must not use "Energy Card <card_no>" as their display name. */
final class EnergyCardPlaceholderNamesTest extends TestCase
{
    public function testNoEnergyCardNamedByCardNumber(): void
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $this->assertIsArray($data);
        $bad = [];
        foreach ($data['cards'] ?? [] as $c) {
            if (!is_array($c)) {
                continue;
            }
            $type = $c['card_type_en'] ?? '';
            if ($type !== 'Energy' && ($c['card_type'] ?? '') !== 'エネルギー') {
                continue;
            }
            $ne = (string)($c['name_en'] ?? '');
            $no = (string)($c['card_no'] ?? '');
            if ($ne === $no || preg_match('/^Energy Card\s+(PL!|LL-)/u', $ne) === 1) {
                $bad[] = $no . ' => ' . $ne;
            }
        }
        $this->assertSame([], $bad, 'Energy cards still named by card_no');
    }

    public function testShiorikoBp3EnergyHasCharacterName(): void
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $found = null;
        foreach ($data['cards'] ?? [] as $c) {
            if (($c['card_no'] ?? '') === 'PL!N-bp3-038-PE＋') {
                $found = $c;
                break;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame('Shioriko Mifune', $found['name_en'] ?? null);
        $this->assertSame('三船栞子', $found['name'] ?? null);
    }
}
