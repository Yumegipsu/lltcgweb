<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-PR-007-PR (Nozomi) [On Enter]/[Live Start] optional_wait_self_wait_opp
 * must resume Live Start after skip or auto-wait — otherwise the phase softlocks
 * with no pending_prompt and the client loops / never reaches Performance.
 */
final class NozomiPr007LiveStartResumeTest extends TestCase
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

    private function member(string $id, int $cost = 3): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => $id,
            'cost' => $cost,
            'active' => true,
            'blade' => 1,
            'hearts' => [],
            'abilities' => [],
        ];
    }

    private function live(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => $id,
            'score' => 1,
            'revealed' => true,
            'abilities' => [],
        ];
    }

    private function baseState(array $oppStage): array
    {
        $nozomi = $this->cardByNo('PL!-PR-007-PR', 'nozo_center');
        $this->assertSame('optional_wait_self_wait_opp', $nozomi['abilities'][0]['type'] ?? null);
        $this->assertSame('on_enter_or_live_start', $nozomi['abilities'][0]['trigger'] ?? null);

        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 10,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $nozomi,
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [$this->live('live_p1')],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => $oppStage,
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [$this->live('live_p2')],
                ],
            ],
        ];
    }

    public function testSkipOptionalWaitSelfResumesLiveStart(): void
    {
        $state = $this->baseState([
            'left' => $this->member('opp_l', 3),
            'center' => $this->member('opp_c', 2),
            'right' => null,
        ]);
        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame('optional_wait_self', $state['pending_prompt']['type'] ?? null);
        $this->assertTrue(!empty($state['pending_prompt']['live_start']));

        $state = \applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'no']);

        $this->assertNotSame('live_start_effects', $state['phase'] ?? null,
            'Skipping Nozo Live Start must leave live_start_effects');
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testYesWithSingleTargetAutoWaitResumesLiveStart(): void
    {
        $state = $this->baseState([
            'left' => $this->member('opp_only', 3),
            'center' => null,
            'right' => null,
        ]);
        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame('optional_wait_self', $state['pending_prompt']['type'] ?? null);

        $state = \applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'yes']);

        // Active Phase may clear Wait markers when Live Start finishes into a new turn,
        // so assert resume + log rather than leftover in_wait flags.
        $state = \tcgTestPickOnlyOption($state); // picks always prompt now, even with one option
        $logText = json_encode($state['log'] ?? []);
        $this->assertTrue(
            str_contains($logText, 'put 1 opponent Stage Member')
            || str_contains($logText, 'into Wait (cost')
            || str_contains($logText, 'opp_only into Wait'),
            'Expected opponent Wait outcome in log'
        );
        $this->assertNotSame('live_start_effects', $state['phase'] ?? null,
            'Auto-wait after Yes must resume / leave live_start_effects');
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testYesWithMultipleTargetsThenPickResumes(): void
    {
        $state = $this->baseState([
            'left' => $this->member('opp_l', 3),
            'center' => $this->member('opp_c', 2),
            'right' => null,
        ]);
        $state = \resolveLiveStartAbilities($state, 'p1');
        $state = \applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'yes']);
        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);

        $slot = $state['pending_prompt']['candidates'][0]['slot'] ?? 'left';
        $state = \applyAction($state, 'p1', 'resolve_prompt', ['slot' => $slot]);

        $this->assertNotSame('live_start_effects', $state['phase'] ?? null);
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testLiveStartSkippedWhenNoLegalOppTargets(): void
    {
        $state = $this->baseState([
            'left' => $this->member('opp_high', 9),
            'center' => null,
            'right' => null,
        ]);
        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertNotSame('optional_wait_self', $state['pending_prompt']['type'] ?? null);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertFalse(\memberIsInWait($state['players']['p1']['stage']['center']));
    }
}
