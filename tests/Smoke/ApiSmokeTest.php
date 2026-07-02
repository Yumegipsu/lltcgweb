<?php

declare(strict_types=1);

namespace LLTCG\Tests\Smoke;

use PHPUnit\Framework\TestCase;

final class ApiSmokeTest extends TestCase
{
    public function testPingReturnsOk(): void
    {
        $out = ping([]);
        $this->assertTrue($out['ok'] ?? false);
        $this->assertArrayHasKey('time', $out);
    }

    public function testGetCardsHasCatalog(): void
    {
        $raw = getCards();
        $data = json_decode($raw, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('cards', $data);
        $this->assertNotEmpty($data['cards']);
    }

    public function testCreateAndJoinRoom(): void
    {
        $created = createRoom([
            'name' => 'Test P1',
            'deck' => 'nijigasaki',
        ]);
        $this->assertNotEmpty($created['room_id'] ?? '');
        $this->assertSame('waiting', $created['status'] ?? '');
        $this->assertNotEmpty($created['player_token'] ?? '');

        $joined = joinRoom([
            'room_id' => $created['room_id'],
            'name' => 'Test P2',
            'deck' => 'cpu',
            'cpu_difficulty' => 'easy',
        ]);
        $this->assertSame('ready', $joined['status'] ?? '');
        $this->assertNotEmpty($joined['player_token'] ?? '');
    }
}
