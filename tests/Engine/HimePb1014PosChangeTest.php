<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!HS-pb1-014 Hime Anyoji — On Enter Position Change moves an opponent Stage
 * Member into the facing area on THEIR Stage (never swaps ownership).
 */
final class HimePb1014PosChangeTest extends TestCase
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
        $this->fail('Missing card ' . $cardNo);
    }

    private function fillerMember(string $id, int $cost = 3, string $slotHint = ''): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'FILLER-' . $id,
            'name_en' => 'Filler ' . $id,
            'name' => 'Filler ' . $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Nijigasaki',
            'cost' => $cost,
            'blade' => 1,
            'hearts' => [['color' => 'red', 'count' => 1]],
            'active' => true,
            'subunit' => $slotHint,
        ];
    }

    private function miraCraMember(string $cardNo, string $id): array
    {
        $card = $this->cardByNo($cardNo, $id);
        $this->assertTrue(
            \cardMatchesSubunit($card, 'みらくらぱーく！')
            || \cardMatchesSubunit($card, 'みらくらぱーく!'),
            $cardNo . ' should be Mira-Cra Park!'
        );
        return $card;
    }

    private function basePlayingState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main',
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

    public function testDoesNotSwapOwnershipAndPromptsController(): void
    {
        $hime = $this->miraCraMember('PL!HS-pb1-014-P＋', 'hime_1');
        $rurino = $this->miraCraMember('PL!HS-bp5-003-SEC', 'rurino_1');
        $ai = $this->fillerMember('ai_left', 4);
        $kanata = $this->fillerMember('kanata_right', 2);
        $karin = $this->fillerMember('karin_center', 9);

        $state = $this->basePlayingState();
        $state['players']['p1']['stage'] = [
            'left' => $rurino,
            'center' => null,
            'right' => $hime,
        ];
        $state['players']['p2']['stage'] = [
            'left' => $ai,
            'center' => $karin,
            'right' => $kanata,
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $hime, 'right');

        $this->assertSame('pos_change_opp_front_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p1', $state['pending_prompt']['responder'] ?? null);
        $this->assertSame('p1', $state['pending_prompt']['owner'] ?? null);
        $this->assertSame('left', $state['pending_prompt']['front_slot'] ?? null);
        $slots = array_column($state['pending_prompt']['candidates'] ?? [], 'slot');
        sort($slots);
        $this->assertSame(['center', 'right'], $slots, 'Facing left (Ai) must not be a candidate');

        // Hime still on P1; Ai still on P2 — no ownership swap yet / ever from the broken path.
        $this->assertSame('hime_1', $state['players']['p1']['stage']['right']['instance_id'] ?? null);
        $this->assertSame('ai_left', $state['players']['p2']['stage']['left']['instance_id'] ?? null);

        $ignored = \actionResolvePrompt($state, 'p2', ['slot' => 'right']);
        $this->assertSame('pos_change_opp_front_pick', $ignored['pending_prompt']['type'] ?? null);
        $this->assertSame('ai_left', $ignored['players']['p2']['stage']['left']['instance_id'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'center']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('hime_1', $state['players']['p1']['stage']['right']['instance_id'] ?? null);
        $this->assertSame('rurino_1', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertSame('karin_center', $state['players']['p2']['stage']['left']['instance_id'] ?? null);
        $this->assertSame('ai_left', $state['players']['p2']['stage']['center']['instance_id'] ?? null);
        $this->assertSame('kanata_right', $state['players']['p2']['stage']['right']['instance_id'] ?? null);
    }

    public function testAlwaysHeartWhileFacingOpponentHasHigherCost(): void
    {
        $hime = $this->miraCraMember('PL!HS-pb1-014-R', 'hime_1');
        $facing = $this->fillerMember('facing_left', 12);
        $lower = $this->fillerMember('low_left', 4);

        $state = $this->basePlayingState();
        $state['phase'] = 'main_first';
        $state['players']['p1']['stage']['right'] = $hime;
        $state['players']['p2']['stage']['left'] = $facing;

        $grants = \collectContinuousPerformanceHeartGrants($state, 'p1');
        $hearts = [];
        foreach ($grants as $grant) {
            if (($grant['instance_id'] ?? '') === 'hime_1') {
                $hearts = array_merge($hearts, $grant['hearts'] ?? []);
            }
        }
        $this->assertContains('pink', $hearts);

        $state['players']['p2']['stage']['left'] = $lower;
        $grants = \collectContinuousPerformanceHeartGrants($state, 'p1');
        $hearts = [];
        foreach ($grants as $grant) {
            if (($grant['instance_id'] ?? '') === 'hime_1') {
                $hearts = array_merge($hearts, $grant['hearts'] ?? []);
            }
        }
        $this->assertNotContains('pink', $hearts);
    }

    public function testSingleCandidateAutoMovesOntoEmptyFront(): void
    {
        $hime = $this->miraCraMember('PL!HS-pb1-014-P＋', 'hime_1');
        $rurino = $this->miraCraMember('PL!HS-bp5-003-SEC', 'rurino_1');
        $only = $this->fillerMember('only_right', 5);

        $state = $this->basePlayingState();
        $state['players']['p1']['stage'] = [
            'left' => null,
            'center' => $hime,
            'right' => $rurino,
        ];
        $state['players']['p2']['stage'] = [
            'left' => null,
            'center' => null,
            'right' => $only,
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $hime, 'center');

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('hime_1', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $this->assertSame('only_right', $state['players']['p2']['stage']['center']['instance_id'] ?? null);
        $this->assertNull($state['players']['p2']['stage']['right'] ?? null);
    }

    public function testNoEffectWhenOnlyOppMemberAlreadyFacing(): void
    {
        $hime = $this->miraCraMember('PL!HS-pb1-014-P＋', 'hime_1');
        $rurino = $this->miraCraMember('PL!HS-bp5-003-SEC', 'rurino_1');
        $facing = $this->fillerMember('facing_left', 4);

        $state = $this->basePlayingState();
        $state['players']['p1']['stage'] = [
            'left' => null,
            'center' => $rurino,
            'right' => $hime,
        ];
        $state['players']['p2']['stage'] = [
            'left' => $facing,
            'center' => null,
            'right' => null,
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $hime, 'right');

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('facing_left', $state['players']['p2']['stage']['left']['instance_id'] ?? null);
        $this->assertSame('hime_1', $state['players']['p1']['stage']['right']['instance_id'] ?? null);
    }

    public function testSkipsWhenStageHasNonMiraCra(): void
    {
        $hime = $this->miraCraMember('PL!HS-pb1-014-P＋', 'hime_1');
        $sayaka = $this->cardByNo('PL!HS-cl1-002-CL', 'sayaka_1');
        $opp = $this->fillerMember('opp_center', 5);

        $state = $this->basePlayingState();
        $state['players']['p1']['stage'] = [
            'left' => $sayaka,
            'center' => null,
            'right' => $hime,
        ];
        $state['players']['p2']['stage'] = [
            'left' => null,
            'center' => $opp,
            'right' => null,
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $hime, 'right');

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('opp_center', $state['players']['p2']['stage']['center']['instance_id'] ?? null);
        $this->assertSame('hime_1', $state['players']['p1']['stage']['right']['instance_id'] ?? null);
    }
}
