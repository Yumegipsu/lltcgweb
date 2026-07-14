<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!SP-bp2-001-P Kanon — On Enter negate then choose WR Liella!/Superstar card. */
final class KanonSpBp2001NegateWrPickTest extends TestCase
{
    private function kanon(): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === 'PL!SP-bp2-001-P') {
                $card['instance_id'] = 'kanon_test';
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing PL!SP-bp2-001-P');
    }

    private function superstarCard(string $id, string $type = 'メンバー', string $name = 'Liella Member'): array
    {
        return [
            'instance_id' => $id,
            'card_type' => $type,
            'name_en' => $name,
            'group' => 'Superstar',
            'cost' => 3,
            'active' => true,
        ];
    }

    private function baseState(array $kanon, array $stageExtra = [], array $wr = []): array
    {
        $stage = array_merge([
            'left' => null,
            'center' => $kanon,
            'right' => null,
        ], $stageExtra);
        return [
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => $wr,
                    'stage' => $stage,
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testOnEnterOffersNegateThenWrPickChoice(): void
    {
        $kanon = $this->kanon();
        $ab = $kanon['abilities'][0];
        $this->assertSame('optional_negate_member_live_start_add_wr', $ab['type'] ?? null);

        $ally = $this->superstarCard('ally_m');
        $wrLive = $this->superstarCard('wr_live', 'ライブ', 'Liella Live');
        $wrMem = $this->superstarCard('wr_mem', 'メンバー', 'Liella Mem B');
        $wrOther = [
            'instance_id' => 'wr_other',
            'card_type' => 'メンバー',
            'name_en' => 'Other Group',
            'group' => "μ's",
            'cost' => 2,
        ];

        $state = $this->baseState($kanon, ['right' => $ally], [$wrLive, $wrMem, $wrOther]);
        $state = resolveAbilityEffect($state, 'p1', $kanon, $ab, [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);

        $this->assertSame('optional_negate_member_live_start_add_wr', $state['pending_prompt']['type'] ?? null);
        $choices = $state['pending_prompt']['choices'] ?? [];
        $this->assertContains('skip', $choices);
        $this->assertContains('kanon_test', $choices);
        $this->assertContains('ally_m', $choices);

        $state = actionResolvePrompt($state, 'p1', ['choice' => 'ally_m']);
        $this->assertTrue(!empty($state['players']['p1']['stage']['right']['live_start_negated']));
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('', $state['pending_prompt']['wr_pick_cfg']['filter'] ?? 'missing');
        $this->assertSame('Superstar', $state['pending_prompt']['wr_pick_cfg']['group'] ?? null);

        $ids = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains('wr_live', $ids);
        $this->assertContains('wr_mem', $ids);
        $this->assertNotContains('wr_other', $ids);
        $this->assertCount(0, $state['players']['p1']['hand']);

        $state = actionResolvePrompt($state, 'p1', ['card_id' => 'wr_live']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('wr_live', $state['players']['p1']['hand'][0]['instance_id'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertNotContains('wr_mem', $handIds);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('wr_mem', $wrIds);
        $this->assertNotContains('wr_live', $wrIds);
    }

    public function testNegateWithEmptyWrStillAppliesNegate(): void
    {
        $kanon = $this->kanon();
        $ab = $kanon['abilities'][0];
        $ally = $this->superstarCard('ally_m');
        $state = $this->baseState($kanon, ['right' => $ally], []);
        $state = resolveAbilityEffect($state, 'p1', $kanon, $ab, [
            'phase' => 'on_enter',
            'slot' => 'center',
        ]);
        $state = actionResolvePrompt($state, 'p1', ['choice' => 'ally_m']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertTrue(!empty($state['players']['p1']['stage']['right']['live_start_negated']));
        $this->assertCount(0, $state['players']['p1']['hand']);
    }
}
