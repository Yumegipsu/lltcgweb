<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Regression for GitHub issue #176 —
 * PL!-bp3-026-L Oh, Love & Peace! Live Start must let the player choose which
 * Stage Member gains +3 Blade (not auto-grant leftmost).
 */
final class Issue176LovePeaceBladePickTest extends TestCase
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

    private function fillerMember(string $id, string $slotName): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'TEST-' . $slotName,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name' => $slotName,
            'name_en' => $slotName,
            'group' => "μ's",
            'cost' => 3,
            'blade' => 1,
            'active' => true,
            'hearts' => [['color' => 'pink', 'count' => 1]],
            'abilities' => [],
        ];
    }

    private function handCards(int $count, string $prefix): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'instance_id' => $prefix . $i,
                'card_type' => 'エネルギー',
            ];
        }
        return $out;
    }

    public function testLovePeaceLiveStartPromptsBladeTargetNotLeftmost(): void
    {
        $live = $this->cardByNo('PL!-bp3-026-L', 'love_peace');
        $left = $this->fillerMember('m_left', 'Left');
        $center = $this->fillerMember('m_center', 'Center');
        $right = $this->fillerMember('m_right', 'Right');
        // Wait the leftmost Member — auto-grant would waste blade here (#176).
        $left['active'] = false;

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            '_live_start_perf_pid' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => $this->handCards(4, 'hand'),
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $left,
                        'center' => $center,
                        'right' => $right,
                    ],
                    'energy_zone' => [],
                    'main_deck' => array_fill(0, 10, ['instance_id' => 'deck', 'card_type' => 'エネルギー']),
                    'success_lives' => [],
                    'live_zone' => [$live],
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

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
            $this->assertSame(2, intval($state['pending_prompt']['discard_count'] ?? 0));

            $ids = ['hand0', 'hand1'];
            $state = \applyAction($state, 'p1', 'resolve_prompt', [
                'choice' => 'yes',
                'discard_ids' => $ids,
            ]);

            $this->assertSame(
                'pick_member_blade_bonus',
                $state['pending_prompt']['type'] ?? null,
                'Must ask which Stage Member gets +3 Blade'
            );
            $this->assertSame(0, intval($state['players']['p1']['stage']['left']['live_blade_bonus'] ?? 0));
            $this->assertSame(0, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
            $this->assertSame(0, intval($state['players']['p1']['stage']['right']['live_blade_bonus'] ?? 0));

            $state = \applyAction($state, 'p1', 'resolve_prompt', ['slot' => 'right']);
            $this->assertSame(0, intval($state['players']['p1']['stage']['left']['live_blade_bonus'] ?? 0));
            $this->assertSame(0, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
            $this->assertSame(3, intval($state['players']['p1']['stage']['right']['live_blade_bonus'] ?? 0));
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testNightingaleLoveSongAlsoPromptsChoice(): void
    {
        $live = $this->cardByNo('PL!-bp4-024-L', 'nightingale');
        $left = $this->fillerMember('n_left', 'Left');
        $right = $this->fillerMember('n_right', 'Right');

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            '_live_start_perf_pid' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $left,
                        'center' => null,
                        'right' => $right,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [$live],
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

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame('pick_member_blade_bonus', $state['pending_prompt']['type'] ?? null);
            $state = \applyAction($state, 'p1', 'resolve_prompt', ['slot' => 'right']);
            $this->assertSame(0, intval($state['players']['p1']['stage']['left']['live_blade_bonus'] ?? 0));
            $this->assertSame(1, intval($state['players']['p1']['stage']['right']['live_blade_bonus'] ?? 0));
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
