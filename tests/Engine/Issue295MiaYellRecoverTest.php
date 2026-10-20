<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #295 — PL!N-bp7-011 Mia: deck→WR auto also fires when she is Yelled
 * into the Waiting Room (not only when milled).
 */
final class Issue295MiaYellRecoverTest extends TestCase
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

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 4,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            '_performance_continue' => 'p1',
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];
    }

    public function testYellFlushOffersMiaSelfMilledRecover(): void
    {
        $mia = $this->cardByNo('PL!N-bp7-011-P', 'issue295_mia');
        $this->assertSame('auto_self_milled_discard_recover', $mia['abilities'][0]['type'] ?? null);

        $state = $this->baseState();
        // Resolve the recover chain outside Live Success so finishPromptEffects
        // does not advance Performance and reshuffle the discard into the deck.
        $state['phase'] = 'main_first';
        unset($state['_performance_continue']);
        $state['players']['p1']['hand'] = [
            ['instance_id' => 'h_disc', 'card_type' => 'メンバー', 'name_en' => 'Fodder', 'card_type_en' => 'Member'],
            ['instance_id' => 'h_keep', 'card_type' => 'メンバー', 'name_en' => 'Keep', 'card_type_en' => 'Member'],
        ];
        // Non-empty deck so empty-deck refresh does not eat the discard from WR.
        $state['players']['p1']['main_deck'] = [
            ['instance_id' => 'd_pad', 'card_type' => 'エネルギー', 'name_en' => 'Energy'],
        ];
        $state['players']['p1']['_pending_yell_wr'] = [$mia];

        $state = \flushPendingYellToWr($state, 'p1');

        $this->assertSame([], $state['players']['p1']['_pending_yell_wr'] ?? []);
        $wrIds = array_column($state['players']['p1']['waiting_room'] ?? [], 'instance_id');
        $this->assertContains('issue295_mia', $wrIds);
        $this->assertSame('self_milled_recover', $state['pending_prompt']['bp7_action'] ?? null);
        $this->assertSame('issue295_mia', $state['pending_prompt']['recover_id'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertSame('self_milled_discard', $state['pending_prompt']['bp7_action'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'ok',
            'card_ids' => ['h_disc'],
        ]);

        $handIds = array_column($state['players']['p1']['hand'] ?? [], 'instance_id');
        $this->assertContains('issue295_mia', $handIds);
        $this->assertNotContains('h_disc', $handIds);
        $wrIds = array_column($state['players']['p1']['waiting_room'] ?? [], 'instance_id');
        $this->assertNotContains('issue295_mia', $wrIds);
        $this->assertContains('h_disc', $wrIds);
    }

    public function testFinishLiveSuccessPausesForYellMia(): void
    {
        $mia = $this->cardByNo('PL!N-bp7-011-SEC', 'issue295_mia_sec');
        $state = $this->baseState();
        $state['players']['p1']['hand'] = [
            ['instance_id' => 'h1', 'card_type' => 'メンバー', 'name_en' => 'Fodder', 'card_type_en' => 'Member'],
        ];
        $state['players']['p1']['_pending_yell_wr'] = [$mia];

        $state = \finishLiveSuccessEffects($state);

        $this->assertSame('live_success_effects', $state['phase'] ?? null);
        $this->assertSame('p1', $state['_performance_continue'] ?? null);
        $this->assertSame('self_milled_recover', $state['pending_prompt']['bp7_action'] ?? null);
        $this->assertSame([], $state['players']['p1']['_pending_yell_wr'] ?? []);
    }
}
