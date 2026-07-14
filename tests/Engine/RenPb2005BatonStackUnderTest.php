<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!SP-pb2-005 Ren — Baton Touch stacks the replaced Liella Member under Ren. */
final class RenPb2005BatonStackUnderTest extends TestCase
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

    private function energy(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'instance_id' => 'en_' . $i,
                'card_type' => 'エネルギー',
                'active' => true,
            ];
        }
        return $out;
    }

    private function baseState(array $keke, array $ren): array
    {
        return [
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
                    'hand' => [$ren],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $keke,
                        'right' => null,
                    ],
                    'energy_zone' => $this->energy(25),
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];
    }

    public function testBatonStacksKekeUnderRenEvenAfterEmptyDeckRefresh(): void
    {
        $keke = $this->cardByNo('PL!SP-pb2-002-PP', 'keke_stage');
        $keke['entered_turn'] = 1;
        $ren = $this->cardByNo('PL!SP-pb2-005-PP', 'ren_hand');

        $state = $this->baseState($keke, $ren);
        // Empty main deck reproduces deck-refresh swallowing the Baton Target out of WR.
        $this->assertSame([], $state['players']['p1']['main_deck']);

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'ren_hand',
            'slot' => 'center',
            'baton_id' => 'keke_stage',
        ]);

        $renStage = $state['players']['p1']['stage']['center'] ?? null;
        $this->assertSame('ren_hand', $renStage['instance_id'] ?? null);
        $this->assertTrue(!empty($renStage['entered_via_baton']));
        $this->assertSame('keke_stage', $renStage['stacked_members'][0]['instance_id'] ?? null);
        $this->assertFalse(in_array(
            'keke_stage',
            array_column($state['players']['p1']['waiting_room'] ?? [], 'instance_id'),
            true
        ));
        $this->assertFalse(in_array(
            'keke_stage',
            array_column($state['players']['p1']['main_deck'] ?? [], 'instance_id'),
            true
        ));

        $inherited = \getAbilitiesByTrigger($renStage, 'activated');
        $this->assertNotEmpty($inherited);
        $this->assertSame(
            'activated_discard_liella_choose_energy_or_hearts',
            $inherited[0]['type'] ?? null
        );

        $state['players']['p1']['hand'][] = [
            'instance_id' => 'hand_liella',
            'card_type' => 'メンバー',
            'name_en' => 'Hand Liella',
            'group' => 'Superstar',
            'cost' => 2,
        ];
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'ren_hand',
            'ability_index' => 'inherit:keke_stage:0',
        ]);
        $this->assertSame('spbp2_discard_liella_choice', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('inherit:keke_stage:0', $state['pending_prompt']['ability_index'] ?? null);
    }

    public function testBatonStacksFromWaitingRoomWhenDeckNonEmpty(): void
    {
        $keke = $this->cardByNo('PL!SP-pb2-002-PP', 'keke_stage');
        $keke['entered_turn'] = 1;
        $ren = $this->cardByNo('PL!SP-pb2-005-PP', 'ren_hand');
        $state = $this->baseState($keke, $ren);
        $state['players']['p1']['main_deck'] = [[
            'instance_id' => 'deck_pad',
            'card_type' => 'メンバー',
            'group' => 'Superstar',
            'cost' => 2,
        ]];

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'ren_hand',
            'slot' => 'center',
            'baton_id' => 'keke_stage',
        ]);

        $renStage = $state['players']['p1']['stage']['center'] ?? null;
        $this->assertSame('keke_stage', $renStage['stacked_members'][0]['instance_id'] ?? null);
        $this->assertSame([], $state['players']['p1']['waiting_room']);
    }
}
