<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #195: Kotori Live Success play-from-under must not lock the slot against
 * overplay/baton (member was already on Stage under the host).
 */
final class Issue195KotoriOverplayAfterLiveSuccessTest extends TestCase
{
    private function museMember(string $id, int $cost = 2): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'TEST-' . $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'μ\'s Member',
            'name' => 'μ\'s Member',
            'group' => "μ's",
            'cost' => $cost,
            'blade' => 1,
            'hearts' => [['color' => 'pink', 'count' => 1]],
            'abilities' => [],
            'active' => true,
        ];
    }

    private function energy(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['instance_id' => "e$i", 'card_type' => 'エネルギー', 'active' => true];
        }
        return $out;
    }

    private function stateAfterKotoriPlacesFromUnder(): array
    {
        $stacked = $this->museMember('under_m', 2);
        $kotori = [
            'instance_id' => 'kotori',
            'card_no' => 'PL!-bp6-003-P',
            'name_en' => 'Kotori Minami',
            'card_type' => 'メンバー',
            'group' => "μ's",
            'cost' => 15,
            'abilities' => [[
                'trigger' => 'live_success',
                'type' => 'play_stacked_member_from_under',
                'group' => "μ's",
                'max_cost' => 2,
            ]],
            'active' => true,
            'stacked_members' => [$stacked],
        ];
        $state = [
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 2,
            'active_player' => 'p1',
            'first_player' => 'p1',
            'status' => 'playing',
            'log' => [],
            // Keep finishPromptEffects from advancing the whole Live round.
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $kotori,
                        'right' => null,
                    ],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => $this->energy(5),
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = plMuseGapResolveEffect($state, 'p1', $kotori, $kotori['abilities'][0], ['slot' => 'center']);
            $state = actionResolvePrompt($state, 'p1', [
                'choice' => 'yes',
                'card_id' => 'under_m',
                'slot' => 'left',
            ]);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
        return $state;
    }

    public function testFromUnderEnterDoesNotMarkEnteredThisTurn(): void
    {
        $state = $this->stateAfterKotoriPlacesFromUnder();
        $placed = $state['players']['p1']['stage']['left'] ?? null;
        $this->assertNotNull($placed);
        $this->assertSame('under_m', $placed['instance_id'] ?? null);
        $this->assertTrue(!empty($placed['entered_from_under']));
        $this->assertFalse(stageMemberEnteredThisTurn($placed, $state));
    }

    public function testCanOverplaySameTurnNumberAfterFromUnderEnter(): void
    {
        $state = $this->stateAfterKotoriPlacesFromUnder();
        $state['phase'] = 'main_first';
        $state['active_player'] = 'p1';
        $state['turn'] = 2;
        $state['players']['p1']['hand'][] = $this->museMember('big_m', 5);

        $state = actionPlayMember($state, 'p1', [
            'card_id' => 'big_m',
            'slot' => 'left',
        ]);

        $this->assertSame('big_m', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertNotSame('under_m', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
    }

    public function testCanBatonSameTurnNumberAfterFromUnderEnter(): void
    {
        $state = $this->stateAfterKotoriPlacesFromUnder();
        $state['phase'] = 'main_first';
        $state['active_player'] = 'p1';
        $state['turn'] = 2;
        $state['players']['p1']['hand'][] = $this->museMember('big_m', 5);

        $state = actionPlayMember($state, 'p1', [
            'card_id' => 'big_m',
            'slot' => 'left',
            'baton_id' => 'under_m',
        ]);

        $this->assertSame('big_m', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertTrue(!empty($state['players']['p1']['stage']['left']['entered_via_baton']));
    }
}
