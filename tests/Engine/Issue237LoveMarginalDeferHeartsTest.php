<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-040 Love Marginal — Live Start wild-heart reduce from Wait→Active (#237).
 * Same order bug as Honoka (#236): if Marginal resolves before WAO-WAO, count was 0.
 */
final class Issue237LoveMarginalDeferHeartsTest extends TestCase
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

    public function testMarginalBeforeWaoStillReducesAfterFlush(): void
    {
        $marginal = $this->cardByNo('PL!-pb2-040-L', 'marginal');
        $wao = $this->cardByNo('PL!-pb1-028-L', 'wao');
        $honoka = $this->cardByNo('PL!-bp3-001-R', 'honoka');
        $kotori = $this->cardByNo('PL!-bp3-003-R', 'kotori');
        $hanayo = $this->cardByNo('PL!-bp3-008-P', 'hanayo');
        waitMember($honoka, ['turn' => 1, 'active_player' => 'p1']);
        waitMember($kotori, ['turn' => 1, 'active_player' => 'p1']);
        waitMember($hanayo, ['turn' => 1, 'active_player' => 'p1']);

        $state = [
            'room_id' => 'ISSUE237',
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
                        'left' => $honoka,
                        'center' => $kotori,
                        'right' => $hanayo,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    // Marginal listed first (bad order vs WAO).
                    'live_zone' => [$marginal, $wao],
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

        // Love Marginal first — must defer, not grant 0 immediately.
        $state = resolveAbilityEffect($state, 'p1', $marginal, $marginal['abilities'][0], [
            'phase' => 'live_start',
        ]);
        $before = null;
        foreach ($state['players']['p1']['live_zone'] as $c) {
            if (($c['instance_id'] ?? '') === 'marginal') {
                $before = $c;
                break;
            }
        }
        $this->assertSame(0, intval($before['hearts_color_reduction']['any'] ?? 0));
        $this->assertNotEmpty($state['players']['p1']['_pb2_defer_hearts_from_wait'] ?? null);

        $state = resolveAbilityEffect($state, 'p1', $wao, $wao['abilities'][0], [
            'phase' => 'live_start',
        ]);
        $this->assertSame(3, intval($state['players']['p1']['_pb2_activated_from_wait_Printemps'] ?? 0));

        $state = finishLiveStartEffects($state, false);
        $lc = null;
        foreach ($state['players']['p1']['live_zone'] as $c) {
            if (($c['instance_id'] ?? '') === 'marginal') {
                $lc = $c;
                break;
            }
        }
        $this->assertIsArray($lc);
        $this->assertSame(6, intval($lc['hearts_color_reduction']['any'] ?? 0));
    }
}
