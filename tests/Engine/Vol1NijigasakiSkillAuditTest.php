<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Deep outcome audit for unique Booster Pack vol.1 Nijigasaki (PL!N-bp1) ability cards.
 * Asserts post-state / prompt shape — not merely "no exception".
 */
final class Vol1NijigasakiSkillAuditTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
    }

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
            'turn' => 2,
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

    /** @return list<array<string, mixed>> */
    private function activeEnergy(int $count, string $prefix = 'vol1n_en'): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'instance_id' => $prefix . $i,
                'card_type' => 'エネルギー',
                'card_type_en' => 'Energy',
                'active' => true,
            ];
        }
        return $out;
    }

    private function stubMember(string $id, string $group = 'Nijigasaki', int $cost = 3, string $heart = 'red'): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'STUB-' . $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => $id,
            'name' => $id,
            'group' => $group,
            'cost' => $cost,
            'active' => true,
            'entered_turn' => 1,
            'hearts' => [['color' => $heart, 'count' => 1]],
            'blade_hearts' => [],
        ];
    }

    private function stubLive(string $id, string $group = 'Nijigasaki', int $score = 2): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'STUB-LIVE-' . $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => $id,
            'name' => $id,
            'group' => $group,
            'score' => $score,
            'hearts' => [['color' => 'red', 'count' => 1]],
            'required_hearts' => [['color' => 'red', 'count' => 1]],
        ];
    }

    private function countActiveEnergy(array $state, string $pid = 'p1'): int
    {
        return count(array_filter(
            $state['players'][$pid]['energy_zone'] ?? [],
            static fn($c) => !empty($c['active'])
        ));
    }

    /** Run mandatory Live Start effects then open the optional Live Start queue. */
    private function beginLiveStart(array $state, string $pid = 'p1'): array
    {
        $state = \resolveLiveStartAbilities($state, $pid);
        if (!empty($state['pending_prompt'])) {
            return $state;
        }
        return \finishLiveStartEffects($state);
    }

    public function testPb1Lanzhu012AutoEnergyWaitIsOncePerTurn(): void
    {
        $lanzhu = $this->cardByNo('PL!N-pb1-012-R', 'pb1_lanzhu_auto');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $lanzhu;
        $state['players']['p1']['energy_deck'] = $this->activeEnergy(2, 'pb1_lanzhu_ed');

        $state = \nijiOnMemberEntered(
            $state,
            'p1',
            $this->stubMember('pb1_cost11_first', 'Nijigasaki', 11)
        );
        $this->assertCount(1, $state['players']['p1']['energy_zone']);
        $this->assertFalse($state['players']['p1']['energy_zone'][0]['active']);

        $state = \nijiOnMemberEntered(
            $state,
            'p1',
            $this->stubMember('pb1_cost11_second', 'Nijigasaki', 11)
        );
        $this->assertCount(1, $state['players']['p1']['energy_zone']);
        $this->assertCount(1, $state['players']['p1']['energy_deck']);
    }

    public function testPb1Lanzhu012LiveSuccessPickIsMandatory(): void
    {
        $lanzhu = $this->cardByNo('PL!N-pb1-012-R', 'pb1_lanzhu_live_success');
        $first = $this->stubMember('pb1_lanzhu_yell_first');
        $second = $this->stubMember('pb1_lanzhu_yell_second');
        $state = $this->baseState('live_success_effects');
        $state['players']['p1']['stage']['center'] = $lanzhu;
        $state['players']['p1']['_pending_yell_wr'] = [$first, $second];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [], 0, [], [$first, $second]);

        $this->assertSame('pick_yell_member', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(
            ['pb1_lanzhu_yell_first', 'pb1_lanzhu_yell_second'],
            array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id')
        );
        $this->assertArrayNotHasKey('choices', $state['pending_prompt']);
    }

    public function testAyumu001LiveStartOptionalPayEnergyOpensBladeThen(): void
    {
        $ayumu = $this->cardByNo('PL!N-bp1-001-P', 'vol1n_ayumu');
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['stage']['center'] = $ayumu;
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(1);

        $state = $this->beginLiveStart($state);
        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('optional_pay_energy', $state['pending_prompt']['ability']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes', 'pay' => true]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(0, $this->countActiveEnergy($state));
        $this->assertSame(1, intval($state['live_modifiers']['p1']['blade_bonus'] ?? 0));
    }

    public function testKasumi002OnEnterDeckSurveilOpensLookPrompt(): void
    {
        $kasumi = $this->cardByNo('PL!N-bp1-002-P', 'vol1n_kasumi');
        $d1 = $this->stubMember('vol1n_d1');
        $d2 = $this->stubMember('vol1n_d2');
        $d3 = $this->stubLive('vol1n_d3');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $kasumi;
        $state['players']['p1']['main_deck'] = [$d1, $d2, $d3];

        $state = \resolveOnEnterAbilities($state, 'p1', $kasumi, 'center');
        $this->assertSame('surveil_arrange', $state['pending_prompt']['type'] ?? null);
        $this->assertCount(3, $state['pending_prompt']['looked_cards'] ?? []);
    }

    public function testKasumi002ActivatedDiscardPlaySelfFromWr(): void
    {
        $kasumi = $this->cardByNo('PL!N-bp1-002-P', 'vol1n_kasumi_wr');
        $discard = $this->stubMember('vol1n_kasumi_disc');
        $state = $this->baseState();
        $state['players']['p1']['waiting_room'] = [$kasumi];
        $state['players']['p1']['hand'] = [$discard];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(2);

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'vol1n_kasumi_wr',
            'ability_index' => 1,
            'slot' => 'left',
            'discard_ids' => ['vol1n_kasumi_disc'],
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('vol1n_kasumi_wr', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertNotContains('vol1n_kasumi_wr', array_column($state['players']['p1']['waiting_room'], 'instance_id'));
    }

    public function testShizuku003OnEnterDiscardAddsNijiLiveFromWr(): void
    {
        $shizuku = $this->cardByNo('PL!N-bp1-003-P', 'vol1n_shizuku');
        $disc = $this->stubMember('vol1n_shiz_disc');
        $live = $this->cardByNo('PL!N-bp1-026-L', 'vol1n_shiz_live');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $shizuku;
        $state['players']['p1']['hand'] = [$disc];
        $state['players']['p1']['waiting_room'] = [$live];

        $state = \resolveOnEnterAbilities($state, 'p1', $shizuku, 'center');
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['vol1n_shiz_disc'],
        ]);
        if (($state['pending_prompt']['type'] ?? '') === 'pick_wr_to_hand') {
            $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'vol1n_shiz_live']);
        }
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertContains('vol1n_shiz_live', array_column($state['players']['p1']['hand'], 'instance_id'));
    }

    public function testShizuku003LiveStartPayEnergyOpensHeartModifier(): void
    {
        $shizuku = $this->cardByNo('PL!N-bp1-003-P', 'vol1n_shizuku_ls');
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['stage']['center'] = $shizuku;
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(1);

        $state = $this->beginLiveStart($state);
        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('optional_pay_energy', $state['pending_prompt']['ability']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes', 'pay' => true]);
        $this->assertSame('choose_heart_modifier', $state['pending_prompt']['type'] ?? null);
    }

    public function testKarin004OnEnterActivatesEnergyWhenOtherNijiPresent(): void
    {
        $karin = $this->cardByNo('PL!N-bp1-004-P', 'vol1n_karin');
        $ally = $this->stubMember('vol1n_niji_ally', 'Nijigasaki', 4);
        $en = $this->activeEnergy(1);
        $en[0]['active'] = false;

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $karin;
        $state['players']['p1']['stage']['left'] = $ally;
        $state['players']['p1']['energy_zone'] = $en;

        $state = \resolveOnEnterAbilities($state, 'p1', $karin, 'center');
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(1, $this->countActiveEnergy($state));
    }

    public function testKarin004OnEnterSkipsWhenOnlyOtherGroupOnStage(): void
    {
        $karin = $this->cardByNo('PL!N-bp1-004-P', 'vol1n_karin2');
        $other = $this->stubMember('vol1n_liella', 'Liella', 4);
        $en = $this->activeEnergy(1);
        $en[0]['active'] = false;

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $karin;
        $state['players']['p1']['stage']['left'] = $other;
        $state['players']['p1']['energy_zone'] = $en;

        $state = \resolveOnEnterAbilities($state, 'p1', $karin, 'center');
        $this->assertSame(0, $this->countActiveEnergy($state));
    }

    public function testAi005LiveStartOptionalDiscardGrantsBlade(): void
    {
        $ai = $this->cardByNo('PL!N-bp1-005-P', 'vol1n_ai');
        $disc = $this->stubMember('vol1n_ai_disc');
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['stage']['center'] = $ai;
        $state['players']['p1']['hand'] = [$disc];

        $state = $this->beginLiveStart($state);
        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('optional_discard_hand', $state['pending_prompt']['ability']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['vol1n_ai_disc'],
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(1, intval($state['live_modifiers']['p1']['blade_bonus'] ?? 0));
        $this->assertContains('vol1n_ai_disc', array_column($state['players']['p1']['waiting_room'], 'instance_id'));
    }

    public function testKanata006ActivatedDiscardActivatesEnergyWhenGroupEntered(): void
    {
        $kanata = $this->cardByNo('PL!N-bp1-006-P', 'vol1n_kanata');
        $disc = $this->stubMember('vol1n_kanata_disc');
        $ally = $this->stubMember('vol1n_kanata_ally');
        $ally['entered_turn'] = 2;
        $en = $this->activeEnergy(2);
        foreach ($en as &$e) {
            $e['active'] = false;
        }
        unset($e);

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $kanata;
        $state['players']['p1']['stage']['left'] = $ally;
        $state['players']['p1']['hand'] = [$disc];
        $state['players']['p1']['energy_zone'] = $en;

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'vol1n_kanata',
            'ability_index' => 0,
            'discard_ids' => ['vol1n_kanata_disc'],
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(2, $this->countActiveEnergy($state));
        $this->assertContains('vol1n_kanata_disc', array_column($state['players']['p1']['waiting_room'], 'instance_id'));
    }

    public function testKanata006ActivatedPayEnergyDraw(): void
    {
        $kanata = $this->cardByNo('PL!N-bp1-006-P', 'vol1n_kanata_draw');
        $deck = $this->stubMember('vol1n_kanata_deck');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $kanata;
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(2);
        $state['players']['p1']['main_deck'] = [$deck];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'vol1n_kanata_draw',
            'ability_index' => 1,
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(0, $this->countActiveEnergy($state));
        $this->assertContains('vol1n_kanata_deck', array_column($state['players']['p1']['hand'], 'instance_id'));
    }

    public function testSetsuna007OnEnterLookRevealFilterChain(): void
    {
        $setsuna = $this->cardByNo('PL!N-bp1-007-P', 'vol1n_setsuna');
        $disc = $this->stubMember('vol1n_set_disc');
        $pick = $this->stubMember('vol1n_set_pick');
        $restA = $this->stubMember('vol1n_set_a');
        $restB = $this->stubLive('vol1n_set_b');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $setsuna;
        $state['players']['p1']['hand'] = [$disc];
        $state['players']['p1']['main_deck'] = [$pick, $restA, $restB];

        $state = \resolveOnEnterAbilities($state, 'p1', $setsuna, 'center');
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['vol1n_set_disc'],
        ]);
        $this->assertSame('pick_looked_deck_hand', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'vol1n_set_pick']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertContains('vol1n_set_pick', array_column($state['players']['p1']['hand'], 'instance_id'));
        $wr = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('vol1n_set_a', $wr);
        $this->assertContains('vol1n_set_b', $wr);
    }

    public function testEmma008ActivatedDiscardMemberAddsLowerCostFromWr(): void
    {
        $emma = $this->cardByNo('PL!N-bp1-008-P', 'vol1n_emma');
        $handMember = $this->stubMember('vol1n_emma_hand', 'Nijigasaki', 5);
        $wrLower = $this->stubMember('vol1n_emma_wr', 'Nijigasaki', 3);
        $wrOther = $this->stubMember('vol1n_emma_wr2', 'Nijigasaki', 2);

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $emma;
        $state['players']['p1']['hand'] = [$handMember];
        $state['players']['p1']['waiting_room'] = [$wrLower, $wrOther];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'vol1n_emma',
            'ability_index' => 0,
        ]);
        $this->assertSame('discard_member_add_lower_wr_member', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['vol1n_emma_hand'],
        ]);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains('vol1n_emma_wr', $candIds);
        $this->assertContains('vol1n_emma_wr2', $candIds);
        $this->assertNotContains('vol1n_emma_hand', $candIds);
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'vol1n_emma_wr2']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertContains('vol1n_emma_wr2', array_column($state['players']['p1']['hand'], 'instance_id'));
        $this->assertContains('vol1n_emma_hand', array_column($state['players']['p1']['waiting_room'], 'instance_id'));
        $this->assertContains('vol1n_emma_wr', array_column($state['players']['p1']['waiting_room'], 'instance_id'));
    }

    public function testEmma008ActivatedSkipDoesNotError(): void
    {
        $emma = $this->cardByNo('PL!N-bp1-008-P', 'vol1n_emma_skip');
        $handMember = $this->stubMember('vol1n_emma_skip_hand', 'Nijigasaki', 5);

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $emma;
        $state['players']['p1']['hand'] = [$handMember];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'vol1n_emma_skip',
            'ability_index' => 0,
        ]);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertContains('vol1n_emma_skip_hand', array_column($state['players']['p1']['hand'], 'instance_id'));
    }

    public function testRina009OnEnterFullMillWrAddChain(): void
    {
        $rina = $this->cardByNo('PL!N-bp1-009-P', 'vol1n_rina');
        $disc = $this->stubMember('vol1n_rina_disc');
        $millMem = $this->stubMember('vol1n_rina_mill');
        $millOther = $this->stubLive('vol1n_rina_mill2');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $rina;
        $state['players']['p1']['hand'] = [$disc];
        $state['players']['p1']['main_deck'] = [$millMem, $millOther];

        $state = \resolveOnEnterAbilities($state, 'p1', $rina, 'center');
        $this->assertSame('optional_discard_mill_wr_add_member', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['vol1n_rina_disc'],
        ]);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'vol1n_rina_mill']);
        $this->assertContains('vol1n_rina_mill', array_column($state['players']['p1']['hand'], 'instance_id'));
    }

    public function testMia011OnEnterRevealDeckUntilLive(): void
    {
        $mia = $this->cardByNo('PL!N-bp1-011-P', 'vol1n_mia');
        $disc = $this->stubMember('vol1n_mia_disc');
        $skip = $this->stubMember('vol1n_mia_skip');
        $live = $this->stubLive('vol1n_mia_live');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $mia;
        $state['players']['p1']['hand'] = [$disc];
        $state['players']['p1']['main_deck'] = [$skip, $live];

        $state = \resolveOnEnterAbilities($state, 'p1', $mia, 'center');
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['vol1n_mia_disc'],
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertContains('vol1n_mia_live', array_column($state['players']['p1']['hand'], 'instance_id'));
        $this->assertContains('vol1n_mia_skip', array_column($state['players']['p1']['waiting_room'], 'instance_id'));
    }

    public function testLanzhu012ContinuousBladeWhenThreeNijiLives(): void
    {
        $lanzhu = $this->cardByNo('PL!N-bp1-012-P', 'vol1n_lanzhu');
        $state = $this->baseState('live_judge');
        $state['players']['p1']['stage']['center'] = $lanzhu;
        $state['players']['p1']['live_zone'] = [
            $this->stubLive('vol1n_lz1'),
            $this->stubLive('vol1n_lz2'),
            $this->stubLive('vol1n_lz3'),
        ];

        $printed = intval($lanzhu['blade'] ?? 0);
        $blade = \getMemberBlade($lanzhu, $state, 'p1', 'center');
        $this->assertGreaterThanOrEqual($printed + 2, $blade);
    }

    public function testLanzhu012ActivatedPayEnergyAddsLiveFromWr(): void
    {
        $lanzhu = $this->cardByNo('PL!N-bp1-012-P', 'vol1n_lanzhu_act');
        $live = $this->stubLive('vol1n_lz_wr');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $lanzhu;
        $state['players']['p1']['waiting_room'] = [$live];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(3);

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'vol1n_lanzhu_act',
            'ability_index' => 1,
        ]);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'vol1n_lz_wr']);
        $this->assertContains('vol1n_lz_wr', array_column($state['players']['p1']['hand'], 'instance_id'));
        $this->assertSame(0, $this->countActiveEnergy($state));
    }

    public function testPoppinUp026LiveSuccessAddsYellGroupToHand(): void
    {
        $live = $this->cardByNo('PL!N-bp1-026-L', 'vol1n_poppin');
        $yell = $this->stubMember('vol1n_yell_niji', 'Nijigasaki', 2);
        $state = $this->baseState('live_success_effects');
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['_pending_yell_wr'] = [$yell];
        // Ensure winning vs opponent so requires_winning gate passes.
        $state['players']['p1']['live_zone'][0]['score'] = 10;
        $state['players']['p2']['live_zone'] = [$this->stubLive('vol1n_opp_live', 'Liella', 1)];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$live], 0, [], [$yell]);
        if (($state['pending_prompt']['type'] ?? '') === 'pick_yell_member') {
            $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'vol1n_yell_niji']);
        }
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertContains('vol1n_yell_niji', array_column($state['players']['p1']['hand'], 'instance_id'));
    }

    public function testSolitudeRain027LiveStartScoresPerDistinctHeartColors(): void
    {
        $live = $this->cardByNo('PL!N-bp1-027-L', 'vol1n_solitude');
        $baseScore = intval($live['score'] ?? 0);
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['stage']['left'] = $this->stubMember('vol1n_sr_a', 'Nijigasaki', 3, 'red');
        $state['players']['p1']['stage']['center'] = $this->stubMember('vol1n_sr_b', 'Nijigasaki', 3, 'blue');
        $state['players']['p1']['stage']['right'] = $this->stubMember('vol1n_sr_c', 'Nijigasaki', 3, 'yellow');

        $state = $this->beginLiveStart($state);
        $afterScore = intval($state['players']['p1']['live_zone'][0]['score'] ?? 0);
        $this->assertSame($baseScore + 3, $afterScore);
    }

    public function testButterfly028LiveStartPayEnergyScoreBonus(): void
    {
        $live = $this->cardByNo('PL!N-bp1-028-L', 'vol1n_butterfly');
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['stage']['center'] = $this->stubMember('vol1n_bf_niji', 'Nijigasaki');
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(2);

        $state = $this->beginLiveStart($state);
        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('optional_pay_energy', $state['pending_prompt']['ability']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes', 'pay' => true]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(0, $this->countActiveEnergy($state));
        $this->assertSame(1, intval($state['live_modifiers']['p1']['score_bonus'] ?? 0));
    }

    public function testEutopia029LiveStartScoreIfThreeLives(): void
    {
        $live = $this->cardByNo('PL!N-bp1-029-L', 'vol1n_eutopia');
        $baseScore = intval($live['score'] ?? 0);
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['live_zone'] = [
            $live,
            $this->stubLive('vol1n_eu2'),
            $this->stubLive('vol1n_eu3'),
        ];

        $state = $this->beginLiveStart($state);
        $afterScore = intval($state['players']['p1']['live_zone'][0]['score'] ?? 0);
        $this->assertSame($baseScore + 2, $afterScore);
    }
}
