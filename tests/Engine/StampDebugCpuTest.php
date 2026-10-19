<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class StampDebugCpuTest extends TestCase
{
    private function cpuMainPhaseState(): array
    {
        $created = createRoom(['name' => 'Stamp P1', 'deck' => 'nijigasaki']);
        joinRoom([
            'room_id' => $created['room_id'],
            'name' => 'CPU (Easy)',
            'deck' => 'cpu',
            'cpu_difficulty' => 'easy',
            'first_player' => 'p1',
        ]);
        $state = loadGame($created['room_id']);
        $this->assertIsArray($state);
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $this->assertNotSame('finished', $state['status'] ?? '');
        return $state;
    }

    public function testCpuMatchRejectsStampWithoutDebugMode(): void
    {
        $state = $this->cpuMainPhaseState();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('player vs player');
        applyAction($state, 'p1', 'send_stamp', [
            'stamp_id' => 'st_000_010',
            'locale' => 'ja',
        ]);
    }

    public function testCpuMatchRejectsStampEvenInDebugMode(): void
    {
        $state = $this->cpuMainPhaseState();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('player vs player');
        applyAction($state, 'p1', 'send_stamp', [
            'stamp_id' => 'st_000_010',
            'locale' => 'ja',
            'debug_mode' => true,
        ]);
    }

    public function testReplayViewSkipsStampCooldown(): void
    {
        $created = createRoom(['name' => 'Stamp P1', 'deck' => 'nijigasaki']);
        joinRoom([
            'room_id' => $created['room_id'],
            'name' => 'Stamp P2',
            'deck' => 'muse',
            'first_player' => 'p1',
        ]);
        $state = loadGame($created['room_id']);
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $state['mode'] = 'replay_view';
        $state = applyAction($state, 'p1', 'send_stamp', [
            'stamp_id' => 'st_000_010',
            'locale' => 'ja',
        ]);
        $again = applyAction($state, 'p1', 'send_stamp', [
            'stamp_id' => 'st_000_011',
            'locale' => 'ja',
        ]);
        $this->assertSame('st_000_011', $again['stamp_pop']['p1']['id'] ?? '');
        $this->assertSame(2, intval($again['stamp_pop']['p1']['n'] ?? 0));
    }
}
