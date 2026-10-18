<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class SetsunaLeavePlayFromHandTest extends TestCase
{
    private function energyChip(string $iid, bool $active = true): array
    {
        return [
            'instance_id' => $iid,
            'card_no' => 'LL-E-001-SD',
            'card_type' => 'エネルギー',
            'card_type_en' => 'Energy',
            'name' => 'Energy',
            'name_en' => 'Energy',
            'active' => $active,
        ];
    }

    private function setsunaStage(): array
    {
        return [
            'instance_id' => 'stage_setsuna',
            'card_no' => 'PL!N-bp3-007-R',
            'name' => '優木せつ菜',
            'name_en' => 'Setsuna Yuki',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Nijigasaki',
            'cost' => 9,
            'blade' => 3,
            'hearts' => [
                ['color' => 'pink', 'count' => 1],
                ['color' => 'red', 'count' => 1],
                ['color' => 'blue', 'count' => 1],
            ],
            'abilities' => [[
                'trigger' => 'activated',
                'type' => 'leave_play_named_from_hand_stack_energy',
                'names' => ['Setsuna Yuki', '優木せつ菜'],
                'max_cost' => 13,
                'energy' => 1,
                'energy_cost' => 2,
            ]],
        ];
    }

    private function setsunaHand(string $iid, int $cost = 5): array
    {
        return [
            'instance_id' => $iid,
            'card_no' => 'PL!N-bp1-007-P',
            'name' => '優木せつ菜',
            'name_en' => 'Setsuna Yuki',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Nijigasaki',
            'cost' => $cost,
            'blade' => 1,
            'hearts' => [['color' => 'red', 'count' => 1]],
            'abilities' => [],
        ];
    }

    private function baseState(array $stageSetsuna, array $hand): array
    {
        return [
            'room_id' => 'SETSUNA1',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'main_first',
            'active_player' => 'p1',
            'first_player' => 'p1',
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => $hand,
                    'stage' => ['left' => null, 'center' => $stageSetsuna, 'right' => null],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'energy_zone' => [
                        $this->energyChip('e1'),
                        $this->energyChip('e2'),
                        $this->energyChip('e3'),
                    ],
                    'live_zone' => [],
                    'live_storage' => [],
                    'success_lives' => [],
                    'discard' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'energy_zone' => [],
                    'live_zone' => [],
                    'live_storage' => [],
                    'success_lives' => [],
                    'discard' => [],
                ],
            ],
        ];
    }

    public function testActivateWithoutHandIdThrows(): void
    {
        $stage = $this->setsunaStage();
        $handMember = $this->setsunaHand('hand_setsuna', 5);
        $state = $this->baseState($stage, [$handMember]);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Choose a Member from hand');
        applyAction($state, 'p1', 'activate_ability', [
            'card_id' => 'stage_setsuna',
            'ability_index' => 0,
        ]);
    }

    public function testActivateWithHandCardSwapsAndStacksEnergy(): void
    {
        $stage = $this->setsunaStage();
        $handMember = $this->setsunaHand('hand_setsuna', 5);
        $state = $this->baseState($stage, [$handMember]);
        $after = applyAction($state, 'p1', 'activate_ability', [
            'card_id' => 'stage_setsuna',
            'ability_index' => 0,
            'hand_card_id' => 'hand_setsuna',
        ]);
        $p1 = $after['players']['p1'];
        $this->assertSame('hand_setsuna', $p1['stage']['center']['instance_id'] ?? null);
        $this->assertCount(1, $p1['waiting_room']);
        $this->assertSame('stage_setsuna', $p1['waiting_room'][0]['instance_id'] ?? null);
        $this->assertCount(0, $p1['hand']);
        // Paid 2 Energy (rested in zone); stacked 1 under the new Member.
        $active = count(array_filter(
            $p1['energy_zone'] ?? [],
            static fn($e) => !empty($e['active'])
        ));
        $this->assertSame(0, $active);
        $this->assertCount(2, $p1['energy_zone']);
        $stacked = countMemberStackedEnergy($p1, $p1['stage']['center']);
        $this->assertSame(1, $stacked);
        $this->assertTrue(!empty($p1['stage']['center']['entered_from_hand']));
    }

    public function testLeavePlayReturnsStackedEnergyAndFiresOnEnter(): void
    {
        $stage = $this->setsunaStage();
        $stage['stacked_energy'] = [
            $this->energyChip('under1', false),
        ];
        $handMember = $this->setsunaHand('hand_setsuna', 5);
        $handMember['card_no'] = 'TEST-ONENTER-DRAW';
        $handMember['abilities'] = [[
            'trigger' => 'on_enter',
            'type' => 'draw',
            'count' => 1,
        ]];
        $state = $this->baseState($stage, [$handMember]);
        $state['players']['p1']['main_deck'] = [[
            'instance_id' => 'deck1',
            'card_no' => 'PL!N-bp1-001-P',
            'name' => '上原歩夢',
            'name_en' => 'Ayumu Uehara',
            'card_type' => 'メンバー',
        ]];
        $zoneBefore = count($state['players']['p1']['energy_zone']);
        $after = applyAction($state, 'p1', 'activate_ability', [
            'card_id' => 'stage_setsuna',
            'ability_index' => 0,
            'hand_card_id' => 'hand_setsuna',
        ]);
        $p1 = $after['players']['p1'];
        $this->assertSame('hand_setsuna', $p1['stage']['center']['instance_id'] ?? null);
        $this->assertSame('TEST-ONENTER-DRAW', $p1['stage']['center']['card_no'] ?? null);
        // Leaving Setsuna's stacked Energy returns to the Energy deck.
        $deckIds = array_map(
            static fn($e) => (string)($e['instance_id'] ?? ''),
            $p1['energy_deck'] ?? []
        );
        $this->assertContains('under1', $deckIds);
        // Leaving Member is in WR, or was shuffled into the deck after On Enter drew the last card.
        $leftIds = array_map(
            static fn($c) => (string)($c['instance_id'] ?? ''),
            array_merge($p1['waiting_room'] ?? [], $p1['main_deck'] ?? [])
        );
        $this->assertContains('stage_setsuna', $leftIds);
        foreach (array_merge($p1['waiting_room'] ?? [], $p1['main_deck'] ?? []) as $c) {
            if (($c['instance_id'] ?? '') === 'stage_setsuna') {
                $this->assertEmpty($c['stacked_energy'] ?? []);
            }
        }
        // Zone: original 3 − 1 stacked under the new Member. The leaving stack went to the deck.
        $this->assertCount($zoneBefore - 1, $p1['energy_zone']);
        $this->assertSame(1, countMemberStackedEnergy($p1, $p1['stage']['center']));
        // On Enter draw from the Member played from hand.
        $this->assertCount(1, $p1['hand']);
        $this->assertSame('deck1', $p1['hand'][0]['instance_id'] ?? null);
    }

    public function testAbilityBlockedWithoutMatchingHandMember(): void
    {
        $stage = $this->setsunaStage();
        $other = [
            'instance_id' => 'hand_other',
            'card_no' => 'PL!N-bp1-001-P',
            'name' => '上原歩夢',
            'name_en' => 'Ayumu Uehara',
            'card_type' => 'メンバー',
            'cost' => 3,
        ];
        $state = $this->baseState($stage, [$other]);
        $reason = activatedAbilityWrBlockReason(
            $state['players']['p1'],
            $stage['abilities'][0]
        );
        $this->assertNotNull($reason);
        $this->assertStringContainsString('hand', strtolower((string)$reason));
    }

    public function testPlayMemberOntoEmptyStage(): void
    {
        $card = $this->setsunaStage();
        $card['instance_id'] = 'hand_bp3';
        unset($card['stacked_energy']);
        $state = $this->baseState(
            [
                'instance_id' => 'filler',
                'card_no' => 'PL!N-bp1-001-P',
                'name' => '上原歩夢',
                'name_en' => 'Ayumu Uehara',
                'card_type' => 'メンバー',
                'cost' => 1,
                'blade' => 1,
                'hearts' => [['color' => 'pink', 'count' => 1]],
                'abilities' => [],
            ],
            [$card]
        );
        // Need enough Energy to pay cost 9.
        $state['players']['p1']['energy_zone'] = [];
        for ($i = 1; $i <= 9; $i++) {
            $state['players']['p1']['energy_zone'][] = $this->energyChip('pay' . $i, true);
        }
        $state['players']['p1']['stage'] = ['left' => null, 'center' => null, 'right' => null];
        $after = applyAction($state, 'p1', 'play_member', [
            'card_id' => 'hand_bp3',
            'slot' => 'center',
        ]);
        $center = $after['players']['p1']['stage']['center'] ?? null;
        $this->assertNotNull($center);
        $this->assertSame('hand_bp3', $center['instance_id'] ?? null);
        $this->assertSame('PL!N-bp3-007-R', $center['card_no'] ?? null);
        $this->assertCount(0, $after['players']['p1']['hand']);
    }
}
