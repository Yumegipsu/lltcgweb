<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #173 — first player's Yell Blade must stay locked after draw even if
 * the second performer's Live Start Wait reduces current stage Blade.
 */
final class Issue173YellBladeStickyAfterWaitTest extends TestCase
{
    private function member(string $id, int $blade): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => "μ's",
            'name_en' => 'M' . $id,
            'cost' => 3,
            'blade' => $blade,
            'active' => true,
            'hearts' => [['color' => 'pink', 'count' => 1]],
        ];
    }

    public function testYellBladeDrawnSurvivesLaterOpponentWait(): void
    {
        $state = [
            'status' => 'playing',
            'phase' => 'live_performance_first',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'First',
                    'hand' => [],
                    'stage' => [
                        'left' => $this->member('p1l', 2),
                        'center' => $this->member('p1c', 3),
                        'right' => null,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [
                        ['instance_id' => 'y1', 'card_type' => 'メンバー', 'group' => "μ's"],
                        ['instance_id' => 'y2', 'card_type' => 'メンバー', 'group' => "μ's"],
                        ['instance_id' => 'y3', 'card_type' => 'メンバー', 'group' => "μ's"],
                        ['instance_id' => 'y4', 'card_type' => 'メンバー', 'group' => "μ's"],
                        ['instance_id' => 'y5', 'card_type' => 'メンバー', 'group' => "μ's"],
                    ],
                    'live_zone' => [[
                        'instance_id' => 'p1live',
                        'card_type' => 'ライブ',
                        'card_type_en' => 'Live',
                        'score' => 1,
                        'required_hearts' => [['color' => 'any', 'count' => 1]],
                        'abilities' => [],
                    ]],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'Second',
                    'hand' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $this->member('p2c', 1),
                        'right' => null,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $before = \computeYellBladeTotal($state, 'p1');
        $this->assertSame(5, $before);

        [$state, $yellCards, $totalBlade] = \drawYellCardsForPlayer($state, 'p1');
        $this->assertSame(5, $totalBlade);
        $this->assertSame(5, \yellBladeDrawnTotal($state, 'p1'));
        $this->assertSame(5, intval($state['_yell_blade_snapshot']['p1'] ?? 0));
        $this->assertCount(5, $yellCards);

        // Second performer's Live Start Wait after first Yell.
        waitMember($state['players']['p1']['stage']['center'], $state);
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['center']));
        $this->assertSame(2, \computeYellBladeTotal($state, 'p1'), 'Live estimate drops after Wait');
        $this->assertSame(5, \yellBladeDrawnTotal($state, 'p1'), 'Drawn Yell Blade stays locked');
        $this->assertSame(5, intval($state['_yell_blade_snapshot']['p1'] ?? 0));

        // #277: the "performed Live! Blades: N" line must report the Blade the Yell was drawn with, not the
        // post-Wait estimate (zonicalt's 16-card Yell was logged as "Blades: 5").
        $state['players']['p1']['yell_cards'] = $yellCards;
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolvePerformanceHeartCheck($state, 'p1', false);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
        $logs = array_map(static fn($l) => is_array($l) ? (string) ($l['msg'] ?? $l['text'] ?? '') : (string) $l, $state['log']);
        $line = array_values(array_filter($logs, static fn($l) => str_contains($l, 'performed Live! Blades:')));
        $this->assertNotEmpty($line);
        $this->assertStringContainsString('Blades: 5 ', $line[0]);
    }

    /**
     * VIVID WORLD (PL!N-bp4-025-L) Live Start: yelled colored blades and ALL
     * blades pay as blue. Draw and score icons stay. Double-colorless is two blues.
     * Refs #285.
     */
    public function testVividWorldLiveStartPaysYelledBladesAsBlue(): void
    {
        $recolored = \recolorYellBladeHeartsForLive(
            ['pink', 'all', 'draw', 'score', 'all2'],
            'blue'
        );
        $this->assertSame(['blue', 'blue', 'draw', 'score', 'blue', 'blue'], $recolored);

        $base = [
            'status' => 'playing',
            'phase' => 'live_performance_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_modifiers' => [
                'p1' => ['yell_blades_to_blue' => true],
                'p2' => [],
            ],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'Niji',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [[
                        'instance_id' => 'vivid',
                        'card_no' => 'PL!N-bp4-025-L',
                        'card_type' => 'ライブ',
                        'card_type_en' => 'Live',
                        'name_en' => 'VIVID WORLD',
                        'score' => 6,
                        'required_hearts' => [['color' => 'blue', 'count' => 1]],
                        'abilities' => [],
                    ]],
                    'success_lives' => [],
                    'yell_cards' => [[
                        'instance_id' => 'y1',
                        'card_type' => 'メンバー',
                        'card_type_en' => 'Member',
                        'blade_hearts' => ['pink'],
                    ]],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'Opp',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $with = \resolvePerformanceHeartCheck($base, 'p1', false);
            $withoutFlag = $base;
            $withoutFlag['live_modifiers']['p1'] = [];
            $without = \resolvePerformanceHeartCheck($withoutFlag, 'p1', false);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }

        $this->assertTrue($with['live_round_success']['p1'] ?? false);
        $this->assertFalse($without['live_round_success']['p1'] ?? true);
    }
}
