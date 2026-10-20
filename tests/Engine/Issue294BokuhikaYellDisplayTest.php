<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/api.php';

/**
 * #294 — Bokutachi wa Hitotsu no Hikari (all2) yell hearts stay wild for payment
 * and HUD, while still reserving distinct missing colors so a later ALL blade
 * does not also claim the first missing color.
 */
final class Issue294BokuhikaYellDisplayTest extends TestCase
{
    public function testAll2StaysAnyButReservesDistinctMissingColors(): void
    {
        $live = [
            'required_hearts' => [
                ['color' => 'pink', 'count' => 1],
                ['color' => 'yellow', 'count' => 1],
                ['color' => 'purple', 'count' => 1],
            ],
        ];
        $pool = ['red', 'blue'];
        $icons = getHeartIconsFromBladeHeart('all2', $pool, [$live]);

        $this->assertSame(['any', 'any'], $icons);
        // Reserved pink then yellow for later ALL blades — not pink twice.
        $this->assertContains('pink', $pool);
        $this->assertContains('yellow', $pool);
        $this->assertNotEquals(
            array_values(array_filter($pool, fn($c) => $c === 'pink')),
            $pool
        );
        $reserved = array_values(array_filter(
            $pool,
            fn($c) => !in_array($c, ['red', 'blue', 'any'], true)
        ));
        $this->assertSame(['pink', 'yellow'], $reserved);
    }

    public function testBokuhikaAll2PaysAsTwoWilds(): void
    {
        $card = tcgCatalogCardByNo('PL!-pb2-039-L');
        $this->assertIsArray($card);
        $this->assertSame(['all2'], $card['blade_hearts'] ?? null);

        $live = [
            'required_hearts' => [
                ['color' => 'pink', 'count' => 1],
                ['color' => 'green', 'count' => 1],
            ],
        ];
        $pool = [];
        $icons = [];
        foreach ($card['blade_hearts'] as $bh) {
            $icons = array_merge($icons, getHeartIconsFromBladeHeart($bh, $pool, [$live]));
        }
        $this->assertSame(['any', 'any'], $icons);
        [$ok] = checkHearts(
            array_merge(['red', 'blue'], $icons),
            [
                ['color' => 'pink', 'count' => 1],
                ['color' => 'green', 'count' => 1],
                ['color' => 'red', 'count' => 1],
                ['color' => 'blue', 'count' => 1],
            ]
        );
        $this->assertTrue($ok);
        $this->assertSame(['pink', 'green'], array_values(array_filter(
            $pool,
            fn($c) => $c !== 'any'
        )));
    }

    public function testLaterAllBladeSkipsColorsReservedByAll2(): void
    {
        $live = [
            'required_hearts' => [
                ['color' => 'pink', 'count' => 1],
                ['color' => 'yellow', 'count' => 1],
                ['color' => 'purple', 'count' => 1],
            ],
        ];
        $pool = [];
        $all2 = getHeartIconsFromBladeHeart('all2', $pool, [$live]);
        $this->assertSame(['any', 'any'], $all2);
        $all = getHeartIconsFromBladeHeart('all', $pool, [$live]);
        $this->assertSame(['purple'], $all);
    }

    public function testPrintedGrayAnyAlsoStayWildWhileReserving(): void
    {
        $live = [
            'required_hearts' => [
                ['color' => 'pink', 'count' => 1],
                ['color' => 'yellow', 'count' => 1],
            ],
        ];
        $pool = [];
        $a = getHeartIconsFromBladeHeart('any', $pool, [$live]);
        $b = getHeartIconsFromBladeHeart('gray', $pool, [$live]);
        $this->assertSame(['any'], $a);
        $this->assertSame(['any'], $b);
        $this->assertSame(['pink', 'yellow'], array_values(array_filter(
            $pool,
            fn($c) => $c !== 'any'
        )));
    }
}
