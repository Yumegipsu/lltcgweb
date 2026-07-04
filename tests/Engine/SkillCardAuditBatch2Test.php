<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class SkillCardAuditBatch2Test extends TestCase
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
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
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
                    'name' => 'Audit P1',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hand' => [],
                    'waiting_room' => [],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'Audit P2',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hand' => [],
                    'waiting_room' => [],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    private function energy(string $id, bool $active = true): array
    {
        return [
            'card_no' => 'LL-E-001-SD',
            'name' => 'Energy Card',
            'card_type' => 'エネルギー',
            'card_type_en' => 'Energy',
            'instance_id' => $id,
            'active' => $active,
        ];
    }

    public function testBp1OnEnterPromptsForWaitingRoomMemberChoice(): void
    {
        $ayumu = $this->cardByNo('LL-bp1-001-R＋', 'audit_bp1_stage');
        $first = $this->cardByNo('LL-bp2-001-R＋', 'audit_bp1_wr_first');
        $chosen = $this->cardByNo('LL-bp3-001-R＋', 'audit_bp1_wr_chosen');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $ayumu;
        $state['players']['p1']['waiting_room'] = [$first, $chosen];

        $state = \resolveOnEnterAbilities($state, 'p1', $ayumu, 'center');

        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('member', $state['pending_prompt']['wr_pick_cfg']['filter'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'audit_bp1_wr_chosen']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(['audit_bp1_wr_chosen'], array_column($state['players']['p1']['hand'], 'instance_id'));
        $this->assertSame(['audit_bp1_wr_first'], array_column($state['players']['p1']['waiting_room'], 'instance_id'));
    }

    public function testBp1LiveStartRequiresExactlyThreeNamedDiscardsForScoreBonus(): void
    {
        $ayumu = $this->cardByNo('LL-bp1-001-R＋', 'audit_bp1_stage');
        $discardA = $this->cardByNo('LL-bp1-001-R＋', 'audit_bp1_discard_a');
        $discardB = $this->cardByNo('LL-bp1-001-R＋', 'audit_bp1_discard_b');
        $discardC = $this->cardByNo('LL-bp1-001-R＋', 'audit_bp1_discard_c');

        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['stage']['center'] = $ayumu;
        $state['players']['p1']['hand'] = [$discardA, $discardB, $discardC];

        $state = \beginLiveStartEffectPhase($state, true, false);

        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(3, $state['pending_prompt']['discard_count'] ?? null);
        $this->assertSame(0, $state['pending_prompt']['max_discard'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => [
                'audit_bp1_discard_a',
                'audit_bp1_discard_b',
                'audit_bp1_discard_c',
            ],
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(3, \getLiveScoreBonus($state, 'p1'));
        $this->assertSame([], array_column($state['players']['p1']['hand'], 'instance_id'));
        $this->assertSame(
            ['audit_bp1_discard_a', 'audit_bp1_discard_b', 'audit_bp1_discard_c'],
            array_column($state['players']['p1']['waiting_room'], 'instance_id')
        );
    }

    public function testBp2ContinuousEffectsAndPartialLiveStartDiscardBladeBonus(): void
    {
        $you = $this->cardByNo('LL-bp2-001-R＋', 'audit_bp2_stage');
        $handCopy = $this->cardByNo('LL-bp2-001-R＋', 'audit_bp2_hand_cost');
        $discardA = $this->cardByNo('LL-bp2-001-R＋', 'audit_bp2_discard_a');
        $discardB = $this->cardByNo('LL-bp2-001-R＋', 'audit_bp2_discard_b');
        $other = $this->cardByNo('LL-bp1-001-R＋', 'audit_bp2_other');

        $costState = $this->baseState();
        $costState['players']['p1']['hand'] = [$handCopy, $discardA, $discardB, $other];

        $this->assertSame(17, \getEffectiveHandCost($costState, 'p1', $handCopy));
        $this->assertTrue(\memberBlocksBaton($you));

        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['stage']['center'] = $you;
        $state['players']['p1']['hand'] = [$discardA, $discardB, $other];

        $state = \beginLiveStartEffectPhase($state, true, false);

        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(2, $state['pending_prompt']['discard_count'] ?? null);
        $this->assertSame(2, $state['pending_prompt']['max_discard'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['audit_bp2_discard_a'],
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(1, \getStageBladeBonus($state, 'p1'));
        $this->assertSame(
            ['audit_bp2_discard_b', 'audit_bp2_other'],
            array_column($state['players']['p1']['hand'], 'instance_id')
        );
    }

    public function testBp3ActivatedAbilityPromptsForNamedWaitingRoomMembers(): void
    {
        $umi = $this->cardByNo('LL-bp3-001-R＋', 'audit_bp3_stage');
        $first = $this->cardByNo('LL-bp3-001-R＋', 'audit_bp3_wr_first');
        $chosen = $this->cardByNo('LL-bp3-001-R＋', 'audit_bp3_wr_chosen');
        $other = $this->cardByNo('LL-bp1-001-R＋', 'audit_bp3_wr_other');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $umi;
        $state['players']['p1']['waiting_room'] = [$first, $chosen, $other];
        $state['players']['p1']['energy_zone'] = [
            $this->energy('audit_bp3_energy_1', false),
            $this->energy('audit_bp3_energy_2', false),
        ];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'audit_bp3_stage',
            'ability_index' => 0,
        ]);

        $this->assertSame('shuffle_named_from_waiting_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(6, $state['pending_prompt']['max_pick'] ?? null);
        $this->assertSame(
            ['audit_bp3_wr_first', 'audit_bp3_wr_chosen'],
            array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id')
        );

        $state = \actionResolvePrompt($state, 'p1', ['card_ids' => ['audit_bp3_wr_chosen']]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(
            ['audit_bp3_wr_first', 'audit_bp3_wr_other'],
            array_column($state['players']['p1']['waiting_room'], 'instance_id')
        );
        $this->assertSame(['audit_bp3_wr_chosen'], array_column($state['players']['p1']['main_deck'], 'instance_id'));
        $this->assertSame(2, \countActiveEnergyInZone($state['players']['p1']));
    }

    public function testBp3LiveStartPayEnergyAppliesBladeBonus(): void
    {
        $umi = $this->cardByNo('LL-bp3-001-R＋', 'audit_bp3_stage');

        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['stage']['center'] = $umi;
        for ($i = 0; $i < 6; $i++) {
            $state['players']['p1']['energy_zone'][] = $this->energy('audit_bp3_pay_energy_' . $i);
        }

        $state = \beginLiveStartEffectPhase($state, true, false);

        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertTrue($state['pending_prompt']['needs_pay'] ?? false);
        $this->assertSame(6, $state['pending_prompt']['pay_cost'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes', 'pay' => true]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(3, \getStageBladeBonus($state, 'p1'));
        $this->assertSame(0, \countActiveEnergyInZone($state['players']['p1']));
    }
}
