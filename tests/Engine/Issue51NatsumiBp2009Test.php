<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression for GitHub issue #51 — Natsumi PL!SP-bp2-009 triggers and snapshot blade. */
final class Issue51NatsumiBp2009Test extends TestCase
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

    private function baseState(string $phase = 'live_start_effects'): array
    {
        return [
            'status' => 'playing',
            'phase' => $phase,
            'seq' => 1,
            'turn' => 1,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => array_fill(0, 10, ['instance_id' => 'deck', 'card_type' => 'エネルギー']),
                    'success_lives' => [],
                    'live_zone' => [],
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

    public function testCardDataUsesLiveStartAndLiveSuccessTriggers(): void
    {
        $card = $this->cardByNo('PL!SP-bp2-009-P', 'issue51_natsumi');
        $this->assertSame('live_start', $card['abilities'][0]['trigger'] ?? null);
        $this->assertSame('blade_per_hand_cards', $card['abilities'][0]['type'] ?? null);
        $this->assertSame('live_success', $card['abilities'][1]['trigger'] ?? null);
        $this->assertSame('draw_and_discard', $card['abilities'][1]['type'] ?? null);
    }

    public function testLiveStartSnapshotsBladeFromHandCount(): void
    {
        $natsumi = $this->cardByNo('PL!SP-bp2-009-P', 'issue51_natsumi');
        $handCards = [];
        for ($i = 0; $i < 4; $i++) {
            $handCards[] = [
                'instance_id' => 'issue51_hand' . $i,
                'card_type' => 'エネルギー',
            ];
        }

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $natsumi;
        $state['players']['p1']['hand'] = $handCards;

        $state = \resolveLiveStartAbilities($state, 'p1');

        $this->assertSame(2, \getStageBladeBonus($state, 'p1'));

        $state['players']['p1']['hand'] = [];
        $this->assertSame(2, \getStageBladeBonus($state, 'p1'), 'Blade bonus should not drop when hand empties');
    }

    public function testLiveSuccessDrawAndDiscardOpensPrompt(): void
    {
        $natsumi = $this->cardByNo('PL!SP-bp2-009-P', 'issue51_natsumi_ls');
        $handCards = [];
        for ($i = 0; $i < 3; $i++) {
            $handCards[] = [
                'instance_id' => 'issue51_ls_hand' . $i,
                'card_type' => 'エネルギー',
            ];
        }

        $state = $this->baseState('live_success_effects');
        $state['players']['p1']['stage']['center'] = $natsumi;
        $state['players']['p1']['hand'] = $handCards;

        $state = \resolveLiveSuccessAbilities($state, 'p1', [], 0, [], []);

        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(5, count($state['players']['p1']['hand']), 'Draw 2 before mandatory discard');
    }
}
