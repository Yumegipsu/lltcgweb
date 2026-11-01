<?php

declare(strict_types=1);

namespace LLTCG\Tests\Client;

use PHPUnit\Framework\TestCase;

/** Claiming a gift with a PR pack must queue the shared pack reveal animation. */
final class GiftPrPackRevealGuardTest extends TestCase
{
    public function testGiftClaimQueuesRankedPrReveal(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/client/js/gifts.js');
        $this->assertStringContainsString('res.pr_pack', $src);
        $this->assertStringContainsString('queueRankedPrReward(res.pr_pack)', $src);
        $this->assertStringContainsString('claimedPrPackToast', $src);
    }

    public function testClaimApiReturnsPrPackPayload(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/gifts.php');
        $this->assertStringContainsString("\$out['pr_pack']", $src);
        $this->assertStringContainsString('return tcgGrantPrPackCards(', $src);
    }
}
