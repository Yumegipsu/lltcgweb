<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-006 Maki Activated Wait-self + discard → Wait opp ≤1 printed heart (#250).
 *
 * The Stage Board button listed the ability, but actionActivateAbility threw
 * "Ability type not implemented" so the click looked like a no-op.
 */
final class Issue250MakiActivateWaitOppTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function bladeOneMember(string $id, string $nameEn): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => $nameEn,
            'name' => $nameEn,
            'group' => "μ's",
            'blade' => 1,
            'active' => true,
            'hearts' => [['color' => 'pink', 'count' => 1]],
        ];
    }

    private function baseState(array $p1, array $p2): array
    {
        return [
            'room_id' => 'ISSUE250',
            'status' => 'playing',
            'seq' => 5,
            'turn' => 2,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];
    }

    public function testCatalogHasActivatedWaitOppAbility(): void
    {
        $maki = $this->cardByNo('PL!-pb2-006-PP', 'maki');
        $ab = $maki['abilities'][0] ?? [];
        $this->assertSame('activated', $ab['trigger'] ?? null);
        $this->assertSame('optional_wait_self_discard_wait_opp_max_printed_hearts', $ab['type'] ?? null);
        $this->assertSame(1, intval($ab['max_printed_hearts'] ?? 0));
        $this->assertTrue(!empty($ab['once_per_turn']));
    }

    public function testActivateOpensDiscardThenCanWaitOpp(): void
    {
        $maki = $this->cardByNo('PL!-pb2-006-PP', 'maki');
        $handCard = $this->bladeOneMember('hand1', 'Hand Fodder');
        $oppA = $this->bladeOneMember('oppA', 'Opp One Heart');
        $oppB = $this->bladeOneMember('oppB', 'Opp Also One');
        // Give oppB two printed hearts so only oppA is legal if we pick carefully;
        // both have 1 heart so both are legal — mirrors the report (multiple 1♥).
        $oppB['hearts'] = [['color' => 'red', 'count' => 1]];

        $p1 = [
            'id' => 'p1',
            'name' => 'P1',
            'hand' => [$handCard],
            'stage' => [
                'left' => null,
                'center' => $maki,
                'right' => null,
            ],
            'waiting_room' => [],
            'energy_zone' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
        $p2 = [
            'id' => 'p2',
            'name' => 'P2',
            'hand' => [],
            'stage' => [
                'left' => $oppA,
                'center' => $oppB,
                'right' => null,
            ],
            'waiting_room' => [],
            'energy_zone' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];

        $state = $this->baseState($p1, $p2);
        $state = actionActivateAbility($state, 'p1', [
            'card_id' => 'maki',
            'ability_index' => 0,
        ]);

        // Activation skips yes/no and Waits Maki, then asks for discard.
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['center']));
        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('effect_discard_hand', $pr['type'] ?? null);
        $this->assertSame(1, intval($pr['count'] ?? $pr['discard'] ?? 0));

        $state = actionResolvePrompt($state, 'p1', [
            'discard_ids' => ['hand1'],
        ]);

        $this->assertCount(0, $state['players']['p1']['hand']);
        $this->assertNotEmpty($state['players']['p1']['waiting_room']);

        $pr2 = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr2);
        // Opp Wait pick after discard.
        $this->assertTrue(
            in_array($pr2['type'] ?? '', [
                'wait_opponent_stage',
                'wait_opp_stage_member',
                'begin_wait_opponent_stage',
                'pb2_begin_wait_opp_printed_hearts',
                'pick_opp_stage_wait',
            ], true)
            || str_contains((string)($pr2['type'] ?? ''), 'wait')
            || str_contains((string)($pr2['type'] ?? ''), 'opp'),
            'Expected opp Wait pick prompt, got ' . json_encode($pr2['type'] ?? null)
        );

        $cands = $pr2['candidates'] ?? $pr2['stage_candidates'] ?? [];
        $this->assertGreaterThanOrEqual(2, count($cands), 'Both 1♥ opp Members should be pickable');
    }

    public function testActivateFailsWithoutOppTarget(): void
    {
        $maki = $this->cardByNo('PL!-pb2-006-PP', 'maki');
        $handCard = $this->bladeOneMember('hand1', 'Hand Fodder');
        $fat = $this->bladeOneMember('fat', 'Fat Opp');
        $fat['hearts'] = [
            ['color' => 'red', 'count' => 2],
            ['color' => 'blue', 'count' => 1],
        ];

        $p1 = [
            'id' => 'p1',
            'name' => 'P1',
            'hand' => [$handCard],
            'stage' => ['left' => null, 'center' => $maki, 'right' => null],
            'waiting_room' => [],
            'energy_zone' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
        $p2 = [
            'id' => 'p2',
            'name' => 'P2',
            'hand' => [],
            'stage' => ['left' => null, 'center' => $fat, 'right' => null],
            'waiting_room' => [],
            'energy_zone' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/printed hearts/i');
        actionActivateAbility($this->baseState($p1, $p2), 'p1', [
            'card_id' => 'maki',
            'ability_index' => 0,
        ]);
    }
}
