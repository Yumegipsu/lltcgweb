<?php
declare(strict_types=1);

namespace Tests\Engine;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/effects.php';

/**
 * Engine coverage for 2026-09 skill-bearing PR promos (PL!-PR-023… / HS-038…).
 */
final class NewPrPromoSkills202609Test extends TestCase
{
    private function baseState(): array
    {
        return [
            'room_id' => 'TEST',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 1,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_modifiers' => [
                'p1' => ['blade_bonus' => 0, 'bonus_hearts' => []],
                'p2' => ['blade_bonus' => 0, 'bonus_hearts' => []],
            ],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'main_deck' => [],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'stage' => ['center' => null, 'left' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'main_deck' => [],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'stage' => ['center' => null, 'left' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testCardsJsonHasAbilitiesForNewPrs(): void
    {
        $cards = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/cards.json'),
            true
        )['cards'] ?? [];
        $by = [];
        foreach ($cards as $c) {
            $by[$c['card_no'] ?? ''] = $c;
        }
        $nos = [
            'PL!-PR-023-PR', 'PL!-PR-024-PR', 'PL!-PR-025-PR',
            'PL!HS-PR-038-PR', 'PL!HS-PR-039-PR', 'PL!HS-PR-040-PR',
            'PL!N-PR-033-PR', 'PL!N-PR-034-PR',
            'PL!S-PR-046-PR', 'PL!S-PR-047-PR', 'PL!S-PR-048-PR',
            'PL!SP-PR-027-PR', 'PL!SP-PR-028-PR',
        ];
        foreach ($nos as $no) {
            $this->assertNotEmpty($by[$no]['abilities'] ?? null, $no);
        }
    }

    public function testEitherSideWaitGrantsBlade(): void
    {
        $state = $this->baseState();
        $eli = [
            'instance_id' => 'eli1',
            'name_en' => 'Eli Ayase',
            'card_type' => 'メンバー',
            'abilities' => [[
                'trigger' => 'auto',
                'type' => 'auto_on_either_stage_wait_blade',
                'amount' => 1,
                'max_uses_per_turn' => 3,
            ]],
            'live_blade_bonus' => 0,
        ];
        $opp = [
            'instance_id' => 'opp1',
            'name_en' => 'Opp',
            'card_type' => 'メンバー',
            'active' => true,
        ];
        $state['players']['p1']['stage']['center'] = $eli;
        $state['players']['p2']['stage']['center'] = $opp;
        waitMember($state['players']['p2']['stage']['center'], $state);
        $state = flushAutoOnWaitAbilities($state);
        $this->assertSame(
            1,
            intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0)
        );
    }

    public function testCombinedSuccessScoreHearts(): void
    {
        $state = $this->baseState();
        $member = [
            'instance_id' => 'eli2',
            'name_en' => 'Eli',
            'card_type' => 'メンバー',
            'hearts' => [],
            'abilities' => [[
                'trigger' => 'continuous',
                'type' => 'hearts_if_combined_success_score_min',
                'min_success_score_sum' => 10,
                'hearts' => [['color' => 'pink', 'count' => 1]],
            ]],
        ];
        $state['players']['p1']['stage']['center'] = $member;
        $state['players']['p1']['success_lives'] = [[
            'card_type' => 'ライブ',
            'score' => 6,
        ]];
        $state['players']['p2']['success_lives'] = [[
            'card_type' => 'ライブ',
            'score' => 4,
        ]];
        $hearts = prVol9ApplyContinuousHearts(
            $state,
            'p1',
            $member,
            $member['abilities'][0],
            []
        );
        $this->assertContains('pink', $hearts);
    }

    public function testGrantBonusHeartsIfNotFromHand(): void
    {
        $state = $this->baseState();
        $ceras = [
            'instance_id' => 'ceras1',
            'name_en' => 'Ceras',
            'card_type' => 'メンバー',
            'entered_from_hand' => false,
            'abilities' => [[
                'trigger' => 'on_enter',
                'type' => 'grant_bonus_hearts_if_not_from_hand',
                'hearts' => [['color' => 'purple', 'count' => 1]],
            ]],
        ];
        $state = resolveAbilityEffect($state, 'p1', $ceras, $ceras['abilities'][0], [
            'phase' => 'on_enter',
        ]);
        $bonus = $state['live_modifiers']['p1']['bonus_hearts']
            ?? $state['players']['p1']['stage']['center']['bonus_hearts']
            ?? null;
        // grant may land on member via applyModifierEffect
        $this->assertTrue(
            !empty($state['live_modifiers']['p1']['bonus_hearts'])
            || !empty($ceras['bonus_hearts'])
            || str_contains(json_encode($state['log'] ?? []), 'bonus heart')
        );
    }

    public function testDeckSurveilHandDelta(): void
    {
        $state = $this->baseState();
        $p = &$state['players']['p1'];
        $p['hand'] = [
            ['instance_id' => 'h1', 'card_type' => 'メンバー'],
            ['instance_id' => 'h2', 'card_type' => 'メンバー'],
            ['instance_id' => 'h3', 'card_type' => 'メンバー'],
        ];
        for ($i = 0; $i < 8; $i++) {
            $p['main_deck'][] = [
                'instance_id' => 'd' . $i,
                'card_type' => 'メンバー',
                'name_en' => 'D' . $i,
            ];
        }
        $src = [
            'instance_id' => 'ginko1',
            'name_en' => 'Ginko',
            'card_type' => 'メンバー',
        ];
        $ab = [
            'trigger' => 'on_enter',
            'type' => 'deck_surveil',
            'look_base' => 10,
            'look_minus_hand' => true,
            'pick' => 2,
            'optional_pick' => true,
        ];
        $state = resolveAbilityEffect($state, 'p1', $src, $ab, ['phase' => 'on_enter']);
        // 10 - 3 hand = look 7 → prompt
        $this->assertSame('pick_looked_deck_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertCount(7, $state['surveil_stash'] ?? []);
    }

    public function testDrawOnEnterFromWr(): void
    {
        $state = $this->baseState();
        $kasumi = [
            'instance_id' => 'kasumi1',
            'name_en' => 'Kasumi',
            'card_type' => 'メンバー',
            'group' => 'Nijigasaki',
            'abilities' => [[
                'trigger' => 'auto',
                'type' => 'draw_on_member_enter_from_wr',
                'draw' => 1,
                'once_per_turn' => true,
            ]],
        ];
        $entered = [
            'instance_id' => 'fromwr1',
            'name_en' => 'From WR',
            'card_type' => 'メンバー',
            'entered_from_wr' => true,
            'cost' => 3,
        ];
        $state['players']['p1']['stage']['center'] = $kasumi;
        $state['players']['p1']['main_deck'] = [
            ['instance_id' => 'top1', 'card_type' => 'メンバー', 'name_en' => 'Top'],
        ];
        $state = nijiOnMemberEntered($state, 'p1', $entered);
        $this->assertCount(1, $state['players']['p1']['hand']);
    }

    public function testOppYellLiveDraw(): void
    {
        $state = $this->baseState();
        $you = [
            'instance_id' => 'you1',
            'name_en' => 'You',
            'card_type' => 'メンバー',
        ];
        $ab = [
            'trigger' => 'live_success',
            'type' => 'live_success_draw_if_opp_yell_has_live',
            'draw' => 1,
        ];
        $state['_last_yell_live_count_p2'] = 1;
        $state['players']['p1']['main_deck'] = [
            ['instance_id' => 'draw1', 'card_type' => 'メンバー'],
        ];
        $state = resolveAbilityEffect($state, 'p1', $you, $ab, ['phase' => 'live_success']);
        $this->assertCount(1, $state['players']['p1']['hand']);
    }

    public function testBatonIncomingBlade(): void
    {
        $state = $this->baseState();
        $incoming = [
            'instance_id' => 'in1',
            'name_en' => 'Incoming',
            'card_type' => 'メンバー',
            'cost' => 10,
            'live_blade_bonus' => 0,
        ];
        $leaver = [
            'instance_id' => 'leave1',
            'name_en' => 'Kotori',
            'card_type' => 'メンバー',
            'abilities' => [[
                'trigger' => 'on_leave_stage',
                'type' => 'on_leave_baton_incoming_blade',
                'min_cost' => 9,
                'amount' => 2,
            ]],
        ];
        $state['players']['p1']['stage']['center'] = $incoming;
        $state = resolveAbilityEffect($state, 'p1', $leaver, $leaver['abilities'][0], [
            'phase' => 'on_leave_stage',
            'baton_incoming' => $incoming,
        ]);
        $this->assertSame(
            2,
            intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0)
        );
    }

    public function testWrLivesDeckTopIfWrMax(): void
    {
        $state = $this->baseState();
        $state['phase'] = 'live_start_effects';
        $ruby = [
            'instance_id' => 'ruby1',
            'name_en' => 'Ruby',
            'card_type' => 'メンバー',
        ];
        $ab = [
            'trigger' => 'live_start',
            'type' => 'live_start_wr_lives_deck_top_if_wr_max',
            'max_wr' => 9,
            'max_pick' => 3,
        ];
        $p = &$state['players']['p1'];
        $p['waiting_room'] = [
            ['instance_id' => 'l1', 'card_type' => 'ライブ', 'name_en' => 'Live1', 'score' => 1],
            ['instance_id' => 'm1', 'card_type' => 'メンバー', 'name_en' => 'M1'],
        ];
        $state = resolveAbilityEffect($state, 'p1', $ruby, $ab, ['phase' => 'live_start']);
        $this->assertSame('sbp5_wr_lives_deck_top', $state['pending_prompt']['type'] ?? null);
    }
}
