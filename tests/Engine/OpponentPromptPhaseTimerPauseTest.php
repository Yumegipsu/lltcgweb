<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Opponent-facing skill prompts pause the turn clock and use a separate choice timer. */
final class OpponentPromptPhaseTimerPauseTest extends TestCase
{
    private function baseState(): array
    {
        return [
            'room_id' => 'opp-prompt-timer-test',
            'mode' => 'pvp',
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'phase_timer_cfg' => ['enabled' => true, 'duration' => 60],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'Active',
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
                    'name' => 'Opponent',
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

    public function testOpponentPromptPausesTurnClockAndResumesRemaining(): void
    {
        $state = $this->baseState();
        \refreshPvpPhaseTimers($state);
        $this->assertNotEmpty($state['phase_timer']['deadlines']['p1']);
        $this->assertNull($state['phase_timer']['deadlines']['p2']);

        // Simulate ~25s already spent on the active player's Main clock.
        $state['phase_timer']['deadlines']['p1'] = time() + 35;
        $state['phase_timer']['durations']['p1'] = 60;

        $state['pending_prompt'] = [
            'type' => 'optional_pay_energy_on_enter',
            'responder' => 'p2',
            'owner' => 'p1',
            'choices' => ['yes', 'no'],
            'prompt' => 'Opponent chooses',
            'source_id' => 'src1',
        ];
        \refreshPvpPhaseTimers($state);

        $this->assertNull($state['phase_timer']['deadlines']['p1'], 'Turn clock must stop while opponent chooses');
        $paused = intval($state['phase_timer']['paused_remaining']['p1'] ?? 0);
        $this->assertGreaterThanOrEqual(34, $paused);
        $this->assertLessThanOrEqual(36, $paused);
        $this->assertNotEmpty($state['phase_timer']['deadlines']['p2'], 'Opponent gets a separate choice timer');
        $oppLeft = intval($state['phase_timer']['deadlines']['p2']) - time();
        $this->assertGreaterThanOrEqual(55, $oppLeft);

        // Opponent answers — turn clock resumes from the saved remaining.
        unset($state['pending_prompt']);
        \refreshPvpPhaseTimers($state);

        $this->assertNull($state['phase_timer']['paused_remaining']['p1'] ?? null);
        $this->assertNull($state['phase_timer']['deadlines']['p2']);
        $resumed = intval($state['phase_timer']['deadlines']['p1']) - time();
        $this->assertGreaterThanOrEqual(34, $resumed);
        $this->assertLessThanOrEqual(36, $resumed);
    }

    public function testOwnPromptKeepsTurnClockRunning(): void
    {
        $state = $this->baseState();
        \refreshPvpPhaseTimers($state);
        $state['phase_timer']['deadlines']['p1'] = time() + 40;
        $state['phase_timer']['durations']['p1'] = 60;

        $state['pending_prompt'] = [
            'type' => 'optional_live_start',
            'responder' => 'p1',
            'owner' => 'p1',
            'choices' => ['yes', 'no'],
            'prompt' => 'Your choice',
            'source_id' => 'src2',
        ];
        \refreshPvpPhaseTimers($state);

        $this->assertNull($state['phase_timer']['paused_remaining']['p1'] ?? null);
        $left = intval($state['phase_timer']['deadlines']['p1']) - time();
        $this->assertGreaterThanOrEqual(39, $left);
        $this->assertLessThanOrEqual(41, $left);
        $this->assertNull($state['phase_timer']['deadlines']['p2']);
    }

    public function testOpponentChoiceTimeoutAutoResolvesWithoutBurningPausedClock(): void
    {
        $state = $this->baseState();
        \refreshPvpPhaseTimers($state);
        $state['phase_timer']['deadlines']['p1'] = time() + 28;
        $state['phase_timer']['durations']['p1'] = 60;

        $state['pending_prompt'] = [
            'type' => 'optional_pay_energy_on_enter',
            'responder' => 'p2',
            'owner' => 'p1',
            'step' => 'confirm',
            'choices' => ['yes', 'no'],
            'prompt' => 'Opponent chooses',
            'source_id' => 'src3',
            'source_name' => 'Test Skill',
        ];
        \refreshPvpPhaseTimers($state);
        $pausedBefore = intval($state['phase_timer']['paused_remaining']['p1'] ?? 0);
        $this->assertGreaterThan(0, $pausedBefore);

        $state['phase_timer']['deadlines']['p2'] = time() - 1;
        $this->assertTrue(\applyPhaseTimeouts($state));
        $this->assertNull($state['pending_prompt'] ?? null);

        // After auto-resolve, Main clock resumes near the paused remaining.
        $resumed = intval($state['phase_timer']['deadlines']['p1'] ?? 0) - time();
        $this->assertGreaterThanOrEqual($pausedBefore - 2, $resumed);
        $this->assertLessThanOrEqual($pausedBefore + 2, $resumed);
    }
}
