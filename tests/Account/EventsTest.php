<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

final class EventsTest extends TestCase
{
    private string $discordId;
    private string $oppId;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/events.php';
        require_once dirname(__DIR__, 2) . '/missions.php';
        tcgEventsEnsureSchema();
        $this->discordId = 'test_ep_' . bin2hex(random_bytes(4));
        $this->oppId = 'test_ep_opp_' . bin2hex(random_bytes(4));
        tcgEnsureUser($this->discordId, ['username' => 'EP Tester']);
        tcgEnsureUser($this->oppId, ['username' => 'EP Opp']);
    }

    private function createActiveEvent(array $extra = []): string
    {
        $db = tcgDb();
        $id = strtoupper(bin2hex(random_bytes(4)));
        $now = time();
        $db->prepare(
            'INSERT INTO tcg_events (id, name, banner_url, starts_at, ends_at, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $extra['name'] ?? 'Test Event',
            '',
            $extra['starts_at'] ?? ($now - 60),
            $extra['ends_at'] ?? ($now + 3600),
            $extra['status'] ?? 'active',
            $now,
            $now,
        ]);
        return $id;
    }

    public function testRankedWinLossMirrorsCoins(): void
    {
        $win = tcgEventPointsForFinishedMatch([
            'status' => 'finished',
            'end_reason' => 'game',
            'mode' => 'ranked',
            'winner' => 'p1',
            'players' => [
                'p1' => ['discord_id' => $this->discordId],
                'p2' => ['discord_id' => $this->oppId],
            ],
        ], 'p1');
        $this->assertSame(200, $win);

        $loss = tcgEventPointsForFinishedMatch([
            'status' => 'finished',
            'end_reason' => 'game',
            'mode' => 'ranked',
            'winner' => 'p2',
            'players' => [
                'p1' => ['discord_id' => $this->discordId],
                'p2' => ['discord_id' => $this->oppId],
            ],
        ], 'p1');
        $this->assertSame(100, $loss);
    }

    public function testCasualPvpAwardsZeroEp(): void
    {
        $n = tcgEventPointsForFinishedMatch([
            'status' => 'finished',
            'end_reason' => 'game',
            'mode' => 'casual',
            'winner' => 'p1',
            'players' => [
                'p1' => ['discord_id' => $this->discordId],
                'p2' => ['discord_id' => $this->oppId],
            ],
        ], 'p1');
        $this->assertSame(0, $n);
    }

    public function testCpuTiersAndResignZero(): void
    {
        $base = [
            'status' => 'finished',
            'end_reason' => 'game',
            'cpu_solo' => true,
            'winner' => 'p1',
            'players' => [
                'p1' => ['discord_id' => $this->discordId],
                'p2' => ['is_cpu' => true],
            ],
        ];
        $this->assertSame(80, tcgEventPointsForFinishedMatch(array_merge($base, ['cpu_difficulty' => 'easy']), 'p1'));
        $this->assertSame(140, tcgEventPointsForFinishedMatch(array_merge($base, ['cpu_difficulty' => 'expert']), 'p1'));

        $resign = tcgEventPointsForFinishedMatch([
            'status' => 'finished',
            'end_reason' => 'resign',
            'mode' => 'ranked',
            'winner' => 'p1',
            'players' => [
                'p1' => ['discord_id' => $this->discordId],
                'p2' => ['discord_id' => $this->oppId],
            ],
        ], 'p1');
        $this->assertSame(0, $resign);
    }

    public function testIdempotentRoomGrantAndMilestone(): void
    {
        $eventId = $this->createActiveEvent();
        $db = tcgDb();
        $db->prepare(
            'INSERT INTO tcg_event_milestones (event_id, threshold_points, sort_order, reward_type, reward_payload)
             VALUES (?, 100, 0, ?, ?)'
        )->execute([$eventId, 'coins', json_encode(['amount' => 50])]);

        $coinsBefore = tcgGetCoins($this->discordId);
        $room = 'E' . strtoupper(bin2hex(random_bytes(3)));
        $state = [
            'status' => 'finished',
            'end_reason' => 'game',
            'mode' => 'ranked',
            'room_id' => $room,
            'winner' => 'p1',
            'players' => [
                'p1' => ['discord_id' => $this->discordId],
                'p2' => ['discord_id' => $this->oppId],
            ],
        ];
        $first = tcgEventPointsOnGameFinished($state);
        $this->assertNotEmpty($first);
        $mine = null;
        foreach ($first as $g) {
            if ($g['discord_id'] === $this->discordId && $g['event_id'] === $eventId) {
                $mine = $g;
                break;
            }
        }
        $this->assertNotNull($mine);
        $this->assertSame(200, $mine['amount']);
        $this->assertSame(200, $mine['balance']);
        $this->assertNotEmpty($mine['milestones']);
        $this->assertSame($coinsBefore + 50, tcgGetCoins($this->discordId));

        $second = tcgEventPointsOnGameFinished($state);
        $again = [];
        foreach ($second as $g) {
            if ($g['discord_id'] === $this->discordId && $g['event_id'] === $eventId) {
                $again[] = $g;
            }
        }
        $this->assertSame([], $again);
        $this->assertSame(200, tcgEventsGetPoints($eventId, $this->discordId));
    }

    public function testRankSettleGrantsOnce(): void
    {
        $now = time();
        $eventId = $this->createActiveEvent([
            'starts_at' => $now - 7200,
            'ends_at' => $now - 10,
            'status' => 'ended',
        ]);
        $db = tcgDb();
        $db->prepare(
            'INSERT INTO tcg_event_rank_rewards (event_id, rank_from, rank_to, reward_type, reward_payload)
             VALUES (?, 1, 1, ?, ?)'
        )->execute([$eventId, 'coins', json_encode(['amount' => 77])]);
        $db->prepare(
            'INSERT INTO tcg_event_points (event_id, discord_id, points, updated_at) VALUES (?, ?, 500, ?)'
        )->execute([$eventId, $this->discordId, $now]);
        $db->prepare(
            'INSERT INTO tcg_event_points (event_id, discord_id, points, updated_at) VALUES (?, ?, 100, ?)'
        )->execute([$eventId, $this->oppId, $now]);

        $before = tcgGetCoins($this->discordId);
        tcgEventsSettleEnded($eventId);
        $this->assertSame($before + 77, tcgGetCoins($this->discordId));

        $row = $db->prepare('SELECT ended_processed_at FROM tcg_events WHERE id = ?');
        $row->execute([$eventId]);
        $this->assertNotNull($row->fetchColumn());

        $before2 = tcgGetCoins($this->discordId);
        tcgEventsSettleEnded($eventId);
        $this->assertSame($before2, tcgGetCoins($this->discordId));
    }
}
