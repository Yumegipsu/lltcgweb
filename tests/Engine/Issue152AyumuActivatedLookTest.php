<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * #152 — PL!N-PR-003/008/010 [Activated] reveal hand → optional Live look.
 */
final class Issue152AyumuActivatedLookTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                $card['entered_turn'] = 1;
                return $card;
            }
        }
        $this->fail('Missing card ' . $cardNo);
    }

    private function energyStub(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'LL-E-001-SD',
            'card_type' => 'エネルギー',
            'card_type_en' => 'Energy',
            'name_en' => 'Energy',
            'name' => 'エネルギー',
        ];
    }

    private function liveStub(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'LIVE-' . $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'Test Live',
            'name' => 'Test Live',
            'score' => 1,
            'required_hearts' => [],
            'group' => 'μ\'s',
        ];
    }

    private function memberStub(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'STUB-' . $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Other Member',
            'name' => 'Other',
            'group' => 'Nijigasaki',
            'cost' => 1,
            'blade' => 1,
            'active' => true,
            'entered_turn' => 1,
        ];
    }

    private function baseState(array $hand, bool $withOther = true): array
    {
        $ayumu = $this->cardByNo('PL!N-PR-003-PR', 'ayumu');
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => $hand,
                    'waiting_room' => [],
                    'main_deck' => [
                        $this->liveStub('deck_live'),
                        $this->energyStub('d2'),
                        $this->energyStub('d3'),
                        $this->energyStub('d4'),
                        $this->energyStub('d5'),
                    ],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'stage' => [
                        'left' => $withOther ? $this->memberStub('other') : null,
                        'center' => $ayumu,
                        'right' => null,
                    ],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testCardsJsonTriggerIsActivated(): void
    {
        foreach (['PL!N-PR-003-PR', 'PL!N-PR-008-PR', 'PL!N-PR-010-PR'] as $no) {
            $card = $this->cardByNo($no, 'x');
            $ab = $card['abilities'][0] ?? [];
            $this->assertSame('activated', $ab['trigger'] ?? null, $no);
            $this->assertSame('reveal_hand_look_live_if_no_live', $ab['type'] ?? null, $no);
            $this->assertTrue(!empty($ab['once_per_turn']), $no);
            $this->assertTrue(!empty($ab['optional_pick']), $no);
            $this->assertArrayNotHasKey('group', $ab, $no);
        }
    }

    public function testActivateOpensLookWhenNoLiveAndOtherMember(): void
    {
        $state = $this->baseState([$this->energyStub('h1')]);
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'ayumu',
            'ability_index' => 0,
        ]);
        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pick_looked_deck_hand', $pr['type']);
        $this->assertTrue(!empty($pr['optional']));
        $this->assertContains('deck_live', $pr['eligible_ids'] ?? []);
        $this->assertTrue(\isAbilityUsed($state['players']['p1']['stage']['center'], 0));
    }

    public function testActivateSkipsLookWhenHandHasLive(): void
    {
        $state = $this->baseState([$this->liveStub('hand_live'), $this->energyStub('h1')]);
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'ayumu',
            'ability_index' => 0,
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertCount(5, $state['players']['p1']['main_deck']);
    }

    public function testActivateSkipsLookWhenAloneOnStage(): void
    {
        $state = $this->baseState([$this->energyStub('h1')], false);
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'ayumu',
            'ability_index' => 0,
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertCount(5, $state['players']['p1']['main_deck']);
    }

    public function testOncePerTurnBlocksSecondActivate(): void
    {
        $state = $this->baseState([$this->energyStub('h1')]);
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'ayumu',
            'ability_index' => 0,
        ]);
        $state['pending_prompt'] = null;
        $state['surveil_stash'] = null;
        $this->expectException(\Exception::class);
        \actionActivateAbility($state, 'p1', [
            'card_id' => 'ayumu',
            'ability_index' => 0,
        ]);
    }
}
