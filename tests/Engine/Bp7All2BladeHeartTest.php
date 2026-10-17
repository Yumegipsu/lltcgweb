<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/api.php';

final class Bp7All2BladeHeartTest extends TestCase
{
    public function testAll2WithoutPoolIsTwoAny(): void
    {
        $this->assertSame(['any', 'any'], getHeartIconsFromBladeHeart('all2'));
    }

    public function testAll2WithPoolStillYieldsTwoHearts(): void
    {
        $live = [
            'card_no' => 'PL!N-bp7-030-L',
            'required_hearts' => [
                ['color' => 'green', 'count' => 1],
                ['color' => 'gray', 'count' => 1],
            ],
        ];
        $pool = ['green', 'red', 'blue', 'yellow'];
        $icons = getHeartIconsFromBladeHeart('all2', $pool, [$live]);
        $this->assertCount(2, $icons);
        foreach ($icons as $c) {
            $this->assertNotSame('', $c);
        }
    }

    public function testMemberBladeHeartCountTreatsAll2AsTwo(): void
    {
        $m = ['blade_hearts' => ['all2']];
        $this->assertSame(2, memberBladeHeartCount($m));
        $m2 = ['blade_hearts' => ['red', 'all2']];
        $this->assertSame(3, memberBladeHeartCount($m2));
    }

    /** PL!-pb2-039-L prints 無2. A single gray token only paid one wild heart (#288). */
    public function testBokutachiYellIconPaysTwoWildHearts(): void
    {
        $card = tcgCatalogCardByNo('PL!-pb2-039-L');
        $this->assertIsArray($card);
        $this->assertSame(['all2'], $card['blade_hearts'] ?? null);
        $icons = [];
        foreach ($card['blade_hearts'] as $bh) {
            $icons = array_merge($icons, getHeartIconsFromBladeHeart($bh));
        }
        $this->assertSame(['any', 'any'], $icons);
        $rows = aggregateYellHeartsByColor([$card]);
        $byColor = [];
        foreach ($rows as $hg) {
            $byColor[$hg['color']] = intval($hg['count']);
        }
        $this->assertSame(2, $byColor['any'] ?? 0);
        [$paysTwo] = checkHearts($icons, [['color' => 'any', 'count' => 2]]);
        $this->assertTrue($paysTwo);
        [$paysTwoFromOne] = checkHearts(['any'], [['color' => 'any', 'count' => 2]]);
        $this->assertFalse($paysTwoFromOne);
        $anyReq = 0;
        foreach ($card['required_hearts'] ?? [] as $req) {
            if (($req['color'] ?? '') === 'any') {
                $anyReq += intval($req['count'] ?? 1);
            }
        }
        $this->assertSame(9, $anyReq);
    }
}
