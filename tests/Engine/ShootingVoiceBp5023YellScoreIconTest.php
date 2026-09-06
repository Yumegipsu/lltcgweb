<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!SP-bp5-023-L Shooting Voice!! — Live Success +2 requires:
 * - either Success Live storage has ≥2 cards, and
 * - Yell revealed a Live with a <score+1> icon (not printed score ≥ 1).
 */
final class ShootingVoiceBp5023YellScoreIconTest extends TestCase
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

    private function baseState(array $yellCards): array
    {
        $voice = $this->cardByNo('PL!SP-bp5-023-L', 'voice');
        return [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_modifiers' => [
                'p1' => ['score_bonus' => 0, 'blade_bonus' => 0, 'bonus_hearts' => []],
                'p2' => ['score_bonus' => 0, 'blade_bonus' => 0, 'bonus_hearts' => []],
            ],
            '_last_yell_cards' => $yellCards,
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    // Prior Success Live storage (≥2) — condition half of the skill.
                    'success_lives' => [
                        ['instance_id' => 'sl1', 'card_type' => 'ライブ', 'score' => 5],
                        ['instance_id' => 'sl2', 'card_type' => 'ライブ', 'score' => 4],
                    ],
                    'live_zone' => [$voice],
                    'yell_cards' => $yellCards,
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
            ],
        ];
    }

    public function testPrintedScoreLiveWithoutIconDoesNotGrantBonus(): void
    {
        // Bug: old check used score >= 1, so any Live Yell falsely armed +2.
        $yell = [
            [
                'instance_id' => 'yell_plain',
                'card_type' => 'ライブ',
                'score' => 5,
                'yell_score_icon' => false,
                'blade_hearts' => [],
            ],
        ];
        $state = $this->baseState($yell);
        $voice = $state['players']['p1']['live_zone'][0];
        $state = \resolveLiveSuccessAbilities($state, 'p1', [$voice], 0, [], $yell);
        $this->assertSame(0, intval($state['live_modifiers']['p1']['score_bonus'] ?? 0));
    }

    public function testSkillBoostedScoreWithoutIconDoesNotGrantBonus(): void
    {
        // Shiki-style score grants bump Live score; still not a Yell score icon.
        $yell = [
            [
                'instance_id' => 'yell_boosted',
                'card_type' => 'ライブ',
                'score' => 6,
                '_printed_score' => 5,
                'yell_score_icon' => false,
                'blade_hearts' => [],
            ],
        ];
        $state = $this->baseState($yell);
        $voice = $state['players']['p1']['live_zone'][0];
        $state = \resolveLiveSuccessAbilities($state, 'p1', [$voice], 0, [], $yell);
        $this->assertSame(0, intval($state['live_modifiers']['p1']['score_bonus'] ?? 0));
    }

    public function testYellScoreIconLiveGrantsPlusTwo(): void
    {
        $yell = [
            [
                'instance_id' => 'yell_icon',
                'card_type' => 'ライブ',
                'score' => 1,
                'yell_score_icon' => true,
                'special_heart' => 'icon_score.png',
                'blade_hearts' => [],
            ],
        ];
        $state = $this->baseState($yell);
        $voice = $state['players']['p1']['live_zone'][0];
        $state = \resolveLiveSuccessAbilities($state, 'p1', [$voice], 0, [], $yell);
        $this->assertSame(2, intval($state['live_modifiers']['p1']['score_bonus'] ?? 0));
    }

    public function testInsufficientSuccessStorageDoesNotGrantBonus(): void
    {
        $yell = [
            [
                'instance_id' => 'yell_icon',
                'card_type' => 'ライブ',
                'score' => 1,
                'yell_score_icon' => true,
                'special_heart' => 'icon_score.png',
            ],
        ];
        $state = $this->baseState($yell);
        $state['players']['p1']['success_lives'] = [
            ['instance_id' => 'sl1', 'card_type' => 'ライブ', 'score' => 5],
        ];
        $voice = $state['players']['p1']['live_zone'][0];
        $state = \resolveLiveSuccessAbilities($state, 'p1', [$voice], 0, [], $yell);
        $this->assertSame(0, intval($state['live_modifiers']['p1']['score_bonus'] ?? 0));
    }
}
