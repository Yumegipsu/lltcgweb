<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression: LL-bp1-001-R＋ On Enter WR member pick (issue #46). */
final class Issue46AyumuWrPickTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function baseState(string $phase = 'main_first'): array
    {
        return [
            'status' => 'playing',
            'phase' => $phase,
            'seq' => 1,
            'turn' => 1,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
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

    public function testTimeoutWrPickPrefersMemberFilterOverFirstCandidate(): void
    {
        $energy = [
            'instance_id' => 'issue46_energy',
            'card_no' => 'PL!N-EN-001',
            'card_type' => 'エネルギー',
            'card_type_en' => 'Energy',
            'name_en' => 'Energy',
        ];
        $member = [
            'instance_id' => 'issue46_member',
            'card_no' => 'PL!N-MEM-001',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Member',
        ];
        $prompt = [
            'type' => 'pick_wr_to_hand',
            'wr_pick_cfg' => ['filter' => 'member'],
            'candidates' => [$energy, $member],
        ];

        $data = buildTimeoutPromptResolution($this->baseState(), 'p1', $prompt);

        $this->assertSame('issue46_member', $data['card_id'] ?? null);
    }

    public function testOnEnterStillOpensMemberWrPick(): void
    {
        $ayumu = $this->cardByNo('LL-bp1-001-R＋', 'issue46_stage');
        $first = $this->cardByNo('LL-bp2-001-R＋', 'issue46_wr_first');
        $chosen = $this->cardByNo('LL-bp3-001-R＋', 'issue46_wr_chosen');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $ayumu;
        $state['players']['p1']['waiting_room'] = [$first, $chosen];

        $state = \resolveOnEnterAbilities($state, 'p1', $ayumu, 'center');

        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('member', $state['pending_prompt']['wr_pick_cfg']['filter'] ?? null);
        $this->assertCount(2, $state['pending_prompt']['candidates'] ?? []);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'issue46_wr_chosen']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertContains('issue46_wr_chosen', array_column($state['players']['p1']['hand'], 'instance_id'));
    }
}
