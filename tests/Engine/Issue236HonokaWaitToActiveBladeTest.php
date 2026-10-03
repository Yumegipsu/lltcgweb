<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-010 Honoka — Live Start +1 Blade per Wait→Active via Printemps (#236).
 * Default Live Start order is Stage then Lives, so Honoka used to resolve before
 * WAO-WAO and grant 0. Blade grant is deferred until Live Start finishes.
 */
final class Issue236HonokaWaitToActiveBladeTest extends TestCase
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
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function baseState(array $honoka, array $left, array $right, array $wao): array
    {
        waitMember($left, ['turn' => 1, 'active_player' => 'p1']);
        waitMember($right, ['turn' => 1, 'active_player' => 'p1']);
        return [
            'room_id' => 'ISSUE236',
            'status' => 'playing',
            'seq' => 10,
            'turn' => 2,
            'phase' => 'live_start_effects',
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
                        'left' => $left,
                        'center' => $honoka,
                        'right' => $right,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => array_fill(0, 12, ['card_type' => 'エネルギー', 'active' => true]),
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [$wao],
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

    public function testHonokaBeforeWaoStillGetsBladeAfterFlush(): void
    {
        $honoka = $this->cardByNo('PL!-pb2-010-R', 'honoka010');
        $left = $this->cardByNo('PL!-bp3-001-R', 'h');
        $right = $this->cardByNo('PL!-bp3-008-P', 'y');
        $wao = $this->cardByNo('PL!-pb1-028-L', 'wao');
        $state = $this->baseState($honoka, $left, $right, $wao);

        // Honoka first (the bad default order), then WAO Activates.
        $state = resolveAbilityEffect($state, 'p1', $honoka, $honoka['abilities'][0], [
            'phase' => 'live_start',
        ]);
        $this->assertSame(0, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
        $this->assertNotEmpty($state['players']['p1']['_pb2_defer_blade_from_wait'] ?? null);

        $state = resolveAbilityEffect($state, 'p1', $wao, $wao['abilities'][0], [
            'phase' => 'live_start',
        ]);
        $this->assertSame(2, intval($state['players']['p1']['_pb2_activated_from_wait_Printemps'] ?? 0));

        $state = plMusePb2FlushDeferredActivatedFromWaitBlade($state);
        $this->assertSame(2, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
        $this->assertArrayNotHasKey('_pb2_defer_blade_from_wait', $state['players']['p1']);
    }

    public function testFinishLiveStartFlushesHonokaBlade(): void
    {
        $honoka = $this->cardByNo('PL!-pb2-010-R', 'honoka010');
        $left = $this->cardByNo('PL!-bp3-001-R', 'h');
        $right = $this->cardByNo('PL!-bp3-008-P', 'y');
        $wao = $this->cardByNo('PL!-pb1-028-L', 'wao');
        $state = $this->baseState($honoka, $left, $right, $wao);
        $state['_live_start_perf_pid'] = 'p1';
        $state['live_start_optional_queue'] = [];

        $state = resolveAbilityEffect($state, 'p1', $honoka, $honoka['abilities'][0], [
            'phase' => 'live_start',
        ]);
        $state = resolveAbilityEffect($state, 'p1', $wao, $wao['abilities'][0], [
            'phase' => 'live_start',
        ]);
        $state = finishLiveStartEffects($state, false);
        $this->assertSame(2, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
    }
}
