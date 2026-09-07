<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class CpuPolicyHarvestTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/Game/CpuPolicy.php';
        require_once dirname(__DIR__, 2) . '/src/Game/CpuLiveRace.php';
    }

    public function testAggregateDropsCpuSeatsAndCountsLiveSets(): void
    {
        $dir = dirname(__DIR__) . '/fixtures/cpu_policy';
        $payloads = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) {
                $payloads[] = $decoded;
            }
        }
        $this->assertGreaterThanOrEqual(3, count($payloads));

        $out = \CpuPolicy::aggregatePayloads($payloads, 0.2);
        $policy = $out['policy'];
        $this->assertSame(2, $policy['corpus']['replays']);
        $this->assertSame(0, $policy['corpus']['held_out']);
        $this->assertIsArray($policy['cards']['members']);
        $this->assertArrayHasKey('PL!N-bp1-029', $policy['cards']['lives']);
        $this->assertArrayHasKey('PL!SP-bp2-024', $policy['cards']['lives']);
        $this->assertGreaterThan(0, $policy['cards']['lives']['PL!N-bp1-029']['rate']);
        $this->assertNull($out['report']['live_set_agreement']);
    }

    public function testLiveRaceTakesClearableLowScoreAtTwoSuccesses(): void
    {
        $pick = \CpuLiveRace::pick([
            'my_success' => 2,
            'opp_success' => 1,
            'opp_can_clear' => false,
            'candidates' => [
                ['id' => 'high', 'score' => 4, 'clearable' => false],
                ['id' => 'low', 'score' => 1, 'clearable' => true],
            ],
        ]);
        $this->assertSame(['low'], $pick['ids']);
        $this->assertSame('take_clear_at_two', $pick['reason']);
    }

    public function testLiveRacePrefersHigherScoreWhenBothCanClear(): void
    {
        $pick = \CpuLiveRace::pick([
            'my_success' => 1,
            'opp_success' => 1,
            'opp_can_clear' => true,
            'candidates' => [
                ['id' => 'low', 'score' => 1, 'clearable' => true],
                ['id' => 'high', 'score' => 3, 'clearable' => true],
            ],
        ]);
        $this->assertSame(['high'], $pick['ids']);
        $this->assertSame('higher_score_both_clear', $pick['reason']);
    }

    public function testLiveRaceDoesNotCommitUnclearableHighLiveWhenBehind(): void
    {
        $pick = \CpuLiveRace::pick([
            'my_success' => 0,
            'opp_success' => 2,
            'opp_can_clear' => true,
            'bluff' => false,
            'candidates' => [
                ['id' => 'cute', 'score' => 5, 'clearable' => false],
                ['id' => 'clear', 'score' => 1, 'clearable' => true],
            ],
        ]);
        $this->assertSame(['clear'], $pick['ids']);
        $this->assertSame('commit_clear', $pick['reason']);
    }
}
