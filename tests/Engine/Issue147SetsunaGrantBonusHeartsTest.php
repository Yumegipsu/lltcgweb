<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #147 — cheer-deck 4-cost Setsuna On Enter must grant 1 blue heart
 * until this Live ends (grant_bonus_hearts was a silent no-op).
 */
final class Issue147SetsunaGrantBonusHeartsTest extends TestCase
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
        $this->fail('Missing test card ' . $cardNo);
    }

    private function baseState(array $setsuna): array
    {
        return [
            'room_id' => 'ISSUE147',
            'status' => 'playing',
            'seq' => 10,
            'turn' => 2,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => $setsuna, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => array_fill(0, 12, ['card_type' => 'エネルギー', 'active' => true]),
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
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

    public function testSetsunaOnEnterGrantsBlueHeartOnSelf(): void
    {
        $setsuna = $this->cardByNo('PL!N-sd2-019-SD2', 'setsuna');
        $ab = ($setsuna['abilities'] ?? [])[0] ?? [];
        $this->assertSame('on_enter', $ab['trigger'] ?? null);
        $this->assertSame('grant_bonus_hearts', $ab['type'] ?? null);
        $this->assertSame('blue', $ab['hearts'][0]['color'] ?? null);

        $state = $this->baseState($setsuna);
        $state = \resolveOnEnterAbilities($state, 'p1', $setsuna, 'center');

        $bonus = $state['players']['p1']['stage']['center']['bonus_hearts'] ?? [];
        $this->assertSame(['blue'], array_values($bonus));
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testRinaLiveStartGrantBonusHeartsAlsoWorks(): void
    {
        $rina = $this->cardByNo('PL!N-pb1-009-R', 'rina');
        $state = $this->baseState($rina);
        $state['phase'] = 'live_start_effects';
        $state['players']['p1']['live_zone'] = [[
            'instance_id' => 'live1',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Nijigasaki',
            'score' => 1,
            'required_hearts' => [['color' => 'yellow', 'count' => 1]],
        ]];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }

        $bonus = $state['players']['p1']['stage']['center']['bonus_hearts'] ?? [];
        sort($bonus);
        $this->assertSame(['blue', 'purple', 'yellow'], $bonus);
    }
}
