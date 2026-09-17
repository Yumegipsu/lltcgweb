<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #191: PL!SP-pb2-020 Natsumi Auto — discard 1 Liella! Live from hand
 * for +2 extra Yell (not Kurage-style Hasunosora Yell mill).
 */
final class Issue191NatsumiHandLiveExtraYellTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
    }

    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents(CARDS_FILE), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function performanceState(array $natsumi, array $handLive): array
    {
        return [
            'room_id' => 'ISSUE191',
            'status' => 'playing',
            'seq' => 10,
            'turn' => 2,
            'phase' => 'live_performance_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'yell_reveal' => [
                'p1' => [
                    [
                        'instance_id' => 'yell_hs',
                        'card_type' => 'メンバー',
                        'group' => 'Hasunosora',
                        'name_en' => 'HS Yell',
                    ],
                    [
                        'instance_id' => 'yell_liella',
                        'card_type' => 'メンバー',
                        'group' => 'Superstar',
                        'name_en' => 'Liella Yell',
                    ],
                ],
            ],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$handLive],
                    'stage' => [
                        'left' => null,
                        'center' => $natsumi,
                        'right' => null,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [
                        ['instance_id' => 'deck1', 'card_type' => 'メンバー', 'group' => 'Superstar', 'name_en' => 'D1'],
                        ['instance_id' => 'deck2', 'card_type' => 'メンバー', 'group' => 'Superstar', 'name_en' => 'D2'],
                        ['instance_id' => 'deck3', 'card_type' => 'メンバー', 'group' => 'Superstar', 'name_en' => 'D3'],
                    ],
                    'energy_deck' => [],
                    'live_zone' => [[
                        'instance_id' => 'live_set',
                        'card_type' => 'ライブ',
                        'group' => 'Superstar',
                        'name_en' => 'Set Live',
                        'score' => 1,
                        'required_hearts' => [['color' => 'red', 'count' => 1]],
                    ]],
                    'success_lives' => [],
                    'yell_cards' => [
                        [
                            'instance_id' => 'yell_hs',
                            'card_type' => 'メンバー',
                            'group' => 'Hasunosora',
                            'name_en' => 'HS Yell',
                        ],
                        [
                            'instance_id' => 'yell_liella',
                            'card_type' => 'メンバー',
                            'group' => 'Superstar',
                            'name_en' => 'Liella Yell',
                        ],
                    ],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testPromptUsesLiellaHandLiveNotHasunosoraYellMill(): void
    {
        $natsumi = $this->cardByNo('PL!SP-pb2-020-R', 'natsumi');
        $handLive = [
            'instance_id' => 'hand_live',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Superstar',
            'name_en' => 'Liella Live',
            'score' => 1,
        ];

        $state = $this->performanceState($natsumi, $handLive);
        $yell = $state['players']['p1']['yell_cards'];
        $state = resolveAutoYellAbilities($state, 'p1', $yell);

        $pr = $state['pending_prompt'] ?? null;
        $this->assertNotNull($pr);
        $this->assertSame('auto_yell_mill_extra_yell', $pr['type'] ?? null);
        $this->assertTrue(!empty($pr['from_hand']));
        $this->assertStringContainsString('Liella!', (string)($pr['prompt'] ?? ''));
        $this->assertStringNotContainsString('Hasunosora', (string)($pr['prompt'] ?? ''));
        $this->assertStringContainsString('hand', strtolower((string)($pr['prompt'] ?? '')));
        $this->assertSame(1, intval($pr['max_pick'] ?? 0));
        $candIds = array_column($pr['candidates'] ?? [], 'instance_id');
        $this->assertSame(['hand_live'], $candIds);
    }

    public function testAcceptingDiscardsHandLiveAndDrawsTwoExtraYell(): void
    {
        $natsumi = $this->cardByNo('PL!SP-pb2-020-R', 'natsumi');
        $handLive = [
            'instance_id' => 'hand_live',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Superstar',
            'name_en' => 'Liella Live',
            'score' => 1,
        ];
        $state = $this->performanceState($natsumi, $handLive);
        $state = resolveAutoYellAbilities($state, 'p1', $state['players']['p1']['yell_cards']);
        $this->assertSame('auto_yell_mill_extra_yell', $state['pending_prompt']['type'] ?? null);

        $state = actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'card_ids' => ['hand_live'],
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'] ?? [], 'instance_id');
        $this->assertNotContains('hand_live', $handIds);
        $wrIds = array_column($state['players']['p1']['waiting_room'] ?? [], 'instance_id');
        $this->assertContains('hand_live', $wrIds);
        $yellIds = array_column(
            $state['players']['p1']['yell_cards'] ?? $state['yell_reveal']['p1'] ?? [],
            'instance_id'
        );
        $this->assertContains('deck1', $yellIds);
        $this->assertContains('deck2', $yellIds);
        // Must not mill the Hasunosora yell reveal as if it were Kurage.
        $this->assertContains('yell_hs', $yellIds);
    }

    public function testSkipsWhenNoLiellaLiveInHand(): void
    {
        $natsumi = $this->cardByNo('PL!SP-pb2-020-R', 'natsumi');
        $handMember = [
            'instance_id' => 'hand_mem',
            'card_type' => 'メンバー',
            'group' => 'Superstar',
            'name_en' => 'Liella Member',
        ];
        $state = $this->performanceState($natsumi, $handMember);
        $state = resolveAutoYellAbilities($state, 'p1', $state['players']['p1']['yell_cards']);
        $this->assertNull($state['pending_prompt'] ?? null);
    }
}
