<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

final class GachaSimTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/gacha.php';
    }

    public function testForcedTierFillRestWithN(): void
    {
        $tiers = tcgGachaBuildForcedTier(11, 2, 3);
        $this->assertCount(11, $tiers);
        $ur = count(array_filter($tiers, static fn ($t) => $t === 'ur'));
        $sr = count(array_filter($tiers, static fn ($t) => $t === 'sr'));
        $n = count(array_filter($tiers, static fn ($t) => $t === 'n'));
        $this->assertSame(2, $ur);
        $this->assertSame(3, $sr);
        $this->assertSame(6, $n);
    }

    public function testForcedTiersClampToCount(): void
    {
        $tiers = tcgGachaBuildForcedTier(3, 5, 5);
        $this->assertCount(3, $tiers);
        $this->assertSame(3, count(array_filter($tiers, static fn ($t) => $t === 'ur')));
    }
}
