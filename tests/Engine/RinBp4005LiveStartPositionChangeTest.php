<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!-bp4-005 position-change is Live Start, not On Enter (official JP live_start). */
final class RinBp4005LiveStartPositionChangeTest extends TestCase
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

    private function filler(string $id, string $slotGroup = 'Aqours'): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!S-sd1-001-SD',
            'name_en' => 'Filler',
            'card_type' => 'メンバー',
            'group' => $slotGroup,
            'cost' => 3,
            'blade' => 1,
            'active' => true,
        ];
    }

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
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
                    'live_zone' => [['instance_id' => 'live1', 'card_type' => 'ライブ', 'score' => 1]],
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

    public function testCatalogMarksPositionChangeAsLiveStart(): void
    {
        $rin = $this->cardByNo('PL!-bp4-005-SEC', 'rin_sec');
        $this->assertSame('live_start', $rin['abilities'][2]['trigger'] ?? null);
        $this->assertSame('position_change_off_center', $rin['abilities'][2]['type'] ?? null);
        $this->assertStringContainsString('[Live Start]', $rin['text'] ?? '');
        $this->assertStringNotContainsString(
            "[Always] [Center] Your Live total score +1.\n[On Enter] If you have no",
            $rin['text'] ?? ''
        );
    }

    public function testOnEnterDoesNotPositionChange(): void
    {
        $rin = $this->cardByNo('PL!-bp4-005-SEC', 'rin_enter');
        $side = $this->filler('side_m');

        $state = $this->baseState();
        $state['phase'] = 'main_first';
        $state['players']['p1']['stage']['center'] = $rin;
        $state['players']['p1']['stage']['left'] = $side;

        $state = \resolveOnEnterAbilities($state, 'p1', $rin, 'center');
        $this->assertSame('rin_enter', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $this->assertSame('side_m', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
    }

    public function testLiveStartPositionChangesOffCenterWhenNoHighBladeMuse(): void
    {
        $rin = $this->cardByNo('PL!-bp4-005-SEC', 'rin_live');
        $side = $this->filler('side_live');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $rin;
        $state['players']['p1']['stage']['left'] = $side;

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame('rin_live', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertSame('side_live', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
    }

    public function testLiveStartSkipsWhenStageHasHighBladeMuse(): void
    {
        $rin = $this->cardByNo('PL!-bp4-005-SEC', 'rin_keep');
        $honoka = [
            'instance_id' => 'honoka5',
            'card_no' => 'PL!-bp4-010-P',
            'name_en' => 'Honoka Kosaka',
            'card_type' => 'メンバー',
            'group' => "μ's",
            'cost' => 15,
            'blade' => 5,
            'active' => true,
        ];

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $rin;
        $state['players']['p1']['stage']['left'] = $honoka;

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame('rin_keep', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $this->assertSame('honoka5', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
    }
}
