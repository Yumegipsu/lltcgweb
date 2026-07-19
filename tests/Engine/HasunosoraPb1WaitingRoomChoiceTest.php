<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class HasunosoraPb1WaitingRoomChoiceTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function member(string $id, string $subunit = ''): array
    {
        return [
            'instance_id' => $id,
            'name' => $id,
            'name_en' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Hasunosora',
            'subunit' => $subunit,
            'active' => true,
        ];
    }

    private function live(string $id, string $subunit = 'スリーズブーケ'): array
    {
        return [
            'instance_id' => $id,
            'name' => $id,
            'name_en' => $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Hasunosora',
            'subunit' => $subunit,
        ];
    }

    private function state(array $source): array
    {
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
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => $source, 'right' => null],
                    'energy_zone' => [['instance_id' => 'energy_1', 'active' => true]],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];
    }

    public function testPb1004LetsPlayerChooseCeriseBouquetLiveAfterMill(): void
    {
        $ginko = $this->cardByNo('PL!HS-pb1-004-R', 'ginko_004');
        $state = $this->state($ginko);
        $state['players']['p1']['hand'] = [$this->member('discard_me')];
        $state['players']['p1']['main_deck'] = [
            $this->live('live_first'),
            $this->member('milled_member'),
            $this->live('live_chosen'),
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $ginko, 'center');
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['discard_me'],
        ]);

        $this->assertSame('pick_wr_live', $state['pending_prompt']['step'] ?? null);
        $this->assertSame(
            ['live_first', 'live_chosen'],
            array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id')
        );
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'live_chosen']);
        $this->assertContains('live_chosen', array_column($state['players']['p1']['hand'], 'instance_id'));
        $this->assertNotContains('live_first', array_column($state['players']['p1']['hand'], 'instance_id'));
    }

    public function testPb1020LetsPlayerChooseBothWaitingRoomCards(): void
    {
        $ginko = $this->cardByNo('PL!HS-pb1-020-N', 'ginko_020');
        $state = $this->state($ginko);
        $state['players']['p1']['hand'] = [$this->member('discard_a'), $this->member('discard_b')];
        $state['players']['p1']['waiting_room'] = [
            $this->member('member_first', 'スリーズブーケ'),
            $this->member('member_chosen', 'スリーズブーケ'),
            $this->live('live_a'),
            $this->live('live_b'),
            $this->live('live_chosen'),
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $ginko, 'center');
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['discard_a', 'discard_b'],
        ]);
        $this->assertSame('pick_wr_member', $state['pending_prompt']['step'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'member_chosen']);
        $this->assertSame('pick_wr_live', $state['pending_prompt']['step'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'live_chosen']);

        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('member_chosen', $handIds);
        $this->assertContains('live_chosen', $handIds);
        $this->assertNotContains('member_first', $handIds);
        $this->assertNotContains('live_a', $handIds);
    }

    public function testPb1012LetsPlayerChooseLiveAfterThresholdShuffle(): void
    {
        $ginko = $this->cardByNo('PL!HS-pb1-012-R', 'ginko_012');
        $state = $this->state($ginko);
        for ($i = 0; $i < 10; $i++) {
            $state['players']['p1']['waiting_room'][] = $this->member('p1_member_' . $i);
            $state['players']['p2']['waiting_room'][] = $this->member('p2_member_' . $i);
        }
        $state['players']['p1']['waiting_room'][] = $this->live('threshold_live_first');
        $state['players']['p1']['waiting_room'][] = $this->live('threshold_live_chosen');

        $state = \resolveOnEnterAbilities($state, 'p1', $ginko, 'center');

        $this->assertSame('pick_wr_live', $state['pending_prompt']['step'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'threshold_live_chosen']);
        $this->assertContains(
            'threshold_live_chosen',
            array_column($state['players']['p1']['hand'], 'instance_id')
        );
        $this->assertNotContains(
            'threshold_live_first',
            array_column($state['players']['p1']['hand'], 'instance_id')
        );
        $this->assertSame(2, \getStageBladeBonus($state, 'p1'));
    }

    public function testClientRoutesNewWaitingRoomChoiceSteps(): void
    {
        $renderer = (string) file_get_contents(dirname(__DIR__, 2) . '/client/js/prompt-renderer.js');
        $this->assertStringContainsString(
            "pr.type==='both_shuffle_wr_members_deck_bottom_threshold'",
            $renderer
        );
        $this->assertStringContainsString(
            "pr.type==='optional_discard_add_cb_member_hs_live'",
            $renderer
        );
        $this->assertStringContainsString("pr.step==='pick_wr_live'", $renderer);
        $this->assertStringContainsString("pr.step==='pick_wr_member'", $renderer);
    }
}
