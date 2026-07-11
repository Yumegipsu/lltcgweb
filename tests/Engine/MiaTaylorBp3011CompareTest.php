<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!N-bp3-011-R Mia Taylor — On Enter compare opp Stage Member hearts/cost/blade. */
final class MiaTaylorBp3011CompareTest extends TestCase
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
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 1,
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

    public function testOnEnterListsFlatOpponentCandidatesExcludingMia(): void
    {
        $mia = $this->cardByNo('PL!N-bp3-011-R', 'mia_src');
        $oppA = $this->cardByNo('PL!N-bp1-002-P', 'opp_ayu');
        $oppMia = $this->cardByNo('PL!N-bp3-011-P', 'opp_mia');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $mia;
        $state['players']['p2']['stage']['left'] = $oppA;
        $state['players']['p2']['stage']['center'] = $oppMia;

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');

        $this->assertSame('opp_member_match_heart_blade', $state['pending_prompt']['type'] ?? null);
        $cands = $state['pending_prompt']['candidates'] ?? [];
        $this->assertCount(1, $cands);
        $this->assertSame('opp_ayu', $cands[0]['instance_id'] ?? null);
        $this->assertSame('left', $cands[0]['slot'] ?? null);
        $this->assertNotEmpty($cands[0]['name_en'] ?? null);
        $this->assertArrayNotHasKey('summary', $cands[0]);
    }

    public function testPickGrantsBladeForSharedHeartCostAndBlade(): void
    {
        $mia = $this->cardByNo('PL!N-bp3-011-R', 'mia_src');
        // Force a triple match against Mia's printed stats.
        $opp = $this->cardByNo('PL!N-bp1-002-P', 'opp_match');
        $opp['cost'] = intval($mia['cost'] ?? 7);
        $opp['blade'] = intval($mia['blade'] ?? 1);
        $opp['hearts'] = $mia['hearts'] ?? [['color' => 'yellow', 'count' => 1]];

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $mia;
        $state['players']['p2']['stage']['right'] = $opp;

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');
        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'right']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(3, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
    }

    public function testPickWithNoMatchesGrantsZeroBlade(): void
    {
        $mia = $this->cardByNo('PL!N-bp3-011-R', 'mia_src');
        $opp = $this->cardByNo('PL!N-bp1-002-P', 'opp_nomatch');
        $opp['cost'] = 99;
        $opp['blade'] = 99;
        $opp['hearts'] = [['color' => 'red', 'count' => 1]];

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $mia;
        $state['players']['p2']['stage']['left'] = $opp;

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'opp_nomatch']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(0, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
    }

    public function testNoEligibleOpponentSkipsPrompt(): void
    {
        $mia = $this->cardByNo('PL!N-bp3-011-R', 'mia_src');
        $oppMia = $this->cardByNo('PL!N-bp3-011-P', 'opp_mia_only');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $mia;
        $state['players']['p2']['stage']['center'] = $oppMia;

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');

        $this->assertNull($state['pending_prompt'] ?? null);
    }
}
