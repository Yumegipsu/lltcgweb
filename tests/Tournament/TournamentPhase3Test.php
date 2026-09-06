<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PHPUnit\Framework\TestCase;

final class TournamentPhase3Test extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/tournament_lib.php';
        require_once dirname(__DIR__, 2) . '/tournament_formats.php';
    }

    public function testSwissPairingsAvoidRematchWhenPossible(): void
    {
        $records = [
            'A' => ['wins' => 1, 'losses' => 0],
            'B' => ['wins' => 1, 'losses' => 0],
            'C' => ['wins' => 0, 'losses' => 1],
            'D' => ['wins' => 0, 'losses' => 1],
        ];
        $pairings = tcgTournamentBuildSwissPairings(
            ['A', 'B', 'C', 'D'],
            $records,
            [['A', 'B']]
        );
        $this->assertCount(2, $pairings);
        foreach ($pairings as $p) {
            $this->assertNull($p['bye']);
            $pair = [$p['p1'], $p['p2']];
            sort($pair);
            $this->assertNotSame(['A', 'B'], $pair);
        }
    }

    public function testPriorPairsSkipWinnersUnlessLivesDeMode(): void
    {
        $matches = [
            [
                'bracket_side' => 'swiss',
                'p1_discord_id' => 'A',
                'p2_discord_id' => 'B',
            ],
            [
                'bracket_side' => 'winners',
                'p1_discord_id' => 'C',
                'p2_discord_id' => 'D',
            ],
        ];
        $swissOnly = tcgTournamentPriorPairsFromMatches($matches);
        $this->assertSame([['A', 'B']], $swissOnly);

        $withWinners = tcgTournamentPriorPairsFromMatches($matches, true);
        $this->assertCount(2, $withWinners);
        $this->assertContains(['A', 'B'], $withWinners);
        $this->assertContains(['C', 'D'], $withWinners);
    }

    public function testDoubleElimLivesPairingsAvoidRematchWhenPossible(): void
    {
        // Lives DE stores rounds as bracket_side=winners; priorPairs must include them.
        $records = [
            'A' => ['wins' => 1, 'losses' => 0],
            'B' => ['wins' => 1, 'losses' => 0],
            'C' => ['wins' => 0, 'losses' => 1],
            'D' => ['wins' => 0, 'losses' => 1],
        ];
        $prior = tcgTournamentPriorPairsFromMatches([
            [
                'bracket_side' => 'winners',
                'p1_discord_id' => 'A',
                'p2_discord_id' => 'B',
            ],
            [
                'bracket_side' => 'winners',
                'p1_discord_id' => 'C',
                'p2_discord_id' => 'D',
            ],
        ], true);
        $pairings = tcgTournamentBuildSwissPairings(
            ['A', 'B', 'C', 'D'],
            $records,
            $prior
        );
        $this->assertCount(2, $pairings);
        foreach ($pairings as $p) {
            $pair = [$p['p1'], $p['p2']];
            sort($pair);
            $this->assertNotSame(['A', 'B'], $pair);
            $this->assertNotSame(['C', 'D'], $pair);
        }
    }

    public function testSwissRoundCountBounds(): void
    {
        $this->assertSame(3, tcgTournamentSwissRoundCount(2));
        $this->assertSame(3, tcgTournamentSwissRoundCount(4));
        $this->assertSame(4, tcgTournamentSwissRoundCount(10));
        $this->assertSame(5, tcgTournamentSwissRoundCount(40));
    }

    public function testBracketPreviewScalesWithPlayerCap(): void
    {
        $p4 = tcgTournamentBracketPreview(4, 'single_elim');
        $winners = array_values(array_filter($p4, static fn($x) => ($x['bracket_side'] ?? '') === 'winners'));
        $this->assertSame(3, count($winners)); // 2 semis + final slots = 2+1

        $p8 = tcgTournamentBracketPreview(8, 'single_elim');
        $w8 = array_values(array_filter($p8, static fn($x) => ($x['bracket_side'] ?? '') === 'winners'));
        $this->assertSame(7, count($w8)); // 4+2+1

        $swiss = tcgTournamentBracketPreview(4, 'swiss');
        $this->assertNotEmpty($swiss);
        $this->assertSame('swiss', $swiss[0]['bracket_side']);
        $swissPlayoff = array_values(array_filter($swiss, static fn($x) => ($x['bracket_side'] ?? '') === 'winners'));
        $this->assertCount(1, $swissPlayoff); // ≤8 → final only

        $swiss9 = tcgTournamentBracketPreview(9, 'swiss');
        $cut9 = array_values(array_filter($swiss9, static fn($x) => ($x['bracket_side'] ?? '') === 'winners'));
        $this->assertCount(3, $cut9); // 2 semis + final

        // Large max capacity must not force a top-4 preview when field estimate is ≤8.
        $swiss16capBut8 = tcgTournamentBracketPreview(8, 'swiss');
        $cut8b = array_values(array_filter($swiss16capBut8, static fn($x) => ($x['bracket_side'] ?? '') === 'winners'));
        $this->assertCount(1, $cut8b);
        // Explicit playoffSize=4 is ignored when n < 9.
        $forced = tcgTournamentBracketPreview(8, 'swiss', 4);
        $forcedCut = array_values(array_filter($forced, static fn($x) => ($x['bracket_side'] ?? '') === 'winners'));
        $this->assertCount(1, $forcedCut);
    }

    public function testSwissOmwTiebreakPrefersStrongerOpponents(): void
    {
        // A, B, C all 2-1. Cut top 2.
        // A beat D(2-1) and E(0-2) → opp WR high
        // B beat E(0-2) and F(0-2) → opp WR low
        // C beat D(2-1) and F(0-2) → mid
        // Actually need consistent records from matches.
        $matches = [
            // Round results producing: A 2-0 vs D,E; wait need all 2-1
            // A>D, A>E, B>E, B>F, C>D, C>F, and D>B? Let's build carefully.
            // Players: A B C D E
            // Want A,B,C tied at 2-1 for top-2 cut.
            // A beat B, A beat E, lost to C → A 2-1
            // B beat D, B beat E, lost to A → B 2-1
            // C beat A, C beat D, lost to ? need C 2-1: C beat A, C beat E, lost to B? Then B needs another win...
            // Simpler synthetic records + matches for OMW only:
        ];
        // Use explicit match list:
        // A vs X (A wins), A vs Y (A wins), A vs Z (A loses) → A 2-1; opponents X,Y,Z
        // B vs X (B wins), B vs W (B wins), B vs Z (B loses) → B 2-1
        // C vs W (C wins), C vs Y (C wins), C vs Z (C loses) → C 2-1
        // X: lost to A, lost to B → need X record. Give X a win vs W? 
        // For OMW we only need records of opponents.
        // Set:
        // X 2-1, Y 0-2, Z 0-3, W 1-2
        // A faced X,Y,Z → OMW = (2/3 + 0 + 0)/3 = 0.222
        // B faced X,W,Z → (2/3 + 1/3 + 0)/3 = 0.333
        // C faced W,Y,Z → (1/3 + 0 + 0)/3 = 0.111
        // So B > A > C for OMW among 2-1
        $matches = [
            ['status' => 'done', 'bracket_side' => 'swiss', 'winner_discord_id' => 'A', 'p1_discord_id' => 'A', 'p2_discord_id' => 'X'],
            ['status' => 'done', 'bracket_side' => 'swiss', 'winner_discord_id' => 'A', 'p1_discord_id' => 'A', 'p2_discord_id' => 'Y'],
            ['status' => 'done', 'bracket_side' => 'swiss', 'winner_discord_id' => 'Z', 'p1_discord_id' => 'A', 'p2_discord_id' => 'Z'],
            ['status' => 'done', 'bracket_side' => 'swiss', 'winner_discord_id' => 'B', 'p1_discord_id' => 'B', 'p2_discord_id' => 'X'],
            ['status' => 'done', 'bracket_side' => 'swiss', 'winner_discord_id' => 'B', 'p1_discord_id' => 'B', 'p2_discord_id' => 'W'],
            ['status' => 'done', 'bracket_side' => 'swiss', 'winner_discord_id' => 'Z', 'p1_discord_id' => 'B', 'p2_discord_id' => 'Z'],
            ['status' => 'done', 'bracket_side' => 'swiss', 'winner_discord_id' => 'C', 'p1_discord_id' => 'C', 'p2_discord_id' => 'W'],
            ['status' => 'done', 'bracket_side' => 'swiss', 'winner_discord_id' => 'C', 'p1_discord_id' => 'C', 'p2_discord_id' => 'Y'],
            ['status' => 'done', 'bracket_side' => 'swiss', 'winner_discord_id' => 'Z', 'p1_discord_id' => 'C', 'p2_discord_id' => 'Z'],
            // pad X and W records: X beat W once already from B? X lost to A and B.
            // Give X a win: X vs Y
            ['status' => 'done', 'bracket_side' => 'swiss', 'winner_discord_id' => 'X', 'p1_discord_id' => 'X', 'p2_discord_id' => 'Y'],
            // W needs another game for 1-2: W already lost to B and C. Give W a win vs Y - Y already has losses
            ['status' => 'done', 'bracket_side' => 'swiss', 'winner_discord_id' => 'W', 'p1_discord_id' => 'W', 'p2_discord_id' => 'Y'],
        ];
        // Recalculate from matches:
        $records = tcgTournamentRecordsFromMatches($matches, 'swiss');
        $this->assertSame(2, $records['A']['wins']);
        $this->assertSame(1, $records['A']['losses']);
        $this->assertSame(2, $records['B']['wins']);
        $this->assertSame(1, $records['B']['losses']);
        $this->assertSame(2, $records['C']['wins']);
        $this->assertSame(1, $records['C']['losses']);

        $ranked = tcgTournamentSortBySwissStanding(['A', 'B', 'C'], $records, $matches, 'swiss');
        $this->assertSame(['B', 'A', 'C'], $ranked);
        $omwB = tcgTournamentOmwPercent('B', $records, $matches, 'swiss');
        $omwA = tcgTournamentOmwPercent('A', $records, $matches, 'swiss');
        $omwC = tcgTournamentOmwPercent('C', $records, $matches, 'swiss');
        $this->assertGreaterThan($omwA, $omwB);
        $this->assertEqualsWithDelta($omwA, $omwC, 0.0001);
    }

    public function testSwissPlayoffSizeByShowedUp(): void
    {
        $this->assertSame(2, tcgTournamentSwissPlayoffSize(2));
        $this->assertSame(2, tcgTournamentSwissPlayoffSize(8));
        $this->assertSame(4, tcgTournamentSwissPlayoffSize(9));
        $this->assertSame(4, tcgTournamentSwissPlayoffSize(16));
    }

    public function testNormalizeKeepsSwissRuntimeFields(): void
    {
        $n = tcgTournamentNormalizeSettings([
            'format' => 'swiss',
            'swiss_rounds' => 3,
            'showed_up' => 8,
            'playoff_size' => 2,
            'swiss_phase' => 'playoff',
        ]);
        $this->assertSame(3, $n['swiss_rounds']);
        $this->assertSame(8, $n['showed_up']);
        $this->assertSame(2, $n['playoff_size']);
        $this->assertSame('playoff', $n['swiss_phase']);
        $encoded = tcgTournamentEncodeSettings($n);
        $decoded = tcgTournamentDecodeSettings($encoded);
        $this->assertSame(3, $decoded['swiss_rounds']);
        $this->assertSame(2, $decoded['playoff_size']);
    }

    public function testRecordsFromMatches(): void
    {
        $recs = tcgTournamentRecordsFromMatches([
            [
                'status' => 'done',
                'winner_discord_id' => 'A',
                'p1_discord_id' => 'A',
                'p2_discord_id' => 'B',
                'bracket_side' => 'swiss',
            ],
            [
                'status' => 'done',
                'winner_discord_id' => 'C',
                'p1_discord_id' => 'C',
                'p2_discord_id' => 'A',
                'bracket_side' => 'swiss',
            ],
            [
                'status' => 'done',
                'winner_discord_id' => 'A',
                'p1_discord_id' => 'A',
                'p2_discord_id' => 'C',
                'bracket_side' => 'winners',
            ],
        ]);
        $this->assertSame(2, $recs['A']['wins']);
        $swissOnly = tcgTournamentRecordsFromMatches([
            [
                'status' => 'done',
                'winner_discord_id' => 'A',
                'p1_discord_id' => 'A',
                'p2_discord_id' => 'B',
                'bracket_side' => 'swiss',
            ],
            [
                'status' => 'done',
                'winner_discord_id' => 'A',
                'p1_discord_id' => 'A',
                'p2_discord_id' => 'C',
                'bracket_side' => 'winners',
            ],
        ], 'swiss');
        $this->assertSame(1, $swissOnly['A']['wins']);
        $this->assertArrayNotHasKey('C', $swissOnly);
    }
}
