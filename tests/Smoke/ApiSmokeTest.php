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

    public function testMutualRematchRestartsHumanGame(): void
    {
        $created = createRoom([
            'name' => 'Rematch P1',
            'deck' => 'nijigasaki',
        ]);
        $roomId = (string)$created['room_id'];
        $p1Token = (string)$created['player_token'];

        $joined = joinRoom([
            'room_id' => $roomId,
            'name' => 'Rematch P2',
            'deck' => 'liella',
        ]);
        $p2Token = (string)$joined['player_token'];

        $state = loadGame($roomId);
        $this->assertNotNull($state);
        $state['status'] = 'finished';
        $state['winner'] = 'p1';
        $state['phase'] = 'finished';
        saveGame($roomId, $state);

        handleAction([
            'room_id' => $roomId,
            'token' => $p1Token,
            'type' => 'request_rematch',
            'data' => [],
        ]);

        $mid = loadGame($roomId);
        $this->assertSame('finished', $mid['status'] ?? '');
        $this->assertTrue($mid['rematch']['p1'] ?? false);
        $this->assertFalse($mid['rematch']['p2'] ?? true);

        handleAction([
            'room_id' => $roomId,
            'token' => $p2Token,
            'type' => 'request_rematch',
            'data' => [],
        ]);

        $after = loadGame($roomId);
        $this->assertSame('setup', $after['status'] ?? '');
        $this->assertContains($after['phase'] ?? '', ['setup', 'coin_flip']);
        $this->assertCount(6, $after['players']['p1']['hand'] ?? []);
        $this->assertCount(6, $after['players']['p2']['hand'] ?? []);
        $this->assertSame('nijigasaki', $after['players']['p1']['deck_choice'] ?? '');
        $this->assertSame('liella', $after['players']['p2']['deck_choice'] ?? '');
    }

    public function testFinishedHumanMatchReportsPvp(): void
    {
        $created = createRoom(['name' => 'P1', 'deck' => 'nijigasaki']);
        $roomId = (string)$created['room_id'];
        joinRoom(['room_id' => $roomId, 'name' => 'P2', 'deck' => 'liella']);

        $state = loadGame($roomId);
        $state['status'] = 'finished';
        $state['winner'] = 'p1';
        saveGame($roomId, $state);

        $state = loadGame($roomId);
        $filtered = filterStateForPlayer($state, $state['players']['p1']['token']);
        $this->assertTrue($filtered['pvp'] ?? false);
        $this->assertArrayHasKey('rematch', $filtered);
    }
}
