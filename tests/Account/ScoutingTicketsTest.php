<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

final class ScoutingTicketsTest extends TestCase
{
    private string $discordId;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/db.php';
        require_once dirname(__DIR__, 2) . '/events.php';
        require_once dirname(__DIR__, 2) . '/gacha.php';
        $this->discordId = 'test_ticket_' . bin2hex(random_bytes(4));
        tcgEnsureUser($this->discordId, ['username' => 'Ticket Tester']);
    }

    public function testAddAndDeductTickets(): void
    {
        $this->assertSame(0, tcgGetScoutingTickets($this->discordId));
        tcgAddScoutingTickets($this->discordId, 5);
        $this->assertSame(5, tcgGetScoutingTickets($this->discordId));
        tcgDeductScoutingTickets($this->discordId, 2);
        $this->assertSame(3, tcgGetScoutingTickets($this->discordId));
    }

    public function testEventRewardGrantsTickets(): void
    {
        tcgEventsGrantReward($this->discordId, 'scouting_ticket', ['amount' => 7]);
        $this->assertSame(7, tcgGetScoutingTickets($this->discordId));
    }

    public function testTicketCostsConstants(): void
    {
        $this->assertSame(1, TCG_GACHA_TICKET_SINGLE_COST);
        $this->assertSame(10, TCG_GACHA_TICKET_MULTI_COST);
        $this->assertSame(10, TCG_GACHA_TICKET_MULTI_COUNT);
    }

    public function testTicketSpendIsOnePerPull(): void
    {
        // Picker sends count; cost is 1 ticket per pull (capped like rolls at 20).
        $this->assertSame(7, 7 * TCG_GACHA_TICKET_SINGLE_COST);
        $this->assertSame(10, 10 * TCG_GACHA_TICKET_SINGLE_COST);
        $this->assertSame(TCG_GACHA_TICKET_MULTI_COST, TCG_GACHA_TICKET_MULTI_COUNT * TCG_GACHA_TICKET_SINGLE_COST);
    }

    public function testNormalizeScoutingTicketReward(): void
    {
        $payload = tcgEventsNormalizeRewardPayload('scouting_ticket', ['amount' => 3]);
        $this->assertSame(['amount' => 3], $payload);
        $this->assertSame('scouting_ticket', tcgEventsNormalizeRewardType('scouting_ticket'));
    }

    public function testTierTicketsAreSeparateCountersAndDeductOne(): void
    {
        $this->assertSame(['sr' => 0, 'ur' => 0], tcgGetScoutingTierTicketCounts($this->discordId));
        tcgAddScoutingTierTickets($this->discordId, 'sr', 3);
        tcgAddScoutingTierTickets($this->discordId, 'ur', 1);
        tcgAddScoutingTickets($this->discordId, 4);
        $this->assertSame(['sr' => 3, 'ur' => 1], tcgGetScoutingTierTicketCounts($this->discordId));
        $this->assertSame(4, tcgGetScoutingTickets($this->discordId));

        tcgDeductScoutingTierTickets($this->discordId, 'sr', TCG_GACHA_TIER_TICKET_COST);
        $this->assertSame(['sr' => 2, 'ur' => 1], tcgGetScoutingTierTicketCounts($this->discordId));
        $this->assertSame(4, tcgGetScoutingTickets($this->discordId));
    }

    public function testTierTicketCannotOverspend(): void
    {
        tcgAddScoutingTierTickets($this->discordId, 'ur', 1);
        $this->expectException(\Exception::class);
        try {
            tcgDeductScoutingTierTickets($this->discordId, 'sr', 1);
        } finally {
            $this->assertSame(1, tcgGetScoutingTierTickets($this->discordId, 'ur'));
        }
    }

    public function testInvalidTierRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        tcgGetScoutingTierTickets($this->discordId, 'n');
    }

    public function testEventRewardGrantsTierTickets(): void
    {
        tcgEventsGrantReward($this->discordId, 'scouting_ticket_sr', ['amount' => 2]);
        tcgEventsGrantReward($this->discordId, 'scouting_ticket_ur', ['amount' => 5]);
        $this->assertSame(['sr' => 2, 'ur' => 5], tcgGetScoutingTierTicketCounts($this->discordId));
        $this->assertSame(0, tcgGetScoutingTickets($this->discordId));
        $this->assertSame(['amount' => 4], tcgEventsNormalizeRewardPayload('scouting_ticket_ur', ['amount' => 4]));
        $this->assertSame('scouting_ticket_sr', tcgEventsNormalizeRewardType('scouting_ticket_sr'));
    }

    public function testTierTicketCurrencyParsing(): void
    {
        $this->assertSame('sr', tcgGachaTierTicketFromCurrency('ticket_sr'));
        $this->assertSame('ur', tcgGachaTierTicketFromCurrency('ticket_ur'));
        $this->assertNull(tcgGachaTierTicketFromCurrency('tickets'));
        $this->assertNull(tcgGachaTierTicketFromCurrency('star_gems'));
    }

    public function testSrTicketGuaranteesSrOrBetter(): void
    {
        $cards = tcgLoadCardsData();
        $map = tcgBuildCardMap($cards);
        for ($i = 0; $i < 300; $i++) {
            $rolled = tcgGachaRollTierTicketPull('sr', $cards, $map);
            $this->assertCount(1, $rolled);
            $this->assertContains($rolled[0]['tier'], ['sr', 'ur']);
            $this->assertNotSame('n', tcgGachaTierForRarity($rolled[0]['rarity']));
        }
    }

    public function testUrTicketAlwaysRollsTopTier(): void
    {
        $cards = tcgLoadCardsData();
        $map = tcgBuildCardMap($cards);
        for ($i = 0; $i < 300; $i++) {
            $rolled = tcgGachaRollTierTicketPull('ur', $cards, $map);
            $this->assertCount(1, $rolled);
            $this->assertSame('ur', $rolled[0]['tier']);
            $this->assertSame('ur', tcgGachaTierForRarity($rolled[0]['rarity']));
        }
    }
}
