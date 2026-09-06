<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** get_state log deltas — only ship new rows after since_log_id. */
final class LogDeltaGetStateTest extends TestCase
{
    public function testAddLogStampsMonotonicIdsAndCapsAt500(): void
    {
        $state = ['log' => [], 'log_id' => 0];
        for ($i = 0; $i < 505; $i++) {
            $state = \addLog($state, "line $i");
        }
        $this->assertCount(500, $state['log']);
        $this->assertSame(505, intval($state['log_id']));
        $this->assertSame(6, intval($state['log'][0]['id']));
        $this->assertSame(505, intval($state['log'][499]['id']));
    }

    public function testTrimReturnsDeltaWhenClientCaughtUp(): void
    {
        $state = ['log' => [], 'log_id' => 0];
        $state = \addLog($state, 'a');
        $state = \addLog($state, 'b');
        $state = \addLog($state, 'c');
        $filtered = [
            'log' => $state['log'],
            'log_id' => $state['log_id'],
            'seq' => 3,
        ];
        [$out, $delta] = \tcgTrimLogForClient($filtered, 1, false);
        $this->assertTrue($delta);
        $this->assertSame('delta', $out['log_mode']);
        $this->assertCount(2, $out['log']);
        $this->assertSame(2, intval($out['log'][0]['id']));
        $this->assertSame(3, intval($out['log'][1]['id']));
        $this->assertSame(3, intval($out['log_id']));
    }

    public function testTrimFallsBackToFullWhenBehindTruncation(): void
    {
        $state = ['log' => [], 'log_id' => 0];
        for ($i = 0; $i < 505; $i++) {
            $state = \addLog($state, "line $i");
        }
        $filtered = [
            'log' => $state['log'],
            'log_id' => $state['log_id'],
        ];
        // Client still thinks last id was 3, but oldest retained is 6.
        [$out, $delta] = \tcgTrimLogForClient($filtered, 3, false);
        $this->assertFalse($delta);
        $this->assertSame('full', $out['log_mode']);
        $this->assertCount(500, $out['log']);
    }

    public function testBackfillLegacyLogAssignsIds(): void
    {
        $state = [
            'log' => [
                ['msg' => 'old1', 'ts' => 1, 'kind' => 'info'],
                ['msg' => 'old2', 'ts' => 2, 'kind' => 'info'],
            ],
        ];
        $state = \tcgBackfillLogIds($state);
        $this->assertSame(1, intval($state['log'][0]['id']));
        $this->assertSame(2, intval($state['log'][1]['id']));
        $this->assertSame(2, intval($state['log_id']));
    }
}
