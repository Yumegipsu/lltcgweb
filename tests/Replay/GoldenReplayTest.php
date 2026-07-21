<?php

declare(strict_types=1);

namespace LLTCG\Tests\Replay;

use PHPUnit\Framework\TestCase;

final class GoldenReplayTest extends TestCase
{
    private function joinedMainFirstState(): array {
        $created = createRoom(['name' => 'Replay P1', 'deck' => 'nijigasaki']);
        joinRoom([
            'room_id' => $created['room_id'],
            'name' => 'Replay P2',
            'deck' => 'cpu',
            'cpu_difficulty' => 'easy',
            'first_player' => 'p1',
        ]);
        $state = loadGame($created['room_id']);
        $this->assertIsArray($state);
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $this->assertSame('main_first', $state['phase'] ?? '');
        return $state;
    }

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

    public function testReplayApplyClearsStalePromptBeforeRecordedAction(): void {
        $state = $this->joinedMainFirstState();
        $state = applyAction($state, 'p1', 'end_main', []);
        $this->assertSame('main_second', $state['phase'] ?? '');

        $state['pending_prompt'] = [
            'type' => 'optional_wait_self',
            'owner' => 'p2',
            'responder' => 'p2',
            'source_name' => 'Replay stale prompt',
            'choices' => ['yes', 'no'],
        ];

        $prepared = replayPrepareStateForRecordedAction($state, 'p2', 'end_main');
        $this->assertSame('p2', $prepared['pending_prompt']['responder'] ?? null);

        $clearedForOtherPlayer = replayPrepareStateForRecordedAction($state, 'p1', 'end_main');
        $this->assertNull($clearedForOtherPlayer['pending_prompt'] ?? null);

        $after = replayApplyActionsThrough($state, [[
            'player' => 'p2',
            'type' => 'end_main',
            'data' => [],
        ]], 1);

        $this->assertSame('live_set', $after['phase'] ?? null);
        $this->assertNull($after['pending_prompt'] ?? null);
    }

    public function testReplaySanitizeViewingStateClearsPendingPrompt(): void {
        $state = $this->joinedMainFirstState();
        $state['pending_prompt'] = [
            'type' => 'surveil_arrange',
            'responder' => 'p1',
            'prompt' => 'Must assign every looked card to deck top or Waiting Room',
        ];
        $state['surveil_stash'] = [['instance_id' => 'x']];
        $state = replaySanitizeViewingState($state);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertNull($state['surveil_stash'] ?? null);
    }

    public function testReplaySurveilFallbackUsesRecordedCardIds(): void {
        $state = $this->joinedMainFirstState();
        $p2 = &$state['players']['p2'];
        $top = array_slice($p2['main_deck'], 0, 2);
        $id0 = $top[0]['instance_id'] ?? '';
        $id1 = $top[1]['instance_id'] ?? '';
        $this->assertNotSame('', $id0);
        $this->assertNotSame('', $id1);

        $state['surveil_stash'] = [['instance_id' => 'wrong_stash_id', 'name_en' => 'Wrong']];
        $state['pending_prompt'] = [
            'type' => 'surveil_arrange',
            'responder' => 'p2',
            'owner' => 'p2',
            'source_name' => 'Test',
        ];

        $after = replayApplyRecordedAction($state, 'p2', 'resolve_prompt', [
            'choice' => 'confirm',
            'top_ids' => [$id0],
            'wr_ids' => [$id1],
        ], 1);

        $this->assertNull($after['pending_prompt'] ?? null);
        $deckTop = $after['players']['p2']['main_deck'][0]['instance_id'] ?? '';
        $this->assertSame($id0, $deckTop);
        $wrIds = array_column($after['players']['p2']['waiting_room'] ?? [], 'instance_id');
        $this->assertContains($id1, $wrIds);
    }

    public function testMulliganReplayRecordsMainDeckOrder(): void {
        $created = createRoom(['name' => 'Mull P1', 'deck' => 'nijigasaki']);
        joinRoom([
            'room_id' => $created['room_id'],
            'name' => 'Mull P2',
            'deck' => 'cpu',
            'cpu_difficulty' => 'easy',
            'first_player' => 'p1',
        ]);
        $state = loadGame($created['room_id']);
        $handCard = $state['players']['p2']['hand'][0]['instance_id'] ?? '';
        $this->assertNotSame('', $handCard);
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $mullData = ['card_ids' => [$handCard]];
        $state = applyAction($state, 'p2', 'mulligan', $mullData);
        $state = appendReplayAction($state, 'p2', 'mulligan', $mullData);
        $logged = end($state['action_log']);
        $this->assertSame('mulligan', $logged['type'] ?? null);
        $this->assertNotEmpty($logged['data']['main_deck_order'] ?? null);
    }

    /**
     * @dataProvider replayFixtureProvider
     */
    public function testReplaySeekToArbitrarySteps(string $path): void {
        $replay = json_decode((string)file_get_contents($path), true);
        $this->assertIsArray($replay);
        validateReplayFile($replay);

        $actions = $replay['actions'] ?? [];
        $total = count($actions);
        if ($total === 0) {
            $steps = [0];
        } else {
            $steps = array_values(array_unique([
                0,
                1,
                intdiv($total, 2),
                max(0, $total - 1),
                $total,
            ]));
            sort($steps);
        }

        foreach ($steps as $step) {
            $state = replayRestoreFromBaseline($replay['baseline'], 'SEEK', 'tok1', 'tok2');
            $after = replayApplyActionsThrough($state, $actions, $step);
            $after = replaySanitizeViewingState($after);
            $this->assertIsArray($after, basename($path) . ' step ' . $step);
            $this->assertNull($after['pending_prompt'] ?? null, basename($path) . ' step ' . $step . ' pending_prompt');
        }
    }
}
