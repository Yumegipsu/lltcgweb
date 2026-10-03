<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-012 Kotori — Activated Wait self + extra cost → Printemps Live from WR (#232).
 * ActivateAbility previously threw "Ability type not implemented".
 */
final class Issue232KotoriActivatedTest extends TestCase
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
            'name_en' => 'Printemps Live ' . $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => "μ's",
            'subunit' => 'Printemps',
            'score' => 1,
        ];
    }

    private function baseState(array $kotori, array $hand, array $wr): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => $hand,
                    'waiting_room' => $wr,
                    'stage' => [
                        'left' => null,
                        'center' => $kotori,
                        'right' => null,
                    ],
                    'energy_zone' => array_fill(0, 10, ['card_type' => 'エネルギー', 'active' => true]),
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
    }

    public function testActivateOpensCostModeAfterWaitSelf(): void
    {
        $kotori = $this->cardByNo('PL!-pb2-012-R', 'kotori012');
        $hand = [
            ['instance_id' => 'h1', 'card_type' => 'メンバー', 'name_en' => 'A'],
            ['instance_id' => 'h2', 'card_type' => 'メンバー', 'name_en' => 'B'],
        ];
        $state = $this->baseState($kotori, $hand, [$this->printempsLive('plive')]);

        $abIdx = null;
        foreach ($kotori['abilities'] ?? [] as $i => $a) {
            if (($a['type'] ?? '') === 'activated_wait_printemps_live_from_wr') {
                $abIdx = $i;
                break;
            }
        }
        $this->assertNotNull($abIdx);

        $state = actionActivateAbility($state, 'p1', [
            'card_id' => 'kotori012',
            'ability_index' => $abIdx,
        ]);

        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pb2_printemps_cost_mode', $pr['type'] ?? null);
        $this->assertSame(['discard2', 'wait2'], $pr['choices'] ?? null);
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['center']));
        $this->assertTrue(isAbilityUsed($state['players']['p1']['stage']['center'], $abIdx));
    }

    public function testDiscard2AddsPrintempsLiveFromWr(): void
    {
        $kotori = $this->cardByNo('PL!-pb2-012-R', 'kotori012');
        $hand = [
            ['instance_id' => 'h1', 'card_type' => 'メンバー', 'name_en' => 'A'],
            ['instance_id' => 'h2', 'card_type' => 'メンバー', 'name_en' => 'B'],
            ['instance_id' => 'h3', 'card_type' => 'メンバー', 'name_en' => 'C'],
        ];
        $state = $this->baseState($kotori, $hand, [$this->printempsLive('plive')]);
        $abIdx = 1;
        foreach ($kotori['abilities'] ?? [] as $i => $a) {
            if (($a['type'] ?? '') === 'activated_wait_printemps_live_from_wr') {
                $abIdx = $i;
                break;
            }
        }

        $state = actionActivateAbility($state, 'p1', [
            'card_id' => 'kotori012',
            'ability_index' => $abIdx,
        ]);
        $pr = $state['pending_prompt'];
        $state = actionResolvePrompt($state, 'p1', [
            'choice' => 'discard2',
            'discard_ids' => ['h1', 'h2'],
        ]);
        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pick_wr_to_hand', $pr['type'] ?? null);
        $this->assertCount(1, $pr['candidates'] ?? []);
        $this->assertSame('plive', $pr['candidates'][0]['instance_id'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertNotContains('h1', $handIds);
        $this->assertNotContains('h2', $handIds);
        $this->assertContains('h3', $handIds);
    }

    public function testNoWrLiveBlocksActivation(): void
    {
        $kotori = $this->cardByNo('PL!-pb2-012-R', 'kotori012');
        $state = $this->baseState($kotori, [
            ['instance_id' => 'h1', 'card_type' => 'メンバー', 'name_en' => 'A'],
            ['instance_id' => 'h2', 'card_type' => 'メンバー', 'name_en' => 'B'],
        ], []);
        $ab = null;
        foreach ($kotori['abilities'] ?? [] as $a) {
            if (($a['type'] ?? '') === 'activated_wait_printemps_live_from_wr') {
                $ab = $a;
                break;
            }
        }
        $this->assertNotNull(activatedAbilityWrBlockReason($state['players']['p1'], $ab));

        $before = $state;
        $state = actionActivateAbility($state, 'p1', [
            'card_id' => 'kotori012',
            'ability_index' => 1,
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['center']));
        $this->assertSame(
            $before['players']['p1']['stage']['center']['instance_id'],
            $state['players']['p1']['stage']['center']['instance_id']
        );
    }

    public function testPlayCostReduceWaitsDistinctPrintemps(): void
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
                    }, range(0, 14)),
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
    }
}
