<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!N-pb1-011-R Mia Taylor — optional stack Energy, pick Nijigasaki Live from WR. */
final class MiaTaylorWrPickTest extends TestCase
{
    public function testOptionalStackEnergyAddWrLivePromptsWhenMultipleLivesInWr(): void
    {
        $liveA = [
            'instance_id' => 'live_wr_a',
            'card_no' => 'PL!N-LIVE-A',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'Niji Live A',
            'group' => 'Nijigasaki',
        ];
        $liveB = [
            'instance_id' => 'live_wr_b',
            'card_no' => 'PL!N-LIVE-B',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'Niji Live B',
            'group' => 'Nijigasaki',
        ];
        $mia = [
            'instance_id' => 'mia_1',
            'card_no' => 'PL!N-pb1-011-R',
            'card_type' => 'メンバー',
            'name_en' => 'Mia Taylor',
            'group' => 'Nijigasaki',
            'abilities' => [[
                'trigger' => 'activated',
                'type' => 'optional_stack_energy_add_wr_live',
                'energy' => 1,
                'group' => 'Nijigasaki',
                'once_per_turn' => true,
            ]],
        ];
        $state = [
            'room_id' => 'MIATAYLOR',
            'status' => 'playing',
            'seq' => 10,
            'turn' => 3,
            'phase' => 'main_first',
            'active_player' => 'p1',
            'first_player' => 'p1',
            'log' => [],
            'pending_prompt' => [
                'type' => 'optional_stack_energy_add_wr_live',
                'owner' => 'p1',
                'responder' => 'p1',
                'source_id' => 'mia_1',
                'source_name' => 'Mia Taylor',
                'ability' => [
                    'type' => 'optional_stack_energy_add_wr_live',
                    'energy' => 1,
                    'group' => 'Nijigasaki',
                ],
                'choices' => ['yes', 'no'],
            ],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'token' => 'tok1',
                    'hand' => [],
                    'waiting_room' => [$liveA, $liveB],
                    'stage' => ['left' => null, 'center' => $mia, 'right' => null],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [['instance_id' => 'e1', 'active' => true]],
                    'energy_deck' => [['instance_id' => 'ed1']],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'token' => 'tok2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                ],
            ],
        ];

        $after = nijiHandlePrompt($state, 'optional_stack_energy_add_wr_live', $state['pending_prompt'], 'yes', []);

        $this->assertSame('pick_wr_to_hand', $after['pending_prompt']['type'] ?? null);
        $this->assertCount(2, $after['pending_prompt']['candidates'] ?? []);

        $after = actionResolvePrompt($after, 'p1', ['card_id' => 'live_wr_b']);

        $this->assertNull($after['pending_prompt'] ?? null);
        $handIds = array_column($after['players']['p1']['hand'], 'instance_id');
        $this->assertContains('live_wr_b', $handIds);
        $wrIds = array_column($after['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('live_wr_a', $wrIds);
        $this->assertNotContains('live_wr_b', $wrIds);
    }
}
