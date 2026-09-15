<?php

declare(strict_types=1);

namespace LLTCG\Tests\Social;

use PHPUnit\Framework\TestCase;

final class SocialBanTest extends TestCase
{
    private string $owner;
    private string $alt;
    private string $main;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/social.php';
        require_once dirname(__DIR__, 2) . '/account_ban.php';
        $this->owner = 'owner_' . bin2hex(random_bytes(3));
        $this->alt = 'alt_' . bin2hex(random_bytes(3));
        $this->main = 'main_' . bin2hex(random_bytes(3));
        tcgEnsureUser($this->alt, ['username' => 'Alt']);
        tcgEnsureUser($this->main, ['username' => 'Main']);
        $now = time();
        $db = tcgDb();
        $db->prepare(
            'INSERT INTO tcg_rank (discord_id, game_mode, rating, wins, losses, draws, games, updated_at)
             VALUES (?, ?, 1200, ?, ?, 0, ?, ?)
             ON CONFLICT(discord_id, game_mode) DO UPDATE SET
               wins=excluded.wins, losses=excluded.losses, games=excluded.games, rating=excluded.rating'
        )->execute([$this->main, 'standard', 10, 2, 12, $now]);
        $db->prepare(
            'INSERT INTO tcg_rank (discord_id, game_mode, rating, wins, losses, draws, games, updated_at)
             VALUES (?, ?, 1100, ?, ?, 0, ?, ?)
             ON CONFLICT(discord_id, game_mode) DO UPDATE SET
               wins=excluded.wins, losses=excluded.losses, games=excluded.games, rating=excluded.rating'
        )->execute([$this->alt, 'standard', 5, 5, 10, $now]);
        $db->prepare(
            'INSERT INTO tcg_ranked_matches
                (match_id, room_id, p1_id, p2_id, p1_token, p2_token, status, created_at, game_mode, winner_pid)
             VALUES (?, ?, ?, ?, ?, ?, \'done\', ?, \'standard\', \'p1\')'
        )->execute(['m' . $this->alt, 'ROOM' . substr($this->alt, -6), $this->main, $this->alt, 't1', 't2', $now]);
    }

    public function testBanWipesAccountReversesOpponentWlAndBlocksLogin(): void
    {
        tcgBanAccount($this->alt, $this->owner, 'alt_abuse');

        $st = tcgDb()->prepare('SELECT wins, losses, games FROM tcg_rank WHERE discord_id = ? AND game_mode = ?');
        $st->execute([$this->main, 'standard']);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(9, intval($row['wins']));
        $this->assertSame(2, intval($row['losses']));
        $this->assertSame(11, intval($row['games']));

        $gone = tcgDb()->prepare('SELECT 1 FROM tcg_users WHERE discord_id = ?');
        $gone->execute([$this->alt]);
        $this->assertFalse($gone->fetchColumn());

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(403);
        tcgEnsureUser($this->alt, ['username' => 'Alt']);
    }

    public function testUnbanRestoresSnapshotAndOpponentWl(): void
    {
        tcgBanAccount($this->alt, $this->owner, 'alt_abuse');
        tcgUnbanAccount($this->alt, $this->owner);

        $st = tcgDb()->prepare('SELECT wins, losses FROM tcg_rank WHERE discord_id = ? AND game_mode = ?');
        $st->execute([$this->main, 'standard']);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(10, intval($row['wins']));
        $this->assertSame(2, intval($row['losses']));

        $user = tcgDb()->prepare('SELECT username FROM tcg_users WHERE discord_id = ?');
        $user->execute([$this->alt]);
        $this->assertSame('Alt', (string)$user->fetchColumn());

        tcgEnsureUser($this->alt, ['username' => 'Alt']);
        $this->assertTrue(true);
    }

    public function testWarnStoresAltAbuseNotice(): void
    {
        tcgBanInsertNotice($this->alt, 'warn', 'alt_abuse');
        $notes = tcgBanPendingNotices($this->alt);
        $this->assertCount(1, $notes);
        $this->assertSame('alt_abuse', $notes[0]['reason']);
        tcgBanAckNotice($this->alt, intval($notes[0]['id']));
        $this->assertSame([], tcgBanPendingNotices($this->alt));
    }

    public function testBanUsesPvpWinnerWhenWinnerPidMissingAndDropsLeaderboard(): void
    {
        $now = time();
        $db = tcgDb();
        $room = 'ROOM' . substr($this->alt, -5) . 'X';
        $db->prepare(
            'INSERT INTO tcg_ranked_matches
                (match_id, room_id, p1_id, p2_id, p1_token, p2_token, status, created_at, game_mode, winner_pid)
             VALUES (?, ?, ?, ?, ?, ?, \'done\', ?, \'standard\', NULL)'
        )->execute(['m2' . $this->alt, $room, $this->main, $this->alt, 't1', 't2', $now]);
        tcgSocialEnsureSchema();
        $db->prepare(
            'INSERT OR IGNORE INTO tcg_pvp_results (room_id, mode, p1_id, p2_id, winner_id, ended_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$room, 'ranked', $this->main, $this->alt, $this->main, $now]);
        $db->prepare(
            'INSERT OR IGNORE INTO tcg_pvp_results (room_id, mode, p1_id, p2_id, winner_id, ended_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(['C' . substr($this->alt, -7), 'casual', $this->main, $this->alt, $this->main, $now]);
        $db->prepare('UPDATE tcg_users SET unranked_games = 3 WHERE discord_id = ?')->execute([$this->main]);

        tcgBanAccount($this->alt, $this->owner, 'alt_abuse');

        $st = $db->prepare('SELECT wins, losses, games FROM tcg_rank WHERE discord_id = ? AND game_mode = ?');
        $st->execute([$this->main, 'standard']);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        // Original test match (p1 win) + missing-winner_pid match resolved via pvp → −2 wins, −2 games
        $this->assertSame(8, intval($row['wins']));
        $this->assertSame(2, intval($row['losses']));
        $this->assertSame(10, intval($row['games']));

        $uq = $db->prepare('SELECT unranked_games FROM tcg_users WHERE discord_id = ?');
        $uq->execute([$this->main]);
        $this->assertSame(2, intval($uq->fetchColumn()));

        $this->assertTrue(tcgBanIsActive($this->alt));
        tcgBanEnsureSchema();
        $boardSql = 'SELECT r.discord_id FROM tcg_rank r
            JOIN tcg_users u ON u.discord_id = r.discord_id
            WHERE r.games > 0 AND r.game_mode = ?' . tcgBanLeaderboardExcludeSql('r.discord_id');
        $board = $db->prepare($boardSql);
        $board->execute(['standard']);
        $ids = $board->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertNotContains($this->alt, array_map('strval', $ids ?: []));
        $this->assertContains($this->main, array_map('strval', $ids ?: []));
    }
}
