<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!HS-pb1-027-L Yume Wazurai — Live Success optional mill must resume Performance
 * (finishPromptEffects), not softlock in live_success_effects.
 */
final class YumewazuraiLiveSuccessMillTest extends TestCase
{
    private function baseState(): array
    {
        $cerise = [
            'instance_id' => 'cerise_1',
            'card_no' => 'PL!HS-pb1-001-R',
            'name_en' => 'Cerise Member',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Hasunosora',
            'subunit' => 'スリーズブーケ',
            'cost' => 3,
            'active' => true,
        ];
        $deck = [];
        for ($i = 1; $i <= 6; $i++) {
            $deck[] = [
                'instance_id' => "deck_$i",
                'card_no' => "DECK-$i",
                'card_type' => 'メンバー',
                'name_en' => "Deck $i",
                'group' => 'Hasunosora',
            ];
        }
        return [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'seq' => 10,
            '_performance_continue' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'main_deck' => $deck,
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                    'stage' => [
                        'center' => $cerise,
                        'left' => null,
                        'right' => null,
                    ],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'main_deck' => [
                        ['instance_id' => 'p2d1', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                        ['instance_id' => 'p2d2', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                        ['instance_id' => 'p2d3', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                    ],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                    'stage' => ['center' => null, 'left' => null, 'right' => null],
                ],
            ],
        ];
    }

    private function yumewazurai(): array
    {
        return [
            'instance_id' => 'yume_live',
            'card_no' => 'PL!HS-pb1-027-L',
            'name_en' => 'Yume Wazurai',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Hasunosora',
            'score' => 1,
            'abilities' => [[
                'trigger' => 'live_success',
                'type' => 'live_success_optional_mill_if_subunit',
                'subunit' => 'スリーズブーケ',
                'count' => 4,
            ]],
        ];
    }

    public function testLiveSuccessOpensOptionalMillWhenCeriseOnStage(): void
    {
        $state = $this->baseState();
        $state = \resolveLiveSuccessAbilities($state, 'p1', [$this->yumewazurai()], 0, [], []);

        $this->assertSame('live_success_optional_mill_if_subunit', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p1', $state['pending_prompt']['responder'] ?? null);
        $this->assertSame(4, $state['pending_prompt']['count'] ?? null);
    }

    public function testYesMillResumesPastLiveSuccessEffects(): void
    {
        $state = $this->baseState();
        $state = \resolveLiveSuccessAbilities($state, 'p1', [$this->yumewazurai()], 0, [], []);
        $this->assertSame('live_success_optional_mill_if_subunit', $state['pending_prompt']['type'] ?? null);

        $after = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);

        $this->assertNull($after['pending_prompt'] ?? null);
        $this->assertNotSame('live_success_effects', $after['phase'] ?? null);
        // Mill 4 into WR; turn-prep draw may then take from the remaining deck.
        $this->assertGreaterThanOrEqual(4, count($after['players']['p1']['waiting_room']));
        $this->assertLessThan(6, count($after['players']['p1']['main_deck']));
        $this->assertArrayNotHasKey('_performance_continue', $after);
    }

    public function testNoSkipAlsoResumesPastLiveSuccessEffects(): void
    {
        $state = $this->baseState();
        $state = \resolveLiveSuccessAbilities($state, 'p1', [$this->yumewazurai()], 0, [], []);

        $after = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);

        $this->assertNull($after['pending_prompt'] ?? null);
        $this->assertNotSame('live_success_effects', $after['phase'] ?? null);
        // No mill; turn-prep may still draw 1 from deck after Live Success resumes.
        $this->assertLessThanOrEqual(1, count($after['players']['p1']['waiting_room']));
        $this->assertArrayNotHasKey('_performance_continue', $after);
    }
}
