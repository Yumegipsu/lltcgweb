<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * #274: You Watanabe (PL!S-bp7-005) [Activated] "Put 2 cards from your hand into the Waiting Room: choose
 * this Member and 1 other Aqours Member. Trigger 1 [On Enter] ability of each of them."
 * Reported: after picking the other Member the effect never fires and the match soft-locks on the pick.
 */
final class Issue274YouActivatedChainTest extends TestCase
{
    private function card(string $no, string $id): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $c) {
            if (($c['card_no'] ?? '') === $no) {
                $c['instance_id'] = $id;
                $c['active'] = true;
                $c['entered_turn'] = 1;
                return $c;
            }
        }
        $this->fail('Missing ' . $no);
    }

    private function member(string $id, int $cost = 3): array
    {
        return ['instance_id' => $id, 'card_no' => 'T-' . $id, 'name_en' => 'M' . $id, 'name' => 'M' . $id, 'card_type' => 'メンバー', 'card_type_en' => 'Member',
            'group' => 'Sunshine', 'cost' => $cost, 'hearts' => [['color' => 'pink', 'count' => 1]], 'blade' => 1];
    }

    private function base(string $otherNo, array $wr, array $hand): array
    {
        $you = $this->card('PL!S-bp7-005-P＋', 'you');
        $other = $this->card($otherNo, 'other');
        $blank = fn(string $id, array $stage, array $hand, array $wr): array => [
            'id' => $id, 'name' => $id, 'hand' => $hand, 'waiting_room' => $wr, 'stage' => $stage, 'energy_zone' => [], 'energy_deck' => [],
            'main_deck' => $id === 'p1' ? array_map(fn($i) => $this->member('d' . $i), range(1, 10)) : [], 'live_zone' => [], 'success_lives' => [],
        ];
        return ['status' => 'playing', 'phase' => 'main_first', 'seq' => 1, 'turn' => 3, 'first_player' => 'p1', 'active_player' => 'p1', 'log' => [],
            'players' => [
                'p1' => $blank('p1', ['left' => $other, 'center' => $you, 'right' => null], $hand, $wr),
                'p2' => $blank('p2', ['left' => null, 'center' => null, 'right' => null], [], []),
            ]];
    }

    private function step(array $state, string $fn, ...$args): array
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            return $fn($state, 'p1', ...$args);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    private function logs(array $state): string
    {
        return implode("\n", array_map(static fn($l) => (string) ($l['msg'] ?? ''), $state['log'] ?? []));
    }

    private function activate(array $wr, array $hand = []): array
    {
        $hand = $hand ?: [$this->member('h1'), $this->member('h2'), $this->member('h3'), $this->member('h4')];
        $state = $this->base('PL!S-bp7-001-R', $wr, $hand);
        $state = $this->step($state, '\actionActivateAbility', ['card_id' => 'you', 'ability_index' => 2]);
        $state = $this->step($state, '\actionResolvePrompt', ['card_ids' => ['h1', 'h2']]);
        return $this->step($state, '\actionResolvePrompt', ['slot' => 'left']);
    }

    private function wr(): array
    {
        return [$this->member('w1', 11), $this->member('w2'), $this->member('w3')];
    }

    public function testPickingTheOtherMemberFiresBothOnEnters(): void
    {
        $state = $this->activate($this->wr());
        $this->assertSame('batch99_stack_wr_member', $state['pending_prompt']['type'] ?? null, 'the first On Enter of You comes first, not the same pick again');
        [$state, $trail] = $this->walk($state);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertArrayNotHasKey('_bp7_deferred_on_enter', $state);
        $this->assertCount(1, $state['players']['p1']['stage']['center']['stacked_members'] ?? [], 'You stacked a Member under herself');
        $log = $this->logs($state);
        $this->assertStringContainsString('re-triggered [On Enter] of Chika Takami', $log, 'the partner Member fires after You');
        $this->assertStringContainsString('added Mw1 to hand', $log, 'the On Enter of Chika resolved');
        $this->assertContains('bp7_pick_cards:add_wr_min_cost_named_pick', $trail);
    }

    public function testDecliningYouStackStillFiresTheOtherMember(): void
    {
        $state = $this->activate($this->wr());
        $this->assertSame('batch99_stack_wr_member', $state['pending_prompt']['type'] ?? null);
        $state = $this->step($state, '\actionResolvePrompt', ['choice' => 'skip']);
        $this->assertSame('bp7_pick_cards', $state['pending_prompt']['type'] ?? null, 'the On Enter of Chika still opens after a skipped stack');
        $this->assertStringContainsString('re-triggered [On Enter] of Chika Takami', $this->logs($state));
    }

    public function testNoWrMemberForYouStillFiresTheOtherMember(): void
    {
        $live = static fn(string $id): array => ['instance_id' => $id, 'card_no' => 'L-' . $id, 'name_en' => 'L' . $id, 'name' => 'L' . $id, 'card_type' => 'ライブ', 'card_type_en' => 'Live', 'group' => 'Sunshine', 'score' => 1];
        $state = $this->activate([], [$live('h1'), $live('h2'), $live('h3'), $live('h4')]);
        $this->assertStringContainsString('re-triggered [On Enter] of Chika Takami', $this->logs($state));
        $this->assertArrayNotHasKey('_bp7_deferred_on_enter', $state);
    }

    public function testDecliningChikaFinishesCleanly(): void
    {
        $state = $this->activate($this->wr());
        $state = $this->step($state, '\actionResolvePrompt', ['card_ids' => ['w2'], 'card_id' => 'w2']);
        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $state = $this->step($state, '\actionResolvePrompt', ['card_ids' => []]);
        $this->assertNull($state['pending_prompt'] ?? null, 'declining the optional discard ends the chain');
        $this->assertArrayNotHasKey('_bp7_chain_prompt', $state);
    }

    public function testOncePerTurn(): void
    {
        $state = $this->activate($this->wr());
        [$state] = $this->walk($state);
        $this->expectException(\Exception::class);
        $this->step($state, '\actionActivateAbility', ['card_id' => 'you', 'ability_index' => 2]);
    }

    /** @return array{0:array,1:list<string>} */
    private function walk(array $state): array
    {
        $trail = [];
        for ($i = 0; $i < 10 && !empty($state['pending_prompt']); $i++) {
            $pr = $state['pending_prompt'];
            $trail[] = (string) ($pr['type'] ?? '') . ':' . (string) ($pr['bp7_action'] ?? '');
            if (($pr['type'] ?? '') === 'batch99_stack_wr_member') {
                // Prefer a low-cost WR mill so Chika's On Enter can still add cost-11 Mw1 (#321 host pick).
                $pick = null;
                foreach ($pr['candidates'] ?? [] as $c) {
                    if (intval($c['cost'] ?? 0) <= 5) {
                        $pick = $c['instance_id'] ?? null;
                        break;
                    }
                }
                $pick = $pick ?: (($pr['candidates'][0]['instance_id'] ?? '') ?: null);
                $data = [
                    'pick_id' => $pick,
                    'target_slot' => $pr['source_slot'] ?? 'center',
                ];
            } else {
                $cands = array_column($pr['candidates'] ?? [], 'instance_id');
                $data = !empty($pr['choices'])
                    ? ['choice' => 'yes', 'card_ids' => array_slice($cands, 0, 1)]
                    : ['card_ids' => array_slice($cands, 0, 1), 'card_id' => $cands[0] ?? ''];
            }
            $state = $this->step($state, '\actionResolvePrompt', $data);
        }
        return [$state, $trail];
    }

    /** #321: On Enter may stack the WR Member under another Stage Member, not only You. */
    public function testOnEnterCanStackUnderAnotherStageMember(): void
    {
        $you = $this->card('PL!S-bp7-005-P', 'you_enter');
        $ally = $this->member('ally', 4);
        $wr = [$this->member('stack_me', 3)];
        $state = [
            'status' => 'playing', 'phase' => 'main_first', 'seq' => 1, 'turn' => 2,
            'first_player' => 'p1', 'active_player' => 'p1', 'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1', 'name' => 'P1', 'hand' => [], 'waiting_room' => $wr,
                    'stage' => ['left' => $ally, 'center' => $you, 'right' => null],
                    'energy_zone' => [], 'main_deck' => [], 'live_zone' => [], 'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2', 'name' => 'P2', 'hand' => [], 'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [], 'main_deck' => [], 'live_zone' => [], 'success_lives' => [],
                ],
            ],
        ];
        $state = \resolveOnEnterAbilities($state, 'p1', $you, 'center');
        $this->assertSame('batch99_stack_wr_member', $state['pending_prompt']['type'] ?? null);
        $this->assertTrue(!empty($state['pending_prompt']['under_any_stage']));
        $state = \actionResolvePrompt($state, 'p1', [
            'pick_id' => 'stack_me',
            'target_slot' => 'left',
        ]);
        $this->assertSame('stack_me', $state['players']['p1']['stage']['left']['stacked_members'][0]['instance_id'] ?? null);
        $this->assertEmpty($state['players']['p1']['stage']['center']['stacked_members'] ?? []);
    }
}
