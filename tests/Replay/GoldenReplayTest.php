<?php

declare(strict_types=1);

namespace LLTCG\Tests\Replay;

use PHPUnit\Framework\TestCase;

final class GoldenReplayTest extends TestCase
{
    public static function replayFixtureProvider(): array {
        $dir = dirname(__DIR__) . '/fixtures/replays';
        $cases = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $name = basename($path);
            $cases[$name] = [$path];
        }
        if ($cases === []) {
            throw new \RuntimeException('No replay fixtures found in ' . $dir);
        }
        return $cases;
    }

    /**
     * @dataProvider replayFixtureProvider
     */
    public function testGoldenReplayFixture(string $path): void {
        $replay = json_decode((string)file_get_contents($path), true);
        $this->assertIsArray($replay, basename($path) . ' must be valid JSON');
        validateReplayFile($replay);

        $expected = $replay['expected'] ?? [];
        $this->assertIsArray($expected, basename($path) . ' must include expected block');

        $actions = $replay['actions'] ?? [];
        $state = replayRestoreFromBaseline($replay['baseline'], 'FIXTURE', 'tok1', 'tok2');
        $after = replayApplyActionsThrough($state, $actions, count($actions));

        if (isset($expected['final_phase'])) {
            $this->assertSame(
                $expected['final_phase'],
                $after['phase'] ?? null,
                basename($path) . ' phase mismatch'
            );
        }
        if (isset($expected['final_phase_prefix'])) {
            $phase = (string)($after['phase'] ?? '');
            $this->assertStringStartsWith(
                (string)$expected['final_phase_prefix'],
                $phase,
                basename($path) . ' phase prefix mismatch (got ' . $phase . ')'
            );
        }
        if (array_key_exists('status', $expected)) {
            $this->assertSame($expected['status'], $after['status'] ?? null, basename($path) . ' status');
        }
        if (array_key_exists('winner', $expected)) {
            $this->assertSame($expected['winner'], $after['winner'] ?? null, basename($path) . ' winner');
        }
        if (array_key_exists('pending_prompt', $expected)) {
            if ($expected['pending_prompt'] === null) {
                $this->assertNull($after['pending_prompt'] ?? null, basename($path) . ' pending_prompt should be cleared');
            } else {
                $this->assertSame($expected['pending_prompt'], $after['pending_prompt']['type'] ?? null);
            }
        }
        if (!empty($expected['hand_contains'])) {
            $ids = array_column($after['players']['p1']['hand'] ?? [], 'instance_id');
            $this->assertContains($expected['hand_contains'], $ids, basename($path) . ' hand_contains');
        }
    }
}
