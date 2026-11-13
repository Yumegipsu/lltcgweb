<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * NSD02 Nijigasaki cheer — skill correctness for new + reused ability types.
 */
final class Nsd02CheerSkillsTest extends TestCase
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
        $this->fail('Missing card ' . $cardNo);
    }

    private function energyStub(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'LL-E-003-SD',
            'card_type' => 'エネルギー',
            'card_type_en' => 'Energy',
            'active' => true,
        ];
    }

    /** @return list<array> */
    private function nEnergy(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->energyStub('e_' . $i);
        }
        return $out;
    }

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
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

    public function testPayEnergyAddFromWr001(): void
    {
        $ayumu = $this->cardByNo('PL!N-sd2-001-SD2', 'ayumu_1');
        $live = $this->cardByNo('PL!N-sd2-025-SD2', 'live_wr');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $ayumu;
        $state['players']['p1']['energy_zone'] = $this->nEnergy(3);
        $state['players']['p1']['waiting_room'] = [$live];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'ayumu_1',
            'ability_index' => 0,
        ]);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $activeEnergy = count(array_filter(
            $state['players']['p1']['energy_zone'],
            fn($e) => !empty($e['active'])
        ));
        $this->assertSame(1, $activeEnergy, 'Paid 2 Energy');
    }

    public function testOptionalPayEnergyBlade004(): void
    {
        $karin = $this->cardByNo('PL!N-sd2-004-SD2', 'karin_1');
        $state = $this->baseState();
        $state['phase'] = 'live_start_effects';
        $state['players']['p1']['stage']['center'] = $karin;
        $state['players']['p1']['energy_zone'] = $this->nEnergy(2);
        $state['players']['p1']['live_zone'] = [[
            'instance_id' => 'live_f',
            'card_no' => 'PL!N-sd2-025-SD2',
            'card_type' => 'ライブ',
            'group' => 'Nijigasaki',
            'score' => 1,
            'face_up' => true,
        ]];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
            $this->assertSame('optional_pay_energy', $state['pending_prompt']['ability']['type'] ?? null);
            $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes', 'pay' => true]);
            $this->assertNull($state['pending_prompt'] ?? null);
            $this->assertSame(
                2,
                intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0)
            );
            $this->assertSame(0, intval($state['live_modifiers']['p1']['blade_bonus'] ?? 0));
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testLeaveStageAddFromWr016And024(): void
    {
        foreach (['PL!N-sd2-016-SD2' => 'live', 'PL!N-sd2-024-SD2' => 'member'] as $cardNo => $filter) {
            $member = $this->cardByNo($cardNo, 'leave_' . $filter);
            $wrCard = $filter === 'live'
                ? $this->cardByNo('PL!N-sd2-025-SD2', 'wr_live')
                : $this->cardByNo('PL!N-sd2-002-SD2', 'wr_mem');
            $state = $this->baseState();
            $state['players']['p1']['stage']['center'] = $member;
            $state['players']['p1']['waiting_room'] = [$wrCard];

            $state = \actionActivateAbility($state, 'p1', [
                'card_id' => 'leave_' . $filter,
                'ability_index' => 0,
            ]);
            $this->assertSame(
                'pick_wr_leave_stage_add',
                $state['pending_prompt']['type'] ?? null,
                "$cardNo should open leave-stage WR pick ($filter)"
            );
        }
    }

    public function testWaitOppMaxCost021(): void
    {
        $emma = $this->cardByNo('PL!N-sd2-021-SD2', 'emma_1');
        $opp = $this->cardByNo('PL!N-sd2-004-SD2', 'opp_low');
        $opp['cost'] = 3;
        $state = $this->baseState();
        $state['phase'] = 'main_first';
        $state['players']['p1']['stage']['center'] = $emma;
        $state['players']['p2']['stage']['center'] = $opp;

        $state = \resolveOnEnterAbilities($state, 'p1', $emma, 'center');
        // Single legal target auto-resolves.
        $state = \tcgTestPickOnlyOption($state); // picks always prompt now, even with one option
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertTrue(
            \memberIsInWait($state['players']['p2']['stage']['center']),
            'Opponent cost≤4 Member should be Waited'
        );
    }

    public function testOptionalWaitGroupMemberBlade006(): void
    {
        $kanata = $this->cardByNo('PL!N-sd2-006-SD2', 'kanata_1');
        // 002 has no Live Start abilities — avoids optional_live_start queue noise.
        $ally = $this->cardByNo('PL!N-sd2-002-SD2', 'ally_1');
        $state = $this->baseState();
        $state['phase'] = 'live_start_effects';
        $state['players']['p1']['stage']['center'] = $kanata;
        $state['players']['p1']['stage']['right'] = $ally;
        $state['players']['p1']['live_zone'] = [[
            'instance_id' => 'live_k',
            'card_no' => 'PL!N-sd2-022-SD2',
            'card_type' => 'ライブ',
            'group' => 'Nijigasaki',
            'score' => 1,
            'face_up' => true,
        ]];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame('optional_wait_group_member_blade', $state['pending_prompt']['type'] ?? null);
            $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
            $this->assertSame('pick_member', $state['pending_prompt']['step'] ?? null);
            $state = \actionResolvePrompt($state, 'p1', ['member_id' => 'ally_1']);
            $this->assertNull($state['pending_prompt'] ?? null);
            $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['right']));
            $this->assertSame(
                2,
                intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0)
            );
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testDrawIfOppSucceeded007(): void
    {
        $setsuna = $this->cardByNo('PL!N-sd2-007-SD2', 'setsuna_1');
        $live = $this->cardByNo('PL!N-sd2-025-SD2', 'succ_live');
        $state = $this->baseState();
        $state['phase'] = 'live_success_effects';
        $state['players']['p1']['stage']['center'] = $setsuna;
        $state['players']['p1']['main_deck'] = [
            ['instance_id' => 'd1', 'card_type' => 'メンバー', 'name_en' => 'D1', 'group' => 'Nijigasaki'],
            ['instance_id' => 'd2', 'card_type' => 'メンバー', 'name_en' => 'D2', 'group' => 'Nijigasaki'],
            ['instance_id' => 'd3', 'card_type' => 'メンバー', 'name_en' => 'D3', 'group' => 'Nijigasaki'],
        ];
        $state['players']['p1']['hand'] = [
            ['instance_id' => 'h1', 'card_type' => 'メンバー', 'name_en' => 'H1', 'group' => 'Nijigasaki'],
        ];
        $state['players']['p2']['succeeded_live_this_turn'] = true;

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$live], 0, [], []);
        // Draw 1 + bonus draw 1, then discard prompt.
        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertGreaterThanOrEqual(2, count($state['players']['p1']['hand']));
    }

    public function testAutoOnAllyWait010(): void
    {
        $shiori = $this->cardByNo('PL!N-sd2-010-SD2', 'shiori_1');
        $ally = $this->cardByNo('PL!N-sd2-004-SD2', 'ally_w');
        $state = $this->baseState();
        $state['phase'] = 'main_first';
        $state['players']['p1']['stage']['center'] = $shiori;
        $state['players']['p1']['stage']['left'] = $ally;
        $state['players']['p1']['hand'] = [
            ['instance_id' => 'hd1', 'card_type' => 'メンバー', 'name_en' => 'HD', 'group' => 'Nijigasaki'],
        ];

        \waitMember($state['players']['p1']['stage']['left'], $state);
        $state = \flushAutoOnWaitAbilities($state);
        $this->assertSame('auto_on_ally_wait_activate_blade', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertSame('discard', $state['pending_prompt']['step'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['discard_ids' => ['hd1']]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertFalse(\memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertSame(
            2,
            intval($state['players']['p1']['stage']['left']['live_blade_bonus'] ?? 0)
        );
    }

    public function testOptionalWaitUpToGroupLiveScore027(): void
    {
        $hikari = $this->cardByNo('PL!N-sd2-027-SD2', 'hikari_1');
        // Members with no Live Start of their own.
        $a = $this->cardByNo('PL!N-sd2-002-SD2', 'm_a');
        $b = $this->cardByNo('PL!N-sd2-018-SD2', 'm_b');
        $state = $this->baseState();
        $state['phase'] = 'live_start_effects';
        $state['players']['p1']['stage']['left'] = $a;
        $state['players']['p1']['stage']['right'] = $b;
        $state['players']['p1']['live_zone'] = [$hikari];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame('optional_wait_up_to_group_live_score', $state['pending_prompt']['type'] ?? null);
            $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
            $this->assertSame('pick_members', $state['pending_prompt']['step'] ?? null);
            $state = \actionResolvePrompt($state, 'p1', ['member_ids' => ['m_a', 'm_b']]);
            $this->assertNull($state['pending_prompt'] ?? null);
            $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['left']));
            $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['right']));
            $live = $state['players']['p1']['live_zone'][0] ?? [];
            $this->assertSame(
                intval($hikari['score'] ?? 0) + 2,
                intval($live['score'] ?? 0),
                'Score +1 per Waited Member'
            );
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testHandCostReductionWithNijiInSuccess003(): void
    {
        $shizuku = $this->cardByNo('PL!N-sd2-003-SD2', 'shizuku_hand');
        $state = $this->baseState();
        $base = intval($shizuku['cost'] ?? 9);

        $noSuccess = \getEffectiveHandCost($state, 'p1', $shizuku);
        $this->assertSame($base, $noSuccess);

        $state['players']['p1']['success_lives'] = [[
            'instance_id' => 'sl1',
            'card_type' => 'ライブ',
            'group' => 'μ\'s',
            'score' => 1,
        ]];
        $wrongGroup = \getEffectiveHandCost($state, 'p1', $shizuku);
        $this->assertSame($base, $wrongGroup, 'Non-Niji success must not reduce');

        $state['players']['p1']['success_lives'] = [[
            'instance_id' => 'sl2',
            'card_type' => 'ライブ',
            'group' => 'Nijigasaki',
            'score' => 1,
        ]];
        $reduced = \getEffectiveHandCost($state, 'p1', $shizuku);
        $this->assertSame(max(0, $base - 2), $reduced);
    }

    public function testReprintAliasSd1HasAbilities(): void
    {
        $reprint = $this->cardByNo('PL!N-sd1-001-SD2', 'ayumu_reprint');
        $this->assertNotEmpty($reprint['abilities'] ?? [], 'PL!N-sd1-001-SD2 must have abilities in cards.json');
        $types = array_map(fn($ab) => $ab['type'] ?? '', $reprint['abilities']);
        $this->assertContains('look_reveal_group', $types);
        $this->assertContains('optional_pay_energy', $types);
    }

    public function testFireBirdGrantsTwoRedHeartsNotPink(): void
    {
        foreach (['PL!N-sd2-026-P', 'PL!N-sd2-026-SD2'] as $no) {
            $live = $this->cardByNo($no, 'fb_' . $no);
            $ab = $live['abilities'][0] ?? [];
            $this->assertSame('pick_group_member_grant_hearts', $ab['type'] ?? null);
            $this->assertSame('red', $ab['hearts'][0]['color'] ?? null, $no . ' official heart02 is Red');
            $this->assertSame(2, intval($ab['hearts'][0]['count'] ?? 0));
            $this->assertStringContainsString('Red hearts', (string)($live['text'] ?? ''));
            $this->assertStringNotContainsString('Pink hearts', (string)($live['text'] ?? ''));
        }

        $fire = $this->cardByNo('PL!N-sd2-026-P', 'fire_bird');
        $setsu = $this->cardByNo('PL!N-bp7-007-SEC', 'setsu_blade4');
        $state = $this->baseState();
        $state['phase'] = 'live_start_effects';
        $state['players']['p1']['stage']['center'] = $setsu;
        $state['players']['p1']['live_zone'] = [$fire];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }

        $bonus = $state['players']['p1']['stage']['center']['bonus_hearts'] ?? [];
        $this->assertSame(['red', 'red'], array_values($bonus));
        $this->assertNotContains('pink', $bonus);
    }

    public function testRegistryKnowsNsd02AbilityTypes(): void
    {
        $known = \LLTCG\Game\EffectRegistry::knownAbilityTypes();
        foreach ([
            'hand_cost_reduction_if_success_live_group',
            'optional_wait_group_member_blade',
            'draw_if_opp_succeeded_this_turn',
            'auto_on_ally_wait_activate_blade',
            'wait_opp_max_original_blade_if_stage_group',
            'pick_group_member_grant_hearts',
            'optional_wait_up_to_group_live_score',
            'activate_members',
            'pay_energy_add_from_wr',
            'leave_stage_add_from_wr',
            'wait_opponent_stage_max_cost',
        ] as $type) {
            $this->assertContains($type, $known, "EffectRegistry must know $type");
        }
    }
}
