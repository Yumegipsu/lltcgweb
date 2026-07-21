<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression: PL!N-bp1-009-R On Enter optional discard/mill/WR pick (issue #46). */
final class Issue46RinaBp1009OnEnterTest extends TestCase
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

    /** @return array{0: array, 1: array, 2: array, 3: array, 4: array} */
    private function rinaSetup(bool $withExtraWrMembers = false): array
    {
        $rina = $this->cardByNo('PL!N-bp1-009-R', 'issue46_rina_stage');
        $discard = $this->cardByNo('LL-E-001-SD', 'issue46_discard');
        $millMember = $this->cardByNo('PL!N-bp1-002-P', 'issue46_mill_member');
        $millOther = $this->cardByNo('PL!N-bp1-003-P', 'issue46_mill_other');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $rina;
        $state['players']['p1']['hand'] = [$discard];
        $state['players']['p1']['main_deck'] = [$millMember, $millOther];

        if ($withExtraWrMembers) {
            $wrA = $this->cardByNo('PL!N-bp1-004-R', 'issue46_wr_a');
            $wrB = $this->cardByNo('PL!N-bp1-005-R', 'issue46_wr_b');
            $state['players']['p1']['waiting_room'] = [$wrA, $wrB];
        }

        return [$state, $rina, $discard, $millMember, $millOther];
    }

    public function testOnEnterOpensOptionalDiscardMillWrAddMember(): void
    {
        [$state] = $this->rinaSetup();

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');

        $this->assertSame('optional_discard_mill_wr_add_member', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(['yes', 'no'], $state['pending_prompt']['choices'] ?? null);
        $this->assertSame(1, $state['pending_prompt']['discard_count'] ?? null);
    }

    public function testNoSkipsWithoutError(): void
    {
        [$state] = $this->rinaSetup();

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertCount(1, $state['players']['p1']['hand']);
        $this->assertEmpty($state['players']['p1']['waiting_room']);
        $this->assertCount(2, $state['players']['p1']['main_deck']);
    }

    public function testYesDiscardMillOpensMemberWrPick(): void
    {
        [$state, , $discard, $millMember] = $this->rinaSetup();

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => [$discard['instance_id']],
        ]);

        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(1, $state['pending_prompt']['pick_count'] ?? null);
        $this->assertSame('member', $state['pending_prompt']['wr_pick_cfg']['filter'] ?? null);
        $candidateIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains($millMember['instance_id'], $candidateIds);
        $this->assertNotContains($discard['instance_id'], $candidateIds);
        $this->assertEmpty($state['players']['p1']['hand']);
        $this->assertEmpty($state['players']['p1']['main_deck']);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains($discard['instance_id'], $wrIds);
        $this->assertContains($millMember['instance_id'], $wrIds);
    }

    public function testFullChainPickMemberAddsToHand(): void
    {
        [$state, , $discard, $millMember] = $this->rinaSetup();

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => [$discard['instance_id']],
        ]);
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => $millMember['instance_id']]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains($millMember['instance_id'], $handIds);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertNotContains($millMember['instance_id'], $wrIds);
    }

    public function testTimeoutOnOptionalPromptReturnsNo(): void
    {
        [$state] = $this->rinaSetup();

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');
        $prompt = $state['pending_prompt'] ?? [];

        $data = \buildTimeoutPromptResolution($state, 'p1', $prompt);
        $this->assertSame('no', $data['choice'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', $data);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertCount(1, $state['players']['p1']['hand']);
    }

    public function testMultipleWrMembersAllCandidates(): void
    {
        [$state, , $discard] = $this->rinaSetup(true);

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => [$discard['instance_id']],
        ]);

        $candidateIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains('issue46_wr_a', $candidateIds);
        $this->assertContains('issue46_wr_b', $candidateIds);
        $this->assertContains('issue46_mill_member', $candidateIds);
        $this->assertGreaterThanOrEqual(3, count($candidateIds));
    }

    public function testYesCoercesStringDiscardIds(): void
    {
        [$state, , $discard, $millMember] = $this->rinaSetup();

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => $discard['instance_id'],
        ]);

        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $candidateIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains($millMember['instance_id'], $candidateIds);
        $this->assertEmpty($state['players']['p1']['hand']);
    }

    public function testSkipAliasResolvesNo(): void
    {
        [$state] = $this->rinaSetup();

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'skip']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertCount(1, $state['players']['p1']['hand']);
        $this->assertEmpty($state['players']['p1']['waiting_room']);
        $this->assertCount(2, $state['players']['p1']['main_deck']);
    }
}
