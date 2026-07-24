<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #70: Members put on Stage by PL!-pb1-018 Nico must still fire [On Enter].
 */
final class Issue70NicoSummonedOnEnterTest extends TestCase
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

    private function filler(string $id, string $type = 'メンバー'): array
    {
        return [
            'instance_id' => $id,
            'card_type' => $type,
            'card_type_en' => $type === 'メンバー' ? 'Member' : 'Live',
            'name_en' => 'Filler ' . $id,
            'cost' => 1,
            'active' => true,
        ];
    }

    private function baseState(): array
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
                    'name' => 'Controller',
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
                    'name' => 'Opponent',
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

    public function testKekeLookRevealFiresWhenSummonedByNico(): void
    {
        $nico = $this->cardByNo('PL!-pb1-018-R', 'nico');
        $keke = $this->cardByNo('PL!SP-bp2-002-R', 'keke_wr');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $nico;
        // One empty slot only so WR→Stage auto-resolves (no pick prompt).
        $state['players']['p1']['stage']['right'] = array_merge($this->filler('p1_fill'), ['cost' => 5]);
        $state['players']['p1']['waiting_room'] = [$keke];
        $state['players']['p1']['main_deck'] = [
            $this->filler('d1'),
            $this->filler('d2'),
            array_merge($this->filler('big'), ['cost' => 12]),
        ];
        // Occupy p2 so only p1 places.
        $blocker = $this->filler('blk');
        $state['players']['p2']['stage'] = [
            'left' => array_merge($blocker, ['instance_id' => 'b1']),
            'center' => array_merge($blocker, ['instance_id' => 'b2']),
            'right' => array_merge($blocker, ['instance_id' => 'b3']),
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $nico, 'center');

        $this->assertSame('keke_wr', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertSame('pick_looked_deck_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p1', $state['pending_prompt']['responder'] ?? null);
        $this->assertCount(3, $state['pending_prompt']['candidates'] ?? []);
        $this->assertNotEmpty($state['_resume_both_wr_member_to_empty_stage'] ?? null);
    }

    public function testKosuzuMillDrawFiresWhenSummonedByNico(): void
    {
        $nico = $this->cardByNo('PL!-pb1-018-R', 'nico2');
        $kosuzu = $this->cardByNo('PL!HS-bp1-008-R', 'kosuzu_wr');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $nico;
        $state['players']['p1']['stage']['right'] = array_merge($this->filler('p1_fill'), ['cost' => 5]);
        $state['players']['p1']['waiting_room'] = [$kosuzu];
        $state['players']['p1']['main_deck'] = [
            $this->filler('m1'),
            $this->filler('m2'),
            $this->filler('m3'),
            $this->filler('draw_me'),
        ];
        $blocker = $this->filler('blk');
        $state['players']['p2']['stage'] = [
            'left' => array_merge($blocker, ['instance_id' => 'b1']),
            'center' => array_merge($blocker, ['instance_id' => 'b2']),
            'right' => array_merge($blocker, ['instance_id' => 'b3']),
        ];
        $handBefore = count($state['players']['p1']['hand']);

        $state = \resolveOnEnterAbilities($state, 'p1', $nico, 'center');

        $this->assertSame('kosuzu_wr', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertSame($handBefore + 1, count($state['players']['p1']['hand']));
        $log = implode("\n", array_map(
            static fn($e) => is_array($e) ? (string)($e['msg'] ?? '') : (string)$e,
            $state['log'] ?? []
        ));
        $this->assertStringContainsString('put 3 card(s) from deck top into Waiting Room', $log);
        $this->assertStringContainsString('all milled cards were Members', $log);
    }

    public function testOnEnterPromptThenOpponentStillGetsPick(): void
    {
        $nico = $this->cardByNo('PL!-pb1-018-R', 'nico3');
        $keke = $this->cardByNo('PL!SP-bp2-002-R', 'keke2');
        $oppMember = [
            'instance_id' => 'opp_a',
            'card_no' => 'PL!-test-opp',
            'name_en' => 'Opp Prefer',
            'card_type' => 'メンバー',
            'group' => "μ's",
            'cost' => 1,
        ];
        $oppOther = array_merge($oppMember, ['instance_id' => 'opp_b', 'name_en' => 'Opp Other', 'cost' => 2]);

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $nico;
        $state['players']['p1']['stage']['right'] = array_merge($this->filler('p1_fill'), ['cost' => 5]);
        $state['players']['p1']['waiting_room'] = [$keke];
        $state['players']['p1']['main_deck'] = [
            $this->filler('d1'),
            $this->filler('d2'),
            array_merge($this->filler('big'), ['cost' => 12]),
        ];
        $state['players']['p2']['waiting_room'] = [$oppMember, $oppOther];

        $state = \resolveOnEnterAbilities($state, 'p1', $nico, 'center');
        $this->assertSame('keke2', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertSame('pick_looked_deck_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertNotEmpty($state['_resume_both_wr_member_to_empty_stage'] ?? null);

        // Resolve look (add cost-12 card) — finishPromptEffects should resume Nico chain for p2.
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'big']);

        $this->assertSame('both_wr_member_to_empty_stage', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);
        $this->assertEmpty($state['_resume_both_wr_member_to_empty_stage'] ?? null);
    }
}
