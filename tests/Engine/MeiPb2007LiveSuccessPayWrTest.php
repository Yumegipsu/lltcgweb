<?php
/**
 * PL!SP-pb2-007-R Mei Yoneme — Live Success optional pay 3 Energy → add Liella! Live from WR.
 */
declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class MeiPb2007LiveSuccessPayWrTest extends TestCase
{
    private function energy(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'EN-001',
            'name_en' => 'Energy',
            'card_type' => 'エネルギー',
            'card_type_en' => 'Energy',
            'face_up' => true,
            'active' => true,
        ];
    }

    private function mei(): array
    {
        return [
            'instance_id' => 'mei_pb2',
            'card_no' => 'PL!SP-pb2-007-R',
            'name' => '米女メイ',
            'name_en' => 'Mei Yoneme',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Superstar',
            'cost' => 11,
            'abilities' => [
                [
                    'trigger' => 'live_success',
                    'type' => 'pay_energy_add_from_wr',
                    'cost' => 3,
                    'group' => 'Superstar',
                    'filter' => 'live',
                    'count' => 1,
                    'once_per_turn' => true,
                ],
            ],
        ];
    }

    private function liellaLive(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!SP-pb1-022-L',
            'name_en' => 'Mirai wa Kaze no You ni',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Superstar',
            'score' => 1,
        ];
    }

    private function baseState(): array
    {
        return [
            'phase' => 'live_success_effects',
            'seq' => 1,
            'first_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [
                        $this->energy('e1'),
                        $this->energy('e2'),
                        $this->energy('e3'),
                    ],
                    'stage' => [
                        'left' => null,
                        'center' => $this->mei(),
                        'right' => null,
                    ],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'name' => 'P2',
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

    public function testLiveSuccessOpensOptionalPayWhenWrHasLiellaLive(): void
    {
        $state = $this->baseState();
        $state['players']['p1']['waiting_room'] = [$this->liellaLive('wr_live')];
        $state = \resolveLiveSuccessAbilities($state, 'p1', [['name_en' => 'Dummy Live']], 0, [], []);
        $this->assertSame('optional_pay_energy_add_from_wr', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(3, intval($state['pending_prompt']['pay_cost'] ?? 0));
    }

    public function testSkipContinuesPastLiveSuccessEffects(): void
    {
        $state = $this->baseState();
        $state['players']['p1']['waiting_room'] = [$this->liellaLive('wr_live')];
        $state = \resolveLiveSuccessAbilities($state, 'p1', [['name_en' => 'Dummy Live']], 0, [], []);
        $this->assertSame('optional_pay_energy_add_from_wr', $state['pending_prompt']['type'] ?? null);
        $after = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);
        $this->assertNull($after['pending_prompt'] ?? null);
        $this->assertSame(3, \countActiveEnergyInZone($after['players']['p1']));
        $this->assertSame('wr_live', $after['players']['p1']['waiting_room'][0]['instance_id'] ?? null);
        $this->assertCount(0, $after['players']['p1']['hand']);
    }

    public function testYesPaysAndAddsChosenLiveFromWr(): void
    {
        $state = $this->baseState();
        $state['players']['p1']['waiting_room'] = [$this->liellaLive('wr_live')];
        $state = \resolveLiveSuccessAbilities($state, 'p1', [['name_en' => 'Dummy Live']], 0, [], []);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(0, \countActiveEnergyInZone($state['players']['p1']));
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'wr_live']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('wr_live', $handIds);
        $this->assertCount(0, $state['players']['p1']['waiting_room']);
    }

    public function testNoPromptWithoutMatchingWrLive(): void
    {
        $state = $this->baseState();
        $state['players']['p1']['waiting_room'] = [[
            'instance_id' => 'muse_live',
            'card_no' => 'PL!LL-bp1-022-L',
            'name_en' => 'Snow halation',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => "μ's",
            'score' => 1,
        ]];
        $state = \resolveLiveSuccessAbilities($state, 'p1', [['name_en' => 'Dummy Live']], 0, [], []);
        $this->assertNull($state['pending_prompt'] ?? null);
    }
}
