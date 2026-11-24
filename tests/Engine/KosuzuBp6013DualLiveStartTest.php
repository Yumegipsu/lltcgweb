<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!HS-bp6-013-R Kosuzu: [On Enter]/[Live Start] must fire at both timings.
 * Playing her in Main then starting Live used to skip Live Start because
 * on_enter_or_live_start_fired was set on enter.
 */
final class KosuzuBp6013DualLiveStartTest extends TestCase
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
        $this->fail('Missing card ' . $cardNo);
    }

    /** Low printed Blade, non-DOLLCHESTRA — legal Kosuzu Wait target. */
    private function lowBladeTarget(string $id): array
    {
        $m = $this->cardByNo('PL!HS-sd1-009-SD', $id);
        $m['blade'] = 2;
        $m['subunit'] = 'Cerise Bouquet';
        return $m;
    }

    private function baseState(array $kosuzu, array $oppTarget): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => null,
                        'right' => $kosuzu,
                    ],
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
                    'stage' => [
                        'left' => $oppTarget,
                        'center' => null,
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];
    }

    public function testLiveStartStillFiresAfterOnEnterSameTurn(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $kosuzu = $this->cardByNo('PL!HS-bp6-013-R', 'kosuzu_bp6');
            $target = $this->lowBladeTarget('opp_low');
            $state = $this->baseState($kosuzu, $target);

            // Simulate On Enter resolving earlier this turn (marks dual-fired flag).
            $state = \resolveOnEnterAbilities($state, 'p1', $kosuzu, 'right');
            // #362: the player picks the target.
            $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
            $state = \actionResolvePrompt($state, 'p1', ['slot' => 'left']);
            $this->assertTrue(
                memberIsInWait($state['players']['p2']['stage']['left'] ?? []),
                'On Enter should Wait the eligible opponent Member'
            );

            // Stand the opponent again so Live Start has a legal target.
            $stood = $this->lowBladeTarget('opp_low_2');
            clearMemberWait($stood);
            $state['players']['p2']['stage']['left'] = $stood;

            $state['phase'] = 'live_start_effects';
            $state['live_attempt'] = ['p1'];
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
            $state = \actionResolvePrompt($state, 'p1', ['slot' => 'left']);

            $oppLeft = $state['players']['p2']['stage']['left'] ?? null;
            $this->assertNotNull($oppLeft);
            $this->assertTrue(
                memberIsInWait($oppLeft),
                'Live Start must Wait again after On Enter earlier this turn'
            );

            $log = implode("\n", array_map(
                static fn($row) => is_array($row) ? (string)($row['msg'] ?? $row['text'] ?? '') : (string)$row,
                $state['log'] ?? []
            ));
            $this->assertStringContainsString('Kosuzu', $log);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
