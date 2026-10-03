<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Love Marginal + Otome Heart Live Start heart reductions (#233).
 */
final class Issue233LoveMarginalOtomeHeartTest extends TestCase
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

    private function fillerLive(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => $id,
            'score' => 1,
            'revealed' => true,
            'abilities' => [],
            'required_hearts' => [['color' => 'any', 'count' => 1]],
        ];
    }

    public function testLoveMarginalReducesAfterWaoActivatesFromWait(): void
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
            'room_id' => 'ISSUE233',
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
                    'live_zone' => [$wao, $marginal],
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
                    'live_zone' => [$this->fillerLive('live_p2')],
                    'success_lives' => [],
                ],
            ],
        ];

        // WAO-WAO first: Activate 3 Printemps from Wait.
        $waoAb = $wao['abilities'][0];
        $state = resolveAbilityEffect($state, 'p1', $wao, $waoAb, ['phase' => 'live_start']);
        $this->assertSame(3, intval($state['players']['p1']['_pb2_activated_from_wait_Printemps'] ?? 0));
        $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['left']));

        // Love Marginal: tiers 1+2+3 → −3−2−1 = −6 any.
        $margAb = $marginal['abilities'][0];
        $state = resolveAbilityEffect($state, 'p1', $marginal, $margAb, ['phase' => 'live_start']);
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

    public function testOtomeHeartCountsAllQualifyingMembersWithoutHeartsOnCopy(): void
    {
        $otome = $this->cardByNo('PL!-bp5-023-L', 'otome');
        // Strip hearts so Live Start must rehydrate from catalog (#233).
        $m1 = $this->cardByNo('PL!-bp3-001-R', 'h1'); // Honoka — has yellow
        $m2 = $this->cardByNo('PL!-bp3-003-R', 'k1'); // Kotori — has yellow
        $m3 = $this->cardByNo('PL!-bp3-008-P', 'y1'); // Hanayo — has yellow
        unset($m1['hearts'], $m2['hearts'], $m3['hearts']);

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => $m1, 'center' => $m2, 'right' => $m3],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [$otome],
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
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $ab = $otome['abilities'][0];
        $state = resolveAbilityEffect($state, 'p1', $otome, $ab, ['phase' => 'live_start']);
        $lc = $state['players']['p1']['live_zone'][0];
        $this->assertSame(3, intval($lc['hearts_color_reduction']['any'] ?? 0));
    }

    public function testTurnPrepClearsActivatedFromWaitCounter(): void
    {
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [['instance_id' => 'd1', 'card_type' => 'メンバー']],
                    'energy_deck' => [['instance_id' => 'e1', 'card_type' => 'エネルギー']],
                    'live_zone' => [],
                    'success_lives' => [],
                    '_pb2_activated_from_wait_Printemps' => 3,
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
        $state = doActivePhase($state, 'p1');
        $this->assertArrayNotHasKey('_pb2_activated_from_wait_Printemps', $state['players']['p1']);
    }
}
