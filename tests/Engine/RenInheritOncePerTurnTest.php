<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Ren PL!SP-pb2-005 inherits once_per_turn Activated from stacked Liella Members.
 * Prompt resolve must not intval() inherit:{id}:{idx} keys (that collapses to 0).
 */
final class RenInheritOncePerTurnTest extends TestCase
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
            'turn' => 4,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => null,
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'main_deck' => array_map(
                        static fn (int $i) => [
                            'instance_id' => 'deck_' . $i,
                            'card_type' => 'メンバー',
                            'group' => 'Superstar',
                            'cost' => 2,
                        ],
                        range(0, 9)
                    ),
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

    public function testInheritedChisatoScoreOncePerTurnCannotRepeat(): void
    {
        $ren = $this->cardByNo('PL!SP-pb2-005-R', 'ren_stage');
        $chisato = $this->cardByNo('PL!SP-bp1-003-P', 'chisato_under');
        $ren['stacked_members'] = [$chisato];

        $hand = $this->cardByNo('PL!SP-bp2-012-N', 'hand_cost10');
        $hand['cost'] = 10;
        $hand['card_type'] = 'メンバー';

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $ren;
        $state['players']['p1']['hand'] = [$hand];

        $inheritKey = 'inherit:chisato_under:0';
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'ren_stage',
            'ability_index' => $inheritKey,
        ]);
        $this->assertSame('reveal_hand_member_cost_live_score', $state['pending_prompt']['type'] ?? null);
        $this->assertSame($inheritKey, $state['pending_prompt']['ability_index'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['card_ids' => ['hand_cost10']]);
        $this->assertEmpty($state['pending_prompt'] ?? null);

        $renAfter = $state['players']['p1']['stage']['center'];
        $this->assertTrue(\isAbilityUsed($renAfter, $inheritKey));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Ability already used this turn');
        \actionActivateAbility($state, 'p1', [
            'card_id' => 'ren_stage',
            'ability_index' => $inheritKey,
        ]);
    }

    public function testInheritedChisatoStackUnderOncePerTurnCannotRepeat(): void
    {
        $ren = $this->cardByNo('PL!SP-pb2-005-R', 'ren_stage');
        $chisato = $this->cardByNo('PL!SP-bp7-003-P', 'chisato_bp7_under');
        $ren['stacked_members'] = [$chisato];

        $hand = $this->cardByNo('PL!SP-bp2-012-N', 'hand_cost10');
        $hand['cost'] = 10;
        $hand['card_type'] = 'メンバー';
        $hand['group'] = 'Superstar';
        $hand['name_en'] = 'Kanon Shibuya';

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $ren;
        $state['players']['p1']['hand'] = [$hand];

        // Activated is ability index 2 on BP7 Chisato (two continuous first).
        $inheritKey = 'inherit:chisato_bp7_under:2';
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'ren_stage',
            'ability_index' => $inheritKey,
        ]);
        $this->assertSame('bp7_pick_cards', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['card_ids' => ['hand_cost10']]);
        $this->assertEmpty($state['pending_prompt'] ?? null);

        $renAfter = $state['players']['p1']['stage']['center'];
        $this->assertTrue(\isAbilityUsed($renAfter, $inheritKey));
        $this->assertCount(2, $renAfter['stacked_members'] ?? []);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Ability already used this turn');
        \actionActivateAbility($state, 'p1', [
            'card_id' => 'ren_stage',
            'ability_index' => $inheritKey,
        ]);
    }
}
