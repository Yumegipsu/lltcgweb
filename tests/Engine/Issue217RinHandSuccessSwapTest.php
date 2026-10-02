<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-014 Rin — reveal lily white Live from hand ↔ Success Live (#217).
 * Server must accept card_id as well as instance_id (client Success Live picker).
 */
final class Issue217RinHandSuccessSwapTest extends TestCase
{
    private function lilyLive(string $id, int $score = 2): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-bp4-025-L',
            'name_en' => 'Binetsu Kara Mystery',
            'card_type' => 'ライブ',
            'subunit' => 'lily white',
            'group' => "μ's",
            'score' => $score,
        ];
    }

    private function successLive(string $id, int $score = 1): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-bp1-025-L',
            'name_en' => 'Success Live',
            'card_type' => 'ライブ',
            'group' => "μ's",
            'score' => $score,
        ];
    }

    private function rinCard(): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === 'PL!-pb2-014-R') {
                $card['instance_id'] = 'rin_test';
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing PL!-pb2-014-R');
    }

    public function testOnEnterSetsOptionalPromptWhenHandAndSuccessReady(): void
    {
        $rin = $this->rinCard();
        $handLive = $this->lilyLive('hand_lw');
        $succ = $this->successLive('succ1');
        $state = [
            'phase' => 'main',
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$handLive],
                    'success_lives' => [$succ],
                    'waiting_room' => [],
                    'deck' => [],
                    'stage' => ['left' => null, 'center' => $rin, 'right' => null],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'success_lives' => [],
                    'waiting_room' => [],
                    'deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];
        $ab = $rin['abilities'][0] ?? null;
        $this->assertIsArray($ab);
        $this->assertSame('optional_reveal_hand_live_swap_success', $ab['type'] ?? '');
        $out = plMusePb2ResolveEffect($state, 'p1', $rin, $ab);
        $pr = $out['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('optional_reveal_hand_live_swap_success', $pr['type']);
        $this->assertNotEmpty($pr['hand_candidates'] ?? []);
        $this->assertNotEmpty($pr['success_candidates'] ?? []);
    }

    public function testSwapResolvesWithCardIdPayload(): void
    {
        $handLive = $this->lilyLive('hand_lw', 3);
        $succ = $this->successLive('succ1', 1);
        $prompt = [
            'type' => 'pb2_pick_hand_success_swap',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_name' => 'Rin',
            'source_instance_id' => 'rin_test',
            'step' => 'pick_success',
            'hand_instance_id' => 'hand_lw',
            'candidates' => [cardPromptSummary($succ)],
        ];
        $state = [
            'phase' => 'main',
            'seq' => 2,
            'log' => [],
            'pending_prompt' => $prompt,
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$handLive],
                    'success_lives' => [$succ],
                    'waiting_room' => [],
                    'deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'success_lives' => [],
                    'waiting_room' => [],
                    'deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];
        $out = plMusePb2ResolvePrompt($state, 'p1', $prompt, 'succ1', ['card_id' => 'succ1']);
        $this->assertNull($out['pending_prompt'] ?? null);
        $handNos = array_column($out['players']['p1']['hand'], 'instance_id');
        $succNos = array_column($out['players']['p1']['success_lives'], 'instance_id');
        $this->assertContains('succ1', $handNos);
        $this->assertContains('hand_lw', $succNos);
    }
}
