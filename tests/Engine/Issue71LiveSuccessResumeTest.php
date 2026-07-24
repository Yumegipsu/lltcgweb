<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #71: Live Success must resume after prompts so Stage Members (Natsumi)
 * and additional Lives (multiple Kimi no Kokoro / Daydream Mermaid) still fire.
 */
final class Issue71LiveSuccessResumeTest extends TestCase
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

    private function handFill(int $n, string $prefix = 'h'): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'instance_id' => $prefix . $i,
                'card_type' => 'メンバー',
                'name_en' => 'Hand ' . $i,
                'cost' => 1,
            ];
        }
        return $out;
    }

    private function deckFill(int $n, string $prefix = 'd'): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'instance_id' => $prefix . $i,
                'card_type' => 'メンバー',
                'name_en' => 'Deck ' . $i,
                'cost' => 1,
            ];
        }
        return $out;
    }

    private function baseState(array $successLives, array $natsumi = null): array
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
                    'hand' => $this->handFill(2),
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $natsumi,
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'energy_deck' => [
                        ['instance_id' => 'en0', 'card_type' => 'エネルギー', 'active' => true],
                    ],
                    'main_deck' => $this->deckFill(10),
                    'success_lives' => [],
                    'live_zone' => $successLives,
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

    private function discardOne(array $state): array
    {
        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $handId = $state['players']['p1']['hand'][0]['instance_id'] ?? '';
        $this->assertNotSame('', $handId);
        return \actionResolvePrompt($state, 'p1', ['discard_ids' => [$handId]]);
    }

    public function testNatsumiFiresAfterKimiNoKokoroDiscard(): void
    {
        $kimi = $this->cardByNo('PL!S-bp2-024-L', 'kimi1');
        $natsumi = $this->cardByNo('PL!SP-bp2-009-P', 'natsumi');
        $state = $this->baseState([$kimi], $natsumi);

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$kimi], 0, [], []);
        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertNotEmpty($state['_live_success_ctx'] ?? null);

        $state = $this->discardOne($state);

        // After Kimi discard, Natsumi's Live Success draw/discard must open.
        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertStringContainsString('Natsumi', (string)($state['pending_prompt']['source_name'] ?? ''));

        $state = $this->discardOne($state);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertEmpty($state['_live_success_ctx'] ?? null);
    }

    public function testNatsumiFiresAfterDaydreamMermaidPick(): void
    {
        $mermaid = $this->cardByNo('PL!N-bp4-030-L', 'mermaid1');
        $natsumi = $this->cardByNo('PL!SP-bp2-009-P', 'natsumi2');
        $state = $this->baseState([$mermaid], $natsumi);
        $state['players']['p1']['waiting_room'] = [[
            'instance_id' => 'wr_m',
            'card_type' => 'メンバー',
            'name_en' => 'WR Member',
            'cost' => 2,
        ]];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$mermaid], 0, [], []);
        $this->assertSame('live_success_pick_energy_or_member', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'energy']);

        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertStringContainsString('Natsumi', (string)($state['pending_prompt']['source_name'] ?? ''));
    }

    public function testMultipleKimiNoKokoroEachFire(): void
    {
        $k1 = $this->cardByNo('PL!S-bp2-024-L', 'kimi_a');
        $k2 = $this->cardByNo('PL!S-bp2-024-L', 'kimi_b');
        $k3 = $this->cardByNo('PL!S-bp2-024-L', 'kimi_c');
        $state = $this->baseState([$k1, $k2, $k3], null);
        // Enough deck/hand for 3× (draw 2, discard 1).
        $state['players']['p1']['hand'] = $this->handFill(1, 'seed');
        $state['players']['p1']['main_deck'] = $this->deckFill(20);

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$k1, $k2, $k3], 0, [], []);
        $discards = 0;
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(
                'effect_discard_hand',
                $state['pending_prompt']['type'] ?? null,
                "Expected discard prompt for Kimi copy #" . ($i + 1)
            );
            $discards++;
            $state = $this->discardOne($state);
        }
        $this->assertSame(3, $discards);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertEmpty($state['_live_success_ctx'] ?? null);
    }

    public function testKimiThenMermaidThenNatsumiChain(): void
    {
        $kimi = $this->cardByNo('PL!S-bp2-024-L', 'kimi_chain');
        $mermaid = $this->cardByNo('PL!N-bp4-030-L', 'mermaid_chain');
        $natsumi = $this->cardByNo('PL!SP-bp2-009-P', 'natsumi_chain');
        $state = $this->baseState([$kimi, $mermaid], $natsumi);
        $state['players']['p1']['main_deck'] = $this->deckFill(20);

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$kimi, $mermaid], 0, [], []);
        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $state = $this->discardOne($state);

        $this->assertSame('live_success_pick_energy_or_member', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'skip']);

        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertStringContainsString('Natsumi', (string)($state['pending_prompt']['source_name'] ?? ''));
    }
}
