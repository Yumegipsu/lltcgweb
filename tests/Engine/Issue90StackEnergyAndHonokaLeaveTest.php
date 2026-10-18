<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression: stack Energy under Members + Honoka leave Stage (#90). */
final class Issue90StackEnergyAndHonokaLeaveTest extends TestCase
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

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'log' => [],
            'players' => [
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
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];
    }

    public function testHonokaBp6010LeavesStageThenWaitsOpp(): void
    {
        $honoka = $this->cardByNo('PL!-bp6-010-N', 'issue90_honoka');
        $opp = $this->cardByNo('PL!-bp6-010-N', 'issue90_opp');
        $opp['cost'] = 3;

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $honoka;
        $state['players']['p1']['main_deck'] = [['instance_id' => 'deck_pad']];
        $state['players']['p2']['stage']['left'] = $opp;
        $state['players']['p2']['stage']['center'] = $this->cardByNo('PL!-bp6-006-P', 'issue90_opp_hi');
        $state['players']['p2']['stage']['center']['cost'] = 17;

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'issue90_honoka',
            'ability_index' => 0,
        ]);

        $this->assertNull($state['players']['p1']['stage']['center'], 'Honoka must leave Stage');
        $wrIds = array_column($state['players']['p1']['waiting_room'] ?? [], 'instance_id');
        $this->assertContains('issue90_honoka', $wrIds);

        // Auto-wait when only one legal target (cost ≤4).
        $this->assertTrue(
            \memberIsInWait($state['players']['p2']['stage']['left'])
            || ($state['pending_prompt']['type'] ?? null) === 'wait_opponent_stage_pick'
        );
        if (($state['pending_prompt']['type'] ?? null) === 'wait_opponent_stage_pick') {
            $state = \actionResolvePrompt($state, 'p1', ['slot' => 'left']);
            $this->assertTrue(\memberIsInWait($state['players']['p2']['stage']['left']));
        }
    }

    public function testMiaStacksRestedEnergyWithoutError(): void
    {
        $mia = $this->cardByNo('PL!N-pb1-011-R', 'issue90_mia');
        $live = [
            'instance_id' => 'issue90_live',
            'card_type' => 'ライブ',
            'group' => 'Nijigasaki',
            'name_en' => 'Niji Live',
        ];

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $mia;
        $state['players']['p1']['waiting_room'] = [$live];
        $state['players']['p1']['energy_zone'] = [
            ['instance_id' => 'e_used', 'active' => false, 'card_type' => 'エネルギー'],
        ];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'issue90_mia',
            'ability_index' => 1,
        ]);

        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $stacked = $state['players']['p1']['stage']['center']['stacked_energy']
            ?? $state['players']['p1']['stage']['center']['stacked_energy_ids']
            ?? [];
        $this->assertNotEmpty($stacked);
        $this->assertCount(0, $state['players']['p1']['energy_zone']);
    }

    public function testMiaMultipleEnergyOpensPickPreferringActive(): void
    {
        $mia = $this->cardByNo('PL!N-pb1-011-R', 'issue90_mia2');
        $live = [
            'instance_id' => 'issue90_live2',
            'card_type' => 'ライブ',
            'group' => 'Nijigasaki',
            'name_en' => 'Niji Live',
        ];

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $mia;
        $state['players']['p1']['waiting_room'] = [$live];
        $state['players']['p1']['energy_zone'] = [
            ['instance_id' => 'e_used', 'active' => false, 'card_type' => 'エネルギー'],
            ['instance_id' => 'e_free', 'active' => true, 'card_type' => 'エネルギー'],
        ];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'issue90_mia2',
            'ability_index' => 1,
        ]);

        $this->assertSame('stack_energy_zone_pick', $state['pending_prompt']['type'] ?? null);
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertSame(['e_free', 'e_used'], $candIds, 'unused Energy listed first');

        $state = \actionResolvePrompt($state, 'p1', ['energy_ids' => ['e_free']]);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $zoneIds = array_column($state['players']['p1']['energy_zone'] ?? [], 'instance_id');
        $this->assertSame(['e_used'], $zoneIds);
    }

    public function testTakeEnergyFromZonePrefersActive(): void
    {
        $p = [
            'energy_zone' => [
                ['instance_id' => 'u1', 'active' => false],
                ['instance_id' => 'a1', 'active' => true],
                ['instance_id' => 'u2', 'active' => false],
            ],
        ];
        $taken = \takeEnergyFromZoneForStack($p, 1);
        $this->assertSame(['a1'], array_column($taken, 'instance_id'));
        $this->assertCount(2, $p['energy_zone']);
    }

    /** #290 — energy stacked under Ayumu returns to the Energy deck on Baton Touch. */
    public function testAyumuBatonReturnsStackedEnergyToDeck(): void
    {
        $ayumu = $this->cardByNo('PL!N-bp3-001-P', 'ayumu_stage');
        $ayumu['entered_turn'] = 1;
        $ayumu['stacked_energy'] = [[
            'instance_id' => 'under_ayumu',
            'card_type' => 'エネルギー',
            'active' => false,
        ]];
        $incoming = $this->cardByNo('PL!N-bp3-001-P', 'ayumu_in');

        $state = $this->baseState();
        $state['turn'] = 3;
        $state['players']['p1']['stage']['center'] = $ayumu;
        $state['players']['p1']['hand'] = [$incoming];
        $state['players']['p1']['main_deck'] = [['instance_id' => 'deck_pad', 'card_type' => 'ライブ']];
        $state['players']['p1']['energy_deck'] = [['instance_id' => 'ed_existing', 'card_type' => 'エネルギー']];

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'ayumu_in',
            'slot' => 'center',
            'baton_id' => 'ayumu_stage',
        ]);

        $onStage = $state['players']['p1']['stage']['center'] ?? null;
        $this->assertSame('ayumu_in', $onStage['instance_id'] ?? null);
        $this->assertSame([], $onStage['stacked_energy'] ?? []);
        $deckIds = array_column($state['players']['p1']['energy_deck'] ?? [], 'instance_id');
        $this->assertSame(['ed_existing', 'under_ayumu'], $deckIds);
        $left = null;
        foreach ($state['players']['p1']['waiting_room'] ?? [] as $c) {
            if (($c['instance_id'] ?? '') === 'ayumu_stage') {
                $left = $c;
            }
        }
        $this->assertNotNull($left);
        $this->assertSame([], $left['stacked_energy'] ?? []);
    }
}
