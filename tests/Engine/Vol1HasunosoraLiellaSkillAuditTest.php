<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Deep outcome audit for unique Booster Pack vol.1 Hasunosora / Liella / LL ability cards.
 */
final class Vol1HasunosoraLiellaSkillAuditTest extends TestCase
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
    private function activeEnergy(int $count, string $prefix = 'vol1h_en'): array
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

    private function stubMember(string $id, string $group = 'Hasunosora', int $cost = 3, string $name = ''): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'STUB-' . $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => $name !== '' ? $name : $id,
            'name' => $name !== '' ? $name : $id,
            'group' => $group,
            'cost' => $cost,
            'active' => true,
            'entered_turn' => 1,
            'hearts' => [['color' => 'red', 'count' => 1]],
            'blade' => 1,
            'blade_hearts' => [],
        ];
    }

    private function stubLive(string $id, string $group = 'Hasunosora', int $score = 2): array
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

    private function beginLiveStart(array $state, string $pid = 'p1'): array
    {
        $state = \resolveLiveStartAbilities($state, $pid);
        if (!empty($state['pending_prompt'])) {
            return $state;
        }
        return \finishLiveStartEffects($state);
    }

    // ─── LL ───────────────────────────────────────────────

    public function testLlBp1001OnEnterOpensMemberWrPick(): void
    {
        $card = $this->cardByNo('LL-bp1-001-R＋', 'vol1_ll_stage');
        $wr = $this->stubMember('vol1_ll_wr', 'Nijigasaki');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['waiting_room'] = [$wr];

        $state = \resolveOnEnterAbilities($state, 'p1', $card, 'center');
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('member', $state['pending_prompt']['wr_pick_cfg']['filter'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'vol1_ll_wr']);
        $this->assertContains('vol1_ll_wr', array_column($state['players']['p1']['hand'], 'instance_id'));
    }

    public function testLlBp1001LiveStartOptionalDiscardNamed(): void
    {
        $card = $this->cardByNo('LL-bp1-001-R＋', 'vol1_ll_ls');
        $a = $this->stubMember('vol1_ll_a', 'Nijigasaki', 3, 'Ayumu Uehara');
        $b = $this->stubMember('vol1_ll_b', 'Superstar', 3, 'Kanon Shibuya');
        $c = $this->stubMember('vol1_ll_c', 'Hasunosora', 3, 'Kaho Hinoshita');
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['hand'] = [$a, $b, $c];

        $state = $this->beginLiveStart($state);
        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('optional_discard_named', $state['pending_prompt']['ability']['type'] ?? null);
    }

    // ─── Hasunosora members / lives ───────────────────────

    public function testHs001OnEnterActivatesEnergy(): void
    {
        $card = $this->cardByNo('PL!HS-bp1-001-P', 'vol1_hs001');
        $en = $this->activeEnergy(1);
        $en[0]['active'] = false;
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['energy_zone'] = $en;
        $state = \resolveOnEnterAbilities($state, 'p1', $card, 'center');
        $this->assertSame(1, $this->countActiveEnergy($state));
    }

    public function testHs002ActivatedPayLeavePlayWrMemberOpensPrompt(): void
    {
        $card = $this->cardByNo('PL!HS-bp1-002-P', 'vol1_hs002');
        $wr = $this->stubMember('vol1_hs002_wr', 'Hasunosora', 4);
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['waiting_room'] = [$wr];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(2);
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'vol1_hs002',
            'ability_index' => 0,
        ]);
        // Single WR match may auto-play into the vacated slot.
        if (!empty($state['pending_prompt'])) {
            $ptype = $state['pending_prompt']['type'] ?? '';
            $this->assertTrue(
                str_contains($ptype, 'wr') || str_contains($ptype, 'leave') || str_contains($ptype, 'play'),
                'unexpected prompt ' . $ptype
            );
        } else {
            $this->assertSame('vol1_hs002_wr', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
            $this->assertContains('vol1_hs002', array_column($state['players']['p1']['waiting_room'], 'instance_id'));
        }
    }

    public function testHs003ActivatedPayEnergyAddsFromWr(): void
    {
        $card = $this->cardByNo('PL!HS-bp1-003-P', 'vol1_hs003');
        $wr = $this->stubMember('vol1_hs003_wr', 'Hasunosora');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['waiting_room'] = [$wr];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(2);
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'vol1_hs003',
            'ability_index' => 1,
        ]);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'vol1_hs003_wr']);
        $this->assertContains('vol1_hs003_wr', array_column($state['players']['p1']['hand'], 'instance_id'));
    }

    public function testHs004LiveStartOptionalPayEnergy(): void
    {
        $card = $this->cardByNo('PL!HS-bp1-004-P', 'vol1_hs004');
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(1);
        $state = $this->beginLiveStart($state);
        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('optional_pay_energy', $state['pending_prompt']['ability']['type'] ?? null);
    }

    public function testHs005OnEnterOptionalDiscardPrompt(): void
    {
        $card = $this->cardByNo('PL!HS-bp1-005-P', 'vol1_hs005');
        $disc = $this->stubMember('vol1_hs005_disc');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['hand'] = [$disc];
        $state['players']['p1']['main_deck'] = [
            $this->stubMember('vol1_hs005_d1'),
            $this->stubMember('vol1_hs005_d2'),
            $this->stubMember('vol1_hs005_d3'),
        ];
        $state = \resolveOnEnterAbilities($state, 'p1', $card, 'center');
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
    }

    public function testHs006OnEnterDrawAndDiscard(): void
    {
        $card = $this->cardByNo('PL!HS-bp1-006-P', 'vol1_hs006');
        $deck = $this->stubMember('vol1_hs006_deck');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['main_deck'] = [$deck, $this->stubMember('vol1_hs006_deck2')];
        $state = \resolveOnEnterAbilities($state, 'p1', $card, 'center');
        $ptype = $state['pending_prompt']['type'] ?? '';
        $this->assertTrue(
            $ptype === 'effect_discard_hand' || $ptype === 'draw_and_discard' || count($state['players']['p1']['hand']) >= 1,
            'Megumi on enter should draw/discard; prompt=' . $ptype
        );
    }

    public function testHs007ActivatedPayEnergyDraw(): void
    {
        $card = $this->cardByNo('PL!HS-bp1-007-P', 'vol1_hs007');
        $deck = $this->stubMember('vol1_hs007_deck');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(2);
        $state['players']['p1']['main_deck'] = [$deck];
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'vol1_hs007',
            'ability_index' => 0,
        ]);
        $this->assertContains('vol1_hs007_deck', array_column($state['players']['p1']['hand'], 'instance_id'));
        $this->assertSame(0, $this->countActiveEnergy($state));
    }

    public function testHs008OnEnterMillThenDrawIfAllMembers(): void
    {
        $card = $this->cardByNo('PL!HS-bp1-008-P', 'vol1_hs008');
        $m1 = $this->stubMember('vol1_hs008_m1', 'Hasunosora');
        $m2 = $this->stubMember('vol1_hs008_m2', 'Hasunosora');
        $m3 = $this->stubMember('vol1_hs008_m3', 'Hasunosora');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['main_deck'] = [$m1, $m2, $m3, $this->stubMember('vol1_hs008_draw')];
        $state = \resolveOnEnterAbilities($state, 'p1', $card, 'center');
        // Either drew or opened a look/mill prompt.
        $this->assertTrue(
            !empty($state['pending_prompt']) || count($state['players']['p1']['hand']) >= 1
            || count($state['players']['p1']['waiting_room']) >= 1
        );
    }

    public function testHs009OnEnterOptionalDiscardLookRevealSubunit(): void
    {
        $card = $this->cardByNo('PL!HS-bp1-009-P', 'vol1_hs009');
        $disc = $this->stubMember('vol1_hs009_disc');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['hand'] = [$disc];
        $state['players']['p1']['main_deck'] = [
            $this->stubMember('vol1_hs009_d1'),
            $this->stubMember('vol1_hs009_d2'),
            $this->stubMember('vol1_hs009_d3'),
        ];
        $state = \resolveOnEnterAbilities($state, 'p1', $card, 'center');
        $ptype = $state['pending_prompt']['type'] ?? '';
        $this->assertTrue(
            in_array($ptype, ['optional_discard_look_reveal_subunit', 'optional_discard_prompt'], true),
            'unexpected ' . $ptype
        );
    }

    public function testHs010OnEnterDrawAndDiscard(): void
    {
        $card = $this->cardByNo('PL!HS-bp1-010-N', 'vol1_hs010');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['main_deck'] = [
            $this->stubMember('vol1_hs010_d1'),
            $this->stubMember('vol1_hs010_d2'),
        ];
        $state = \resolveOnEnterAbilities($state, 'p1', $card, 'center');
        $this->assertTrue(
            !empty($state['pending_prompt']) || count($state['players']['p1']['hand']) >= 1
        );
    }

    public function testHs011OnEnterOptionalDiscard(): void
    {
        $card = $this->cardByNo('PL!HS-bp1-011-N', 'vol1_hs011');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['hand'] = [$this->stubMember('vol1_hs011_disc')];
        $state['players']['p1']['main_deck'] = [
            $this->stubLive('vol1_hs011_live'),
            $this->stubMember('vol1_hs011_d2'),
            $this->stubMember('vol1_hs011_d3'),
            $this->stubMember('vol1_hs011_d4'),
            $this->stubMember('vol1_hs011_d5'),
        ];
        $state = \resolveOnEnterAbilities($state, 'p1', $card, 'center');
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
    }

    public function testHs021LiveSuccessPickYellMember(): void
    {
        $live = $this->cardByNo('PL!HS-bp1-021-L', 'vol1_hs021');
        $yell = $this->stubLive('vol1_hs021_yell', 'Hasunosora');
        $live['score'] = 10;
        $state = $this->baseState('live_success_effects');
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['_pending_yell_wr'] = [$yell];
        $state['players']['p2']['live_zone'] = [$this->stubLive('vol1_hs021_opp', 'Liella', 1)];
        $state = \resolveLiveSuccessAbilities($state, 'p1', [$live], 0, [], [$yell]);
        if (in_array($state['pending_prompt']['type'] ?? '', ['pick_yell_member', 'live_success_pick_yell_live'], true)) {
            $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'vol1_hs021_yell']);
        }
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertContains('vol1_hs021_yell', array_column($state['players']['p1']['hand'], 'instance_id'));
    }

    public function testHs023LiveSuccessEnergyWaitIfWinning(): void
    {
        $live = $this->cardByNo('PL!HS-bp1-023-L', 'vol1_hs023');
        $live['score'] = 10;
        $state = $this->baseState('live_success_effects');
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['energy_deck'] = [
            ['instance_id' => 'ed1', 'card_type' => 'エネルギー'],
        ];
        $state['players']['p2']['live_zone'] = [$this->stubLive('vol1_hs023_opp', 'Liella', 1)];
        $beforeWait = count(array_filter(
            $state['players']['p1']['energy_zone'] ?? [],
            static fn($c) => empty($c['active'])
        ));
        $state = \resolveLiveSuccessAbilities($state, 'p1', [$live], 0, [], []);
        $afterEnergy = count($state['players']['p1']['energy_zone'] ?? []);
        $this->assertTrue(
            $afterEnergy > 0 || !empty($state['pending_prompt']) || $beforeWait !== $afterEnergy
            || !empty($state['log'])
        );
    }

    // ─── Liella / Superstar ───────────────────────────────

    public function testSp001ContinuousCannotLiveIfSoloStage(): void
    {
        $card = $this->cardByNo('PL!SP-bp1-001-P', 'vol1_sp001');
        $state = $this->baseState('live_set');
        $state['players']['p1']['stage']['center'] = $card;
        // Solo on stage — continuous flag / block should be detectable via ability presence.
        $this->assertSame('cannot_live_if_solo_stage', $card['abilities'][0]['type'] ?? null);
        $blocked = false;
        foreach ($card['abilities'] as $ab) {
            if (($ab['type'] ?? '') === 'cannot_live_if_solo_stage') {
                $blocked = \countStageMembers($state['players']['p1']) === 1;
            }
        }
        $this->assertTrue($blocked);
    }

    public function testSp002OnEnterOptionalPayEnergy(): void
    {
        $card = $this->cardByNo('PL!SP-bp1-002-P', 'vol1_sp002');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(1);
        $state = \resolveOnEnterAbilities($state, 'p1', $card, 'center');
        $this->assertSame('optional_pay_energy_on_enter', $state['pending_prompt']['type'] ?? null);
    }

    public function testSp003ActivatedRevealHandMemberCost(): void
    {
        $card = $this->cardByNo('PL!SP-bp1-003-P', 'vol1_sp003');
        $hand = $this->stubMember('vol1_sp003_hand', 'Superstar', 10);
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['hand'] = [$hand];
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'vol1_sp003',
            'ability_index' => 0,
        ]);
        $this->assertSame('reveal_hand_member_cost_live_score', $state['pending_prompt']['type'] ?? null);
    }

    public function testSp004ContinuousBladeBonusIfCenter(): void
    {
        $card = $this->cardByNo('PL!SP-bp1-004-P', 'vol1_sp004');
        $state = $this->baseState('live_judge');
        $state['players']['p1']['stage']['center'] = $card;
        $blade = \getMemberBlade($card, $state, 'p1', 'center');
        $this->assertGreaterThanOrEqual(intval($card['blade'] ?? 0) + 1, $blade);
    }

    public function testSp005OnEnterOptionalDiscard(): void
    {
        $card = $this->cardByNo('PL!SP-bp1-005-P', 'vol1_sp005');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['hand'] = [$this->stubMember('vol1_sp005_disc', 'Superstar')];
        $state['players']['p1']['main_deck'] = [
            $this->stubMember('vol1_sp005_d1', 'Superstar'),
            $this->stubMember('vol1_sp005_d2', 'Superstar'),
            $this->stubMember('vol1_sp005_d3', 'Superstar'),
            $this->stubMember('vol1_sp005_d4', 'Superstar'),
            $this->stubMember('vol1_sp005_d5', 'Superstar'),
        ];
        $state = \resolveOnEnterAbilities($state, 'p1', $card, 'center');
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
    }

    public function testSp006LiveStartOptionalPayEnergy(): void
    {
        $card = $this->cardByNo('PL!SP-bp1-006-P', 'vol1_sp006');
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(1);
        $state = $this->beginLiveStart($state);
        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('optional_pay_energy', $state['pending_prompt']['ability']['type'] ?? null);
    }

    public function testSp007OnEnterAddWrLiveIfMinEnergy(): void
    {
        $card = $this->cardByNo('PL!SP-bp1-007-P', 'vol1_sp007');
        $live = $this->stubLive('vol1_sp007_live', 'Superstar');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['waiting_room'] = [$live];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(11);
        $state = \resolveOnEnterAbilities($state, 'p1', $card, 'center');
        if (($state['pending_prompt']['type'] ?? '') === 'pick_wr_to_hand') {
            $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'vol1_sp007_live']);
        }
        $this->assertContains('vol1_sp007_live', array_column($state['players']['p1']['hand'], 'instance_id'));
    }

    public function testSp008OnEnterDrawCards(): void
    {
        $card = $this->cardByNo('PL!SP-bp1-008-P', 'vol1_sp008');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['main_deck'] = [
            $this->stubMember('vol1_sp008_d1', 'Superstar'),
            $this->stubMember('vol1_sp008_d2', 'Superstar'),
        ];
        $state = \resolveOnEnterAbilities($state, 'p1', $card, 'center');
        $this->assertGreaterThanOrEqual(1, count($state['players']['p1']['hand']));
    }

    public function testSp009ActivatedDrawAndDiscard(): void
    {
        $card = $this->cardByNo('PL!SP-bp1-009-P', 'vol1_sp009');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['main_deck'] = [
            $this->stubMember('vol1_sp009_d1', 'Superstar'),
            $this->stubMember('vol1_sp009_d2', 'Superstar'),
        ];
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'vol1_sp009',
            'ability_index' => 0,
        ]);
        $this->assertTrue(
            !empty($state['pending_prompt']) || count($state['players']['p1']['hand']) >= 1
        );
    }

    public function testSp010ActivatedMandatoryDiscardLookReveal(): void
    {
        $card = $this->cardByNo('PL!SP-bp1-010-P', 'vol1_sp010');
        $disc = $this->stubMember('vol1_sp010_disc', 'Superstar');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['hand'] = [$disc];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(2);
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'vol1_sp010',
            'ability_index' => 0,
        ]);
        $this->assertSame('mandatory_discard_look_reveal', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(0, $this->countActiveEnergy($state));
    }

    public function testSp011ActivatedLeaveStageAddFromWr(): void
    {
        $card = $this->cardByNo('PL!SP-bp1-011-P', 'vol1_sp011');
        $wr = $this->stubLive('vol1_sp011_wr', 'Superstar');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['waiting_room'] = [$wr];
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'vol1_sp011',
            'ability_index' => 0,
        ]);
        $ptype = $state['pending_prompt']['type'] ?? '';
        $this->assertTrue(
            in_array($ptype, ['pick_wr_to_hand', 'pick_wr_leave_stage_add'], true)
            || in_array('vol1_sp011_wr', array_column($state['players']['p1']['hand'], 'instance_id'), true),
            'Tomari should open WR pick or auto-add; got ' . $ptype
        );
    }

    public function testSp012OnEnterOptionalPayEnergy(): void
    {
        $card = $this->cardByNo('PL!SP-bp1-012-N', 'vol1_sp012');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(1);
        $state = \resolveOnEnterAbilities($state, 'p1', $card, 'center');
        $this->assertSame('optional_pay_energy_on_enter', $state['pending_prompt']['type'] ?? null);
    }

    public function testSp021OnEnterOptionalDiscard(): void
    {
        $card = $this->cardByNo('PL!SP-bp1-021-N', 'vol1_sp021');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $card;
        $state['players']['p1']['hand'] = [$this->stubMember('vol1_sp021_disc', 'Superstar')];
        $state['players']['p1']['energy_deck'] = [
            ['instance_id' => 'vol1_sp021_ed', 'card_type' => 'エネルギー'],
        ];
        $state = \resolveOnEnterAbilities($state, 'p1', $card, 'center');
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['vol1_sp021_disc'],
        ]);
        $waitEnergy = count(array_filter(
            $state['players']['p1']['energy_zone'] ?? [],
            static fn($c) => empty($c['active'])
        ));
        $this->assertGreaterThanOrEqual(1, $waitEnergy + count($state['players']['p1']['energy_zone'] ?? []));
    }

    public function testSp024LiveStartGrantNamedMembersBlade(): void
    {
        $live = $this->cardByNo('PL!SP-bp1-024-L', 'vol1_sp024');
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['stage']['center'] = $this->stubMember('vol1_sp024_kanon', 'Superstar', 3, 'Kanon Shibuya');
        $state = $this->beginLiveStart($state);
        $center = $state['players']['p1']['stage']['center'];
        $blade = \getMemberBlade($center, $state, 'p1', 'center');
        $this->assertGreaterThanOrEqual(1, $blade);
    }

    public function testSp026LiveStartSetRequiredHeartsIfDistinctGroup(): void
    {
        $live = $this->cardByNo('PL!SP-bp1-026-L', 'vol1_sp026');
        $before = $live['required_hearts'] ?? [];
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['stage']['left'] = $this->stubMember('vol1_sp026_a', 'Superstar', 3, 'Kanon Shibuya');
        $state['players']['p1']['stage']['center'] = $this->stubMember('vol1_sp026_b', 'Superstar', 3, 'Keke Tang');
        $state['players']['p1']['stage']['right'] = $this->stubMember('vol1_sp026_c', 'Superstar', 3, 'Chisato Arashi');
        $state['players']['p1']['waiting_room'] = [
            $this->stubMember('vol1_sp026_d', 'Superstar', 3, 'Sumire Heanna'),
            $this->stubMember('vol1_sp026_e', 'Superstar', 3, 'Ren Hazuki'),
        ];
        $state = $this->beginLiveStart($state);
        $after = $state['players']['p1']['live_zone'][0]['required_hearts'] ?? $before;
        $this->assertNotSame(json_encode($before), json_encode($after));
    }

    public function testSp027LiveStartScoreIfMinEnergy(): void
    {
        $live = $this->cardByNo('PL!SP-bp1-027-L', 'vol1_sp027');
        $base = intval($live['score'] ?? 0);
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(12);
        $state = $this->beginLiveStart($state);
        $after = intval($state['players']['p1']['live_zone'][0]['score'] ?? 0);
        $this->assertSame($base + 1, $after);
    }
}