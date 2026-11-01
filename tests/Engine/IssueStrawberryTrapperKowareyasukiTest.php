<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * B03096 (squeakychair_. vs eric.t__): Kowareyasuki strips opponent surplus, then
 * each Strawberry Trapper must see "no excess" and get +2 when Aqours blue hearts ≥ 4.
 */
final class IssueStrawberryTrapperKowareyasukiTest extends TestCase
{
    private function live(string $no, string $id): array
    {
        $c = \tcgCatalogCardByNo($no);
        $this->assertIsArray($c, $no);
        $c['instance_id'] = $id;
        mergeCardCatalogFields($c);
        $c['_printed_score'] = liveCardPrintedScore($c);
        $c['score'] = intval($c['score'] ?? $c['_printed_score']);
        return $c;
    }

    private function member(string $no, string $id, array $hearts): array
    {
        $c = \tcgCatalogCardByNo($no);
        $this->assertIsArray($c, $no);
        $c['instance_id'] = $id;
        $c['active'] = true;
        $c['hearts'] = $hearts;
        return $c;
    }

    public function testHelperReadsClearedSurplusNotStaleFlag(): void
    {
        $state = [
            'live_perf_success' => ['p2' => [['instance_id' => 'x']]],
            'live_round_success' => ['p2' => true],
            '_live_excess_hearts' => ['p2' => 0],
            '_live_success_no_excess' => ['p2' => false],
            'players' => ['p2' => []],
        ];
        $this->assertTrue(\opponentSucceededLiveWithoutCurrentExcess($state, 'p2'));

        $state['_live_excess_hearts']['p2'] = 2;
        $this->assertFalse(\opponentSucceededLiveWithoutCurrentExcess($state, 'p2'));
    }

    public function testHelperIgnoresPartialSuccessOnFailedRound(): void
    {
        // Individual Live cards can pass hearts while the round still fails — that is not
        // "opponent succeeded a Live" for Strawberry Trapper (0C2CE1 / Kyra follow-up).
        $state = [
            'live_perf_success' => ['p2' => [['instance_id' => 'partial']]],
            'live_round_success' => ['p2' => false],
            '_live_excess_hearts' => ['p2' => 0],
            '_live_success_no_excess' => ['p2' => true],
            'players' => ['p2' => []],
        ];
        $this->assertFalse(\opponentSucceededLiveWithoutCurrentExcess($state, 'p2'));
    }

    public function testBothTrappersScoreAfterKowareClearsSurplus(): void
    {
        $koware = $this->live('PL!S-bp6-024-L', 'koware');
        $trap1 = $this->live('PL!S-pb1-021-L', 'trap1');
        $trap2 = $this->live('PL!S-pb1-021-L', 'trap2');
        $this->assertSame(5, (int)$koware['score']);
        $this->assertSame(1, (int)$trap1['score']);

        // 4 blue hearts across Aqours stage (Riko 1 + Chika 2 + Mari 1).
        $riko = $this->member('PL!S-sd1-002-SD', 'riko', [
            ['color' => 'red', 'count' => 2],
            ['color' => 'green', 'count' => 2],
            ['color' => 'blue', 'count' => 1],
        ]);
        $chika = $this->member('PL!S-bp3-001-P', 'chika', [
            ['color' => 'red', 'count' => 3],
            ['color' => 'green', 'count' => 2],
            ['color' => 'blue', 'count' => 2],
        ]);
        $mari = $this->member('PL!S-bp5-017-N', 'mari', [
            ['color' => 'blue', 'count' => 1],
        ]);

        $state = [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 5,
            'first_player' => 'p2',
            'active_player' => 'p1',
            'log' => [],
            'live_perf_success' => [
                'p1' => [$koware, $trap1, $trap2],
                'p2' => [['instance_id' => 'opp_live']],
            ],
            // Opponent already resolved their successful Live (first performer) with surplus.
            'live_round_success' => ['p2' => true],
            '_live_excess_hearts' => ['p1' => 0, 'p2' => 2],
            '_live_success_no_excess' => ['p1' => true, 'p2' => false],
            'live_modifiers' => ['p1' => [], 'p2' => []],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => $riko, 'center' => $chika, 'right' => $mari],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [$koware, $trap1, $trap2],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                    'succeeded_live_this_turn' => true,
                ],
            ],
        ];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$koware, $trap1, $trap2], 0, [], []);
        $this->assertSame('live_success_order_sources', $state['pending_prompt']['type'] ?? null);
        // Match the B03096 order: Kowareyasuki first, then both Trappers.
        $state = \actionResolvePrompt($state, 'p1', [
            'card_ids' => ['koware', 'trap1', 'trap2'],
        ]);

        $msgs = implode("\n", array_column($state['log'], 'msg'));
        $this->assertStringContainsString('opponent lost 2 surplus heart(s)', $msgs, $msgs);
        // Both copies score after Kowareyasuki clears surplus (B03096 bug: neither did).
        $this->assertSame(2, substr_count($msgs, 'score +2 (Aqours hearts + opponent no excess)'), $msgs);
        $this->assertSame(0, (int)($state['_live_excess_hearts']['p2'] ?? -1));
        $this->assertTrue(!empty($state['_live_success_no_excess']['p2']));

        // Direct effect path: Kowareyasuki +1 while the card is still in live_zone.
        $koware2 = $this->live('PL!S-bp6-024-L', 'koware2');
        $solo = [
            'log' => [],
            'live_round_success' => ['p2' => true],
            '_live_excess_hearts' => ['p2' => 2],
            '_live_success_no_excess' => ['p2' => false],
            'live_modifiers' => ['p1' => [], 'p2' => []],
            'players' => [
                'p1' => [
                    'id' => 'p1', 'name' => 'P1',
                    'live_zone' => [$koware2],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hand' => [], 'waiting_room' => [], 'energy_zone' => [], 'main_deck' => [], 'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2', 'name' => 'P2',
                    'live_zone' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hand' => [], 'waiting_room' => [], 'energy_zone' => [], 'main_deck' => [], 'success_lives' => [],
                    'succeeded_live_this_turn' => true,
                ],
            ],
        ];
        $solo = \sBp6ResolveEffect($solo, 'p1', $koware2, [
            'trigger' => 'live_success',
            'type' => 'live_success_opp_lose_surplus_score',
            'min_opp_lost' => 2,
            'score_bonus' => 1,
        ]);
        $this->assertSame(6, (int)$solo['players']['p1']['live_zone'][0]['score']);
        $this->assertTrue(!empty($solo['_live_success_no_excess']['p2']));
    }

    public function testKowareDoesNotStripHeartsFromFailedOppLive(): void
    {
        // 0C2CE1 turn 6: opponent failed the Live; leftover hearts must not count as surplus.
        $koware = $this->live('PL!S-bp6-024-L', 'koware');
        $trap = $this->live('PL!S-pb1-021-L', 'trap1');
        $riko = $this->member('PL!S-sd1-002-SD', 'riko', [
            ['color' => 'blue', 'count' => 4],
        ]);
        $state = [
            'log' => [],
            'live_round_success' => ['p2' => false],
            // Buggy pre-fix value: failed Live stored all hearts as "excess".
            '_live_excess_hearts' => ['p2' => 20],
            '_live_success_no_excess' => ['p2' => false],
            'live_modifiers' => ['p1' => [], 'p2' => []],
            'players' => [
                'p1' => [
                    'id' => 'p1', 'name' => 'P1',
                    'live_zone' => [$koware, $trap],
                    'stage' => ['left' => $riko, 'center' => null, 'right' => null],
                    'hand' => [], 'waiting_room' => [], 'energy_zone' => [], 'main_deck' => [], 'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2', 'name' => 'P2',
                    'live_zone' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hand' => [], 'waiting_room' => [], 'energy_zone' => [], 'main_deck' => [], 'success_lives' => [],
                ],
            ],
        ];
        $state = \sBp6ResolveEffect($state, 'p1', $koware, [
            'trigger' => 'live_success',
            'type' => 'live_success_opp_lose_surplus_score',
            'min_opp_lost' => 2,
            'score_bonus' => 1,
        ]);
        $state = \resolveAbilityEffect($state, 'p1', $trap, [
            'trigger' => 'live_success',
            'type' => 'score_if_group_stage_hearts_opp_no_excess',
            'group' => 'Sunshine',
            'min_hearts' => 4,
            'amount' => 2,
            'heart_color' => 'blue',
        ], ['phase' => 'live_success']);
        $msgs = implode("\n", array_column($state['log'], 'msg'));
        $this->assertStringNotContainsString('opponent lost', $msgs, $msgs);
        $this->assertStringNotContainsString('score +2', $msgs, $msgs);
        $this->assertSame(5, (int)$state['players']['p1']['live_zone'][0]['score']);
        $this->assertSame(1, (int)$state['players']['p1']['live_zone'][1]['score']);
        // Fake fail-surplus left untouched (Koware refused to strip a non-success).
        $this->assertSame(20, (int)($state['_live_excess_hearts']['p2'] ?? -1));
    }

    public function testTrapperDoesNotScoreWhileOppStillHasSurplus(): void
    {
        $trap = $this->live('PL!S-pb1-021-L', 'trap1');
        $riko = $this->member('PL!S-sd1-002-SD', 'riko', [
            ['color' => 'blue', 'count' => 4],
        ]);
        $state = [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 2,
            'log' => [],
            'live_perf_success' => [
                'p1' => [$trap],
                'p2' => [['instance_id' => 'opp_live']],
            ],
            'live_round_success' => ['p1' => true, 'p2' => true],
            '_live_excess_hearts' => ['p2' => 2],
            '_live_success_no_excess' => ['p2' => false],
            'live_modifiers' => ['p1' => [], 'p2' => []],
            'players' => [
                'p1' => [
                    'id' => 'p1', 'name' => 'P1', 'hand' => [], 'waiting_room' => [],
                    'stage' => ['left' => $riko, 'center' => null, 'right' => null],
                    'energy_zone' => [], 'main_deck' => [], 'live_zone' => [$trap], 'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2', 'name' => 'P2', 'hand' => [], 'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [], 'main_deck' => [], 'live_zone' => [], 'success_lives' => [],
                    'succeeded_live_this_turn' => true,
                ],
            ],
        ];
        $state = \resolveLiveSuccessAbilities($state, 'p1', [$trap], 0, [], []);
        $this->assertSame(1, (int)$state['players']['p1']['live_zone'][0]['score']);
        $msgs = implode("\n", array_column($state['log'], 'msg'));
        $this->assertStringNotContainsString('score +2', $msgs);
    }
}
