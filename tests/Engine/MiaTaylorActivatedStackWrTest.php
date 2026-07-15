<?php
/**
 * PL!N-pb1-011-R Mia Taylor — Activated stack Energy → pick Nijigasaki Live from WR.
 */
declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class MiaTaylorActivatedStackWrTest extends TestCase
{
    private function mia(): array
    {
        return [
            'instance_id' => 'mia_1',
            'card_no' => 'PL!N-pb1-011-R',
            'name_en' => 'Mia Taylor',
            'card_type' => 'メンバー',
            'group' => 'Nijigasaki',
            'abilities' => [
                [
                    'trigger' => 'continuous',
                    'type' => 'blade_per_stacked_energy',
                    'amount' => 1,
                ],
                [
                    'trigger' => 'activated',
                    'type' => 'optional_stack_energy_add_wr_live',
                    'energy' => 1,
                    'group' => 'Nijigasaki',
                    'once_per_turn' => true,
                ],
            ],
        ];
    }

    private function live(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!N-LIVE',
            'name_en' => 'Niji Live',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Nijigasaki',
        ];
    }

    private function baseState(): array
    {
        return [
            'room_id' => 'MIAACT',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'main_first',
            'active_player' => 'p1',
            'first_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'token' => 't1',
                    'hand' => [],
                    'waiting_room' => [$this->live('wr_live')],
                    'main_deck' => [],
                    'energy_zone' => [
                        ['instance_id' => 'e1', 'active' => true, 'card_type' => 'エネルギー'],
                    ],
                    'energy_deck' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $this->mia(),
                        'right' => null,
                    ],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'token' => 't2',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testActivateStacksEnergyAndOpensWrPick(): void
    {
        $state = $this->baseState();
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'mia_1',
            'ability_index' => 1,
        ]);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $mia = $state['players']['p1']['stage']['center'];
        $this->assertGreaterThanOrEqual(1, count($mia['stacked_energy'] ?? $mia['stacked_energy_ids'] ?? []));
        $this->assertSame(0, \countActiveEnergyInZone($state['players']['p1']));
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'wr_live']);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('wr_live', $handIds);
    }

    public function testAbilityIsActivatedNotOnEnterInCatalog(): void
    {
        $cards = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/cards.json'),
            true
        );
        $found = null;
        foreach ($cards['cards'] as $c) {
            if (($c['card_no'] ?? '') === 'PL!N-pb1-011-R') {
                $found = $c;
                break;
            }
        }
        $this->assertNotNull($found);
        $ab = $found['abilities'][1] ?? [];
        $this->assertSame('activated', $ab['trigger'] ?? null);
        $this->assertTrue(!empty($ab['once_per_turn']));
    }
}
