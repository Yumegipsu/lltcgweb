<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Regression: Nijigasaki Wait activate / Live Start / Live Success bugs
 * (Emma PB1-008, Cara Tesoro PB1-037, La Bella Patria BP3-027, Emma BP3-008).
 */
final class NijiWaitActivateAndLiveBugsTest extends TestCase
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

    private function basePlayers(): array
    {
        return [
            'p1' => [
                'id' => 'p1',
                'name' => 'P1',
                'hand' => [],
                'waiting_room' => [],
                'stage' => ['left' => null, 'center' => null, 'right' => null],
                'energy_zone' => [],
                'energy_deck' => [],
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
                'energy_deck' => [],
                'main_deck' => [],
                'success_lives' => [],
                'live_zone' => [],
            ],
        ];
    }

    public function testEmmaPb1008HandCostReducedWithWaitNijigasaki(): void
    {
        $emma = $this->cardByNo('PL!N-pb1-008-R', 'emma_hand');
        $waiter = $this->cardByNo('PL!N-bp3-008-SEC', 'wait_niji');
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'players' => $this->basePlayers(),
        ];
        waitMember($waiter, $state);
        $state['players']['p1']['hand'] = [$emma];
        $state['players']['p1']['stage']['left'] = $waiter;

        $printed = intval($emma['cost'] ?? 0);
        $this->assertSame($printed - 2, getEffectiveHandCost($state, 'p1', $emma));
    }

    public function testEmmaPb1008OnEnterActivatesWaitMember(): void
    {
        $emma = $this->cardByNo('PL!N-pb1-008-R', 'emma');
        $waiter = $this->cardByNo('PL!N-bp3-009-P', 'wait_rina');
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'players' => $this->basePlayers(),
        ];
        waitMember($waiter, $state);
        $this->assertTrue(memberIsInWait($waiter));
        $state['players']['p1']['stage']['center'] = $emma;
        $state['players']['p1']['stage']['left'] = $waiter;

        $onEnter = null;
        foreach ($emma['abilities'] as $ab) {
            if (($ab['trigger'] ?? '') === 'on_enter') {
                $onEnter = $ab;
                break;
            }
        }
        $this->assertNotNull($onEnter);
        $state = resolveAbilityEffect($state, 'p1', $emma, $onEnter, ['phase' => 'on_enter']);
        $this->assertSame('player_choice', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'activate_member']);
        $left = $state['players']['p1']['stage']['left'];
        $this->assertFalse(memberIsInWait($left));
        $this->assertTrue(!empty($state['players']['p1']['_niji_turn_flags']['activated_wait_member']));
    }

    public function testCaraTesoroLiveStartScorePlusTwo(): void
    {
        $live = $this->cardByNo('PL!N-pb1-037-L', 'cara');
        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['_niji_turn_flags'] = [
            'activated_wait_energy' => true,
            'activated_wait_member' => true,
        ];

        $baseScore = intval($live['score'] ?? 0);
        $ab = $live['abilities'][0];
        $state = resolveAbilityEffect($state, 'p1', $live, $ab, ['phase' => 'live_start']);
        $scored = $state['players']['p1']['live_zone'][0];
        $this->assertSame($baseScore + 2, intval($scored['score'] ?? 0));
    }

    public function testCaraTesoroLiveStartScorePlusOneEnergyOnly(): void
    {
        $live = $this->cardByNo('PL!N-pb1-037-L', 'cara');
        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['_niji_turn_flags'] = [
            'activated_wait_energy' => true,
        ];

        $baseScore = intval($live['score'] ?? 0);
        $state = resolveAbilityEffect($state, 'p1', $live, $live['abilities'][0], ['phase' => 'live_start']);
        $scored = $state['players']['p1']['live_zone'][0];
        $this->assertSame($baseScore + 1, intval($scored['score'] ?? 0));
    }

    public function testCaraTesoroTracksNijiActivateEnergy(): void
    {
        $emma = $this->cardByNo('PL!N-pb1-008-R', 'emma');
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['energy_zone'] = [
            ['instance_id' => 'e1', 'active' => false],
            ['instance_id' => 'e2', 'active' => false],
        ];

        $state = resolveAbilityEffect($state, 'p1', $emma, [
            'type' => 'activate_energy',
            'count' => 2,
        ], ['phase' => 'choice']);

        $this->assertTrue(!empty($state['players']['p1']['_niji_turn_flags']['activated_wait_energy']));
        $this->assertTrue($state['players']['p1']['energy_zone'][0]['active']);
        $this->assertTrue($state['players']['p1']['energy_zone'][1]['active']);
    }

    public function testKanataWaitSelfActivateEnergyTracksForCaraTesoro(): void
    {
        $kanata = $this->cardByNo('PL!N-pb1-006-R', 'kanata1');
        $live = $this->cardByNo('PL!N-pb1-037-L', 'cara');
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 3,
            'active_player' => 'p1',
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['stage']['left'] = $kanata;
        $state['players']['p1']['energy_zone'] = [
            ['instance_id' => 'e1', 'active' => false],
            ['instance_id' => 'e2', 'active' => true],
        ];

        $state = applyAction($state, 'p1', 'activate_ability', [
            'card_id' => 'kanata1',
            'ability_index' => 0,
        ]);
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertTrue(
            !empty($state['players']['p1']['_niji_turn_flags']['activated_wait_energy']),
            'Kanata Wait→activate Energy must count for Cara Tesoro'
        );

        // Also activated a Wait Member later the same turn → Cara scores +2.
        $state['players']['p1']['_niji_turn_flags']['activated_wait_member'] = true;
        $state['players']['p1']['live_zone'] = [$live];
        $baseScore = intval($live['score'] ?? 0);
        $state = resolveAbilityEffect($state, 'p1', $live, $live['abilities'][0], ['phase' => 'live_start']);
        $scored = $state['players']['p1']['live_zone'][0];
        $this->assertSame($baseScore + 2, intval($scored['score'] ?? 0));
    }

    public function testLaBellaPatriaLiveSuccessPutsEnergyInWaitOnGreenExcess(): void
    {
        $live = $this->cardByNo('PL!N-bp3-027-L', 'patria');
        $niji = $this->cardByNo('PL!N-bp3-008-SEC', 'emma_stage');
        $energyCard = ['instance_id' => 'ed1', 'card_type' => 'エネルギー', 'active' => true];

        $state = [
            'status' => 'playing',
            'phase' => 'live_performance',
            'seq' => 1,
            'turn' => 4,
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['stage']['center'] = $niji;
        $state['players']['p1']['energy_deck'] = [$energyCard];
        $state['players']['p1']['energy_zone'] = [];

        $state = resolveAbilityEffect($state, 'p1', $live, $live['abilities'][0], [
            'phase' => 'live_success',
            'excess_hearts' => 1,
            'excess_heart_colors' => ['green'],
        ]);

        $this->assertCount(1, $state['players']['p1']['energy_zone']);
        $this->assertFalse($state['players']['p1']['energy_zone'][0]['active'] ?? true);
        $this->assertEmpty($state['players']['p1']['energy_deck']);
    }

    public function testEmmaBp3008LiveStartActivatesOtherWaitMember(): void
    {
        $emma = $this->cardByNo('PL!N-bp3-008-SEC', 'emma_sec');
        $waiter = $this->cardByNo('PL!N-bp3-009-P', 'wait_rina');
        $handA = $this->cardByNo('PL!N-bp3-027-L', 'h1');
        $handB = $this->cardByNo('PL!N-pb1-037-L', 'h2');

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'players' => $this->basePlayers(),
        ];
        waitMember($waiter, $state);
        $state['players']['p1']['stage']['center'] = $emma;
        $state['players']['p1']['stage']['left'] = $waiter;
        $state['players']['p1']['hand'] = [$handA, $handB];

        $liveStart = null;
        foreach ($emma['abilities'] as $ab) {
            if (($ab['type'] ?? '') === 'optional_discard_activate_wait_hearts') {
                $liveStart = $ab;
                break;
            }
        }
        $this->assertNotNull($liveStart);
        $state = resolveAbilityEffect($state, 'p1', $emma, $liveStart, ['phase' => 'live_start']);
        $this->assertSame('optional_discard_activate_wait_hearts', $state['pending_prompt']['type'] ?? null);

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \actionResolvePrompt($state, 'p1', [
                'choice' => 'yes',
                'discard_ids' => ['h1', 'h2'],
            ]);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }

        $left = $state['players']['p1']['stage']['left'];
        $this->assertFalse(memberIsInWait($left));
        $this->assertTrue(!empty($state['players']['p1']['_niji_turn_flags']['activated_wait_member']));
        $this->assertCount(2, $state['players']['p1']['waiting_room']);
    }

    public function testEmmaBp3008LiveStartPromptsStageWaitMembersNotHand(): void
    {
        $emma = $this->cardByNo('PL!N-bp3-008-SEC', 'emma_sec');
        $leftWait = $this->cardByNo('PL!N-bp3-009-P', 'wait_rina');
        $rightWait = $this->cardByNo('PL!N-pb1-011-R', 'wait_mia');
        $handA = $this->cardByNo('PL!N-bp3-027-L', 'h1');
        $handB = $this->cardByNo('PL!N-pb1-037-L', 'h2');

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'players' => $this->basePlayers(),
        ];
        waitMember($leftWait, $state);
        waitMember($rightWait, $state);
        $state['players']['p1']['stage']['center'] = $emma;
        $state['players']['p1']['stage']['left'] = $leftWait;
        $state['players']['p1']['stage']['right'] = $rightWait;
        $state['players']['p1']['hand'] = [$handA, $handB, $emma];

        $liveStart = null;
        foreach ($emma['abilities'] as $ab) {
            if (($ab['type'] ?? '') === 'optional_discard_activate_wait_hearts') {
                $liveStart = $ab;
                break;
            }
        }
        $this->assertNotNull($liveStart);
        $state = resolveAbilityEffect($state, 'p1', $emma, $liveStart, ['phase' => 'live_start']);

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \actionResolvePrompt($state, 'p1', [
                'choice' => 'yes',
                'discard_ids' => ['h1', 'h2'],
            ]);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }

        $prompt = $state['pending_prompt'] ?? [];
        $this->assertSame('pick_wait', $prompt['step'] ?? null);
        $slots = array_column($prompt['candidates'] ?? [], 'slot');
        sort($slots);
        $this->assertSame(['left', 'right'], $slots);
        foreach ($prompt['candidates'] ?? [] as $cand) {
            $this->assertNotSame('emma_sec', $cand['instance_id'] ?? null);
            $this->assertNotSame('h1', $cand['instance_id'] ?? null);
        }

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \actionResolvePrompt($state, 'p1', ['slot' => 'right']);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['right']));
    }

    public function testEmmaBp3008ActivatedWaitsActiveMemberNotAlreadyWaiting(): void
    {
        $emma = $this->cardByNo('PL!N-bp3-008-SEC', 'emma_sec');
        $kanata = $this->cardByNo('PL!N-pb1-006-R', 'kanata1');
        $mia = $this->cardByNo('PL!N-pb1-011-R', 'mia1');
        $mia['abilities'] = [];

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 3,
            'active_player' => 'p1',
            'first_player' => 'p1',
            'players' => $this->basePlayers(),
        ];
        waitMember($kanata, $state);
        $state['players']['p1']['stage']['center'] = $emma;
        $state['players']['p1']['stage']['left'] = $kanata;
        $state['players']['p1']['stage']['right'] = $mia;
        $state['players']['p1']['main_deck'] = [
            ['instance_id' => 'draw1', 'card_type' => 'メンバー', 'name_en' => 'Draw'],
        ];
        $handBefore = count($state['players']['p1']['hand'] ?? []);

        $state = applyAction($state, 'p1', 'activate_ability', [
            'card_id' => 'emma_sec',
            'ability_index' => 0,
        ]);

        // Only Mia is Active — auto-resolve without a pick prompt.
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['right']), 'Mia must be put into Wait');
        $this->assertSame($handBefore + 1, count($state['players']['p1']['hand']));
    }

    public function testEmmaBp3008ActivatedCannotTargetAlreadyWaitingWhenOnlyWaitTargets(): void
    {
        $emma = $this->cardByNo('PL!N-bp3-008-SEC', 'emma_sec');
        $kanata = $this->cardByNo('PL!N-pb1-006-R', 'kanata1');
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 3,
            'active_player' => 'p1',
            'first_player' => 'p1',
            'players' => $this->basePlayers(),
        ];
        waitMember($kanata, $state);
        $state['players']['p1']['stage']['center'] = $emma;
        $state['players']['p1']['stage']['left'] = $kanata;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/No other Active/');
        applyAction($state, 'p1', 'activate_ability', [
            'card_id' => 'emma_sec',
            'ability_index' => 0,
        ]);
    }

    public function testEmmaBp3008ActivatedPromptsWhenMultipleActiveTargets(): void
    {
        $emma = $this->cardByNo('PL!N-bp3-008-SEC', 'emma_sec');
        $a = $this->cardByNo('PL!N-bp3-009-P', 'rina1');
        $b = $this->cardByNo('PL!N-pb1-011-R', 'mia1');
        $b['abilities'] = [];

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 3,
            'active_player' => 'p1',
            'first_player' => 'p1',
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['stage']['center'] = $emma;
        $state['players']['p1']['stage']['left'] = $a;
        $state['players']['p1']['stage']['right'] = $b;
        $state['players']['p1']['main_deck'] = [
            ['instance_id' => 'draw1', 'card_type' => 'メンバー', 'name_en' => 'Draw'],
        ];

        $state = applyAction($state, 'p1', 'activate_ability', [
            'card_id' => 'emma_sec',
            'ability_index' => 0,
        ]);
        $this->assertSame('wait_other_group_draw', $state['pending_prompt']['type'] ?? null);
        $this->assertCount(2, $state['pending_prompt']['stage_members'] ?? []);

        $state = applyAction($state, 'p1', 'resolve_prompt', [
            'member_id' => 'mia1',
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['right']));
        $this->assertCount(1, $state['players']['p1']['hand']);
    }
}
