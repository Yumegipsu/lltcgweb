<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/api.php';

/**
 * #294 — Bokutachi wa Hitotsu no Hikari (all2) yell hearts stay colorless
 * on the HUD / owned pool (two "any"), not painted as the first missing color.
 */
final class Issue294BokuhikaYellDisplayTest extends TestCase
{
    public function testAll2StaysAnyInOwnedPool(): void
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
        // Colorless hearts are appended as any — they do not become pink/yellow (#296).
        $this->assertSame(['red', 'blue', 'any', 'any'], $pool);
    }

    public function testBokuhikaAll2PaysAsTwoColorless(): void
    {
        $card = tcgCatalogCardByNo('PL!-pb2-039-L');
        $this->assertIsArray($card);
        $this->assertSame(['all2'], $card['blade_hearts'] ?? null);

        $icons = [];
        foreach ($card['blade_hearts'] as $bh) {
            $icons = array_merge($icons, getHeartIconsFromBladeHeart($bh));
        }
        $this->assertSame(['any', 'any'], $icons);
        [$ok] = checkHearts(
            array_merge(['red', 'blue'], $icons),
            [
                ['color' => 'red', 'count' => 1],
                ['color' => 'blue', 'count' => 1],
                ['color' => 'any', 'count' => 2],
            ]
        );
        $this->assertTrue($ok);
    }

    public function testLaterAllBladeStillSeesMissingColorsAfterAll2(): void
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
        // Colorless does not cover pink — ALL still resolves to the first missing color.
        $all = getHeartIconsFromBladeHeart('all', $pool, [$live]);
        $this->assertSame(['pink'], $all);
    }
}
