<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class ZoneMovementTest extends TestCase
{
    private function playerWithDeckAndWr(): array {
        $card = static fn(string $id, string $type = 'メンバー'): array => [
            'instance_id' => $id,
            'card_type' => $type,
            'name' => 'Test',
            'name_en' => 'Test',
            'cost' => 1,
        ];
        return [
            'name' => 'Zone Test',
            'hand' => [$card('hand-1'), $card('hand-2')],
            'main_deck' => [$card('deck-1'), $card('deck-2')],
            'waiting_room' => [$card('wr-1')],
            'energy_zone' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
        ];
    }

    public function testDiscardHandCardsByIdsMovesToWaitingRoom(): void {
        $p = $this->playerWithDeckAndWr();
        $moved = discardHandCardsByIds($p, ['hand-1']);
        $this->assertCount(1, $moved);
        $this->assertSame('hand-1', $moved[0]['instance_id']);
        $handIds = array_column($p['hand'], 'instance_id');
        $this->assertNotContains('hand-1', $handIds);
        $wrIds = array_column($p['waiting_room'], 'instance_id');
        $this->assertContains('hand-1', $wrIds);
    }

    public function testRefreshMainDeckFromWaitingRoomWhenDeckEmpty(): void {
        $state = [
            'turn' => 2,
            'players' => [
                'p1' => array_merge($this->playerWithDeckAndWr(), ['main_deck' => []]),
            ],
            'log' => [],
        ];
        $refreshed = refreshMainDeckFromWaitingRoom($state, 'p1');
        $this->assertGreaterThan(0, $refreshed);
        $this->assertNotEmpty($state['players']['p1']['main_deck']);
        $this->assertEmpty($state['players']['p1']['waiting_room']);
    }

    public function testTakeFromMainDeckTopMillsExpectedCount(): void {
        $state = [
            'turn' => 1,
            'players' => ['p1' => $this->playerWithDeckAndWr()],
            'log' => [],
        ];
        $taken = takeFromMainDeckTop($state, 'p1', 2);
        $this->assertCount(2, $taken);
        $this->assertCount(0, $state['players']['p1']['main_deck']);
    }
}
