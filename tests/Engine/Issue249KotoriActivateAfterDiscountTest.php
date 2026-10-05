<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-012 Kotori — after Wait-2 play-cost discount, Activated still works via
 * discard-2 when hand has 2+ cards (#249). Wait-2 additional cost needs 2 *other*
 * Active Printemps, which the discount already Waited.
 */
final class Issue249KotoriActivateAfterDiscountTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function printempsLive(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-TEST-LIVE-' . $id,
            'name_en' => 'Printemps Live',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => "μ's",
            'subunit' => 'Printemps',
            'score' => 1,
        ];
    }

    private function abIdx(array $kotori): int
    {
        foreach ($kotori['abilities'] ?? [] as $i => $a) {
            if (($a['type'] ?? '') === 'activated_wait_printemps_live_from_wr') {
                return (int)$i;
            }
        }
        $this->fail('Missing activated ability');
    }

    public function testActivateViaDiscardAfterPlayCostDiscount(): void
    {
        $kotori = $this->cardByNo('PL!-pb2-012-P+', 'kotori');
        $honoka = $this->cardByNo('PL!-bp3-001-R', 'honoka');
        $hanayo = $this->cardByNo('PL!-bp3-008-P', 'hanayo');
        $extra1 = $this->cardByNo('PL!-bp3-002-R', 'extra1');
        $extra2 = $this->cardByNo('PL!-bp3-003-R', 'extra2');

        $energy = [];
        for ($i = 0; $i < 11; $i++) {
            $energy[] = [
                'instance_id' => 'e' . $i,
                'card_type' => 'エネルギー',
                'card_type_en' => 'Energy',
                'active' => true,
            ];
        }

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$kotori, $extra1, $extra2],
                    'waiting_room' => [$this->printempsLive('plive')],
                    'stage' => [
                        'left' => $honoka,
                        'center' => null,
                        'right' => $hanayo,
                    ],
                    'energy_zone' => $energy,
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $state = actionPlayMember($state, 'p1', [
            'card_id' => 'kotori',
            'slot' => 'center',
            'pb2_wait_slots' => ['left', 'right'],
        ]);

        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['right']));
        $this->assertSame(2, count($state['players']['p1']['hand']));

        $center = $state['players']['p1']['stage']['center'];
        $idx = $this->abIdx($center);
        $this->assertTrue(plMusePb2KotoriCanPayExtraCost(
            $state['players']['p1'],
            $center['abilities'][$idx],
            'kotori'
        ));

        $state = actionActivateAbility($state, 'p1', [
            'card_id' => 'kotori',
            'ability_index' => $idx,
        ]);
        $this->assertSame('pb2_printemps_cost_mode', $state['pending_prompt']['type'] ?? null);
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['center']));

        $state = actionResolvePrompt($state, 'p1', [
            'choice' => 'discard2',
            'discard_ids' => ['extra1', 'extra2'],
        ]);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame([], $state['players']['p1']['hand']);
    }

    public function testCannotActivateWait2PathAfterDiscountWithEmptyHand(): void
    {
        $kotori = $this->cardByNo('PL!-pb2-012-P+', 'kotori');
        $honoka = $this->cardByNo('PL!-bp3-001-R', 'honoka');
        $hanayo = $this->cardByNo('PL!-bp3-008-P', 'hanayo');

        $energy = [];
        for ($i = 0; $i < 11; $i++) {
            $energy[] = [
                'instance_id' => 'e' . $i,
                'card_type' => 'エネルギー',
                'card_type_en' => 'Energy',
                'active' => true,
            ];
        }

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$kotori],
                    'waiting_room' => [$this->printempsLive('plive')],
                    'stage' => [
                        'left' => $honoka,
                        'center' => null,
                        'right' => $hanayo,
                    ],
                    'energy_zone' => $energy,
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $state = actionPlayMember($state, 'p1', [
            'card_id' => 'kotori',
            'slot' => 'center',
            'pb2_wait_slots' => ['left', 'right'],
        ]);

        $center = $state['players']['p1']['stage']['center'];
        $idx = $this->abIdx($center);
        $this->assertFalse(plMusePb2KotoriCanPayExtraCost(
            $state['players']['p1'],
            $center['abilities'][$idx],
            'kotori'
        ));

        $this->expectException(\Exception::class);
        actionActivateAbility($state, 'p1', [
            'card_id' => 'kotori',
            'ability_index' => $idx,
        ]);
    }
}
