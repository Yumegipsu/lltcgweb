<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!N-bp7-026-L Just Believe!!! — discard N → pick up to N Nijigasaki for +1 Blade (#188).
 */
final class Issue188JustBelieveMultiBladePickTest extends TestCase
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

    private function fillerHand(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Hand Filler',
            'name' => 'フィラー',
            'group' => 'Nijigasaki',
            'cost' => 1,
            'blade' => 0,
            'active' => true,
            'hearts' => [],
            'abilities' => [],
        ];
    }

    public function testDiscardTwoOpensMultiStagePickAndGrantsBoth(): void
    {
        $live = $this->cardByNo('PL!N-bp7-026-L', 'just_believe');
        $m1 = $this->cardByNo('PL!N-bp1-002-P', 'niji_left');
        $m2 = $this->cardByNo('PL!N-bp1-002-P', 'niji_right');
        $this->assertSame('Kasumi Nakasu', $m1['name_en'] ?? '');

        $ab = null;
        foreach ($live['abilities'] as $row) {
            if (($row['type'] ?? '') === 'live_start_discard_up_to_grant_members_blade') {
                $ab = $row;
                break;
            }
        }
        $this->assertNotNull($ab);

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            '_live_start_perf_pid' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$this->fillerHand('h1'), $this->fillerHand('h2'), $this->fillerHand('h3')],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $m1,
                        'center' => null,
                        'right' => $m2,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [$live],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveAbilityEffect($state, 'p1', $live, $ab, ['phase' => 'live_start']);
            $pr = $state['pending_prompt'] ?? null;
            $this->assertIsArray($pr);
            $this->assertSame('bp7_pick_cards', $pr['type'] ?? '');
            $this->assertSame(2, intval($pr['pick_max'] ?? 0));

            $state = \actionResolvePrompt($state, 'p1', ['card_ids' => ['h1', 'h2']]);
            $pr2 = $state['pending_prompt'] ?? null;
            $this->assertIsArray($pr2);
            $this->assertSame('bp7_pick_stage_member', $pr2['type'] ?? '');
            $this->assertSame('grant_blade_members', $pr2['bp7_action'] ?? '');
            $this->assertSame(2, intval($pr2['pick_max'] ?? 0));
            $this->assertTrue(!empty($pr2['multi']));
            $this->assertCount(2, $pr2['candidates'] ?? []);

            $state = \actionResolvePrompt($state, 'p1', [
                'slots' => ['left', 'right'],
                'card_ids' => ['niji_left', 'niji_right'],
            ]);
            $this->assertSame(1, intval($state['players']['p1']['stage']['left']['live_blade_bonus'] ?? 0));
            $this->assertSame(1, intval($state['players']['p1']['stage']['right']['live_blade_bonus'] ?? 0));
            // Live-start continuation may open a follow-up prompt in isolation; blade grants are the contract.
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
