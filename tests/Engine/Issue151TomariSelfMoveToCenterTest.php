<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #151 — Tomari pb2-022 (+4 Blade) must fire when she herself swaps onto Center,
 * including when cost-13 Tomari leaves Center via Live Start position-change.
 */
final class Issue151TomariSelfMoveToCenterTest extends TestCase
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

    public function testSelfMoveToCenterGrantsBlade(): void
    {
        $tomari15 = $this->cardByNo('PL!SP-pb2-022-P＋', 'tomari15');
        $state = $this->baseState();
        $state['phase'] = 'main_first';
        $state['players']['p1']['stage']['center'] = $tomari15;

        $state = \spBp2TriggerMoveToCenterHeart($state, 'p1', $tomari15, 'center');
        $m = $state['players']['p1']['stage']['center'];
        $this->assertSame(4, intval($m['live_blade_bonus'] ?? 0));

        // Once per turn.
        $state = \spBp2TriggerMoveToCenterHeart($state, 'p1', $tomari15, 'center');
        $m = $state['players']['p1']['stage']['center'];
        $this->assertSame(4, intval($m['live_blade_bonus'] ?? 0));
    }

    public function testCost13LeavesCenterSwapGrantsCost15BladeThenOpensChoose(): void
    {
        $tomari13 = $this->cardByNo('PL!SP-pb2-011-PP', 'tomari13');
        $tomari15 = $this->cardByNo('PL!SP-pb2-022-P＋', 'tomari15');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $tomari13;
        $state['players']['p1']['stage']['right'] = $tomari15;
        $state['pending_prompt'] = [
            'type' => 'optional_swap_area_on_enter',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_id' => 'tomari13',
            'source_slot' => 'center',
            'source_name' => 'Tomari Onitsuka',
            'choices' => ['skip', 'left', 'center', 'right'],
            'prompt' => 'Position-change this Member?',
            'ability' => ['trigger' => 'live_start', 'type' => 'optional_swap_area_on_enter'],
        ];

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'right']);

        $this->assertSame('right', \findMemberSlot($state['players']['p1'], 'tomari13'));
        $this->assertSame('center', \findMemberSlot($state['players']['p1'], 'tomari15'));
        $t15 = $state['players']['p1']['stage']['center'];
        $this->assertSame(4, intval($t15['live_blade_bonus'] ?? 0), 'pb2-022 must gain +4 when she lands on Center');
        // Cost-13 Center-leave choose should still open after.
        $this->assertSame('spbp2_center_move_choose', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('tomari13', $state['pending_prompt']['source_id'] ?? null);
    }

    public function testCatalogRUsesBladeNotHeart(): void
    {
        $card = $this->cardByNo('PL!SP-pb2-022-R', 'tomari_r');
        $ab = ($card['abilities'] ?? [])[0] ?? [];
        $this->assertSame('auto_on_move_to_center_subunit_blade', $ab['type'] ?? null);
        $this->assertSame(4, intval($ab['blade'] ?? 0));
        $this->assertTrue(!empty($ab['once_per_turn']));
        $this->assertStringContainsString('Once per turn', (string)($card['text'] ?? ''));
    }
}
