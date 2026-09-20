<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #192 — Anju (PL!-bp5-222) On Enter: Wait + discard 1, look top 3,
 * add 1 to hand (not surveil-arrange).
 */
final class Issue192AnjuWaitLookPickTest extends TestCase
{
    private function anju(): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === 'PL!-bp5-222-R') {
                $card['instance_id'] = 'anju1';
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing PL!-bp5-222-R');
    }

    public function testAbilitiesUseLookRevealNotSurveil(): void
    {
        $anju = $this->anju();
        $ab = $anju['abilities'][0] ?? [];
        $this->assertSame('optional_wait_self_look_reveal', $ab['type'] ?? null);
        $this->assertSame(1, intval($ab['discard'] ?? 0));
        $this->assertSame(3, intval($ab['look'] ?? 0));
        $this->assertSame(1, intval($ab['pick'] ?? 0));
    }

    public function testOnEnterYesDiscardOpensPickToHand(): void
    {
        $anju = $this->anju();
        $handCard = [
            'instance_id' => 'hand1',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Hand Card',
            'group' => "μ's",
        ];
        $deck = [];
        for ($i = 1; $i <= 5; $i++) {
            $deck[] = [
                'instance_id' => "d$i",
                'card_type' => 'メンバー',
                'card_type_en' => 'Member',
                'name_en' => "Deck $i",
                'group' => "μ's",
            ];
        }
        $state = [
            'room_id' => 'ISSUE192',
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
                    'hand' => [$handCard],
                    'stage' => ['left' => null, 'center' => $anju, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => array_fill(0, 12, ['card_type' => 'エネルギー', 'active' => true]),
                    'main_deck' => $deck,
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

        $state = \resolveOnEnterAbilities($state, 'p1', $anju, 'center');
        $this->assertSame('optional_wait_self_look_reveal', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['hand1'],
        ]);
        $this->assertSame('pick_looked_deck_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertNotSame('surveil_arrange', $state['pending_prompt']['type'] ?? null);
        $this->assertCount(3, $state['surveil_stash'] ?? []);
        $this->assertTrue(
            !empty($state['players']['p1']['stage']['center']['in_wait'])
            || empty($state['players']['p1']['stage']['center']['active']),
            'Anju should be in Wait'
        );
        $this->assertCount(1, $state['players']['p1']['waiting_room'] ?? [], 'discarded hand card in WR');
    }
}
