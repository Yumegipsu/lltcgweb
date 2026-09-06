<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * #157 — PL!-sd1-009 Nico Live Start WR μ's ≥25 grants Live Score +1 (not hearts).
 */
final class Issue157NicoSdLiveScoreTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                $card['entered_turn'] = 1;
                return $card;
            }
        }
        $this->fail('Missing card ' . $cardNo);
    }

    private function museStub(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'MUSE-' . $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Muse Filler',
            'name' => 'Filler',
            'group' => "μ's",
            'cost' => 1,
        ];
    }

    private function baseState(int $wrMuseCount): array
    {
        $nico = $this->cardByNo('PL!-sd1-009-SD', 'nico');
        $wr = [];
        for ($i = 0; $i < $wrMuseCount; $i++) {
            $wr[] = $this->museStub('wr' . $i);
        }
        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 5,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => $wr,
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'stage' => ['left' => null, 'center' => $nico, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testCardsJsonAbilityIsLiveScoreNotHearts(): void
    {
        $nico = $this->cardByNo('PL!-sd1-009-SD', 'x');
        $ab = $nico['abilities'][0] ?? [];
        $this->assertSame('live_start', $ab['trigger'] ?? null);
        $this->assertSame('live_start_wr_group_live_score', $ab['type'] ?? null);
        $this->assertSame(25, intval($ab['min_count'] ?? 0));
        $this->assertSame(1, intval($ab['amount'] ?? 0));
    }

    public function testLiveStartWith25MuseInWrAddsScoreBonus(): void
    {
        $state = $this->baseState(25);
        $state = \resolveAbilityEffect($state, 'p1', $state['players']['p1']['stage']['center'], [
            'trigger' => 'live_start',
            'type' => 'live_start_wr_group_live_score',
            'group' => "μ's",
            'min_count' => 25,
            'amount' => 1,
        ], ['slot' => 'center', 'phase' => 'live_start']);

        $this->assertSame(1, intval($state['live_modifiers']['p1']['score_bonus'] ?? 0));
        $this->assertSame(0, intval($state['live_modifiers']['p1']['live_score_bonus'] ?? 0));
        $this->assertSame(1, \getLiveScoreBonus($state, 'p1'));
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testLiveStartBelowThresholdDoesNothing(): void
    {
        $state = $this->baseState(24);
        $state = \resolveAbilityEffect($state, 'p1', $state['players']['p1']['stage']['center'], [
            'trigger' => 'live_start',
            'type' => 'live_start_wr_group_live_score',
            'group' => "μ's",
            'min_count' => 25,
            'amount' => 1,
        ], ['slot' => 'center', 'phase' => 'live_start']);

        $this->assertSame(0, intval($state['live_modifiers']['p1']['score_bonus'] ?? 0));
        $this->assertSame(0, \getLiveScoreBonus($state, 'p1'));
    }

    public function testThreeNicosStackScoreBonus(): void
    {
        $state = $this->baseState(25);
        $state['players']['p1']['stage']['left'] = $this->cardByNo('PL!-sd1-009-SD', 'nico2');
        $state['players']['p1']['stage']['right'] = $this->cardByNo('PL!-sd1-009-SD', 'nico3');
        foreach (['left', 'center', 'right'] as $slot) {
            $m = $state['players']['p1']['stage'][$slot];
            $state = \resolveAbilityEffect($state, 'p1', $m, $m['abilities'][0], [
                'slot' => $slot,
                'phase' => 'live_start',
            ]);
        }
        $this->assertSame(3, intval($state['live_modifiers']['p1']['score_bonus'] ?? 0));
        $this->assertSame(3, \getLiveScoreBonus($state, 'p1'));
    }
}
