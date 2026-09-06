<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #160 — swapping Center pb2 Tomari with Left bp4 Tomari must fire BOTH:
 * bp4 Wait pick AND pb2 Center-move choose (once per turn).
 */
final class Issue160TomariCenterSwapChooseTest extends TestCase
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

    private function withPrintedBlade(array $card, int $blade): array
    {
        $card['blade'] = $blade;
        return $card;
    }

    private function emptyPlayer(string $id): array
    {
        return [
            'id' => $id,
            'name' => strtoupper($id),
            'hand' => [],
            'waiting_room' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'energy_zone' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $this->emptyPlayer('p1'),
                'p2' => $this->emptyPlayer('p2'),
            ],
        ];
    }

    public function testPb2LiveStartSwapWithBp4OpensWaitThenCenterChoose(): void
    {
        $tomariPb2 = $this->cardByNo('PL!SP-pb2-011-PP', 'tomari_pb2');
        $tomariBp4 = $this->cardByNo('PL!SP-bp4-011-P', 'tomari_bp4');
        $oppLow = $this->withPrintedBlade($this->cardByNo('PL!HS-sd1-015-SD', 'opp_low'), 2);
        $oppLow2 = $this->withPrintedBlade($this->cardByNo('PL!HS-bp5-008-R', 'opp_low2'), 1);

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $tomariPb2;
        $state['players']['p1']['stage']['left'] = $tomariBp4;
        $state['players']['p2']['stage']['left'] = $oppLow;
        $state['players']['p2']['stage']['right'] = $oppLow2;
        $state['pending_prompt'] = [
            'type' => 'optional_swap_area_on_enter',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_id' => 'tomari_pb2',
            'source_slot' => 'center',
            'source_name' => 'Tomari Onitsuka',
            'choices' => ['skip', 'left', 'center', 'right'],
            'prompt' => 'Position-change this Member?',
            'ability' => ['trigger' => 'live_start', 'type' => 'optional_swap_area_on_enter'],
        ];

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'left']);

        $this->assertSame('left', \findMemberSlot($state['players']['p1'], 'tomari_pb2'));
        $this->assertSame('center', \findMemberSlot($state['players']['p1'], 'tomari_bp4'));

        // bp4 Wait pick opens first (displaced Member).
        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertNotEmpty($state['_deferred_area_move_abilities'] ?? null, 'pb2 Center-leave must be deferred');

        $state = \actionResolvePrompt($state, 'p1', ['slots' => ['left']]);
        $this->assertTrue(\memberIsInWait($state['players']['p2']['stage']['left']));

        // After Wait resolves, pb2 Center-move choose must still open (#160).
        $this->assertSame('spbp2_center_move_choose', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('tomari_pb2', $state['pending_prompt']['source_id'] ?? null);
    }

    public function testBp4LeavesCenterStillOpensPb2ChooseBeforeWait(): void
    {
        $tomariPb2 = $this->cardByNo('PL!SP-pb2-011-PP', 'tomari_pb2_obs');
        $tomariBp4 = $this->cardByNo('PL!SP-bp4-011-P', 'tomari_bp4_ctr');
        $oppLow = $this->withPrintedBlade($this->cardByNo('PL!HS-sd1-015-SD', 'opp_a'), 2);
        $oppLow2 = $this->withPrintedBlade($this->cardByNo('PL!HS-bp5-008-R', 'opp_b'), 1);

        $state = $this->baseState();
        $state['phase'] = 'main_first';
        $state['players']['p1']['stage']['center'] = $tomariBp4;
        $state['players']['p1']['stage']['left'] = $tomariPb2;
        $state['players']['p2']['stage']['left'] = $oppLow;
        $state['players']['p2']['stage']['right'] = $oppLow2;

        $state = \applyStagePositionChange($state, 'p1', 'center', 'right');

        // Center left → pb2 choose first; bp4 Wait deferred.
        $this->assertSame('spbp2_center_move_choose', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('tomari_pb2_obs', $state['pending_prompt']['source_id'] ?? null);
        $this->assertNotEmpty($state['_deferred_area_move_abilities'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'draw']);
        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
    }
}
