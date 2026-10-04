<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!HS-bp6-032 Fusion Crust — Live Success Yell pick is Members with cost ≤4 (#241).
 * cardMatchesYellPick ignored max_member_cost when filter=member (only checked max_cost),
 * so cost 5+ Members were offered.
 */
final class Issue241FusionCrustYellCostCapTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function member(string $id, int $cost): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => "Member cost $cost",
            'group' => 'Hasunosora',
            'cost' => $cost,
        ];
    }

    public function testCardMatchesYellPickHonorsMaxMemberCost(): void
    {
        $ab = [
            'filter' => 'member',
            'max_member_cost' => 4,
        ];
        $this->assertTrue(cardMatchesYellPick($this->member('a', 4), $ab));
        $this->assertTrue(cardMatchesYellPick($this->member('b', 0), $ab));
        $this->assertFalse(cardMatchesYellPick($this->member('c', 5), $ab));
        $this->assertFalse(cardMatchesYellPick($this->member('d', 13), $ab));
    }

    public function testFusionCrustLiveSuccessFiltersYellPool(): void
    {
        $fusion = $this->cardByNo('PL!HS-bp6-032-L', 'fusion');
        $ab = $fusion['abilities'][0];
        $this->assertSame('live_success_pick_yell_card', $ab['type'] ?? null);
        $this->assertSame(4, intval($ab['max_member_cost'] ?? 0));

        $low = $this->member('low', 3);
        $exact = $this->member('exact', 4);
        $high = $this->member('high', 8);
        $live = [
            'instance_id' => 'ylive',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'Yell Live',
            'score' => 1,
        ];

        $eligible = filterYellPoolForAbility([$low, $exact, $high, $live], $ab);
        $ids = array_column($eligible, 'instance_id');
        $this->assertContains('low', $ids);
        $this->assertContains('exact', $ids);
        $this->assertNotContains('high', $ids);
        $this->assertNotContains('ylive', $ids);
    }

    public function testLiveSuccessOpensPickWithoutHighCostMembers(): void
    {
        $fusion = $this->cardByNo('PL!HS-bp6-032-L', 'fusion');
        $low = $this->member('low', 2);
        $high = $this->member('high', 10);
        $state = [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 2,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [$fusion],
                    'energy_zone' => [],
                    'main_deck' => [],
                    '_pending_yell_wr' => [$low, $high],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                ],
            ],
        ];

        $state = resolveAbilityEffect($state, 'p1', $fusion, $fusion['abilities'][0], [
            'phase' => 'live_success',
            'yell_cards' => [$low, $high],
        ]);

        // Only one eligible → auto-adds to hand (no prompt).
        $this->assertNull($state['pending_prompt'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertSame(['low'], $handIds);
        $pendingIds = array_column($state['players']['p1']['_pending_yell_wr'], 'instance_id');
        $this->assertContains('high', $pendingIds);
        $this->assertNotContains('low', $pendingIds);
    }

    public function testHighCostOnlyDoesNotOpenPick(): void
    {
        $fusion = $this->cardByNo('PL!HS-bp6-032-L', 'fusion');
        $high = $this->member('high', 9);
        $state = [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 2,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [$fusion],
                    'energy_zone' => [],
                    'main_deck' => [],
                    '_pending_yell_wr' => [$high],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                ],
            ],
        ];

        $state = resolveAbilityEffect($state, 'p1', $fusion, $fusion['abilities'][0], [
            'phase' => 'live_success',
            'yell_cards' => [$high],
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame([], $state['players']['p1']['hand']);
    }
}
