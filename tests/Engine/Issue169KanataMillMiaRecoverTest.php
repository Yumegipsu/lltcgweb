<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #169 — Kanata mill still offers its choice after 13-cost Mia's
 * milled-from-deck recover (discard to add herself to hand).
 */
final class Issue169KanataMillMiaRecoverTest extends TestCase
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

    private function filler(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'エネルギー',
            'card_type_en' => 'Energy',
            'name_en' => 'Energy',
        ];
    }

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
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

    private function millKanataIntoMia(): array
    {
        $kanata = $this->cardByNo('PL!N-bp7-006-SEC', 'issue169_kanata');
        $mia = $this->cardByNo('PL!N-bp7-011-P', 'issue169_mia');
        $this->assertSame([], $mia['blade_hearts'] ?? null);
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $kanata;
        $state['players']['p1']['hand'] = [
            ['instance_id' => 'h_disc', 'card_type' => 'メンバー', 'name_en' => 'Fodder', 'card_type_en' => 'Member'],
            ['instance_id' => 'h_keep', 'card_type' => 'メンバー', 'name_en' => 'Keep', 'card_type_en' => 'Member'],
        ];
        $state['players']['p1']['main_deck'] = [
            $mia,
            $this->filler('d2'),
            $this->filler('d3'),
        ];
        $mill = $kanata['abilities'][1];
        $this->assertSame('activated_mill_group_live_or_bladeless_choice', $mill['type'] ?? null);
        $state = \resolveAbilityEffect($state, 'p1', $kanata, $mill, ['phase' => 'activated']);
        $this->assertSame('self_milled_recover', $state['pending_prompt']['bp7_action'] ?? null);
        $this->assertNotSame('player_choice', $state['pending_prompt']['type'] ?? null);
        return $state;
    }

    public function testKanataChoiceOpensAfterMiaIsAddedToHand(): void
    {
        $state = $this->millKanataIntoMia();

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertSame('self_milled_discard', $state['pending_prompt']['bp7_action'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'ok',
            'card_ids' => ['h_disc'],
        ]);

        $handIds = array_column($state['players']['p1']['hand'] ?? [], 'instance_id');
        $this->assertContains('issue169_mia', $handIds);
        $this->assertNotContains('h_disc', $handIds);
        $this->assertSame('player_choice', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('issue169_kanata', $state['pending_prompt']['source_id'] ?? null);
        $this->assertContains('activate', $state['pending_prompt']['choices'] ?? []);
        $this->assertContains('blade', $state['pending_prompt']['choices'] ?? []);
    }

    public function testKanataChoiceStillOpensIfMiaRecoverIsDeclined(): void
    {
        $state = $this->millKanataIntoMia();

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);

        $wrIds = array_column($state['players']['p1']['waiting_room'] ?? [], 'instance_id');
        $this->assertContains('issue169_mia', $wrIds);
        $this->assertSame('player_choice', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('issue169_kanata', $state['pending_prompt']['source_id'] ?? null);
    }
}
