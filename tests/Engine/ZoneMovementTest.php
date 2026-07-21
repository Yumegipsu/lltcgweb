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

    public function testDrawLastCardRefreshesDeckImmediately(): void {
        $card = static fn(string $id): array => [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'name' => 'Test',
            'name_en' => 'Test',
            'cost' => 1,
        ];
        $state = [
            'turn' => 3,
            'players' => [
                'p1' => [
                    'name' => 'Issue53',
                    'hand' => [],
                    'main_deck' => [$card('last-deck')],
                    'waiting_room' => [$card('wr-a'), $card('wr-b')],
                    'energy_zone' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
            'log' => [],
        ];
        $drawn = drawCardsForPlayer($state, 'p1', 1);
        $this->assertSame(1, $drawn);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('last-deck', $handIds);
        // Deck must already be refreshed — not left empty until Live Set.
        $this->assertNotEmpty($state['players']['p1']['main_deck']);
        $this->assertEmpty($state['players']['p1']['waiting_room']);
        $deckIds = array_column($state['players']['p1']['main_deck'], 'instance_id');
        $this->assertContains('wr-a', $deckIds);
        $this->assertContains('wr-b', $deckIds);
        $this->assertTrue(
            (bool) preg_match('/Deck refresh:/', implode("\n", array_column($state['log'], 'msg')))
        );
    }

    public function testRefreshEmptyMainDecksSafetyNet(): void {
        $card = static fn(string $id): array => [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'name' => 'Test',
            'name_en' => 'Test',
            'cost' => 1,
        ];
        $state = [
            'turn' => 1,
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'hand' => [],
                    'main_deck' => [],
                    'waiting_room' => [$card('wr-1')],
                    'energy_zone' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
                'p2' => [
                    'name' => 'P2',
                    'hand' => [],
                    'main_deck' => [$card('still-there')],
                    'waiting_room' => [$card('wr-ignored')],
                    'energy_zone' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
            'log' => [],
        ];
        $state = refreshEmptyMainDecks($state);
        $this->assertNotEmpty($state['players']['p1']['main_deck']);
        $this->assertEmpty($state['players']['p1']['waiting_room']);
        $this->assertCount(1, $state['players']['p2']['main_deck']);
        $this->assertCount(1, $state['players']['p2']['waiting_room']);
    }

    public function testDeckRefreshDefersWhileWrPickPromptOpen(): void {
        $card = static fn(string $id, string $type = 'ライブ'): array => [
            'instance_id' => $id,
            'card_type' => $type,
            'card_type_en' => $type === 'ライブ' ? 'Live' : 'Member',
            'name' => 'Test',
            'name_en' => 'Test Live',
            'group' => 'Hasunosora',
            'subunit' => 'Cerasus',
            'cost' => 1,
        ];
        $liveA = $card('live-a');
        $liveB = $card('live-b');
        $state = [
            'turn' => 3,
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 10,
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'hand' => [],
                    'main_deck' => [], // empty → would refresh
                    'waiting_room' => [$liveA, $liveB],
                    'energy_zone' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
            'pending_prompt' => [
                'type' => 'optional_discard_mill_add_wr_subunit_live',
                'step' => 'pick_wr_live',
                'owner' => 'p1',
                'responder' => 'p1',
                'source_name' => 'Ginko Momose',
                'candidates' => [
                    ['instance_id' => 'live-a', 'card_type' => 'ライブ', 'name_en' => 'Test Live'],
                    ['instance_id' => 'live-b', 'card_type' => 'ライブ', 'name_en' => 'Test Live'],
                ],
                'prompt' => 'Choose 1 matching Live card from your Waiting Room to add to your hand.',
            ],
            'log' => [],
        ];

        $refreshed = refreshMainDeckFromWaitingRoom($state, 'p1');
        $this->assertSame(0, $refreshed, 'WR pick must block deck refresh');
        $this->assertEmpty($state['players']['p1']['main_deck']);
        $this->assertCount(2, $state['players']['p1']['waiting_room']);
        $this->assertSame('pick_wr_live', $state['pending_prompt']['step'] ?? null);

        // After the pick clears, refresh may proceed.
        unset($state['pending_prompt']);
        $refreshed = refreshMainDeckFromWaitingRoom($state, 'p1');
        $this->assertSame(2, $refreshed);
        $this->assertCount(2, $state['players']['p1']['main_deck']);
        $this->assertEmpty($state['players']['p1']['waiting_room']);
    }

    public function testPickWrToHandAlsoBlocksDeckRefresh(): void {
        $mem = [
            'instance_id' => 'wr-mem',
            'card_type' => 'メンバー',
            'name_en' => 'Member',
            'group' => 'μ\'s',
            'cost' => 2,
        ];
        $state = [
            'turn' => 1,
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'hand' => [],
                    'main_deck' => [],
                    'waiting_room' => [$mem],
                    'energy_zone' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
            'pending_prompt' => [
                'type' => 'pick_wr_to_hand',
                'owner' => 'p1',
                'responder' => 'p1',
                'wr_pick_cfg' => ['group' => 'μ\'s', 'filter' => 'member'],
                'candidates' => [['instance_id' => 'wr-mem', 'card_type' => 'メンバー']],
            ],
            'log' => [],
        ];
        $this->assertSame(0, refreshMainDeckFromWaitingRoom($state, 'p1'));
        $this->assertCount(1, $state['players']['p1']['waiting_room']);
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
