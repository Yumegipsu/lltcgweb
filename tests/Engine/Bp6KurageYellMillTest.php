<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!HS-bp6-027-L Tsukuyomi Kurage — yell mill + extra yell during Performance. */
final class Bp6KurageYellMillTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
    }

    private function kurageLive(): array
    {
        return [
            'instance_id' => 'kurage',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Hasunosora',
            'name' => '月夜見海月',
            'name_en' => 'Tsukuyomi Kurage',
            'score' => 5,
            'required_hearts' => [['color' => 'green', 'count' => 1]],
            'abilities' => [[
                'trigger' => 'auto',
                'type' => 'auto_yell_mill_extra_yell',
                'group' => 'Hasunosora',
                'max_mill' => 3,
                'once_per_turn' => true,
            ]],
        ];
    }

    private function yellDeck(): array
    {
        return [
            ['instance_id' => 'yell1', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'Y1'],
            ['instance_id' => 'yell2', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'Y2'],
            ['instance_id' => 'extra1', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'E1'],
            ['instance_id' => 'extra2', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'E2'],
            ['instance_id' => 'extra3', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'E3'],
            ['instance_id' => 'extra4', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'E4'],
        ];
    }

    private function performanceState(): array
    {
        return [
            'room_id' => 'KURAGE',
            'status' => 'playing',
            'seq' => 10,
            'turn' => 2,
            'phase' => 'live_performance_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => [
                        'left' => null,
                        'center' => [
                            'instance_id' => 'blade_member',
                            'card_type' => 'メンバー',
                            'group' => 'Hasunosora',
                            'name_en' => 'Blade',
                            'blade' => 2,
                            'active' => true,
                            'hearts' => [['color' => 'green', 'count' => 3]],
                        ],
                        'right' => null,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => $this->yellDeck(),
                    'energy_deck' => [],
                    'live_zone' => [$this->kurageLive()],
                    'success_lives' => [],
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

    public function testLiveInZoneTriggersYellMillPrompt(): void
    {
        $state = $this->performanceState();
        $after = \resolvePerformancePhase($state, 'p1', false);

        $this->assertSame('auto_yell_mill_extra_yell', $after['pending_prompt']['type'] ?? null);
        $this->assertSame('p1', $after['pending_prompt']['owner'] ?? null);
        $this->assertNotEmpty($after['pending_prompt']['candidates'] ?? []);
        $this->assertSame('p1', $after['_performance_continue'] ?? null);
        $yellIds = array_map(
            fn($c) => $c['instance_id'] ?? '',
            $after['players']['p1']['yell_cards'] ?? []
        );
        $this->assertContains('yell1', $yellIds);
        $this->assertContains('yell2', $yellIds);
    }

    public function testMillingTwoCardsPerformsTwoExtraYellDraws(): void
    {
        $state = $this->performanceState();
        // Pad so extra Yell draws do not shuffle milled WR cards back into the deck.
        $deck = $state['players']['p1']['main_deck'];
        for ($i = 0; $i < 40; $i++) {
            $deck[] = [
                'instance_id' => "pad$i",
                'card_type' => 'メンバー',
                'group' => 'Hasunosora',
                'name_en' => "Pad$i",
            ];
        }
        $state['players']['p1']['main_deck'] = $deck;
        $state = \resolvePerformancePhase($state, 'p1', false);
        $this->assertSame('auto_yell_mill_extra_yell', $state['pending_prompt']['type'] ?? null);

        $deckBefore = count($state['players']['p1']['main_deck']);
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'card_ids' => ['yell1', 'yell2'],
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(4, $deckBefore - count($state['players']['p1']['main_deck']),
            'Two extra Yells at Blade 2 should draw 4 cards');
        $wrIds = array_map(
            fn($c) => $c['instance_id'] ?? '',
            $state['players']['p1']['waiting_room'] ?? []
        );
        $this->assertContains('yell1', $wrIds);
        $this->assertContains('yell2', $wrIds);
        $yellIds = array_map(
            fn($c) => $c['instance_id'] ?? '',
            \currentPlayerYellCards($state, 'p1')
        );
        $this->assertContains('extra1', $yellIds);
        $this->assertContains('extra4', $yellIds);
        $this->assertNotContains('yell1', $yellIds);
    }

    public function testHeartCheckUsesCombinedYellPoolAfterExtraYell(): void
    {
        $state = $this->performanceState();

        $state = \resolvePerformancePhase($state, 'p1', false);
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'card_ids' => ['yell1', 'yell2'],
        ]);

        $pool = \currentPlayerYellCards($state, 'p1');
        $this->assertCount(4, $pool);

        unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        $state = \continuePerformanceAfterYellAbilities($state, 'p1');
        $this->assertNull($state['pending_prompt'] ?? null);

        $logText = implode("\n", array_column($state['log'] ?? [], 'msg'));
        $this->assertStringContainsString('performed Live!', $logText);
        $this->assertStringContainsString('Live success: 1', $logText);
        $successIds = array_map(
            fn($c) => $c['instance_id'] ?? '',
            $state['players']['p1']['success_lives'] ?? []
        );
        $this->assertContains('kurage', $successIds);
    }

    public function testAllEligibleYellCardsAreCandidatesWhenMoreThanMaxMill(): void
    {
        $state = $this->performanceState();
        $state['players']['p1']['stage']['center']['blade'] = 5;
        $deck = [
            ['instance_id' => 'yell1', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'Y1'],
            ['instance_id' => 'yell2', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'Y2'],
            ['instance_id' => 'yell3', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'Y3'],
            ['instance_id' => 'yell4', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'Y4'],
            ['instance_id' => 'yell5', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'Y5'],
            // Ineligible: has blade heart
            ['instance_id' => 'bladey', 'card_type' => 'メンバー', 'group' => 'Hasunosora', 'name_en' => 'BH', 'blade_hearts' => ['green']],
        ];
        // Pad so extra Yell draws do not recycle milled cards from WR back into the deck.
        for ($i = 0; $i < 40; $i++) {
            $deck[] = [
                'instance_id' => "pad$i",
                'card_type' => 'メンバー',
                'group' => 'Hasunosora',
                'name_en' => "Pad$i",
            ];
        }
        $state['players']['p1']['main_deck'] = $deck;

        $state = \resolvePerformancePhase($state, 'p1', false);
        $this->assertSame('auto_yell_mill_extra_yell', $state['pending_prompt']['type'] ?? null);
        $candIds = array_map(
            fn($c) => $c['instance_id'] ?? '',
            $state['pending_prompt']['candidates'] ?? []
        );
        $this->assertSame(['yell1', 'yell2', 'yell3', 'yell4', 'yell5'], $candIds);
        $this->assertSame(3, $state['pending_prompt']['max_pick'] ?? null);
        $this->assertNotContains('bladey', $candIds);

        // Pick the last three eligible cards (not only the first three).
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'card_ids' => ['yell3', 'yell4', 'yell5'],
        ]);
        $wrIds = array_map(
            fn($c) => $c['instance_id'] ?? '',
            $state['players']['p1']['waiting_room'] ?? []
        );
        $this->assertContains('yell3', $wrIds);
        $this->assertContains('yell4', $wrIds);
        $this->assertContains('yell5', $wrIds);
        $this->assertNotContains('yell1', $wrIds);
        $yellIds = array_map(
            fn($c) => $c['instance_id'] ?? '',
            \currentPlayerYellCards($state, 'p1')
        );
        $this->assertContains('yell1', $yellIds);
        $this->assertContains('yell2', $yellIds);
        $this->assertNotContains('yell3', $yellIds);
        $this->assertNotContains('yell4', $yellIds);
        $this->assertNotContains('yell5', $yellIds);
    }

    public function testOncePerTurnBlocksPromptOnExtraYellDraws(): void
    {
        $state = $this->performanceState();
        // Pad deck so three extra Yells do not recycle WR.
        $deck = $state['players']['p1']['main_deck'];
        for ($i = 0; $i < 40; $i++) {
            $deck[] = [
                'instance_id' => "pad$i",
                'card_type' => 'メンバー',
                'group' => 'Hasunosora',
                'name_en' => "Pad$i",
            ];
        }
        $state['players']['p1']['main_deck'] = $deck;
        $state['players']['p1']['stage']['center']['blade'] = 1;

        $state = \resolvePerformancePhase($state, 'p1', false);
        $this->assertSame('auto_yell_mill_extra_yell', $state['pending_prompt']['type'] ?? null);
        $this->assertTrue(
            \isAbilityUsed($state['players']['p1']['live_zone'][0], 0),
            'once_per_turn must be marked on the returned live card when the prompt opens'
        );

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'card_ids' => ['yell1', 'yell2'],
        ]);

        // Extra Yells call resolveAutoYellAbilities again — must NOT re-open Kurage.
        $this->assertNull($state['pending_prompt'] ?? null, 'Kurage mill prompt must not reappear during extra Yell');
        $millLogs = array_filter(
            $state['log'] ?? [],
            fn($e) => str_contains($e['msg'] ?? '', 'optional Yell mill (choose)')
        );
        $this->assertCount(1, $millLogs, 'Kurage mill prompt log must appear exactly once');
    }

    public function testClientPreservesOpenKuragePickerAndCancelDoesNotDecline(): void
    {
        $renderer = (string) file_get_contents(dirname(__DIR__, 2) . '/client/js/prompt-renderer.js');

        $this->assertStringContainsString(
            "incomingPromptKey === openHandPromptKey",
            $renderer,
            'Polling the same prompt must preserve its open card picker'
        );
        $this->assertMatchesRegularExpression(
            "/type==='auto_yell_mill_extra_yell'[\\s\\S]*promptKey: promptIdentityKey\\(s\\)/",
            $renderer
        );
        $this->assertMatchesRegularExpression(
            "/Cancel only returns to Kurage's explicit Yes\\/No branch[\\s\\S]*renderPrompt\\(G\\.gameState, myId\\)/",
            $renderer,
            'Cancel must return to the branch dialog instead of sending choice=no'
        );
    }
}
