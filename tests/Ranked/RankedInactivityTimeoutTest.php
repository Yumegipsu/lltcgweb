<?php

declare(strict_types=1);

namespace LLTCG\Tests\Ranked;

use PHPUnit\Framework\TestCase;

final class RankedInactivityTimeoutTest extends TestCase
{
    private function state(string $mode = 'ranked'): array
    {
        return [
            'room_id' => 'ranked-inactivity-test',
            'mode' => $mode,
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 1,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'phase_timer_cfg' => ['enabled' => true, 'duration' => 45],
            'ranked' => [
                'p1_discord_id' => 'ranked-test-p1',
                'p2_discord_id' => 'ranked-test-p2',
                'applied' => false,
            ],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'Inactive Player',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'Active Player',
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
    }

    public function testConsecutiveRankedTimeoutsUse120Then60Then15AndResign(): void
    {
        $state = $this->state();

        \setPhaseDeadline($state, 'p1');
        $this->assertSame(120, $state['phase_timer']['durations']['p1']);
        $this->assertFalse(\registerRankedInactivityTimeout($state, 'p1'));
        $this->assertSame(1, $state['ranked']['inactivity_timeouts']['p1']);

        \clearPhaseDeadline($state, 'p1');
        \setPhaseDeadline($state, 'p1');
        $this->assertSame(60, $state['phase_timer']['durations']['p1']);
        $this->assertFalse(\registerRankedInactivityTimeout($state, 'p1'));
        $this->assertSame(2, $state['ranked']['inactivity_timeouts']['p1']);

        \clearPhaseDeadline($state, 'p1');
        \setPhaseDeadline($state, 'p1');
        $this->assertSame(15, $state['phase_timer']['durations']['p1']);
        $this->assertTrue(\registerRankedInactivityTimeout($state, 'p1'));

        $this->assertSame('finished', $state['status']);
        $this->assertSame('p2', $state['winner']);
        $this->assertSame('resign', $state['end_reason']);
        $this->assertSame('p1', $state['resigned_by']);
        $this->assertSame('p1', $state['ranked']['auto_resigned_for_inactivity']);
    }

    public function testSameDeadlineWindowOnlyCountsOnce(): void
    {
        $state = $this->state();
        \setPhaseDeadline($state, 'p1');

        $this->assertFalse(\registerRankedInactivityTimeout($state, 'p1'));
        $this->assertFalse(\registerRankedInactivityTimeout($state, 'p1'));
        $this->assertSame(1, $state['ranked']['inactivity_timeouts']['p1']);
    }

    public function testThirdExpiredPromptClockResignsBeforeAutoResolvingPrompt(): void
    {
        $state = $this->state();
        $state['ranked']['inactivity_timeouts']['p1'] = 2;
        $state['pending_prompt'] = [
            'type' => 'optional_wait_self',
            'owner' => 'p1',
            'responder' => 'p1',
            'choices' => ['yes', 'no'],
        ];
        \setPhaseDeadline($state, 'p1');
        $state['phase_timer']['deadlines']['p1'] = time() - 1;

        $this->assertTrue(\applyPhaseTimeouts($state));
        $this->assertSame('finished', $state['status']);
        $this->assertSame('p2', $state['winner']);
        $this->assertArrayNotHasKey('pending_prompt', $state);
    }

    public function testValidatedActivityResetsOnlyActingPlayer(): void
    {
        $state = $this->state();
        $state['ranked']['inactivity_timeouts'] = ['p1' => 2, 'p2' => 1];
        $state['ranked']['last_timeout_window'] = ['p1' => 7, 'p2' => 8];

        \resetRankedInactivityTimeouts($state, 'p1');

        $this->assertSame(0, $state['ranked']['inactivity_timeouts']['p1']);
        $this->assertSame(1, $state['ranked']['inactivity_timeouts']['p2']);
        $this->assertArrayNotHasKey('p1', $state['ranked']['last_timeout_window']);
        $this->assertSame(8, $state['ranked']['last_timeout_window']['p2']);
        $this->assertTrue(\rankedActionShowsPlayerActivity('play_member'));
        $this->assertFalse(\rankedActionShowsPlayerActivity('send_stamp'));
    }

    public function testCasualTimerKeepsConfiguredDurationAndNeverGetsStrike(): void
    {
        $state = $this->state('pvp');
        \setPhaseDeadline($state, 'p1');

        $this->assertSame(45, $state['phase_timer']['durations']['p1']);
        $this->assertFalse(\registerRankedInactivityTimeout($state, 'p1'));
        $this->assertArrayNotHasKey('inactivity_timeouts', $state['ranked']);
    }
}
