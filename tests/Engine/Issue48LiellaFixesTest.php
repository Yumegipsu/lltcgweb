<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression tests for GitHub issue #48 (Liella card bugs). */
final class Issue48LiellaFixesTest extends TestCase
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

    private function baseState(string $phase = 'main_first'): array
    {
        return [
            'status' => 'playing',
            'phase' => $phase,
            'seq' => 1,
            'turn' => 1,
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
                    'main_deck' => [['instance_id' => 'deck_top']],
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

    /** @return list<array<string, mixed>> */
    private function activeEnergy(int $count, string $prefix = 'issue48_en'): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'instance_id' => $prefix . $i,
                'card_type' => 'エネルギー',
                'active' => true,
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function batonMember(string $id, int $cost): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name' => $id,
            'name_en' => $id,
            'group' => 'Superstar',
            'cost' => $cost,
            'active' => true,
            'entered_turn' => 1,
            'hearts' => [['color' => 'red', 'count' => 1]],
            'blade_hearts' => [],
        ];
    }

    public function testSumireDoubleBatonTouchReducesCostFromTwoMembers(): void
    {
        $sumire = $this->cardByNo('PL!SP-bp4-004-P', 'issue48_sumire');
        $primary = $this->batonMember('issue48_baton_primary', 13);
        $secondary = $this->batonMember('issue48_baton_secondary', 2);

        $state = $this->baseState();
        $state['turn'] = 2;
        $state['phase'] = 'main_second';
        $state['active_player'] = 'p1';
        $state['players']['p1']['hand'] = [$sumire];
        $state['players']['p1']['stage']['center'] = $primary;
        $state['players']['p1']['stage']['left'] = $secondary;
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(7);

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'issue48_sumire',
            'slot' => 'center',
            'baton_id' => 'issue48_baton_primary',
            'baton_id2' => 'issue48_baton_secondary',
        ]);

        $this->assertSame('issue48_sumire', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $this->assertSame(2, $state['players']['p1']['stage']['center']['baton_count'] ?? 0);
        $stageIds = array_column(array_filter($state['players']['p1']['stage']), 'instance_id');
        $this->assertNotContains('issue48_baton_primary', $stageIds);
        $this->assertNotContains('issue48_baton_secondary', $stageIds);
    }

    /** On Enter must prompt when multiple WR Members are eligible (issue #48 follow-up). */
    public function testSumireDoubleBatonOnEnterPromptsWrMemberChoice(): void
    {
        $sumire = $this->cardByNo('PL!SP-bp4-004-P', 'issue48_sumire_wr');
        $primary = $this->batonMember('issue48_baton_a', 13);
        $secondary = $this->batonMember('issue48_baton_b', 2);
        $wrCheapA = $this->batonMember('issue48_wr_a', 3);
        $wrCheapB = $this->batonMember('issue48_wr_b', 4);
        $wrExpensive = $this->batonMember('issue48_wr_expensive', 5);

        $state = $this->baseState();
        $state['turn'] = 2;
        $state['phase'] = 'main_second';
        $state['active_player'] = 'p1';
        $state['players']['p1']['hand'] = [$sumire];
        $state['players']['p1']['stage']['center'] = $primary;
        $state['players']['p1']['stage']['left'] = $secondary;
        $state['players']['p1']['waiting_room'] = [$wrCheapA, $wrCheapB, $wrExpensive];
        $state['players']['p1']['main_deck'] = [
            ['instance_id' => 'draw1', 'card_type' => 'メンバー'],
            ['instance_id' => 'draw2', 'card_type' => 'メンバー'],
        ];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(7);

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'issue48_sumire_wr',
            'slot' => 'center',
            'baton_id' => 'issue48_baton_a',
            'baton_id2' => 'issue48_baton_b',
        ]);

        $this->assertSame('ssd1_play_wr_empty', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('pick_wr', $state['pending_prompt']['step'] ?? null);
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains('issue48_wr_a', $candIds);
        $this->assertContains('issue48_wr_b', $candIds);
        $this->assertContains('issue48_baton_b', $candIds); // cost 2 from Baton → WR
        $this->assertNotContains('issue48_wr_expensive', $candIds);
        $this->assertNotContains('issue48_baton_a', $candIds); // cost 13

        // Stage must not have auto-placed a WR member yet.
        $stageIds = array_column(array_filter($state['players']['p1']['stage']), 'instance_id');
        $this->assertNotContains('issue48_wr_a', $stageIds);
        $this->assertNotContains('issue48_wr_b', $stageIds);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'issue48_wr_b']);
        $this->assertSame('pick_slot', $state['pending_prompt']['step'] ?? null);
        $slot = ($state['pending_prompt']['slots'] ?? ['right'])[0];
        $state = \actionResolvePrompt($state, 'p1', ['slot' => $slot]);

        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertSame(
            'issue48_wr_b',
            $state['players']['p1']['stage'][$slot]['instance_id'] ?? null
        );
    }

    public function testNicoOnEnterPromptsOpponentToPickActiveMember(): void
    {
        $nico = $this->cardByNo('PL!-bp4-009-R', 'issue48_nico');
        $oppLeft = $this->batonMember('issue48_opp_left', 3);
        $oppCenter = $this->batonMember('issue48_opp_center', 4);

        $state = $this->baseState();
        $state['players']['p2']['stage']['left'] = $oppLeft;
        $state['players']['p2']['stage']['center'] = $oppCenter;

        $state = \resolveOnEnterAbilities($state, 'p1', $nico, 'center');

        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);

        $blocked = \actionResolvePrompt($state, 'p1', ['slot' => 'left']);
        $this->assertTrue(!empty($blocked['_resolve_prompt_noop']));
        $this->assertSame('wait_opponent_stage_pick', $blocked['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p2', ['slot' => 'left']);
        $this->assertTrue(\memberIsInWait($state['players']['p2']['stage']['left']));
        $this->assertFalse(\memberIsInWait($state['players']['p2']['stage']['center']));
    }

    public function testTomariBp2OnEnterOpensDistinctLivePick(): void
    {
        $tomari = $this->cardByNo('PL!SP-bp2-011-R', 'issue48_tomari_bp2');
        $liveA = $this->cardByNo('PL!SP-bp1-023-L', 'issue48_live_a');
        $liveA['instance_id'] = 'issue48_live_a';
        $liveA['card_type'] = 'ライブ';
        $liveB = $this->cardByNo('PL!SP-bp1-024-L', 'issue48_live_b');
        $liveB['instance_id'] = 'issue48_live_b';
        $liveB['card_type'] = 'ライブ';

        $state = $this->baseState();
        $state['players']['p1']['waiting_room'] = [$liveA, $liveB];

        $state = \resolveOnEnterAbilities($state, 'p1', $tomari, 'center');
        $this->assertSame('pick_wr_distinct_lives_opp_choice', $state['pending_prompt']['type'] ?? null);
    }

    public function testKekePb2ActivatedDiscardOffersEnergyHeartsOrBoth(): void
    {
        $keke = $this->cardByNo('PL!SP-pb2-002-PP', 'issue48_keke_pb2');
        $discard = $this->cardByNo('PL!SP-pb2-003-R', 'issue48_keke_discard');
        $discard['blade_hearts'] = [];

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $keke;
        $state['players']['p1']['hand'] = [$discard];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'issue48_keke_pb2',
            'ability_index' => 0,
        ]);
        $this->assertSame('spbp2_discard_liella_choice', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('pick_hand', $state['pending_prompt']['step'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'issue48_keke_discard']);
        $this->assertSame('choose', $state['pending_prompt']['step'] ?? null);
        $this->assertContains('both', $state['pending_prompt']['choices'] ?? []);
    }

    public function testRenAutoWrHookOpensPayEnergyAddHandPrompt(): void
    {
        $ren = $this->cardByNo('PL!SP-bp5-005-P', 'issue48_ren');
        $toWr = $this->cardByNo('PL!SP-pb2-003-R', 'issue48_ren_wr');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $ren;

        $state = \appendCardsToWaitingRoom($state, 'p1', [$toWr]);

        $this->assertSame('spbp5_wr_pay_add_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p1', $state['pending_prompt']['responder'] ?? null);
    }

    public function testTomariCenterMoveChooseOncePerTurn(): void
    {
        $tomari = $this->cardByNo('PL!SP-pb2-011-R', 'issue48_tomari');
        $moved = $this->cardByNo('PL!SP-pb2-001-R', 'issue48_moved');
        $moved['instance_id'] = 'issue48_center_moved';

        $state = $this->baseState();
        $state['players']['p1']['stage']['left'] = $tomari;
        $state['players']['p1']['stage']['center'] = $moved;

        $state = \spBp2TriggerCenterMoveChoose($state, 'p1', $moved, 'center');
        $this->assertSame('spbp2_center_move_choose', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(['blade', 'wait_opp', 'draw'], $state['pending_prompt']['choices'] ?? null);

        unset($state['pending_prompt']);

        $state = \spBp2TriggerCenterMoveChoose($state, 'p1', $moved, 'center');
        $this->assertEmpty($state['pending_prompt'] ?? null);
    }

    public function testTomariLiveStartSwapFromCenterOpensAutoChoose(): void
    {
        $tomari = $this->cardByNo('PL!SP-pb2-011-R', 'issue48_tomari_ls');
        $partner = $this->batonMember('issue48_partner', 4);

        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['stage']['center'] = $tomari;
        $state['players']['p1']['stage']['left'] = $partner;
        $state['pending_prompt'] = [
            'type' => 'optional_swap_area_on_enter',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_id' => 'issue48_tomari_ls',
            'source_slot' => 'center',
            'source_name' => 'Tomari Onitsuka',
            'choices' => ['skip', 'left', 'right'],
            'ability' => ['trigger' => 'live_start', 'type' => 'optional_swap_area_on_enter'],
        ];

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'left']);
        $this->assertSame('issue48_tomari_ls', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertSame('spbp2_center_move_choose', $state['pending_prompt']['type'] ?? null);
    }

    public function testTomariSelfLeaveCenterTriggersAndBladeChoice(): void
    {
        $tomari = $this->cardByNo('PL!SP-pb2-011-R', 'issue48_tomari_self');

        $state = $this->baseState();
        $state['players']['p1']['stage']['left'] = $tomari;
        $state['players']['p1']['stage']['center'] = null;

        $state = \spBp2TriggerCenterMoveChoose(
            $state,
            'p1',
            $state['players']['p1']['stage']['left'],
            'center'
        );
        $this->assertSame('spbp2_center_move_choose', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'blade']);
        $this->assertSame(2, intval($state['live_modifiers']['p1']['blade_bonus'] ?? 0));
        $this->assertEmpty($state['pending_prompt'] ?? null);
    }

    public function testKanonLookRevealOpensHandOrStageDestination(): void
    {
        $kanon = $this->cardByNo('PL!SP-pb2-001-R', 'issue48_kanon_stage');
        $member = $this->cardByNo('PL!SP-pb2-003-R', 'issue48_deck_member');
        $member['cost'] = 3;

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $kanon;
        $state['players']['p1']['hand'] = [$this->cardByNo('PL!SP-pb2-004-R', 'issue48_discard')];
        $state['players']['p1']['main_deck'] = [$member];

        $state = \resolveOnEnterAbilities($state, 'p1', $kanon, 'center');
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);

        $discardId = $state['players']['p1']['hand'][0]['instance_id'];
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => [$discardId],
        ]);
        $this->assertSame('pick_looked_deck_hand', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'issue48_deck_member']);
        $this->assertSame('pick_destination', $state['pending_prompt']['step'] ?? null);
        $this->assertContains('left', $state['pending_prompt']['slots'] ?? []);
    }

    public function testKanonLookRevealRejectsCostAboveMax(): void
    {
        $kanon = $this->cardByNo('PL!SP-pb2-001-R', 'issue48_kanon_max');
        $cheap = $this->cardByNo('PL!SP-pb2-003-R', 'issue48_cheap');
        $cheap['cost'] = 3;
        $kinako = $this->cardByNo('PL!SP-pb2-017-R', 'issue48_kinako');
        $this->assertGreaterThan(4, intval($kinako['cost'] ?? 0));

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $kanon;
        $state['players']['p1']['hand'] = [$this->cardByNo('PL!SP-pb2-004-R', 'issue48_discard_max')];
        $state['players']['p1']['main_deck'] = [$kinako, $cheap, ['instance_id' => 'pad1'], ['instance_id' => 'pad2'], ['instance_id' => 'pad3']];

        $state = \resolveOnEnterAbilities($state, 'p1', $kanon, 'center');
        $discardId = $state['players']['p1']['hand'][0]['instance_id'];
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => [$discardId],
        ]);
        $this->assertSame('pick_looked_deck_hand', $state['pending_prompt']['type'] ?? null);
        $eligible = $state['pending_prompt']['eligible_ids'] ?? [];
        $this->assertContains('issue48_cheap', $eligible);
        $this->assertNotContains('issue48_kinako', $eligible);
    }

    public function testKanonStagePlayPreservesPlayedMemberOnEnter(): void
    {
        $kanon = $this->cardByNo('PL!SP-pb2-001-R', 'issue48_kanon_enter');
        // Kinako has On Enter; use a cost-4 copy so Kanon can play her.
        $played = $this->cardByNo('PL!SP-pb2-017-R', 'issue48_played_kinako');
        $played['cost'] = 4;
        $handFuel = $this->cardByNo('PL!SP-pb2-003-R', 'issue48_kinako_fuel');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $kanon;
        $state['players']['p1']['hand'] = [
            $this->cardByNo('PL!SP-pb2-004-R', 'issue48_discard_enter'),
            $handFuel,
        ];
        $state['players']['p1']['main_deck'] = [
            $played,
            ['instance_id' => 'deck_pad1', 'card_type' => 'メンバー', 'group' => 'Superstar'],
            ['instance_id' => 'deck_pad2', 'card_type' => 'メンバー', 'group' => 'Superstar'],
            ['instance_id' => 'deck_pad3', 'card_type' => 'メンバー', 'group' => 'Superstar'],
            ['instance_id' => 'deck_pad4', 'card_type' => 'メンバー', 'group' => 'Superstar'],
            ['instance_id' => 'deck_pad5', 'card_type' => 'メンバー', 'group' => 'Superstar'],
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $kanon, 'center');
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['issue48_discard_enter'],
        ]);
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'issue48_played_kinako']);
        $this->assertSame('pick_destination', $state['pending_prompt']['step'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'left']);
        $this->assertSame('issue48_played_kinako', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('issue48_played_kinako', $state['pending_prompt']['source_id'] ?? null);
    }

    public function testOptionalPlayHandMemberPickSlotClearsParentPrompt(): void
    {
        $keke = $this->cardByNo('PL!SP-sd1-002-SD', 'issue48_keke');
        $handMember = $this->cardByNo('PL!SP-pb2-003-R', 'issue48_hand_member');
        $handMember['cost'] = 2;

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $keke;
        $state['players']['p1']['hand'] = [$handMember];

        $state = \resolveOnEnterAbilities($state, 'p1', $keke, 'center');
        $this->assertSame('optional_pay_play_hand_member', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'card_id' => 'issue48_hand_member',
        ]);
        if (($state['pending_prompt']['step'] ?? '') === 'pick_slot') {
            $slot = $state['pending_prompt']['slots'][0] ?? 'left';
            $state = \actionResolvePrompt($state, 'p1', ['slot' => $slot]);
        }
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertNotEmpty(
            array_filter($state['players']['p1']['stage'] ?? [], fn($m) => ($m['instance_id'] ?? '') === 'issue48_hand_member')
        );
    }

    /** Single empty Stage slot auto-places — must not reopen the yes/no option prompt (#48). */
    public function testOptionalPlayHandMemberAutoPlaceClearsParentPrompt(): void
    {
        $keke = $this->cardByNo('PL!SP-sd1-002-SD', 'issue48_keke_auto');
        $keke['entered_turn'] = 1;
        $handMember = $this->cardByNo('PL!SP-pb2-003-R', 'issue48_hand_auto');
        $handMember['cost'] = 2;
        $blockerL = $this->cardByNo('PL!SP-pb2-003-R', 'issue48_block_l');
        $blockerL['entered_turn'] = 1;

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $keke;
        $state['players']['p1']['stage']['left'] = $blockerL;
        $state['players']['p1']['stage']['right'] = null;
        $state['players']['p1']['hand'] = [$handMember];

        $state = \resolveOnEnterAbilities($state, 'p1', $keke, 'center');
        $this->assertSame('optional_pay_play_hand_member', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'card_id' => 'issue48_hand_auto',
        ]);
        $this->assertNull($state['pending_prompt'] ?? null, 'Auto-place must clear parent option prompt');
        $this->assertSame('issue48_hand_auto', $state['players']['p1']['stage']['right']['instance_id'] ?? null);
    }

    /** Skipping the optional play must clear the prompt so it cannot reopen (#48). */
    public function testOptionalPlayHandMemberSkipClearsParentPrompt(): void
    {
        $keke = $this->cardByNo('PL!SP-sd1-002-SD', 'issue48_keke_skip');
        $handMember = $this->cardByNo('PL!SP-pb2-003-R', 'issue48_hand_skip');
        $handMember['cost'] = 2;

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $keke;
        $state['players']['p1']['hand'] = [$handMember];

        $state = \resolveOnEnterAbilities($state, 'p1', $keke, 'center');
        $this->assertSame('optional_pay_play_hand_member', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);
        $this->assertNull($state['pending_prompt'] ?? null, 'Skip must clear parent option prompt');
        $this->assertCount(1, $state['players']['p1']['hand']);
    }

    public function testPerformanceYellPromptDoesNotJumpToLiveSuccessPhase(): void
    {
        $state = $this->baseState('live_performance_first');
        $live = $this->cardByNo('PL!SP-bp2-011-R', 'issue48_live');
        $live['instance_id'] = 'issue48_live_zone';
        $live['card_type'] = 'ライブ';
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['main_deck'] = array_fill(0, 12, $live);
        $state['pending_prompt'] = [
            'type' => 'player_choice',
            'responder' => 'p1',
            'choices' => ['yes', 'no'],
        ];

        $state = \resolvePerformancePhase($state, 'p1', true);
        $this->assertNotSame('live_success_effects', $state['phase'] ?? '');
        $this->assertSame('live_performance_first', $state['phase'] ?? '');
    }
}
