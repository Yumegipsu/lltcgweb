<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PHPUnit\Framework\TestCase;

final class TournamentPublicMatchReplaysTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/tournament_formats.php';
        require_once dirname(__DIR__, 2) . '/tournament_lib.php';
        require_once dirname(__DIR__, 2) . '/tournament_replays.php';
    }

    public function testPublicMatchExposesGamesWithReplayIds(): void
    {
        $meta = [
            'best_of' => 3,
            'p1_wins' => 2,
            'p2_wins' => 1,
            'games' => [
                [
                    'room_id' => 'AAAA01',
                    'winner_discord_id' => '111',
                    'replay_id' => 42,
                    'at' => 100,
                ],
                [
                    'room_id' => 'AAAA02',
                    'winner_discord_id' => '222',
                    'at' => 200,
                ],
                [
                    'room_id' => 'AAAA03',
                    'winner_discord_id' => '111',
                    'replay_id' => 99,
                    'at' => 300,
                ],
            ],
        ];
        $pub = \tcgTournamentPublicMatch([
            'id' => 'm1',
            'tournament_id' => 'T1',
            'round' => 2,
            'bracket_slot' => 0,
            'bracket_side' => 'winners',
            'p1_discord_id' => '111',
            'p2_discord_id' => '222',
            'room_id' => 'AAAA03',
            'status' => 'done',
            'winner_discord_id' => '111',
            'connect_deadline_at' => null,
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);

        $this->assertSame(3, $pub['best_of']);
        $this->assertSame(2, $pub['p1_wins']);
        $this->assertSame(1, $pub['p2_wins']);
        $this->assertCount(3, $pub['games']);
        $this->assertSame(42, $pub['games'][0]['replay_id']);
        $this->assertNull($pub['games'][1]['replay_id']);
        $this->assertSame(99, $pub['games'][2]['replay_id']);
        $this->assertSame('AAAA01', $pub['games'][0]['room_id']);
    }

    public function testPublicMatchHydratesReplayIdsFromArchiveIndex(): void
    {
        $meta = [
            'best_of' => 1,
            'p1_wins' => 1,
            'p2_wins' => 0,
            'games' => [
                [
                    'room_id' => 'bbbb02',
                    'winner_discord_id' => '111',
                    'at' => 100,
                ],
            ],
        ];
        $pub = \tcgTournamentPublicMatch([
            'id' => 'm2',
            'tournament_id' => 'T2',
            'round' => 2,
            'bracket_slot' => 0,
            'bracket_side' => 'swiss',
            'p1_discord_id' => '111',
            'p2_discord_id' => '222',
            'room_id' => 'BBBB02',
            'status' => 'done',
            'winner_discord_id' => '111',
            'connect_deadline_at' => null,
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        ], ['BBBB02' => 77]);

        $this->assertSame(77, $pub['games'][0]['replay_id']);
    }

    public function testHydratePublicGamesFillsOnlyMissingIds(): void
    {
        $games = [
            ['room_id' => 'R1', 'replay_id' => 5],
            ['room_id' => 'R2', 'replay_id' => null],
            ['room_id' => 'R3', 'replay_id' => null],
        ];
        $out = \tcgTournamentHydratePublicGames($games, [
            'R1' => 99,
            'R2' => 88,
        ]);
        $this->assertSame(5, $out[0]['replay_id']);
        $this->assertSame(88, $out[1]['replay_id']);
        $this->assertNull($out[2]['replay_id']);
    }
}
