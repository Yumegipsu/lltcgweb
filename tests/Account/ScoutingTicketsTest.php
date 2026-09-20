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
}
