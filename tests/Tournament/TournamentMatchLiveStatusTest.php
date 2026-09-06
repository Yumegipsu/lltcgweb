<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PHPUnit\Framework\TestCase;

/**
 * Bracket must flip ready → live when players enter (join / active_game) or
 * when the room already has both seats present / turns started.
 */
final class TournamentMatchLiveStatusTest extends TestCase
{
    /** @var list<string> */
    private array $tournamentIds = [];

    /** @var list<string> */
    private array $userIds = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        putenv('TCG_TOURNAMENTS_ENABLED=1');
        require_once dirname(__DIR__, 2) . '/db.php';
        require_once dirname(__DIR__, 2) . '/coins.php';
        require_once dirname(__DIR__, 2) . '/tournament.php';

        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/migrations/017_tournaments.sql');
        try {
            tcgDb()->exec($sql);
        } catch (\Throwable $e) {
            // already applied
        }
        tcgDbEnsureColumn(tcgDb(), 'tcg_tournament_matches', 'bracket_side', "TEXT NOT NULL DEFAULT 'winners'");
        tcgDbEnsureColumn(tcgDb(), 'tcg_tournament_matches', 'meta_json', "TEXT NOT NULL DEFAULT '{}'");
    }

    protected function tearDown(): void
    {
        $db = tcgDb();
        foreach ($this->tournamentIds as $tid) {
            $db->prepare('DELETE FROM tcg_tournament_matches WHERE tournament_id = ?')->execute([$tid]);
            $db->prepare('DELETE FROM tcg_tournament_entrants WHERE tournament_id = ?')->execute([$tid]);
            $db->prepare('DELETE FROM tcg_tournaments WHERE id = ?')->execute([$tid]);
        }
        foreach ($this->userIds as $uid) {
            $db->prepare('DELETE FROM tcg_users WHERE discord_id = ?')->execute([$uid]);
        }
    }

    private function ensureUser(string $uid): void
    {
        $this->userIds[] = $uid;
        $now = time();
        tcgDb()->prepare(
            'INSERT OR REPLACE INTO tcg_users (discord_id, username, avatar_url, starter_deck, created_at, updated_at)
             VALUES (?, ?, NULL, "muse", ?, ?)'
        )->execute([$uid, $uid, $now, $now]);
    }

    /** @return array{tid:string,mid:string,p1:string,p2:string} */
    private function seedReadyMatch(): array
    {
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $tid = 'TL' . $suffix;
        $mid = 'TM' . $suffix;
        $host = 'tl_host_' . $suffix;
        $p1 = 'tl_p1_' . $suffix;
        $p2 = 'tl_p2_' . $suffix;
        $this->tournamentIds[] = $tid;
        $this->ensureUser($host);
        $this->ensureUser($p1);
        $this->ensureUser($p2);
        $now = time();
        $db = tcgDb();
        $db->prepare(
            'INSERT INTO tcg_tournaments
             (id, host_discord_id, title, status, game_mode, start_at, checkin_mins,
              min_players, max_players, entry_fee_coins, prize_pool_coins, settings_json, created_at, updated_at)
             VALUES (?, ?, "Live status", "running", "standard", ?, 10, 2, 8, 0, 0, "{}", ?, ?)'
        )->execute([$tid, $host, $now - 60, $now, $now]);
        $db->prepare(
            'INSERT INTO tcg_tournament_matches
             (id, tournament_id, round, bracket_slot, bracket_side, p1_discord_id, p2_discord_id,
              room_id, p1_token, p2_token, status, winner_discord_id, connect_deadline_at, meta_json, created_at, updated_at)
             VALUES (?, ?, 1, 0, "winners", ?, ?, "ROOM01", "tok1", "tok2", "ready", NULL, ?, "{}", ?, ?)'
        )->execute([$mid, $tid, $p1, $p2, $now + 180, $now, $now]);
        return ['tid' => $tid, 'mid' => $mid, 'p1' => $p1, 'p2' => $p2];
    }

    public function testPromoteMatchLiveIsIdempotent(): void
    {
        $ids = $this->seedReadyMatch();
        $this->assertTrue(tcgTournamentPromoteMatchLive($ids['mid']));
        $stmt = tcgDb()->prepare('SELECT status FROM tcg_tournament_matches WHERE id = ?');
        $stmt->execute([$ids['mid']]);
        $this->assertSame('live', (string)$stmt->fetchColumn());
        $this->assertFalse(tcgTournamentPromoteMatchLive($ids['mid']));
    }

    public function testActiveGamePromotesReadyToLive(): void
    {
        $ids = $this->seedReadyMatch();
        $active = tcgGetActiveTournamentGame($ids['p1']);
        $this->assertNotNull($active);
        $this->assertSame('ROOM01', $active['room_id'] ?? null);
        $stmt = tcgDb()->prepare('SELECT status FROM tcg_tournament_matches WHERE id = ?');
        $stmt->execute([$ids['mid']]);
        $this->assertSame('live', (string)$stmt->fetchColumn());
    }

    public function testRoomLooksInProgressRequiresBothSeatsOrTurn(): void
    {
        $this->assertFalse(tcgTournamentRoomLooksInProgress([
            'turn' => 0,
            'status' => 'playing',
            'players' => [
                'p1' => ['connected' => true],
                'p2' => [],
            ],
        ]));
        $this->assertTrue(tcgTournamentRoomLooksInProgress([
            'turn' => 0,
            'status' => 'playing',
            'players' => [
                'p1' => ['last_seen' => time()],
                'p2' => ['connected' => true],
            ],
        ]));
        $this->assertTrue(tcgTournamentRoomLooksInProgress([
            'turn' => 2,
            'status' => 'playing',
            'players' => [
                'p1' => [],
                'p2' => [],
            ],
        ]));
    }
}
