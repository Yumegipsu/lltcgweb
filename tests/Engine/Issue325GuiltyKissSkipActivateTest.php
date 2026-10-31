<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * #325: Guilty Night, Guilty Kiss! Live Success Wait choice must mark Waited Members
 * with skip_activate_next_turn (manual pick path, not only auto-resolve).
 */
final class Issue325GuiltyKissSkipActivateTest extends TestCase
{
    private function card(string $no, string $id): array
    {
        $c = \tcgCatalogCardByNo($no);
        $this->assertIsArray($c, $no);
        $c['instance_id'] = $id;
        $c['active'] = true;
        return $c;
    }

    public function testManualWaitPickSetsSkipActivateNextTurn(): void
    {
        $live = $this->card('PL!S-bp7-025-L', 'gnk');
        $oppA = [
            'instance_id' => 'opp_a', 'card_no' => 'T-A', 'name_en' => 'Opp A', 'card_type' => 'メンバー',
            'group' => "μ's", 'cost' => 3, 'blade' => 1, 'active' => true,
        ];
        $oppB = [
            'instance_id' => 'opp_b', 'card_no' => 'T-B', 'name_en' => 'Opp B', 'card_type' => 'メンバー',
            'group' => "μ's", 'cost' => 4, 'blade' => 1, 'active' => true,
        ];
        $state = [
            'status' => 'playing', 'phase' => 'live_success_effects', 'seq' => 1, 'turn' => 5,
            'first_player' => 'p1', 'active_player' => 'p1', 'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1', 'name' => 'P1', 'hand' => [], 'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [], 'main_deck' => [], 'live_zone' => [],
                    'success_lives' => [$live],
                ],
                'p2' => [
                    'id' => 'p2', 'name' => 'P2', 'hand' => [], 'waiting_room' => [],
                    'stage' => ['left' => $oppA, 'center' => $oppB, 'right' => null],
                    'energy_zone' => [], 'main_deck' => [], 'live_zone' => [], 'success_lives' => [],
                ],
            ],
        ];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$live], 0, [], []);
        $this->assertSame('player_choice', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'wait']);
        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertNotEmpty($state['_bp7_wait_skip_activate'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['slots' => ['left', 'center']]);
        $this->assertTrue(!empty($state['players']['p2']['stage']['left']['skip_activate_next_turn']));
        $this->assertTrue(!empty($state['players']['p2']['stage']['center']['skip_activate_next_turn']));
        $this->assertTrue(\memberIsInWait($state['players']['p2']['stage']['left']));
        $msgs = implode("\n", array_column($state['log'], 'msg'));
        $this->assertStringContainsString('will not activate during their next Active Phase', $msgs);
    }
}
