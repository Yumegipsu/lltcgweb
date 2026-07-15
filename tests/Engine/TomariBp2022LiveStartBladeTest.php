<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Issue #58: PL!SP-bp2-022-N Tomari — Live Start pay 1 Energy for +2 Blade. */
final class TomariBp2022LiveStartBladeTest extends TestCase
{
    public function testTomariBp2022AbilityIsOptionalPayBlade(): void
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $this->assertIsArray($data);
        $tomari = null;
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === 'PL!SP-bp2-022-N') {
                $tomari = $card;
                break;
            }
        }
        $this->assertNotNull($tomari);
        $this->assertStringContainsString('pay 1 Energy', (string)($tomari['text'] ?? ''));
        $this->assertStringContainsString('+2 Blade', (string)($tomari['text'] ?? ''));
        $this->assertStringNotContainsString('heart color', (string)($tomari['text'] ?? ''));

        $ab = ($tomari['abilities'] ?? [])[0] ?? null;
        $this->assertIsArray($ab);
        $this->assertSame('live_start', $ab['trigger'] ?? null);
        $this->assertSame('optional_pay_energy', $ab['type'] ?? null);
        $this->assertSame(1, intval($ab['cost'] ?? 0));
        $this->assertSame('blade_bonus', $ab['then']['type'] ?? null);
        $this->assertSame(2, intval($ab['then']['amount'] ?? 0));
    }
}
