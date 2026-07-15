<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Opponent stage-board Active effects must not name face-down Live storage.
 */
final class OmitUnrevealedLiveActiveEffectsTest extends TestCase
{
    private function stateWithFaceDownLive(): array
    {
        return [
            'phase' => 'live_set',
            'active_player' => 'p1',
            'live_modifiers' => [
                'p1' => ['score_bonus' => 0],
                'p2' => ['score_bonus' => 0],
            ],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [[
                        'instance_id' => 'secret_live',
                        'card_type' => 'ライブ',
                        'card_type_en' => 'Live',
                        'name' => 'Butterfly',
                        'name_en' => 'Butterfly',
                        'revealed' => false,
                        'abilities' => [[
                            'trigger' => 'continuous',
                            'type' => 'draw_per_yell_card',
                            'amount' => 1,
                        ]],
                    ]],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testOmitUnrevealedHidesOpponentLiveEffects(): void
    {
        $state = $this->stateWithFaceDownLive();
        $full = collectActiveContinuousEffects($state, 'p2');
        $hidden = collectActiveContinuousEffects($state, 'p2', true);

        $this->assertNotEmpty($full);
        $this->assertTrue(
            (bool) array_filter($full, static fn(array $e): bool => ($e['who'] ?? '') === 'Butterfly'),
            'Full collection should include the face-down Live effect'
        );
        $this->assertSame([], $hidden);
    }

    public function testRevealedLiveStillListedForOpponentUi(): void
    {
        $state = $this->stateWithFaceDownLive();
        $state['players']['p2']['live_zone'][0]['revealed'] = true;
        $effects = collectActiveContinuousEffects($state, 'p2', true);
        $this->assertNotEmpty($effects);
        $this->assertTrue(
            (bool) array_filter($effects, static fn(array $e): bool => ($e['who'] ?? '') === 'Butterfly')
        );
    }

    public function testGetLiveScoreBonusIgnoresOmitFlag(): void
    {
        $state = $this->stateWithFaceDownLive();
        // getLiveScoreBonus must never omit unrevealed Lives (rules path).
        $this->assertSame(
            getLiveScoreBonusBreakdown($state, 'p2', false)['total'],
            getLiveScoreBonus($state, 'p2')
        );
        $this->assertSame(
            0,
            getLiveScoreBonusBreakdown($state, 'p2', true)['total']
        );
    }
}
