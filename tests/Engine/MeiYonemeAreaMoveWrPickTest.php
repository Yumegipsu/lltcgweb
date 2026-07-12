<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!SP-bp4-007-R Mei Yoneme — area-move Auto adds a Liella! Live (score ≤3) from WR.
 * Must open pick_wr_to_hand, never auto-first-match.
 */
final class MeiYonemeAreaMoveWrPickTest extends TestCase
{
    public function testAreaMoveOpensWrLivePickWhenMultipleEligible(): void
    {
        $liveA = [
            'instance_id' => 'live_wr_a',
            'card_no' => 'PL!SP-LIVE-A',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'Liella Live A',
            'group' => 'Superstar',
            'score' => 2,
        ];
        $liveB = [
            'instance_id' => 'live_wr_b',
            'card_no' => 'PL!SP-LIVE-B',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'Liella Live B',
            'group' => 'Superstar',
            'score' => 3,
        ];
        $tooHigh = [
            'instance_id' => 'live_wr_high',
            'card_no' => 'PL!SP-LIVE-HIGH',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'Liella Live High',
            'group' => 'Superstar',
            'score' => 4,
        ];
        $mei = [
            'instance_id' => 'mei_1',
            'card_no' => 'PL!SP-bp4-007-R',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Mei Yoneme',
            'group' => 'Superstar',
            'abilities' => [[
                'trigger' => 'auto',
                'type' => 'auto_area_move_wr_live',
                'group' => 'Superstar',
                'max_live_score' => 3,
                'once_per_turn' => true,
            ]],
        ];
        $state = [
            'room_id' => 'MEIWR',
            'status' => 'playing',
            'seq' => 10,
            'turn' => 3,
            'phase' => 'main_first',
            'active_player' => 'p1',
            'first_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'token' => 'tok1',
                    'hand' => [],
                    'waiting_room' => [$liveA, $liveB, $tooHigh],
                    'stage' => ['left' => null, 'center' => $mei, 'right' => null],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
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
                    'energy_deck' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $after = resolveAutoAreaMoveAbilities($state, 'p1', 'mei_1', 'left');

        $this->assertSame('pick_wr_to_hand', $after['pending_prompt']['type'] ?? null);
        $this->assertSame('p1', $after['pending_prompt']['responder'] ?? null);
        $candIds = array_column($after['pending_prompt']['candidates'] ?? [], 'instance_id');
        sort($candIds);
        $this->assertSame(['live_wr_a', 'live_wr_b'], $candIds);
        $this->assertCount(0, $after['players']['p1']['hand']);
        $this->assertCount(3, $after['players']['p1']['waiting_room']);
    }

    public function testAreaMoveOpensWrLivePickEvenWithSingleEligible(): void
    {
        $liveA = [
            'instance_id' => 'live_wr_only',
            'card_no' => 'PL!SP-LIVE-ONLY',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'Liella Live Only',
            'group' => 'Superstar',
            'score' => 1,
        ];
        $mei = [
            'instance_id' => 'mei_1',
            'card_no' => 'PL!SP-bp4-007-R',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Mei Yoneme',
            'group' => 'Superstar',
            'abilities' => [[
                'trigger' => 'auto',
                'type' => 'auto_area_move_wr_live',
                'group' => 'Superstar',
                'max_live_score' => 3,
                'once_per_turn' => true,
            ]],
        ];
        $state = [
            'room_id' => 'MEIWR1',
            'status' => 'playing',
            'seq' => 10,
            'turn' => 3,
            'phase' => 'main_first',
            'active_player' => 'p1',
            'first_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'token' => 'tok1',
                    'hand' => [],
                    'waiting_room' => [$liveA],
                    'stage' => ['left' => null, 'center' => $mei, 'right' => null],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
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
                    'energy_deck' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $after = resolveAutoAreaMoveAbilities($state, 'p1', 'mei_1', 'right');

        $this->assertSame('pick_wr_to_hand', $after['pending_prompt']['type'] ?? null);
        $candIds = array_column($after['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertSame(['live_wr_only'], $candIds);
        $this->assertCount(0, $after['players']['p1']['hand']);
    }
}
