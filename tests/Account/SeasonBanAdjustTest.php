<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

final class SeasonBanAdjustTest extends TestCase
{
    /** @var list<string> */
    private array $ids = [];

    /** @var list<string> */
    private array $rooms = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/matchmaking.php';
        require_once dirname(__DIR__, 2) . '/account_ban.php';
        $GLOBALS['TCG_SEASON_NOW'] = strtotime('2026-10-15 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCG_SEASON_NOW']);
        $db = tcgDb();
        foreach ($this->rooms as $room) {
            $db->prepare('DELETE FROM tcg_ranked_matches WHERE room_id = ?')->execute([$room]);
            $db->prepare('DELETE FROM tcg_pvp_results WHERE room_id = ?')->execute([$room]);
        }
        foreach ($this->ids as $id) {
            $db->prepare('DELETE FROM tcg_account_bans WHERE discord_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM tcg_season_rank WHERE discord_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM tcg_season_history WHERE discord_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM tcg_rank WHERE discord_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM tcg_users WHERE discord_id = ?')->execute([$id]);
        }
    }

    private function user(string $name): string
    {
        $id = 'sban_' . $name . '_' . bin2hex(random_bytes(3));
        tcgEnsureUser($id, ['username' => $name]);
        $this->ids[] = $id;
        return $id;
    }

    private function win(string $winner, string $loser, int $at): void
    {
        $room = strtoupper(bin2hex(random_bytes(3)));
        $this->rooms[] = $room;
        tcgDb()->prepare(
            'INSERT INTO tcg_ranked_matches
                (match_id, room_id, p1_id, p2_id, p1_token, p2_token, status, created_at, game_mode, winner_pid)
             VALUES (?, ?, ?, ?, ?, ?, \'done\', ?, \'standard\', \'p1\')'
        )->execute(['m' . $room, $room, $winner, $loser, 'ta', 'tb', $at]);
        tcgApplyRankResult($winner, $loser, false, TCG_GAME_MODE_STANDARD);
    }

    /** @return array<string,mixed> */
    private function season(string $id): array
    {
        $st = tcgDb()->prepare(
            'SELECT step, points, peak_step, wins, losses FROM tcg_season_rank
             WHERE discord_id = ? AND game_mode = ?'
        );
        $st->execute([$id, TCG_GAME_MODE_STANDARD]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        return $row;
    }

    public function testBanRemovesSeasonPointsWonFromThatAccount(): void
    {
        $main = $this->user('main');
        $alt = $this->user('alt');
        $t = strtotime('2026-10-05 12:00:00 UTC');
        for ($i = 0; $i < 7; $i++) {
            $this->win($main, $alt, $t + $i);
        }

        $before = $this->season($main);
        $this->assertSame(1, (int)$before['step']);
        $this->assertSame(12, (int)$before['points']);
        $this->assertSame(7, (int)$before['wins']);
        $this->assertSame(1, (int)$before['peak_step']);

        tcgBanAccount($alt, 'owner_season_ban', 'alt_abuse');

        $after = $this->season($main);
        $this->assertSame(0, (int)$after['step']);
        $this->assertSame(0, (int)$after['points']);
        $this->assertSame(0, (int)$after['wins']);
        $this->assertSame(0, (int)$after['losses']);
        $this->assertSame(0, (int)$after['peak_step']);

        tcgUnbanAccount($alt, 'owner_season_ban');
        $this->assertSame($before, $this->season($main));
    }

    public function testBanKeepsSeasonPointsFromOtherOpponentsAndRestoresALoss(): void
    {
        $main = $this->user('main');
        $alt = $this->user('alt');
        $other = $this->user('other');
        $t = strtotime('2026-10-06 12:00:00 UTC');
        $step = 0;
        for ($i = 0; $step < 2 && $i < 40; $i++) {
            $this->win($main, $other, $t + $i);
            $step = (int)$this->season($main)['step'];
        }
        $this->assertGreaterThanOrEqual(2, $step);
        $climbed = $this->season($main);
        $otherBefore = $this->season($other);

        $this->win($alt, $main, $t + 100);
        $lost = $this->season($main);
        $this->assertSame((int)$climbed['wins'], (int)$lost['wins']);
        $this->assertSame((int)$climbed['losses'] + 1, (int)$lost['losses']);
        $this->assertNotSame((int)$climbed['points'], (int)$lost['points']);

        tcgBanAccount($alt, 'owner_season_ban', 'alt_abuse');

        $this->assertSame($climbed, $this->season($main));
        $this->assertSame($otherBefore, $this->season($other));

        tcgUnbanAccount($alt, 'owner_season_ban');
        $this->assertSame($lost, $this->season($main));
    }
}
