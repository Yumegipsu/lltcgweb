<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Regression: GitHub issue #66 — multi-Live heart reservation, stripped SUKI Live Start.
 */
final class Issue66PerfBugsTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function basePlayers(): array
    {
        return [
            'p1' => [
                'id' => 'p1',
                'name' => 'P1',
                'hand' => [],
                'waiting_room' => [],
                'stage' => ['left' => null, 'center' => null, 'right' => null],
                'energy_zone' => [],
                'main_deck' => [],
                'success_lives' => [],
                'live_zone' => [],
            ],
            'p2' => [
                'id' => 'p2',
                'name' => 'P2',
                'hand' => [],
                'waiting_room' => [],
                'stage' => ['left' => null, 'center' => null, 'right' => null],
                'energy_zone' => [],
                'main_deck' => [],
                'success_lives' => [],
                'live_zone' => [],
            ],
        ];
    }

    public function testAnySlotsReserveLaterLiveColoredRequirements(): void
    {
        $reqAny = [['color' => 'any', 'count' => 5]];
        $reqRed = [['color' => 'red', 'count' => 5]];
        $pool = array_merge(array_fill(0, 5, 'red'), array_fill(0, 5, 'green'));

        // Without reservation, paying any×5 first spends all reds and fails red×5.
        [$okGreedy] = checkHearts($pool, $reqAny);
        $this->assertTrue($okGreedy);

        $reserve = coloredHeartDemandFromRequirements($reqRed);
        [$ok1, $rem] = checkHearts($pool, $reqAny, $reserve);
        [$ok2] = checkHearts($rem, $reqRed);
        $this->assertTrue($ok1);
        $this->assertTrue($ok2, 'Reserved reds must remain for the later Live');
    }

    public function testTwoMijukuWithThirtyFiveHeartsBothSucceed(): void
    {
        $mijuku = [
            ['color' => 'red', 'count' => 2],
            ['color' => 'green', 'count' => 2],
            ['color' => 'blue', 'count' => 2],
            ['color' => 'any', 'count' => 8],
        ];
        $pool = array_merge(
            array_fill(0, 15, 'red'),
            array_fill(0, 6, 'green'),
            array_fill(0, 6, 'blue'),
            array_fill(0, 4, 'yellow'),
            array_fill(0, 4, 'pink')
        );
        $reserve = coloredHeartDemandFromRequirements($mijuku);
        [$ok1, $rem] = checkHearts($pool, $mijuku, $reserve);
        [$ok2] = checkHearts($rem, $mijuku);
        $this->assertTrue($ok1);
        $this->assertTrue($ok2);
    }

    public function testStrippedSukiLiveStartWithChikaContinuousBladeScores(): void
    {
        // Runtime hand→zone copies often omit abilities/score (issue #66 comment).
        $live = [
            'instance_id' => 'suki_stripped',
            'card_no' => 'PL!S-bp3-025-L',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'SUKI for you, DREAM for you!',
            'group' => 'Sunshine',
        ];
        $chika = $this->cardByNo('PL!S-bp2-001-P', 'chika_center');
        // Opponent already has a Success Live so Chika's +3 Blade continuous applies.
        $oppLive = $this->cardByNo('PL!S-bp2-022-L', 'opp_success');

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['stage']['center'] = $chika;
        $state['players']['p1']['success_lives'] = [];
        $state['players']['p2']['success_lives'] = [$oppLive];

        $blade = getMemberBlade($chika, $state, 'p1', 'center');
        $this->assertGreaterThanOrEqual(7, $blade, 'Chika should be 4+3=7 Blades');

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame(
            'score_if_stage_member_hearts',
            $state['pending_prompt']['type'] ?? null,
            'Stripped SUKI must still open Live Start after catalog hydrate'
        );

        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'center']);
        $msgs = array_map(
            static fn($line) => is_array($line) ? (string)($line['msg'] ?? '') : (string)$line,
            $state['log'] ?? []
        );
        $this->assertTrue(
            (bool)array_filter($msgs, static fn($m) => str_contains($m, 'score +1')),
            'Expected score +1 log after choosing 7-Blade Chika'
        );
    }

    public function testBumpLiveCardScoreHydratesPrintedScoreOnStrippedLive(): void
    {
        $state = [
            'players' => [
                'p1' => [
                    'live_zone' => [[
                        'instance_id' => 'suki_stripped',
                        'card_no' => 'PL!S-bp3-025-L',
                        'card_type' => 'ライブ',
                        'card_type_en' => 'Live',
                    ]],
                ],
            ],
        ];
        $ok = bumpLiveCardScore($state, 'p1', 'suki_stripped', 1);
        $this->assertTrue($ok);
        $this->assertSame(6, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));
    }

    public function testPerformanceAllOrNothingWithPartialHeartFail(): void
    {
        $liveOk = $this->cardByNo('PL!S-bp2-024-L', 'kimino'); // 1 blue + 1 any
        $liveHard = $this->cardByNo('PL!S-bp2-022-L', 'mijuku'); // 14 hearts
        $member = $this->cardByNo('PL!S-bp2-001-P', 'chika');

        $state = [
            'status' => 'playing',
            'phase' => 'live_performance_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => $this->basePlayers(),
            'yell_reveal' => ['p1' => [], 'p2' => []],
        ];
        // Only enough hearts for Kimino, not Mijuku.
        $member['hearts'] = [
            ['color' => 'blue', 'count' => 1],
            ['color' => 'red', 'count' => 1],
        ];
        $state['players']['p1']['stage']['center'] = $member;
        $state['players']['p1']['live_zone'] = [$liveOk, $liveHard];
        $state['players']['p1']['yell_cards'] = [];

        $state = \resolvePerformanceHeartCheck($state, 'p1', false);

        $this->assertFalse($state['live_round_success']['p1'] ?? true);
        $this->assertSame([], $state['players']['p1']['live_zone']);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('kimino', $wrIds);
        $this->assertContains('mijuku', $wrIds);
    }
}
