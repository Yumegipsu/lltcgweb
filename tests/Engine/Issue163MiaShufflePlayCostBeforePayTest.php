<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #163 — PL!N-bp7-011 Mia Always −2 (shuffle WR Members) must apply before pay,
 * including with Baton Touch (13 − 2 − 4 = 7 Energy).
 */
final class Issue163MiaShufflePlayCostBeforePayTest extends TestCase
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

    private function energy(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'エネルギー',
            'card_type_en' => 'Energy',
            'active' => true,
            'name_en' => 'Energy Card',
        ];
    }

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 3,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [['instance_id' => 'pad']],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testBatonTouchWithSevenEnergyAndShuffleOpt(): void
    {
        $mia = $this->cardByNo('PL!N-bp7-011-SEC', 'mia163');
        $low = $this->cardByNo('PL!N-bp7-022-N', 'low4');
        $low['cost'] = 4;
        $low['entered_turn'] = 1;
        $wrM = $this->cardByNo('PL!N-bp7-007-P', 'wr_member');

        $state = $this->baseState();
        $state['players']['p1']['hand'] = [$mia];
        $state['players']['p1']['stage']['center'] = $low;
        $state['players']['p1']['waiting_room'] = [$wrM];
        for ($i = 0; $i < 7; $i++) {
            $state['players']['p1']['energy_zone'][] = $this->energy('e' . $i);
        }

        // Without shuffle flag, 13 − 4 = 9 > 7 should fail.
        try {
            \actionPlayMember($state, 'p1', [
                'card_id' => 'mia163',
                'slot' => 'center',
                'baton_id' => 'low4',
            ]);
            $this->fail('Expected play without shuffle to fail with only 7 Energy');
        } catch (\Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('energy', $e->getMessage());
        }

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'mia163',
            'slot' => 'center',
            'baton_id' => 'low4',
            'bp7_shuffle_wr_members' => true,
        ]);

        $this->assertSame('mia163', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $this->assertNotSame('low4', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        // Baton sends low4 to WR, then shuffle moves every WR Member (incl. low4) to deck bottom.
        $deckIds = array_column($state['players']['p1']['main_deck'] ?? [], 'instance_id');
        $this->assertContains('low4', $deckIds);
        $this->assertContains('wr_member', $deckIds);
        $this->assertNotContains('wr_member', array_column($state['players']['p1']['waiting_room'] ?? [], 'instance_id'));
        $this->assertCount(0, array_filter(
            $state['players']['p1']['energy_zone'],
            static fn($e) => !empty($e['active'])
        ), '13 − 2 − 4 = 7 Energy must be paid');
    }
}
