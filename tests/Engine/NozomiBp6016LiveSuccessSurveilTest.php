<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!-bp6-016-N Nozomi — Live Success rearrange all 3 on deck top (no WR mill). */
final class NozomiBp6016LiveSuccessSurveilTest extends TestCase
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

    private function stubCard(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'LL-E-001-SD',
            'name_en' => $id,
            'card_type' => 'エネルギー',
        ];
    }

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_success_effects',
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

    public function testLiveSuccessOpensReturnAllSurveilPrompt(): void
    {
        $nozomi = $this->cardByNo('PL!-bp6-016-N', 'bp6_nozomi');
        $this->assertTrue(!empty($nozomi['abilities'][0]['return_all']));

        $a = $this->stubCard('bp6_noz_a');
        $b = $this->stubCard('bp6_noz_b');
        $c = $this->stubCard('bp6_noz_c');
        $rest = $this->stubCard('bp6_noz_rest');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $nozomi;
        $state['players']['p1']['main_deck'] = [$a, $b, $c, $rest];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [], 0, [], []);

        $this->assertSame('surveil_arrange', $state['pending_prompt']['type'] ?? null);
        $this->assertTrue(!empty($state['pending_prompt']['return_all']));
        $this->assertStringContainsString('put them back on top in any order', $state['pending_prompt']['prompt'] ?? '');
        $this->assertStringNotContainsString('Waiting Room', $state['pending_prompt']['prompt'] ?? '');
        $this->assertCount(3, $state['pending_prompt']['looked_cards'] ?? []);
        $this->assertSame(['bp6_noz_rest'], array_column($state['players']['p1']['main_deck'], 'instance_id'));
    }

    public function testReturnAllRejectsWaitingRoomAssignment(): void
    {
        $nozomi = $this->cardByNo('PL!-bp6-016-N', 'bp6_nozomi2');
        $a = $this->stubCard('bp6_noz2_a');
        $b = $this->stubCard('bp6_noz2_b');
        $c = $this->stubCard('bp6_noz2_c');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $nozomi;
        $state['players']['p1']['main_deck'] = [$a, $b, $c];
        $state = \resolveLiveSuccessAbilities($state, 'p1', [], 0, [], []);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('All looked cards must be returned to the top of the deck');
        \actionResolvePrompt($state, 'p1', [
            'choice' => 'confirm',
            'top_ids' => ['bp6_noz2_a', 'bp6_noz2_b'],
            'wr_ids' => ['bp6_noz2_c'],
        ]);
    }

    public function testReturnAllRearrangePutsAllBackOnTop(): void
    {
        $nozomi = $this->cardByNo('PL!-bp6-016-N', 'bp6_nozomi3');
        $a = $this->stubCard('bp6_noz3_a');
        $b = $this->stubCard('bp6_noz3_b');
        $c = $this->stubCard('bp6_noz3_c');
        $rest = $this->stubCard('bp6_noz3_rest');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $nozomi;
        $state['players']['p1']['main_deck'] = [$a, $b, $c, $rest];
        $state = \resolveLiveSuccessAbilities($state, 'p1', [], 0, [], []);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'confirm',
            'top_ids' => ['bp6_noz3_c', 'bp6_noz3_a', 'bp6_noz3_b'],
            'wr_ids' => [],
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(
            ['bp6_noz3_c', 'bp6_noz3_a', 'bp6_noz3_b', 'bp6_noz3_rest'],
            array_column($state['players']['p1']['main_deck'], 'instance_id')
        );
        $this->assertSame([], $state['players']['p1']['waiting_room']);
    }
}
