<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/api.php';

/**
 * #296 — Mirai no Oto ga Kikoeru (PL!SP-bp7-028-L) double colorless yell
 * hearts must not pay a chosen purple (or other) requirement. Official
 * 8.3.15.1.1 only lets ALL blades choose a color; 無色×2 is colorless.
 */
final class Issue296MiraiOtoAll2Test extends TestCase
{
    public function testMiraiOtoAll2DoesNotFillPurpleRequirement(): void
    {
        $card = tcgCatalogCardByNo('PL!SP-bp7-028-L');
        $this->assertIsArray($card);
        $this->assertSame(['all2'], $card['blade_hearts'] ?? null);

        $icons = [];
        foreach ($card['blade_hearts'] as $bh) {
            $icons = array_merge($icons, getHeartIconsFromBladeHeart($bh));
        }
        $this->assertSame(['any', 'any'], $icons);

        // Short purple: stage has red+yellow only. all2 must not save the Live.
        [$ok] = checkHearts(
            array_merge(['red', 'red', 'red', 'yellow', 'yellow', 'yellow'], $icons),
            [
                ['color' => 'red', 'count' => 3],
                ['color' => 'yellow', 'count' => 3],
                ['color' => 'purple', 'count' => 3],
                ['color' => 'any', 'count' => 2],
            ]
        );
        $this->assertFalse($ok, 'colorless all2 must not pay purple');
    }

    public function testAll2StillFillsGrayRequirementSlots(): void
    {
        [$ok] = checkHearts(
            ['red', 'any', 'any'],
            [
                ['color' => 'red', 'count' => 1],
                ['color' => 'any', 'count' => 2],
            ]
        );
        $this->assertTrue($ok);
    }

    public function testAllBladeStillPaysChosenColor(): void
    {
        [$ok] = checkHearts(
            ['red', 'all'],
            [
                ['color' => 'red', 'count' => 1],
                ['color' => 'purple', 'count' => 1],
            ]
        );
        $this->assertTrue($ok, 'ALL blade may pay purple');
    }

    public function testColorlessAnyDoesNotPayColoredSlot(): void
    {
        [$ok] = checkHearts(
            ['pink', 'any'],
            [
                ['color' => 'pink', 'count' => 1],
                ['color' => 'purple', 'count' => 1],
            ]
        );
        $this->assertFalse($ok);
    }
}
