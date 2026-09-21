<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #196: two Stage copies of cost-15 Ceras (bp6-007) must each force an
 * opponent Wait when Retrofuture plays an Edel Note Member from Waiting Room.
 */
final class Issue196DualCerasRetrofutureWaitTest extends TestCase
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

    private function emptyPlayer(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'hand' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'waiting_room' => [],
            'energy_zone' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    public function testRetrofutureWrPlayTriggersBothCerasWaits(): void
    {
        $cerasL = $this->cardByNo('PL!HS-bp6-007-P', 'ceras_l');
        $cerasR = $this->cardByNo('PL!HS-bp6-007-R', 'ceras_r');
        // Cost-4 Edel Note with no On Enter — isolates Auto Wait chain.
        $edelWr = $this->cardByNo('PL!HS-sd1-007-SD', 'edel_wr');
        $retro = $this->cardByNo('PL!HS-bp5-022-L', 'retro');
        $oppA = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_a');
        $oppB = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_b');
        $oppC = $this->cardByNo('PL!HS-sd1-015-SD', 'opp_c');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['left'] = $cerasL;
        $p1['stage']['right'] = $cerasR;
        $p1['waiting_room'] = [$edelWr];
        $p1['live_zone'] = [$retro];
        $p1['energy_zone'] = array_map(
            static fn(int $i): array => ['instance_id' => "e$i", 'active' => true],
            range(0, 5)
        );

        $p2 = $this->emptyPlayer('p2', 'P2');
        $p2['stage'] = [
            'left' => $oppA,
            'center' => $oppB,
            'right' => $oppC,
        ];

        $state = [
            'room_id' => 'ISSUE196',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            '_live_start_perf_pid' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $then = $retro['abilities'][0]['then'];
            $state = resolveAbilityEffect($state, 'p1', $retro, $then, [
                'phase' => 'live_start',
            ]);
            $this->assertSame('live_start_edel_choice', $state['pending_prompt']['type'] ?? null);

            $state = applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'play']);
            $this->assertSame('live_start_edel_play_wr', $state['pending_prompt']['type'] ?? null);

            $state = applyAction($state, 'p1', 'resolve_prompt', ['card_id' => 'edel_wr']);
            $this->assertSame('edel_wr', $state['players']['p1']['stage']['center']['instance_id'] ?? null);

            // First Ceras Auto — opponent chooses Wait.
            $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
            $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);
            $this->assertNotEmpty($state['_resume_hs_auto_on_other_enter'] ?? null);

            $state = applyAction($state, 'p2', 'resolve_prompt', ['slot' => 'left']);
            $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['left']));

            // Second Ceras must open another Wait pick (was dropped under Live Start #196).
            $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
            $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);

            $state = applyAction($state, 'p2', 'resolve_prompt', ['slot' => 'center']);
            $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['center']));
            $this->assertFalse(memberIsInWait($state['players']['p2']['stage']['right']));
            // Live Start may continue with other prompts; both Auto Waits must already apply.
            $this->assertNotSame(
                'wait_opponent_stage_pick',
                $state['pending_prompt']['type'] ?? null,
                'Both Cerases already resolved; no third Wait pick expected'
            );
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
