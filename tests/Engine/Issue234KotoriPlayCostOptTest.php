<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use Exception;
use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-012 Kotori — optional Wait 2 distinct Printemps for −2 play cost (#234).
 *
 * Client affordability must treat the discounted cost as reachable; server must
 * apply pb2_wait_slots when the player opts in (covers Energy only enough for
 * the reduced cost).
 */
final class Issue234KotoriPlayCostOptTest extends TestCase
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

    public function testPlayCostOptReducesWhenElevenEnergy(): void
    {
        $kotori = $this->cardByNo('PL!-pb2-012-R', 'kotori_hand');
        $honoka = $this->cardByNo('PL!-bp3-001-R', 'honoka');
        $hanayo = $this->cardByNo('PL!-bp3-008-P', 'hanayo');
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
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $honoka,
                        'center' => null,
                        'right' => $hanayo,
                    ],
                    // 11 Active Energy — enough only WITH the Wait discount (13→11).
                    'energy_zone' => array_map(static function (int $i): array {
                        return [
                            'instance_id' => 'ae_' . $i,
                            'card_type' => 'エネルギー',
                            'card_type_en' => 'Energy',
                            'active' => true,
                        ];
                    }, range(0, 10)),
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

        $full = getEffectiveHandCost($state, 'p1', $kotori);
        $this->assertSame(13, $full);

        [$reduced, $ab] = plMusePb2AdjustHandPlayCost($state, 'p1', $kotori, $full, [
            'wait_slots' => ['left', 'right'],
        ]);
        $this->assertNotNull($ab);
        $this->assertSame(11, $reduced);

        $state = actionPlayMember($state, 'p1', [
            'card_id' => 'kotori_hand',
            'slot' => 'center',
            'pb2_wait_slots' => ['left', 'right'],
        ]);
        $this->assertSame('kotori_hand', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['right']));
        $activeLeft = count(array_filter(
            $state['players']['p1']['energy_zone'] ?? [],
            static fn($e) => !empty($e['active'])
        ));
        $this->assertSame(0, $activeLeft);
    }

    public function testPlayWithoutOptFailsWhenOnlyElevenEnergy(): void
    {
        $kotori = $this->cardByNo('PL!-pb2-012-R', 'kotori_hand');
        $honoka = $this->cardByNo('PL!-bp3-001-R', 'honoka');
        $hanayo = $this->cardByNo('PL!-bp3-008-P', 'hanayo');
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
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $honoka,
                        'center' => null,
                        'right' => $hanayo,
                    ],
                    'energy_zone' => array_map(static function (int $i): array {
                        return [
                            'instance_id' => 'ae_' . $i,
                            'card_type' => 'エネルギー',
                            'card_type_en' => 'Energy',
                            'active' => true,
                        ];
                    }, range(0, 10)),
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

        $this->expectException(Exception::class);
        actionPlayMember($state, 'p1', [
            'card_id' => 'kotori_hand',
            'slot' => 'center',
        ]);
    }
}
