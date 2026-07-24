<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Softlock: phase stays live_success_effects with no pending_prompt and no
 * _performance_continue — UI shows "Resolve Live Success prompts" with nothing
 * to click. finishLiveSuccessEffects must advance the phase.
 */
final class LiveSuccessSoftlockHealTest extends TestCase
{
    private function stuckState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 20,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_attempt' => ['p1'],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [
                        ['instance_id' => 'd1', 'card_type' => 'メンバー', 'name_en' => 'D1'],
                    ],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hearts' => 3,
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hearts' => 3,
                ],
            ],
        ];
    }

    public function testFinishLiveSuccessEffectsAdvancesWhenStuckWithoutContinue(): void
    {
        $state = $this->stuckState();
        $this->assertArrayNotHasKey('_performance_continue', $state);
        $this->assertEmpty($state['pending_prompt'] ?? null);

        $after = \finishLiveSuccessEffects($state);

        $this->assertNotSame(
            'live_success_effects',
            $after['phase'] ?? null,
            'Must leave live_success_effects when there is nothing left to resolve'
        );
        $this->assertEmpty($after['pending_prompt'] ?? null);
    }

    public function testFinishPromptEffectsHealsStuckLiveSuccessPhase(): void
    {
        $state = $this->stuckState();
        $after = \finishPromptEffects($state);

        $this->assertNotSame('live_success_effects', $after['phase'] ?? null);
    }

    public function testPendingPromptKeepsContinueMarker(): void
    {
        $state = $this->stuckState();
        $state['pending_prompt'] = [
            'type' => 'effect_discard_hand',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_name' => 'Test Live',
            'discard' => 1,
        ];

        $after = \finishLiveSuccessEffects($state);

        $this->assertSame('live_success_effects', $after['phase'] ?? null);
        $this->assertSame('p1', $after['_performance_continue'] ?? null);
        $this->assertNotEmpty($after['pending_prompt'] ?? null);
    }
}
