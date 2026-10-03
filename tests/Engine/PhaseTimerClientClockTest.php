<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Phase timer client fields + expire grace (clock-skew / early skip reports). */
final class PhaseTimerClientClockTest extends TestCase
{
    public function testEnrichAddsServerNowAndRemaining(): void
    {
        $state = [
            'phase_timer' => [
                'enabled' => true,
                'duration' => 120,
                'deadlines' => ['p1' => time() + 40, 'p2' => null],
                'durations' => ['p1' => 120, 'p2' => null],
                'window_ids' => ['p1' => 1, 'p2' => null],
            ],
        ];
        \enrichPhaseTimerClientFields($state);
        $this->assertArrayHasKey('server_now', $state);
        $this->assertSame(null, $state['phase_timer']['remaining']['p2']);
        $left = intval($state['phase_timer']['remaining']['p1']);
        $this->assertGreaterThanOrEqual(39, $left);
        $this->assertLessThanOrEqual(40, $left);
    }

    public function testExpireGraceDelaysAutoSkip(): void
    {
        $now = time();
        $this->assertFalse(\phaseTimerDeadlineExpired($now - 1, $now));
        $this->assertTrue(\phaseTimerDeadlineExpired($now - 3, $now));
        $this->assertFalse(\phaseTimerDeadlineExpired(null, $now));
    }
}
