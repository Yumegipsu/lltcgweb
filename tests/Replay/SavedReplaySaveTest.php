<?php

declare(strict_types=1);

namespace LLTCG\Tests\Replay;

use PHPUnit\Framework\TestCase;

final class SavedReplaySaveTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!function_exists('tcgReplayRowNeedsRepair')) {
            if (!defined('TCG_ACCOUNT_LIB_ONLY')) {
                define('TCG_ACCOUNT_LIB_ONLY', true);
            }
            require_once dirname(__DIR__, 2) . '/account.php';
        }
    }

    private function sampleReplayPayload(string $roomId = 'SAVE01'): array
    {
        $created = createRoom(['name' => 'Saver', 'deck' => 'nijigasaki']);
        joinRoom([
            'room_id' => $created['room_id'],
            'name' => 'Opp',
            'deck' => 'cpu',
            'cpu_difficulty' => 'easy',
            'first_player' => 'p1',
        ]);
        $state = loadGame($created['room_id']);
        $this->assertIsArray($state);
        $state = captureReplayBaselineIfNeeded($state);
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = appendReplayAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $state = appendReplayAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $state['status'] = 'finished';
        $state['winner'] = 'p1';
        saveGame($created['room_id'], $state);
        $payload = buildReplayExportPayload($state, 'p1');
        $payload['meta']['room_id'] = $roomId;
        return [$created, $payload];
    }

    public function testExportAllowedWhenWinnerSetBeforeStatusFinished(): void
    {
        $state = [
            'status' => 'playing',
            'winner' => 'p1',
            'phase' => 'main',
        ];
        assertReplayExportAllowed([], $state);
        $this->addToAssertionCount(1);
    }

    public function testReplayRowNeedsRepairDetectsEmptyPayload(): void
    {
        $this->assertTrue(tcgReplayRowNeedsRepair([
            'action_count' => 0,
            'payload_json' => '',
        ]));
        $this->assertFalse(tcgReplayRowNeedsRepair([
            'action_count' => 2,
            'payload_json' => json_encode([
                'schema_version' => REPLAY_SCHEMA_VERSION,
                'meta' => ['saver_player_id' => 'p1'],
                'baseline' => ['players' => ['p1' => [], 'p2' => []]],
                'actions' => [
                    ['player' => 'p1', 'type' => 'mulligan', 'data' => []],
                    ['player' => 'p2', 'type' => 'mulligan', 'data' => []],
                ],
                'frames' => [
                    ['players' => ['p1' => [], 'p2' => []]],
                    ['players' => ['p1' => [], 'p2' => []]],
                    ['players' => ['p1' => [], 'p2' => []]],
                ],
                'full_log' => [],
                'log_ends' => [0, 0, 0],
            ]),
        ]));
        $this->assertTrue(tcgReplayRowNeedsRepair([
            'action_count' => 2,
            'payload_json' => json_encode([
                'schema_version' => REPLAY_SCHEMA_VERSION,
                'meta' => ['saver_player_id' => 'p1'],
                'baseline' => ['players' => ['p1' => [], 'p2' => []]],
                'actions' => [
                    ['player' => 'p1', 'type' => 'mulligan', 'data' => []],
                    ['player' => 'p2', 'type' => 'mulligan', 'data' => []],
                ],
                'frames' => [
                    ['players' => ['p1' => [], 'p2' => []]],
                ],
                'full_log' => [],
                'log_ends' => [0],
            ]),
        ]));
    }

    public function testReplaySaveAcceptsClientExportedPayload(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        putenv('TCG_LOCAL_FAKE_AUTH=1');
        $uid = '900000000000000001';
        $token = \tcgLocalIssueToken($uid);
        [$created, $payload] = $this->sampleReplayPayload('SAVE01');

        $result = tcgApiReplaySave([
            'auth_token' => $token,
            'room_id' => 'SAVE01',
            'player_token' => $created['player_token'],
            'preserve' => true,
            'replay' => $payload,
        ]);

        $this->assertTrue($result['success'] ?? false);
        $this->assertNotEmpty($result['replay']['id'] ?? null);
        $this->assertSame('SAVE01', $result['replay']['room_id'] ?? null);
        $this->assertGreaterThan(0, intval($result['replay']['action_count'] ?? 0));
    }

    public function testReplaySaveAcceptsSlimClientPayload(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        putenv('TCG_LOCAL_FAKE_AUTH=1');
        $uid = '900000000000000002';
        $token = \tcgLocalIssueToken($uid);
        [$created, $payload] = $this->sampleReplayPayload('SLIM01');
        $slim = stripReplayForTransfer($payload);
        $this->assertTrue(isReplayTransferSlim($slim));
        $this->assertArrayNotHasKey('frames', $slim);

        $result = tcgApiReplaySave([
            'auth_token' => $token,
            'room_id' => 'SLIM01',
            'player_token' => $created['player_token'],
            'preserve' => true,
            'replay' => $slim,
        ]);

        $this->assertTrue($result['success'] ?? false);
        $row = \tcgReplayLoadOwnedRow($uid, intval($result['replay']['id'] ?? 0));
        $stored = replayPayloadDecodeFromStorage((string)$row['payload_json']);
        // Library stores slim v1 so long matches do not time out converting frames (#186).
        $this->assertSame(1, intval($stored['schema_version'] ?? 0));
        $this->assertTrue(isReplayTransferSlim($stored));
        $loaded = tcgReplayPayloadFromRow($row);
        $this->assertSame(REPLAY_SCHEMA_VERSION, intval($loaded['schema_version'] ?? 0));
        $this->assertCount(count($loaded['actions']) + 1, $loaded['frames'] ?? []);
    }

    public function testReplaySaveRefreshesShorterExistingPayload(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        putenv('TCG_LOCAL_FAKE_AUTH=1');
        $uid = '900000000000000001';
        $token = \tcgLocalIssueToken($uid);
        [$created, $payload] = $this->sampleReplayPayload('RFSH01');
        $short = $payload;
        $short['actions'] = array_slice($payload['actions'], 0, 1);
        $short['meta']['game_seq'] = 1;

        $first = tcgApiReplaySave([
            'auth_token' => $token,
            'room_id' => 'RFSH01',
            'player_token' => $created['player_token'],
            'autosave' => true,
            'kind' => 'autosave',
            'replay' => stripReplayForTransfer($short),
        ]);
        $this->assertTrue($first['success'] ?? false);
        $this->assertSame(1, intval($first['replay']['action_count'] ?? 0));

        $full = $payload;
        $full['meta']['game_seq'] = 99;
        $second = tcgApiReplaySave([
            'auth_token' => $token,
            'room_id' => 'RFSH01',
            'player_token' => $created['player_token'],
            'preserve' => true,
            'kind' => 'library',
            'replay' => stripReplayForTransfer($full),
        ]);
        $this->assertTrue($second['success'] ?? false);
        $this->assertTrue($second['refreshed'] ?? false);
        $this->assertGreaterThan(1, intval($second['replay']['action_count'] ?? 0));
        $this->assertTrue(!empty($second['replay']['preserved']));

        $row = \tcgReplayLoadOwnedRow($uid, intval($second['replay']['id'] ?? 0));
        $stored = replayPayloadDecodeFromStorage((string)$row['payload_json']);
        $this->assertCount(count($payload['actions']), $stored['actions'] ?? []);
    }
}
