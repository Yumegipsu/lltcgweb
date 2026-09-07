<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #165 — Colorful Dreams Live Success Order of Activation must not loop
 * when the Order prompt is force-dismissed / resumed without order_ids.
 */
final class Issue165LiveSuccessOrderDismissLoopTest extends TestCase
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

    private function yellThreeColors(): array
    {
        return [
            [
                'instance_id' => 'y1',
                'card_type' => 'メンバー',
                'blade_hearts' => ['pink'],
                'hearts' => [['color' => 'pink', 'count' => 1]],
            ],
            [
                'instance_id' => 'y2',
                'card_type' => 'メンバー',
                'blade_hearts' => ['red'],
                'hearts' => [['color' => 'red', 'count' => 1]],
            ],
            [
                'instance_id' => 'y3',
                'card_type' => 'メンバー',
                'blade_hearts' => ['yellow'],
                'hearts' => [['color' => 'yellow', 'count' => 1]],
            ],
        ];
    }

    private function baseState(array $lives, array $yell): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            '_performance_continue' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [
                        ['instance_id' => 'h0', 'card_type' => 'メンバー', 'name_en' => 'H', 'cost' => 1],
                        ['instance_id' => 'h1', 'card_type' => 'メンバー', 'name_en' => 'H2', 'cost' => 1],
                    ],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => array_fill(0, 10, ['instance_id' => 'd', 'card_type' => 'メンバー']),
                    'success_lives' => [],
                    'live_zone' => $lives,
                    '_pending_yell_wr' => $yell,
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

    public function testForceDismissOrderAppliesDefaultAndDoesNotReopen(): void
    {
        $cd = $this->cardByNo('PL!N-bp7-025-L', 'cd1');
        $kimi = $this->cardByNo('PL!S-bp2-024-L', 'kimi1');
        $yell = $this->yellThreeColors();
        $state = $this->baseState([$cd, $kimi], $yell);

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$cd, $kimi], 0, [], $yell);
        $this->assertSame('live_success_order_sources', $state['pending_prompt']['type'] ?? null);

        $after = \forceDismissPendingPromptForPlayer($state, 'p1', 'test');
        $this->assertNotSame(
            'live_success_order_sources',
            $after['pending_prompt']['type'] ?? null,
            'Dismissing Order must apply default order and continue, not reopen'
        );
        // Colorful Dreams (default first) should have scored +1.
        $cdNow = null;
        foreach ($after['players']['p1']['live_zone'] as $lc) {
            if (($lc['instance_id'] ?? '') === 'cd1') {
                $cdNow = $lc;
                break;
            }
        }
        $this->assertNotNull($cdNow);
        $this->assertSame(2, intval($cdNow['score'] ?? 0));
    }

    public function testColorfulDreamsOrderConfirmDoesNotReopen(): void
    {
        $cd = $this->cardByNo('PL!N-bp7-025-L', 'cd1');
        $kimi = $this->cardByNo('PL!S-bp2-024-L', 'kimi1');
        $yell = $this->yellThreeColors();
        $state = $this->baseState([$cd, $kimi], $yell);

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$cd, $kimi], 0, [], $yell);
        $ids = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertSame(['cd1', 'kimi1'], $ids);

        $state = \actionResolvePrompt($state, 'p1', ['card_ids' => $ids]);
        $this->assertNotSame('live_success_order_sources', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(2, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));
    }

    public function testResumeWithoutOrderIdsDoesNotReopenOrder(): void
    {
        $cd = $this->cardByNo('PL!N-bp7-025-L', 'cd1');
        $kimi = $this->cardByNo('PL!S-bp2-024-L', 'kimi1');
        $yell = $this->yellThreeColors();
        $state = $this->baseState([$cd, $kimi], $yell);

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$cd, $kimi], 0, [], $yell);
        $this->assertSame('live_success_order_sources', $state['pending_prompt']['type'] ?? null);
        // Mid-resume without order_ids (lost choice) — must default, not reopen.
        unset($state['pending_prompt']);
        unset($state['_live_success_ctx']['order_ids']);
        $this->assertTrue(!empty($state['_live_success_resume']));

        $state = \resumeLiveSuccessEffectPhase($state);
        $this->assertNotSame('live_success_order_sources', $state['pending_prompt']['type'] ?? null);
    }
}
