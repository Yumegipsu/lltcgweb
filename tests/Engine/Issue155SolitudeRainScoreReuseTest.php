<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #155 — Solitude Rain (PL!N-bp1-027-L) must not keep Live Start score
 * bumps when sent to the Waiting Room and later reused (tournament 7B88CD:
 * +3 then +5 stacked to score 8).
 */
final class Issue155SolitudeRainScoreReuseTest extends TestCase
{
    private function solitude(string $iid): array
    {
        return [
            'instance_id' => $iid,
            'card_no' => 'PL!N-bp1-027-L',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'Solitude Rain',
            'group' => 'Nijigasaki',
            'score' => 0,
            '_printed_score' => 0,
            'abilities' => [[
                'trigger' => 'live_start',
                'type' => 'score_per_distinct_heart_colors',
                'group' => 'Nijigasaki',
                'filter' => 'member',
                'amount' => 1,
            ]],
        ];
    }

    private function nijiMember(string $iid, string $name, array $hearts): array
    {
        return [
            'instance_id' => $iid,
            'card_no' => 'PL!N-TEST-' . $iid,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => $name,
            'group' => 'Nijigasaki',
            'hearts' => $hearts,
        ];
    }

    public function testDrainToWaitingRoomRestoresPrintedScore(): void
    {
        $live = $this->solitude('sol_reuse');
        $live['score'] = 5;
        $live['_effect_score_bonus'] = 5;

        $state = [
            'room_id' => 'ISSUE155',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 3,
            'phase' => 'live_judge',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'live_zone' => [$live],
                    'waiting_room' => [],
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'live_zone' => [],
                    'waiting_room' => [],
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];

        $anims = [];
        $out = drainLiveStorageLeftovers($state, $anims);
        $wr = $out['players']['p1']['waiting_room'][0] ?? null;
        $this->assertNotNull($wr);
        $this->assertSame(0, intval($wr['score'] ?? -1));
        $this->assertSame(0, intval($wr['_printed_score'] ?? -1));
        $this->assertArrayNotHasKey('_effect_score_bonus', $wr);
    }

    public function testReuseAfterPriorBonusDoesNotStack(): void
    {
        $live = $this->solitude('sol_2');
        // Simulate dirty WR copy (pre-fix) then restore on the path to hand.
        $live['score'] = 3;
        $live['_effect_score_bonus'] = 3;
        $clean = liveCardRestorePrintedScore($live);
        $this->assertSame(0, intval($clean['score'] ?? -1));

        $state = [
            'room_id' => 'ISSUE155B',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 5,
            'phase' => 'live_start_effects',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'live_zone' => [$clean],
                    'waiting_room' => [],
                    'hand' => [],
                    'stage' => [
                        'left' => $this->nijiMember('ai', 'Ai Miyashita', [
                            ['color' => 'red', 'count' => 1],
                            ['color' => 'blue', 'count' => 1],
                        ]),
                        'center' => $this->nijiMember('emma', 'Emma Verde', [
                            ['color' => 'red', 'count' => 2],
                            ['color' => 'green', 'count' => 2],
                            ['color' => 'purple', 'count' => 3],
                        ]),
                        'right' => $this->nijiMember('ayumu', 'Ayumu Uehara', [
                            ['color' => 'pink', 'count' => 2],
                            ['color' => 'red', 'count' => 2],
                            ['color' => 'green', 'count' => 2],
                            ['color' => 'blue', 'count' => 2],
                        ]),
                    ],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'live_zone' => [],
                    'waiting_room' => [],
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];

        $out = resolveAbilityEffect($state, 'p1', $clean, $clean['abilities'][0], [
            'phase' => 'live_start',
        ]);
        // pink, red, green, purple, blue = 5 (not 5+3 leftover)
        $this->assertSame(5, intval($out['players']['p1']['live_zone'][0]['score'] ?? 0));
        $this->assertSame(5, intval($out['players']['p1']['live_zone'][0]['_effect_score_bonus'] ?? 0));
    }

    public function testDistinctColorsIgnoreNonOfficialColors(): void
    {
        $live = $this->solitude('sol_cap');
        $state = [
            'room_id' => 'ISSUE155C',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'live_zone' => [$live],
                    'waiting_room' => [],
                    'hand' => [],
                    'stage' => [
                        'left' => $this->nijiMember('a', 'A', [
                            ['color' => 'pink', 'count' => 1],
                            ['color' => 'green', 'count' => 1],
                            ['color' => 'blue', 'count' => 1],
                        ]),
                        'center' => $this->nijiMember('b', 'B', [
                            ['color' => 'red', 'count' => 1],
                            ['color' => 'yellow', 'count' => 1],
                            ['color' => 'purple', 'count' => 1],
                            ['color' => 'any', 'count' => 2],
                        ]),
                        'right' => $this->nijiMember('c', 'C', [
                            // Garbage / future tokens must not exceed the official 6.
                            ['color' => 'rainbow', 'count' => 1],
                            ['color' => 'gray', 'count' => 1],
                        ]),
                    ],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'live_zone' => [],
                    'waiting_room' => [],
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];

        $out = resolveAbilityEffect($state, 'p1', $live, $live['abilities'][0], [
            'phase' => 'live_start',
        ]);
        $this->assertSame(6, intval($out['players']['p1']['live_zone'][0]['score'] ?? 0));
    }
}
