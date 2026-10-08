<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * 13-cost Kotori adds a Printemps Live from Waiting Room.
 * PL!-bp4-024-L omits subunit; PL!-bp4-024-SRL is Printemps. Both prints must match.
 */
final class Issue261NightingaleKotoriWrTest extends TestCase
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

    private function baseState(array $kotori, array $wr): array
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
                    'hand' => [
                        ['instance_id' => 'h1', 'card_type' => 'メンバー', 'name_en' => 'A'],
                        ['instance_id' => 'h2', 'card_type' => 'メンバー', 'name_en' => 'B'],
                    ],
                    'waiting_room' => $wr,
                    'stage' => ['left' => null, 'center' => $kotori, 'right' => null],
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

    public function testBothNightingalePrintsArePrintempsLivesForKotori(): void
    {
        $kotori = $this->cardByNo('PL!-pb2-012-R', 'kotori012');
        $l = $this->cardByNo('PL!-bp4-024-L', 'night-l');
        $srl = $this->cardByNo('PL!-bp4-024-SRL', 'night-srl');
        unset($l['subunit']);
        $bare = [
            'instance_id' => 'night-bare',
            'card_no' => 'PL!-bp4-024-L',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
        ];
        $other = $this->cardByNo('PL!-bp3-020-L', 'snow');
        $cfg = ['filter' => 'live', 'subunit' => 'Printemps'];

        $this->assertTrue(cardMatchesWrPick($l, $cfg));
        $this->assertTrue(cardMatchesWrPick($srl, $cfg));
        $this->assertTrue(cardMatchesWrPick($bare, $cfg));
        $this->assertFalse(cardMatchesWrPick($other, $cfg));

        $ab = null;
        $abIdx = 1;
        foreach ($kotori['abilities'] ?? [] as $i => $a) {
            if (($a['type'] ?? '') === 'activated_wait_printemps_live_from_wr') {
                $ab = $a;
                $abIdx = $i;
                break;
            }
        }
        $this->assertIsArray($ab);

        $state = $this->baseState($kotori, [$l, $other]);
        $this->assertNull(activatedAbilityWrBlockReason($state['players']['p1'], $ab));

        $state = actionActivateAbility($state, 'p1', [
            'card_id' => 'kotori012',
            'ability_index' => $abIdx,
        ]);
        $state = actionResolvePrompt($state, 'p1', [
            'choice' => 'discard2',
            'discard_ids' => ['h1', 'h2'],
        ]);
        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pick_wr_to_hand', $pr['type'] ?? null);
        $ids = array_column($pr['candidates'] ?? [], 'instance_id');
        $this->assertSame(['night-l'], $ids);
    }
}
