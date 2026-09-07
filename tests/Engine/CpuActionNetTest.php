<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class CpuActionNetTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/Game/CpuPolicy.php';
        require_once dirname(__DIR__, 2) . '/src/Game/CpuActionNet.php';
    }

    public function testTrainReadsRankedActionsAndJudgesTakenLineHigher(): void
    {
        $dir = dirname(__DIR__) . '/fixtures/cpu_policy';
        $payloads = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) {
                $payloads[] = $decoded;
            }
        }
        $net = \CpuActionNet::train($payloads, 24);
        $this->assertGreaterThan(0, $net['samples']);
        $this->assertSame(20, count($net['w1'][0]));

        $live = \CpuActionNet::features([
            'kind' => 'live_set',
            'turn' => 3,
            'my_success' => 0,
            'opp_success' => 0,
            'empty_slots' => 2,
            'hearts' => 2,
            'opp_hearts' => 0,
            'score' => 3,
            'can_clear' => true,
            'hand' => 5,
        ]);
        $pass = $live;
        $pass[13] = 1.0;
        $pass[14] = 0.0;
        $taken = \CpuActionNet::judge($net, $live);
        $skipped = \CpuActionNet::judge($net, $pass);
        $this->assertGreaterThan($skipped['take'], $taken['take']);
        $this->assertArrayHasKey('score', $taken);
    }
}
