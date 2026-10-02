<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-009 Nico — activate 2 Energy when Baton Touched to WR by a μ's Member
 * of cost 15+ (#221). Was authored trigger:auto and checked leaving-card baton
 * history, so the skill never fired.
 */
final class Issue221Pb2009NicoBatonEnergyTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function energyZone(int $active, int $inactive): array
    {
        $out = [];
        for ($i = 0; $i < $active; $i++) {
            $out[] = [
                'instance_id' => 'ae_' . $i,
                'card_type' => 'エネルギー',
                'card_type_en' => 'Energy',
                'active' => true,
            ];
        }
        for ($i = 0; $i < $inactive; $i++) {
            $out[] = [
                'instance_id' => 'ie_' . $i,
                'card_type' => 'エネルギー',
                'card_type_en' => 'Energy',
                'active' => false,
            ];
        }
        return $out;
    }

    private function countActiveEnergy(array $state, string $pid): int
    {
        $n = 0;
        foreach ($state['players'][$pid]['energy_zone'] ?? [] as $e) {
            if ($e['active'] ?? false) {
                $n++;
            }
        }
        return $n;
    }

    public function testCatalogTriggerIsOnLeaveStage(): void
    {
        $nico = $this->cardByNo('PL!-pb2-009-PP', 'nico9');
        $abs = $nico['abilities'] ?? [];
        $this->assertNotEmpty($abs);
        $this->assertSame('on_leave_stage', $abs[0]['trigger'] ?? null);
        $this->assertSame('auto_on_leave_stage_if_baton_min_cost_energy', $abs[0]['type'] ?? null);
    }

    public function testBatonFromCost15MuseActivatesTwoEnergy(): void
    {
        $leaving = $this->cardByNo('PL!-pb2-009-PP', 'nico9');
        $incoming = $this->cardByNo('PL!-bp5-009-R', 'borarara');
        $this->assertSame(9, intval($leaving['cost'] ?? 0));
        $this->assertGreaterThanOrEqual(15, intval($incoming['cost'] ?? 0));

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 4,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$incoming],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $leaving,
                        'center' => null,
                        'right' => null,
                    ],
                    // 7 active before baton; pay 6 → 1/7, then skill flips 2 → 3/7
                    'energy_zone' => $this->energyZone(7, 0),
                    'main_deck' => [
                        [
                            'instance_id' => 'deck_filler_1',
                            'card_type' => 'メンバー',
                            'card_type_en' => 'Member',
                            'name_en' => 'Filler',
                            'cost' => 1,
                            'active' => true,
                        ],
                        [
                            'instance_id' => 'deck_filler_2',
                            'card_type' => 'メンバー',
                            'card_type_en' => 'Member',
                            'name_en' => 'Filler',
                            'cost' => 1,
                            'active' => true,
                        ],
                    ],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => $this->energyZone(3, 0),
                    'main_deck' => [
                        [
                            'instance_id' => 'p2_deck_1',
                            'card_type' => 'メンバー',
                            'card_type_en' => 'Member',
                            'name_en' => 'Filler',
                            'cost' => 1,
                            'active' => true,
                        ],
                    ],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];

        $this->assertSame(7, $this->countActiveEnergy($state, 'p1'));

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => $incoming['instance_id'],
            'slot' => 'left',
            'baton_id' => $leaving['instance_id'],
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('PL!-bp5-009-R', $state['players']['p1']['stage']['left']['card_no'] ?? null);
        $wrNos = array_map(
            static fn($c) => $c['card_no'] ?? '',
            $state['players']['p1']['waiting_room'] ?? []
        );
        $deckNos = array_map(
            static fn($c) => $c['card_no'] ?? '',
            $state['players']['p1']['main_deck'] ?? []
        );
        $stacked = $state['players']['p1']['stage']['left']['stacked_members'] ?? [];
        $this->assertTrue(
            in_array('PL!-pb2-009-PP', $wrNos, true)
            || in_array('PL!-pb2-009-PP', $deckNos, true)
            || in_array('PL!-pb2-009-PP', array_map(static fn($c) => $c['card_no'] ?? '', $stacked), true),
            'Leaving Nico should be in WR (or deck/stack after refresh). wr=' . json_encode($wrNos)
        );
        // 1 remaining after pay + 2 activated from pb2-009 = 3/7
        $this->assertSame(3, $this->countActiveEnergy($state, 'p1'));
        $log = implode("\n", array_map(
            static fn($e) => is_array($e) ? (string)($e['msg'] ?? '') : (string)$e,
            $state['log'] ?? []
        ));
        $this->assertStringContainsString('activated Energy (Baton with high-cost', $log);
    }

    public function testNonMuseCost15DoesNotActivate(): void
    {
        $leaving = $this->cardByNo('PL!-pb2-009-PP', 'nico9');
        $incoming = [
            'instance_id' => 'other15',
            'card_no' => 'PL!TEST-15',
            'name_en' => 'Other Ace',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Liella!',
            'cost' => 15,
            'active' => true,
            'hearts' => [['color' => 'pink', 'count' => 1]],
            'abilities' => [],
        ];

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 4,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$incoming],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $leaving,
                        'center' => null,
                        'right' => null,
                    ],
                    'energy_zone' => $this->energyZone(7, 0),
                    'main_deck' => [
                        [
                            'instance_id' => 'deck_filler_nm',
                            'card_type' => 'メンバー',
                            'card_type_en' => 'Member',
                            'name_en' => 'Filler',
                            'cost' => 1,
                            'active' => true,
                        ],
                    ],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => $this->energyZone(3, 0),
                    'main_deck' => [
                        [
                            'instance_id' => 'p2_deck_nm',
                            'card_type' => 'メンバー',
                            'card_type_en' => 'Member',
                            'name_en' => 'Filler',
                            'cost' => 1,
                            'active' => true,
                        ],
                    ],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => $incoming['instance_id'],
            'slot' => 'left',
            'baton_id' => $leaving['instance_id'],
        ]);

        // Paid 6 for baton; skill must not flip any of the inactive 6.
        $this->assertSame(1, $this->countActiveEnergy($state, 'p1'));
    }
}
