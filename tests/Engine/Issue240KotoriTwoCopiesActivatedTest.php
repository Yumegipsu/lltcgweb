<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-012 Kotori — each Stage copy has its own once-per-turn Activated (#240/#242).
 *
 * Card text additional cost is discard 2 OR Wait 2 Printemps Members. Self is already
 * Waited by the colon cost, so the Wait path needs 2 *other* Active Printemps — do not
 * count self toward that 2 (the #240 Wait-1 reading was wrong; see #242).
 */
final class Issue240KotoriTwoCopiesActivatedTest extends TestCase
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

    private function printempsLive(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-TEST-LIVE-' . $id,
            'name_en' => 'Printemps Live ' . $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => "μ's",
            'subunit' => 'Printemps',
            'score' => 1,
        ];
    }

    private function handCards(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = [
                'instance_id' => 'h' . $i,
                'card_type' => 'メンバー',
                'name_en' => 'Hand ' . $i,
                'group' => "μ's",
            ];
        }
        return $out;
    }

    private function abIdx(array $kotori): int
    {
        foreach ($kotori['abilities'] ?? [] as $i => $a) {
            if (($a['type'] ?? '') === 'activated_wait_printemps_live_from_wr') {
                return (int)$i;
            }
        }
        $this->fail('Missing activated ability');
    }

    private function baseState(array $left, array $center, array $right, array $hand, array $wr): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => $hand,
                    'waiting_room' => $wr,
                    'stage' => [
                        'left' => $left,
                        'center' => $center,
                        'right' => $right,
                    ],
                    'energy_zone' => array_fill(0, 10, ['card_type' => 'エネルギー', 'active' => true]),
                    'main_deck' => [],
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

    public function testEachCopyHasOwnOncePerTurnViaDiscard2(): void
    {
        $k1 = $this->cardByNo('PL!-pb2-012-R', 'k1');
        $k2 = $this->cardByNo('PL!-pb2-012-P+', 'k2');
        $honoka = $this->cardByNo('PL!-bp3-001-R', 'honoka');
        $idx = $this->abIdx($k1);
        // 4 hand cards so both copies can pay discard2 after the first Live is added.
        $state = $this->baseState(
            $k1,
            $k2,
            $honoka,
            $this->handCards(4),
            [$this->printempsLive('l1'), $this->printempsLive('l2')]
        );

        $state = actionActivateAbility($state, 'p1', ['card_id' => 'k1', 'ability_index' => $idx]);
        $state = actionResolvePrompt($state, 'p1', [
            'choice' => 'discard2',
            'discard_ids' => ['h1', 'h2'],
        ]);
        if (($state['pending_prompt']['type'] ?? '') === 'pick_wr_to_hand') {
            $cid = $state['pending_prompt']['candidates'][0]['instance_id'] ?? 'l1';
            $state = actionResolvePrompt($state, 'p1', ['card_id' => $cid]);
        }

        $this->assertTrue(isAbilityUsed($state['players']['p1']['stage']['left'], $idx));
        $this->assertFalse(isAbilityUsed($state['players']['p1']['stage']['center'], $idx));
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['center']));

        $this->assertTrue(plMusePb2KotoriCanPayExtraCost(
            $state['players']['p1'],
            $k2['abilities'][$idx],
            'k2'
        ));

        $state = actionActivateAbility($state, 'p1', ['card_id' => 'k2', 'ability_index' => $idx]);
        $this->assertSame('pb2_printemps_cost_mode', $state['pending_prompt']['type'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $discard = array_values(array_filter($handIds, static fn($id) => str_starts_with((string)$id, 'h')));
        $this->assertGreaterThanOrEqual(2, count($discard));
        $state = actionResolvePrompt($state, 'p1', [
            'choice' => 'discard2',
            'discard_ids' => array_slice($discard, 0, 2),
        ]);
        if (($state['pending_prompt']['type'] ?? '') === 'pick_wr_to_hand') {
            $cid = $state['pending_prompt']['candidates'][0]['instance_id'] ?? 'l2';
            $state = actionResolvePrompt($state, 'p1', ['card_id' => $cid]);
        }

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertTrue(isAbilityUsed($state['players']['p1']['stage']['center'], $idx));
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['center']));
    }

    public function testCanPayExtraCostNeedsTwoOtherPrintemps(): void
    {
        $k1 = $this->cardByNo('PL!-pb2-012-R', 'k1');
        $honoka = $this->cardByNo('PL!-bp3-001-R', 'honoka');
        $hanayo = $this->cardByNo('PL!-bp3-008-P', 'hanayo');
        $idx = $this->abIdx($k1);
        $p = [
            'hand' => [],
            'stage' => [
                'left' => $k1,
                'center' => $honoka,
                'right' => $hanayo,
            ],
            'waiting_room' => [],
        ];
        $this->assertTrue(plMusePb2KotoriCanPayExtraCost($p, $k1['abilities'][$idx], 'k1'));
        $p['stage']['right'] = null;
        $this->assertFalse(plMusePb2KotoriCanPayExtraCost($p, $k1['abilities'][$idx], 'k1'));
        $p['hand'] = $this->handCards(2);
        $this->assertTrue(plMusePb2KotoriCanPayExtraCost($p, $k1['abilities'][$idx], 'k1'));
    }
}
