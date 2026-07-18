<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class ShiorikoBp4010SwapTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function live(string $id, string $name): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'TEST-' . $id,
            'name' => $name,
            'name_en' => $name,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Nijigasaki',
            'score' => 1,
        ];
    }

    private function baseState(array $shioriko): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [$this->live('wr_live', 'Waiting Live')],
                    'stage' => ['left' => null, 'center' => $shioriko, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [$this->live('success_live', 'Success Live')],
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

    public function testSwapKeepsSourceContextAndUsesEachCorrectZone(): void
    {
        $shioriko = $this->cardByNo('PL!N-bp4-010-P', 'shioriko_bp4_010');
        $state = $this->baseState($shioriko);

        $state = \resolveOnEnterAbilities($state, 'p1', $shioriko, 'center');
        $this->assertSame('optional_success_wr_live_swap', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('confirm', $state['pending_prompt']['step'] ?? null);
        $this->assertSame('shioriko_bp4_010', $state['pending_prompt']['source_id'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertSame('pick_success_live', $state['pending_prompt']['step'] ?? null);
        $this->assertSame(['success_live'], array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id'));
        $this->assertSame('shioriko_bp4_010', $state['pending_prompt']['source_id'] ?? null);
        $this->assertSame('optional_success_wr_live_swap', $state['pending_prompt']['ability']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'success_live']);
        $this->assertSame('pick_wr_live', $state['pending_prompt']['step'] ?? null);
        $this->assertContains('wr_live', array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id'));
        $this->assertSame('shioriko_bp4_010', $state['pending_prompt']['source_id'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'wr_live']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(['wr_live'], array_column($state['players']['p1']['success_lives'], 'instance_id'));
        $this->assertSame(['success_live'], array_column($state['players']['p1']['waiting_room'], 'instance_id'));
    }

    public function testClientRoutesSuccessStepToSuccessLivePicker(): void
    {
        $renderer = (string)file_get_contents(dirname(__DIR__, 2) . '/client/js/prompt-renderer.js');
        $this->assertMatchesRegularExpression(
            "/pr\\.step==='pick_success_live'[\\s\\S]*openSuccessLiveAreaPick\\(pr/",
            $renderer
        );
    }
}
