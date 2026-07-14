<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!-pb1-018 Nico: each player chooses which cost≤2 WR Member enters Stage in Wait. */
final class NicoPb1018BothWrToStageTest extends TestCase
{
    private function nicoCard(string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === 'PL!-pb1-018-R') {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing PL!-pb1-018-R');
    }

    private function wrMember(string $id, string $name, int $cost): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-test-' . $id,
            'name_en' => $name,
            'card_type' => 'メンバー',
            'group' => "μ's",
            'cost' => $cost,
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

    public function testEachPlayerChoosesWhichWrMemberEntersInWait(): void
    {
        $nico = $this->nicoCard('nico_stage');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $nico;
        $state['players']['p1']['waiting_room'] = [
            $this->wrMember('p1_a', 'P1 Prefer', 1),
            $this->wrMember('p1_b', 'P1 Other', 2),
        ];
        $state['players']['p2']['waiting_room'] = [
            $this->wrMember('p2_a', 'P2 Prefer', 1),
            $this->wrMember('p2_b', 'P2 Other', 2),
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $nico, 'center');

        $this->assertSame('both_wr_member_to_empty_stage', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p1', $state['pending_prompt']['responder'] ?? null);
        $this->assertSame('pick_wr', $state['pending_prompt']['step'] ?? null);
        $this->assertCount(2, $state['pending_prompt']['candidates'] ?? []);

        // First WR entry must not be blindly auto-first (old bug): controller picks p1_b.
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'p1_b']);
        $this->assertSame('pick_slot', $state['pending_prompt']['step'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'left']);

        $this->assertSame('p1_b', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertTrue(!empty($state['players']['p1']['stage']['left']['blocks_slot_entries']));

        $this->assertSame('both_wr_member_to_empty_stage', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);

        $blocked = \actionResolvePrompt($state, 'p1', ['card_id' => 'p2_a']);
        $this->assertTrue(!empty($blocked['_resolve_prompt_noop']));

        $state = \actionResolvePrompt($state, 'p2', ['card_id' => 'p2_a']);
        $state = \actionResolvePrompt($state, 'p2', ['slot' => 'right']);

        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertSame('p2_a', $state['players']['p2']['stage']['right']['instance_id'] ?? null);
        $this->assertTrue(\memberIsInWait($state['players']['p2']['stage']['right']));
        $this->assertSame('p1_a', $state['players']['p1']['waiting_room'][0]['instance_id'] ?? null);
        $this->assertSame('p2_b', $state['players']['p2']['waiting_room'][0]['instance_id'] ?? null);
    }

    public function testAutoResolvesWhenOnlyOneEligibleChoiceEach(): void
    {
        $nico = $this->nicoCard('nico_only');
        $filler = [
            'instance_id' => 'filler',
            'name_en' => 'Filler',
            'card_type' => 'メンバー',
            'group' => "μ's",
            'cost' => 5,
            'active' => true,
        ];
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $nico;
        $state['players']['p1']['stage']['right'] = $filler;
        $state['players']['p1']['waiting_room'] = [$this->wrMember('p1_only', 'Only', 2)];
        $state['players']['p2']['stage']['center'] = array_merge($filler, ['instance_id' => 'filler2']);
        $state['players']['p2']['stage']['right'] = array_merge($filler, ['instance_id' => 'filler3']);
        $state['players']['p2']['waiting_room'] = [$this->wrMember('p2_only', 'OnlyOpp', 1)];

        $state = \resolveOnEnterAbilities($state, 'p1', $nico, 'center');

        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertSame('p1_only', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertSame('p2_only', $state['players']['p2']['stage']['left']['instance_id'] ?? null);
        $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertTrue(!empty($state['players']['p1']['stage']['left']['blocks_slot_entries']));
    }
}
