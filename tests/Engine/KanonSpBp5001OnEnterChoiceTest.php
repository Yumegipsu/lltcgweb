<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!SP-bp5-001 Kanon — optional pay 1 → Choose one: Wait opp cost≤4 OR draw 1.
 * On Enter must open the same player_choice as Live Start (not skip nested choice).
 */
final class KanonSpBp5001OnEnterChoiceTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                $card['entered_turn'] = 1;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
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

    /** @return list<array<string, mixed>> */
    private function activeEnergy(int $count, string $prefix = 'kanon_en'): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'instance_id' => $prefix . $i,
                'card_type' => 'エネルギー',
                'active' => true,
            ];
        }
        return $out;
    }

    private function playKanonIntoChoice(): array
    {
        $kanon = $this->cardByNo('PL!SP-bp5-001-R＋', 'kanon_oe');
        $opp = $this->cardByNo('PL!SP-sd1-002-SD', 'kanon_opp');
        $opp['cost'] = 3;
        $opp['instance_id'] = 'kanon_opp';
        $draw = ['instance_id' => 'kanon_draw', 'card_type' => 'メンバー', 'name' => 'DrawTarget'];
        $filler = ['instance_id' => 'kanon_f1', 'card_type' => 'メンバー', 'name' => 'Filler'];

        $state = $this->baseState();
        $state['players']['p1']['hand'] = [$kanon];
        $state['players']['p1']['main_deck'] = [$draw, $filler];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(15);
        $state['players']['p2']['stage']['left'] = $opp;

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'kanon_oe',
            'slot' => 'center',
        ]);
        $this->assertSame('optional_pay_energy_on_enter', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertSame('player_choice', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(['wait', 'draw'], $state['pending_prompt']['choices'] ?? null);

        return $state;
    }

    public function testOnEnterPayOpensWaitOrDrawChoice(): void
    {
        $this->playKanonIntoChoice();
    }

    public function testOnEnterChoiceDrawWorks(): void
    {
        $state = $this->playKanonIntoChoice();
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'draw']);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('kanon_draw', $handIds);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertFalse(\memberIsInWait($state['players']['p2']['stage']['left']));
    }

    public function testOnEnterChoiceWaitWorks(): void
    {
        $state = $this->playKanonIntoChoice();
        // Single legal target auto-resolves wait_opponent_stage_max_cost.
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'wait']);
        $state = \tcgTestPickOnlyOption($state); // picks always prompt now, even with one option
        $this->assertTrue(\memberIsInWait($state['players']['p2']['stage']['left']));
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testOnEnterPaySkippedWithoutActiveEnergy(): void
    {
        $kanon = $this->cardByNo('PL!SP-bp5-001-R＋', 'kanon_oe');
        $opp = $this->cardByNo('PL!SP-sd1-002-SD', 'kanon_opp');
        $opp['cost'] = 3;
        $draw = ['instance_id' => 'kanon_draw', 'card_type' => 'メンバー', 'name' => 'DrawTarget'];

        $state = $this->baseState();
        $state['players']['p1']['hand'] = [$kanon];
        $state['players']['p1']['main_deck'] = [$draw];
        // Kanon costs 10 — leftover active Energy is 0, so the optional pay must not open.
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(10);
        $state['players']['p2']['stage']['left'] = $opp;

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'kanon_oe',
            'slot' => 'center',
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(0, \countActiveEnergyInZone($state['players']['p1']));
        $this->assertSame([], $state['players']['p1']['hand']);
    }

    public function testOnEnterPayStillOpensWhenWaitHasNoTargetsThenDraws(): void
    {
        $kanon = $this->cardByNo('PL!SP-bp5-001-R＋', 'kanon_oe');
        $oppHigh = $this->cardByNo('PL!HS-pb1-007-R', 'kanon_opp_high');
        $draw = ['instance_id' => 'kanon_draw', 'card_type' => 'メンバー', 'name' => 'DrawTarget'];
        $filler = ['instance_id' => 'kanon_f1', 'card_type' => 'メンバー', 'name' => 'Filler'];

        $state = $this->baseState();
        $state['players']['p1']['hand'] = [$kanon];
        $state['players']['p1']['main_deck'] = [$draw, $filler];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(15);
        $state['players']['p2']['stage']['left'] = $oppHigh;

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'kanon_oe',
            'slot' => 'center',
        ]);
        $this->assertSame('optional_pay_energy_on_enter', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('kanon_draw', $handIds);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertFalse(\memberIsInWait($state['players']['p2']['stage']['left']));
    }
}
