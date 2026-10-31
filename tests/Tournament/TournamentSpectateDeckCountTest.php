<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PHPUnit\Framework\TestCase;

/**
 * Delayed tournament spectate must keep public main/energy deck sizes after
 * stripping card faces from the delay ring.
 */
final class TournamentSpectateDeckCountTest extends TestCase
{
    private string $room = '';
    private string $path = '';

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/tournament_spectate.php';
        require_once dirname(__DIR__, 2) . '/spectate.php';
        $this->room = 'T' . strtoupper(bin2hex(random_bytes(3)));
        $this->path = tcgTournamentDelayFile($this->room);
        @mkdir(dirname($this->path), 0755, true);
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function testDelayedSnapshotPreservesDeckCountsWithoutFaces(): void
    {
        $live = [
            'mode' => 'tournament',
            'seq' => 5,
            'phase' => 'main_first',
            'spectate_stream_delay_secs' => 15,
            'room_id' => $this->room,
            'players' => [
                'p1' => [
                    'name' => 'Alice',
                    'hand' => [['card_no' => 'H1']],
                    'main_deck' => array_fill(0, 27, ['card_no' => 'M']),
                    'energy_deck' => array_fill(0, 9, ['card_no' => 'E']),
                ],
                'p2' => [
                    'name' => 'Bob',
                    'hand' => [],
                    'main_deck' => array_fill(0, 31, ['card_no' => 'M']),
                    'energy_deck' => array_fill(0, 6, ['card_no' => 'E']),
                ],
            ],
        ];

        tcgTournamentRecordDelayedSnapshot($this->room, $live);
        $this->assertFileExists($this->path);
        $ring = json_decode((string)file_get_contents($this->path), true);
        $entry = $ring['entries'][0] ?? null;
        $this->assertIsArray($entry);
        $p1 = $entry['state']['players']['p1'];
        $this->assertArrayNotHasKey('main_deck', $p1);
        $this->assertArrayNotHasKey('energy_deck', $p1);
        $this->assertSame(27, (int)$p1['main_deck_count']);
        $this->assertSame(9, (int)$p1['energy_deck_count']);
        $this->assertSame(31, (int)$entry['state']['players']['p2']['main_deck_count']);
        $this->assertSame(6, (int)$entry['state']['players']['p2']['energy_deck_count']);
    }

    public function testPileCountHelperPrefersStoredCountWhenFacesStripped(): void
    {
        $stripped = ['main_deck_count' => 22, 'energy_deck_count' => 8];
        $this->assertSame(22, tcgTournamentSpectatePileCount($stripped, 'main_deck'));
        $this->assertSame(8, tcgTournamentSpectatePileCount($stripped, 'energy_deck'));

        $faces = [
            'main_deck' => array_fill(0, 15, ['card_no' => 'M']),
            'main_deck_count' => 99, // ignored when faces present
            'energy_deck' => array_fill(0, 4, ['card_no' => 'E']),
        ];
        $this->assertSame(15, tcgTournamentSpectatePileCount($faces, 'main_deck'));
        $this->assertSame(4, tcgTournamentSpectatePileCount($faces, 'energy_deck'));

        $empty = ['main_deck' => [], 'energy_deck' => []];
        $this->assertSame(0, tcgTournamentSpectatePileCount($empty, 'main_deck'));
        $this->assertSame(0, tcgTournamentSpectatePileCount($empty, 'energy_deck'));
    }

    public function testHoldStateIncludesLivePileCounts(): void
    {
        $live = [
            'mode' => 'tournament',
            'seq' => 1,
            'spectate_stream_delay_secs' => 30,
            'players' => [
                'p1' => [
                    'name' => 'Alice',
                    'hand' => [['card_no' => 'SECRET']],
                    'main_deck' => array_fill(0, 40, ['card_no' => 'M']),
                    'energy_deck' => array_fill(0, 12, ['card_no' => 'E']),
                ],
                'p2' => [
                    'name' => 'Bob',
                    'hand' => [],
                    'main_deck' => array_fill(0, 39, ['card_no' => 'M']),
                    'energy_deck' => array_fill(0, 11, ['card_no' => 'E']),
                ],
            ],
        ];
        $hold = tcgTournamentSpectateHoldState($live, 30);
        $this->assertSame(40, (int)$hold['players']['p1']['main_deck_count']);
        $this->assertSame(12, (int)$hold['players']['p1']['energy_deck_count']);
        $this->assertSame(39, (int)$hold['players']['p2']['main_deck_count']);
        $this->assertSame(11, (int)$hold['players']['p2']['energy_deck_count']);
        $this->assertSame([], $hold['players']['p1']['main_deck']);
        $this->assertSame([], $hold['players']['p1']['hand']);
    }
}
