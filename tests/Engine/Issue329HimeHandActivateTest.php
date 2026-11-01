<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Refs #329 — PL!HS-bp6-014 Hime hand Activated ability.
 * Official text: activate while this card is in hand (not "another copy").
 * Engine already resolved hand_discard_named_blade; UI omitted hand skills.
 */
final class Issue329HimeHandActivateTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $c = \tcgCatalogCardByNo($cardNo);
        $this->assertIsArray($c, $cardNo);
        $c['instance_id'] = $instanceId;
        $c['active'] = true;
        mergeCardCatalogFields($c);
        return $c;
    }

    public function testEnglishTextMatchesOfficialInHandOnly(): void
    {
        $hime = $this->cardByNo('PL!HS-bp6-014-R', 'hime');
        $text = (string)($hime['text'] ?? '');
        $this->assertStringContainsString('if this card is in your hand', $text);
        $this->assertStringNotContainsString('another of this card', $text);
        $ab = $hime['abilities'][0] ?? [];
        $this->assertSame('hand_discard_named_blade', $ab['type'] ?? '');
        $this->assertTrue(!empty($ab['from_hand_only']));
    }

    public function testActivateFromHandDrawsAndOffersNamedBlade(): void
    {
        $hime = $this->cardByNo('PL!HS-bp6-014-R', 'hime_hand');
        $rurino = $this->cardByNo('PL!HS-bp6-003-R', 'rurino');
        $this->assertSame('Rurino Osawa', $rurino['name_en'] ?? '');
        // Keep 2+ cards in deck so discarding Hime to WR is not immediately reshuffled
        // by the empty-deck refresh that runs after drawing the last deck card.
        $deck = [];
        for ($i = 1; $i <= 3; $i++) {
            $deck[] = [
                'instance_id' => "deck$i",
                'card_type' => 'メンバー',
                'name_en' => "Deck Filler $i",
            ];
        }
        $state = [
            'room_id' => 'T329',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$hime],
                    'waiting_room' => [],
                    'main_deck' => $deck,
                    'energy_zone' => array_fill(0, 3, ['instance_id' => 'e', 'active' => true]),
                    'energy_deck' => [],
                    'stage' => ['left' => $rurino, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $state = \applyAction($state, 'p1', 'activate_ability', [
            'card_id' => 'hime_hand',
            'ability_index' => 0,
        ]);

        $this->assertNotContains('hime_hand', array_column($state['players']['p1']['hand'], 'instance_id'), 'Hime leaves hand');
        $this->assertContains('hime_hand', array_column($state['players']['p1']['waiting_room'], 'instance_id'));
        $this->assertContains('deck1', array_column($state['players']['p1']['hand'], 'instance_id'), 'drew 1');
        $this->assertSame('pick_named_member_blade', $state['pending_prompt']['type'] ?? null);

        $state = \applyAction($state, 'p1', 'resolve_prompt', ['slot' => 'left']);
        $blade = intval($state['players']['p1']['stage']['left']['live_blade_bonus'] ?? 0);
        $this->assertSame(1, $blade);
    }

    public function testCannotActivateFromStage(): void
    {
        $hime = $this->cardByNo('PL!HS-bp6-014-R', 'hime_stage');
        $state = [
            'room_id' => 'T329b',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'stage' => ['left' => null, 'center' => $hime, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Activate from hand only');
        \applyAction($state, 'p1', 'activate_ability', [
            'card_id' => 'hime_stage',
            'ability_index' => 0,
        ]);
    }
}
