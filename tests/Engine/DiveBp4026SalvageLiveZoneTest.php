<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!N-bp4-026-L DIVE! — Main Phase salvage from WR may put a "DIVE!" Live
 * face-up into Live storage (next Live Set place-cap −1), then grant +2 Blade
 * to 1 Nijigasaki Stage Member.
 */
final class DiveBp4026SalvageLiveZoneTest extends TestCase
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
            'main_deck' => [
                ['instance_id' => $id . '_d0', 'card_type' => 'メンバー', 'name_en' => 'Draw'],
            ],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    private function baseState(array $p1, array $p2): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 4,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];
    }

    public function testSalvageOpensOptionalFaceUpLiveZonePrompt(): void
    {
        $dive = $this->cardByNo('PL!N-bp4-026-L', 'dive_salvage');
        $ayumu = $this->cardByNo('PL!N-bp1-001-P', 'ayumu_stage');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $ayumu;
        $p1['waiting_room'] = [$dive];
        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));

        $state['pending_prompt'] = [
            'type' => 'pick_wr_to_hand',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_name' => 'Salvage',
            'candidates' => [\cardPromptSummary($dive)],
            'ability' => ['type' => 'add_from_wr', 'filter' => 'live'],
            'wr_pick_cfg' => ['filter' => 'live'],
            'pick_count' => 1,
        ];

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'dive_salvage']);

        $this->assertSame('optional_named_live_zone_from_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('confirm', $state['pending_prompt']['step'] ?? null);
        $this->assertContains('dive_salvage', array_column($state['players']['p1']['hand'], 'instance_id'));
        $this->assertSame([], $state['players']['p1']['live_zone']);
    }

    public function testAcceptPlacesFaceUpAppliesPenaltyAndBlade(): void
    {
        $dive = $this->cardByNo('PL!N-bp4-026-L', 'dive_salvage');
        $ayumu = $this->cardByNo('PL!N-bp1-001-P', 'ayumu_stage');
        $setsuna = $this->cardByNo('PL!N-bp1-007-P', 'setsuna_stage');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $ayumu;
        $p1['stage']['left'] = $setsuna;
        $p1['waiting_room'] = [$dive];
        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));

        $state['pending_prompt'] = [
            'type' => 'pick_wr_to_hand',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_name' => 'Salvage',
            'candidates' => [\cardPromptSummary($dive)],
            'ability' => ['type' => 'add_from_wr', 'filter' => 'live'],
            'wr_pick_cfg' => ['filter' => 'live'],
            'pick_count' => 1,
        ];
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'dive_salvage']);
        $this->assertSame('optional_named_live_zone_from_hand', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        // Two Nijigasaki Members → blade pick.
        $this->assertSame('pick_group_member_blade_faceup', $state['pending_prompt']['type'] ?? null);
        $zone = $state['players']['p1']['live_zone'];
        $this->assertCount(1, $zone);
        $this->assertSame('dive_salvage', $zone[0]['instance_id'] ?? null);
        $this->assertTrue(!empty($zone[0]['revealed']));
        $this->assertTrue(!empty($zone[0]['preplaced_live_zone']));
        $this->assertSame(1, intval($state['players']['p1']['live_set_cap_penalty'] ?? 0));
        $this->assertNotContains('dive_salvage', array_column($state['players']['p1']['hand'], 'instance_id'));

        $state = \actionResolvePrompt($state, 'p1', [
            'card_id' => 'setsuna_stage',
            'slot' => 'left',
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(
            2,
            intval($state['players']['p1']['stage']['left']['live_blade_bonus'] ?? 0)
        );
        $this->assertSame(
            0,
            intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0)
        );
    }

    public function testSkipLeavesDiveInHand(): void
    {
        $dive = $this->cardByNo('PL!N-bp4-026-L', 'dive_skip');
        $ayumu = $this->cardByNo('PL!N-bp1-001-P', 'ayumu_stage');
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $ayumu;
        $p1['waiting_room'] = [$dive];
        $state = $this->baseState($p1, $this->emptyPlayer('p2', 'P2'));

        $state['pending_prompt'] = [
            'type' => 'pick_wr_to_hand',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_name' => 'Salvage',
            'candidates' => [\cardPromptSummary($dive)],
            'ability' => ['type' => 'add_from_wr', 'filter' => 'live'],
            'wr_pick_cfg' => ['filter' => 'live'],
            'pick_count' => 1,
        ];
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'dive_skip']);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertContains('dive_skip', array_column($state['players']['p1']['hand'], 'instance_id'));
        $this->assertSame([], $state['players']['p1']['live_zone']);
        $this->assertSame(0, intval($state['players']['p1']['live_set_cap_penalty'] ?? 0));
    }
}
