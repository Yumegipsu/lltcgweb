<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Natural match-win lock: 2 Success Lives + sole Live-round win (or 3 Success)
 * must survive opponent resign during Live Success skill prompts.
 */
final class NaturalWinLockTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/api.php';
    }

    private function baseState(array $p1Success, array $roundSuccess, array $attempting = ['p1', 'p2']): array
    {
        $mkLive = static function (string $id): array {
            return [
                'instance_id' => $id,
                'card_no' => 'PL!-bp1-001-L',
                'card_type' => 'ライブ',
                'card_type_en' => 'Live',
                'name_en' => 'Live',
                'score' => 1,
            ];
        };
        $success = [];
        foreach ($p1Success as $id) {
            $success[] = $mkLive($id);
        }
        return [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'live_attempt' => $attempting,
            'live_round_success' => $roundSuccess,
            'players' => [
                'p1' => [
                    'name' => 'Winner',
                    'success_lives' => $success,
                    'live_zone' => [$mkLive('p1-live')],
                    'waiting_room' => [],
                ],
                'p2' => [
                    'name' => 'Leaver',
                    'success_lives' => [],
                    'live_zone' => [],
                    'waiting_room' => [],
                ],
            ],
        ];
    }

    public function testLocksWhenTwoSuccessAndOpponentFailedLive(): void
    {
        $state = $this->baseState(['s1', 's2'], ['p1' => true, 'p2' => false]);
        $this->assertTrue(seatHasMatchWinningLiveProgress($state, 'p1'));
        $this->assertTrue(maybeLockNaturalMatchWin($state, 'p1'));
        $this->assertSame('p1', $state['natural_win_locked']);
    }

    public function testDoesNotLockWhileOpponentPerformancePending(): void
    {
        $state = $this->baseState(['s1', 's2'], ['p1' => true]);
        $this->assertFalse(seatHasMatchWinningLiveProgress($state, 'p1'));
        $this->assertFalse(maybeLockNaturalMatchWin($state, 'p1'));
        $this->assertArrayNotHasKey('natural_win_locked', $state);
    }

    public function testResignAfterLockKeepsLockedWinner(): void
    {
        $state = $this->baseState(['s1', 's2'], ['p1' => true, 'p2' => false]);
        maybeLockNaturalMatchWin($state, 'p1');
        $state['status'] = 'finished';
        $state['end_reason'] = 'resign';
        $state['resigned_by'] = 'p2';
        $state['winner'] = 'p1';
        applyNaturalWinLockOnEarlyExit($state, 'p2');
        $this->assertSame('p1', $state['winner']);

        require_once dirname(__DIR__, 2) . '/coins.php';
        $this->assertTrue(tcgCoinsNaturalFinish($state));
    }

    public function testLocksOnThirdSuccessPlacement(): void
    {
        $state = $this->baseState(['s1', 's2', 's3'], []);
        $this->assertTrue(seatHasMatchWinningLiveProgress($state, 'p1'));
        $this->assertTrue(maybeLockNaturalMatchWin($state, 'p1'));
        $this->assertSame('p1', $state['natural_win_locked']);
    }
}
