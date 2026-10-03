<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-bp5-007 Nozomi — On Enter via Baton from any lower-cost Member (#239).
 * if_baton_lower_cost used memberBatonFromLowerCostSubunit with empty baton_subunit,
 * and subunitNamesMatch('', …) always failed, so trim/draw never ran.
 */
final class Issue239NozomiBatonTrimTest extends TestCase
{
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

    private function handCards(string $pid, int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = [
                'instance_id' => $pid . 'h' . $i,
                'card_type' => 'メンバー',
                'name_en' => 'Filler',
                'group' => "μ's",
            ];
        }
        return $out;
    }

    private function deckCards(string $pid, int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = [
                'instance_id' => $pid . 'd' . $i,
                'card_type' => 'メンバー',
                'name_en' => 'Deck',
                'group' => "μ's",
            ];
        }
        return $out;
    }

    private function baseState(array $nozomi): array
    {
        return [
            'room_id' => 'ISSUE239',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 3,
            'phase' => 'main_first',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => $this->handCards('p1', 5),
                    'main_deck' => $this->deckCards('p1', 10),
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $nozomi,
                        'right' => null,
                    ],
                    'live_zone' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => $this->handCards('p2', 5),
                    'main_deck' => $this->deckCards('p2', 10),
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testEmptySubunitLowerCostPassesHelper(): void
    {
        $m = [
            'cost' => 13,
            'entered_via_baton' => true,
            'baton_from_cost' => 4,
            'baton_from_subunit' => 'Printemps',
        ];
        $this->assertTrue(memberBatonFromLowerCostSubunit($m, ''));
        $this->assertFalse(memberBatonFromLowerCostSubunit($m, 'lily white'));
        $this->assertTrue(memberBatonFromLowerCostSubunit($m, 'Printemps'));
    }

    public function testEqualOrHigherCostDoesNotPass(): void
    {
        $m = [
            'cost' => 13,
            'entered_via_baton' => true,
            'baton_from_cost' => 13,
            'baton_from_subunit' => 'Printemps',
        ];
        $this->assertFalse(memberBatonFromLowerCostSubunit($m, ''));
        $m['baton_from_cost'] = 20;
        $this->assertFalse(memberBatonFromLowerCostSubunit($m, ''));
    }

    public function testBatonFromLowerCostOpensBothTrimPrompt(): void
    {
        $nozomi = $this->cardByNo('PL!-bp5-007-R', 'nozomi007');
        $nozomi['entered_via_baton'] = true;
        $nozomi['baton_from_cost'] = 5;
        $nozomi['baton_from_subunit'] = 'Printemps';
        $state = $this->baseState($nozomi);

        $state = resolveAbilityEffect($state, 'p1', $nozomi, $nozomi['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
            'ability_index' => 0,
        ]);

        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('effect_discard_hand', $pr['type'] ?? null);
        $this->assertSame('p1', $pr['responder'] ?? null);
        $this->assertSame(2, $pr['count'] ?? null);
    }

    public function testNoBatonDoesNothing(): void
    {
        $nozomi = $this->cardByNo('PL!-bp5-007-R', 'nozomi007');
        $state = $this->baseState($nozomi);

        $state = resolveAbilityEffect($state, 'p1', $nozomi, $nozomi['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
            'ability_index' => 0,
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertCount(5, $state['players']['p1']['hand']);
        $this->assertCount(5, $state['players']['p2']['hand']);
    }

    public function testFullTrimThenDrawBothPlayers(): void
    {
        $nozomi = $this->cardByNo('PL!-bp5-007-R', 'nozomi007');
        $nozomi['entered_via_baton'] = true;
        $nozomi['baton_from_cost'] = 2;
        $nozomi['baton_from_subunit'] = 'BiBi';
        $state = $this->baseState($nozomi);

        $state = resolveAbilityEffect($state, 'p1', $nozomi, $nozomi['abilities'][0], [
            'phase' => 'on_enter',
            'slot' => 'center',
            'ability_index' => 0,
        ]);
        $state = actionResolvePrompt($state, 'p1', ['discard_ids' => ['p1h1', 'p1h2']]);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);
        $state = actionResolvePrompt($state, 'p2', ['discard_ids' => ['p2h1', 'p2h2']]);

        $this->assertNull($state['pending_prompt'] ?? null);
        // 5 - 2 + 3 = 6 each
        $this->assertCount(6, $state['players']['p1']['hand']);
        $this->assertCount(6, $state['players']['p2']['hand']);
        $this->assertCount(2, $state['players']['p1']['waiting_room']);
        $this->assertCount(2, $state['players']['p2']['waiting_room']);
    }
}
