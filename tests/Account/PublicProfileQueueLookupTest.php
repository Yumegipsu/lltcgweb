<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

/**
 * public_profile must resolve queue status without scanning the entire games/ dir.
 */
final class PublicProfileQueueLookupTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        $offlineAuth = dirname(__DIR__, 2) . '/llr_auth_offline.php';
        putenv('TCG_LLR_AUTH_FILE=' . $offlineAuth);
        if (!defined('TCG_ACCOUNT_LIB_ONLY')) {
            define('TCG_ACCOUNT_LIB_ONLY', true);
        }
        require_once dirname(__DIR__, 2) . '/account.php';
    }

    public function testCasualMatchLookupUsesDbTrackedRoomsOnly(): void
    {
        $uid = '900000000000000301';
        tcgEnsureUser($uid, ['username' => 'QueueLookup']);

        $gamesDir = defined('GAMES_DIR') ? GAMES_DIR : '';
        $this->assertNotSame('', $gamesDir);
        if (!is_dir($gamesDir)) {
            mkdir($gamesDir, 0755, true);
        }

        // Decoy file that would have been scanned by the old glob path.
        $decoyRoom = 'DECOY1';
        $decoyPath = $gamesDir . $decoyRoom . '.json';
        file_put_contents($decoyPath, json_encode([
            'room_id' => $decoyRoom,
            'mode' => 'casual',
            'status' => 'playing',
            'players' => [
                'p1' => ['name' => 'A', 'discord_id' => $uid],
                'p2' => ['name' => 'B', 'discord_id' => '900000000000000302'],
            ],
        ]));

        $trackedRoom = 'TRACK1';
        $trackedPath = $gamesDir . $trackedRoom . '.json';
        file_put_contents($trackedPath, json_encode([
            'room_id' => $trackedRoom,
            'mode' => 'casual',
            'status' => 'playing',
            'players' => [
                'p1' => ['name' => 'A', 'discord_id' => $uid],
                'p2' => ['name' => 'B', 'discord_id' => '900000000000000303'],
            ],
        ]));

        $db = tcgDb();
        $db->prepare(
            'INSERT INTO tcg_casual_matches (queue_key, room_id, player_token, player_id, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$uid, $trackedRoom, 'tok', 'p1', time()]);

        try {
            $hit = tcgPublicFindCasualMatchForUser($uid);
            $this->assertNotNull($hit);
            $this->assertSame('in_match', $hit['status'] ?? null);
            $this->assertSame($trackedRoom, $hit['room_id'] ?? null);
        } finally {
            @unlink($decoyPath);
            @unlink($trackedPath);
            $db->prepare('DELETE FROM tcg_casual_matches WHERE room_id IN (?, ?)')
                ->execute([$decoyRoom, $trackedRoom]);
        }
    }
}
