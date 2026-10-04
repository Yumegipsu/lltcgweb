<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * #243 correction — Shiranai Live Start +1 must not stick after Success→hand swap.
 *
 * Kyra 0511FD: Rin swapped a score-bumped Shiranai out of Success into hand, then
 * set two Shiranai (sticky score 2 + printed 1 = 3) and Live Start stacked again.
 */
final class Issue243ShiranaiStickySuccessSwapTest extends TestCase
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

    public function testRinHandSuccessSwapStripsLiveStartBonus(): void
    {
        $rin = $this->cardByNo('PL!-pb2-014-R', 'rin');
        $handLive = $this->cardByNo('PL!-pb2-041-L', 'hand_live');
        $succ = $this->cardByNo('PL!-pb1-029-SRL', 'succ_shiranai');
        $succ['score'] = 2;
        $succ['_printed_score'] = 1;
        $succ['_effect_score_bonus'] = 1;

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 4,
            'active_player' => 'p1',
            'log' => [],
            'pending_prompt' => [
                'type' => 'pb2_pick_hand_success_swap',
                'owner' => 'p1',
                'responder' => 'p1',
                'source_name' => 'Rin Hoshizora',
                'source_instance_id' => 'rin',
                'step' => 'pick_success',
                'hand_instance_id' => 'hand_live',
                'candidates' => [],
            ],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$handLive],
                    'success_lives' => [$succ],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => $rin, 'right' => null],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'energy_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'success_lives' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'energy_zone' => [],
                ],
            ],
        ];

        $out = actionResolvePrompt($state, 'p1', ['card_id' => 'succ_shiranai']);
        $inHand = null;
        foreach ($out['players']['p1']['hand'] as $c) {
            if (($c['instance_id'] ?? '') === 'succ_shiranai') {
                $inHand = $c;
                break;
            }
        }
        $this->assertNotNull($inHand);
        $this->assertSame(1, intval($inHand['score'] ?? 0));
        $this->assertArrayNotHasKey('_effect_score_bonus', $inHand);
    }

    public function testSetLiveCardsStripsStickyBonusFromHand(): void
    {
        $umi = $this->cardByNo('PL!-PR-004-PR', 'umi');
        $sticky = $this->cardByNo('PL!-pb1-029-SRL', 'sticky');
        $sticky['score'] = 2;
        $sticky['_printed_score'] = 1;
        $sticky['_effect_score_bonus'] = 1;
        $fresh = $this->cardByNo('PL!-pb1-029-L', 'fresh');

        $state = [
            'status' => 'playing',
            'phase' => 'live_set',
            'seq' => 1,
            'turn' => 4,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_set_order' => ['p1'],
            'live_set_index' => 0,
            'live_ready' => [],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$sticky, $fresh],
                    'success_lives' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => $umi, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'main_deck' => [
                        $this->cardByNo('PL!-PR-005-PR', 'draw1'),
                        $this->cardByNo('PL!-PR-005-PR', 'draw2'),
                    ],
                    'energy_deck' => [],
                    'energy_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'success_lives' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'energy_zone' => [],
                ],
            ],
        ];

        $out = actionSetLiveCards($state, 'p1', [
            'card_ids' => ['sticky', 'fresh'],
        ]);
        $sum = 0;
        foreach ($out['players']['p1']['live_zone'] as $lc) {
            if (!$lc || !str_contains($lc['card_no'] ?? '', 'pb1-029')) {
                continue;
            }
            $sum += intval($lc['score'] ?? 0);
            $this->assertSame(1, intval($lc['score'] ?? 0), $lc['instance_id']);
            $this->assertArrayNotHasKey('_effect_score_bonus', $lc);
        }
        $this->assertSame(2, $sum, 'Two Shiranai must enter Live at printed score 1 each');
    }
}
