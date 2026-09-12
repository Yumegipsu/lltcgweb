<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Issue #181: overflow sanitize must apply Elo before clearing pending ranked rows.
 */
final class Issue181OverflowSanitizeEloTest extends TestCase
{
    public function testSanitizeOverflowFinishedAppliesEloBeforeComplete(): void
    {
        $path = dirname(__DIR__, 2) . '/matchmaking.php';
        $src = (string)file_get_contents($path);
        $this->assertNotFalse(strpos($src, 'function tcgTryApplyRankedEloFromOverflowFinishedRow'));

        $fnPos = strpos($src, 'function tcgSanitizeRankedMatchRow');
        $this->assertNotFalse($fnPos);
        $chunk = substr($src, $fnPos, 2500);

        $finishedPos = strpos($chunk, "\$probe === 'finished'");
        $this->assertNotFalse($finishedPos, 'sanitize must branch on finished overflow probe');

        $applyPos = strpos($chunk, 'tcgTryApplyRankedEloFromOverflowFinishedRow($row)');
        $completePos = strpos($chunk, 'tcgCompleteRankedMatch($roomId)', $finishedPos);
        $this->assertNotFalse($applyPos);
        $this->assertNotFalse($completePos);
        $this->assertGreaterThan(
            $finishedPos,
            $applyPos,
            'Elo heal must run inside the finished probe branch'
        );
        $this->assertGreaterThan(
            $applyPos,
            $completePos,
            'Must apply Elo before tcgCompleteRankedMatch on finished overflow rooms'
        );
    }

    public function testAbandonFinishedOverflowAppliesEloBeforeComplete(): void
    {
        $path = dirname(__DIR__, 2) . '/matchmaking.php';
        $src = (string)file_get_contents($path);
        $fnPos = strpos($src, 'function tcgAbandonActiveRankedGame');
        $this->assertNotFalse($fnPos);
        $chunk = substr($src, $fnPos, 4500);

        $finishedPos = strpos($chunk, "\$probe === 'finished'");
        $this->assertNotFalse($finishedPos);
        $applyPos = strpos($chunk, 'tcgTryApplyRankedEloFromOverflowFinishedRow($row)', $finishedPos);
        $this->assertNotFalse($applyPos, 'abandon finished overflow must heal Elo');
    }

    public function testWebhookApplyRecordsPvpHistoryAndElo(): void
    {
        require_once dirname(__DIR__, 2) . '/matchmaking.php';
        require_once dirname(__DIR__, 2) . '/social.php';

        $winnerId = 'test_i181_w_' . bin2hex(random_bytes(3));
        $loserId = 'test_i181_l_' . bin2hex(random_bytes(3));
        tcgEnsureUser($winnerId, ['username' => 'I181W']);
        tcgEnsureUser($loserId, ['username' => 'I181L']);
        $roomId = 'T' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        $db = tcgDb();
        $db->prepare('INSERT INTO tcg_ranked_matches
            (match_id, room_id, p1_id, p2_id, p1_token, p2_token, status, created_at, game_mode, pr_rewarded)
            VALUES (?, ?, ?, ?, ?, ?, "pending", ?, ?, 0)')
            ->execute([
                'M' . $roomId,
                $roomId,
                $winnerId,
                $loserId,
                'tok1',
                'tok2',
                time(),
                'standard',
            ]);

        $beforeW = intval(tcgRankRow($winnerId, 'standard')['rating']);
        $beforeL = intval(tcgRankRow($loserId, 'standard')['rating']);

        $out = tcgApplyRankedResultFromWebhook([
            'room_id' => $roomId,
            'winner' => 'p1',
            'p1_discord_id' => $winnerId,
            'p2_discord_id' => $loserId,
            'game_mode' => 'standard',
            'end_reason' => 'game',
            'turn' => 3,
        ]);

        $this->assertTrue($out['success'] ?? false);
        $this->assertGreaterThan($beforeW, intval(tcgRankRow($winnerId, 'standard')['rating']));
        $this->assertLessThan($beforeL, intval(tcgRankRow($loserId, 'standard')['rating']));

        $stmt = $db->prepare('SELECT status, winner_pid FROM tcg_ranked_matches WHERE room_id = ?');
        $stmt->execute([$roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('done', $row['status'] ?? null);
        $this->assertSame('p1', $row['winner_pid'] ?? null);

        $hist = $db->prepare(
            'SELECT 1 FROM tcg_pvp_results WHERE room_id = ? AND mode = ? LIMIT 1'
        );
        $hist->execute([$roomId, 'ranked']);
        $this->assertNotFalse($hist->fetchColumn(), 'ranked finish must write match history');
    }
}
