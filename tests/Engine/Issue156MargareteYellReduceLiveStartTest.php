<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!SP-bp2-010 Wien Margarete — yell-reveal −8 is Live Start (JP), not Always (#156).
 */
final class Issue156MargareteYellReduceLiveStartTest extends TestCase
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
            'main_deck' => array_map(
                static fn(int $i): array => [
                    'instance_id' => $id . '_d' . $i,
                    'card_type' => 'メンバー',
                    'name_en' => 'Deck' . $i,
                    'blade' => 1,
                ],
                range(0, 19)
            ),
            'energy_deck' => [],
            'live_zone' => [[
                'instance_id' => $id . '_live',
                'card_type' => 'ライブ',
                'card_no' => 'PL!SP-bp2-026-L',
                'name_en' => 'Test Live',
                'score' => 1,
                'revealed' => true,
                'required_hearts' => [['color' => 'red', 'count' => 1]],
            ]],
            'success_lives' => [],
        ];
    }

    private function baseState(array $p1, array $p2): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 4,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];
    }

    public function testAbilityIrIsLiveStartNotContinuous(): void
    {
        $wien = $this->cardByNo('PL!SP-bp2-010-P', 'wien_ir');
        $this->assertSame('continuous', $wien['abilities'][0]['trigger'] ?? null);
        $this->assertSame('continuous_opp_live_gray_heart', $wien['abilities'][0]['type'] ?? null);
        $this->assertSame('live_start', $wien['abilities'][1]['trigger'] ?? null);
        $this->assertSame('reduce_yell_reveal_count', $wien['abilities'][1]['type'] ?? null);
        $this->assertStringContainsString('[Live Start]', (string)($wien['text'] ?? ''));
        $this->assertDoesNotMatchRegularExpression(
            '/\[Always\].*\[Always\]/s',
            (string)($wien['text'] ?? '')
        );
    }

    public function testLiveStartWithAllyAppliesYellReduction(): void
    {
        $wien = $this->cardByNo('PL!SP-bp2-010-P', 'wien_ls');
        $ally = $this->cardByNo('PL!SP-bp2-001-P', 'ally_ls');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $wien;
        $p1['stage']['left'] = $ally;
        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));

        $state = \resolveLiveStartAbilities($state, 'p1');
        // Drain any optional Live Start prompts from other cards.
        $guard = 0;
        while (!empty($state['pending_prompt']) && $guard++ < 20) {
            $pr = $state['pending_prompt'];
            $choice = ($pr['choices'][0] ?? null) === 'yes' ? 'no' : ($pr['choices'][0] ?? 'skip');
            try {
                $state = \actionResolvePrompt($state, 'p1', ['choice' => $choice]);
            } catch (\Throwable $e) {
                unset($state['pending_prompt']);
                break;
            }
        }

        $this->assertSame(
            8,
            intval($state['live_modifiers']['p1']['yell_reveal_reduction'] ?? 0)
        );
    }

    public function testLiveStartSoloDoesNotReduceYell(): void
    {
        $wien = $this->cardByNo('PL!SP-bp2-010-P', 'wien_solo');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $wien;
        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));

        $state = \resolveLiveStartAbilities($state, 'p1');
        $guard = 0;
        while (!empty($state['pending_prompt']) && $guard++ < 20) {
            unset($state['pending_prompt']);
            break;
        }

        $this->assertSame(
            0,
            intval($state['live_modifiers']['p1']['yell_reveal_reduction'] ?? 0)
        );
    }
}
