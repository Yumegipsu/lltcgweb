<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #197: Shioriko (PL!N-sd2-010) Auto when multiple allies enter Wait at once
 * must let the player choose which Waited Member to activate — not silently bind
 * one Stage slot (JSON key order previously preferred `right`).
 */
final class Issue197ShiorikoAllyWaitPickTest extends TestCase
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
            'main_deck' => [],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    public function testSimultaneousWaitsOfferPickThenActivateChosen(): void
    {
        $shiori = $this->cardByNo('PL!N-sd2-010-SD2', 'shiori');
        $left = $this->cardByNo('PL!N-sd2-004-SD2', 'ally_l');
        $right = $this->cardByNo('PL!N-sd2-002-SD2', 'ally_r');

        // Deliberately put `right` first in the array (as some JSON payloads do).
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage'] = [
            'right' => $right,
            'center' => $shiori,
            'left' => $left,
        ];
        $p1['hand'] = [
            ['instance_id' => 'hd1', 'card_type' => 'メンバー', 'name_en' => 'HD', 'group' => 'Nijigasaki'],
        ];

        $state = [
            'room_id' => 'ISSUE197',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 1,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        waitMember($state['players']['p1']['stage']['left'], $state);
        waitMember($state['players']['p1']['stage']['right'], $state);
        $state = flushAutoOnWaitAbilities($state);

        $this->assertSame('auto_on_ally_wait_activate_blade', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('pick_waited', $state['pending_prompt']['step'] ?? null);
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        sort($candIds);
        $this->assertSame(['ally_l', 'ally_r'], $candIds);

        // Choose Right explicitly (must not auto-bind without a pick).
        $state = applyAction($state, 'p1', 'resolve_prompt', [
            'member_id' => 'ally_r',
            'slot' => 'right',
        ]);
        $this->assertSame('discard', $state['pending_prompt']['step'] ?? null);
        $this->assertSame('ally_r', $state['pending_prompt']['waited_id'] ?? null);

        $state = applyAction($state, 'p1', 'resolve_prompt', [
            'discard_ids' => ['hd1'],
        ]);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['right']));
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertSame(
            2,
            intval($state['players']['p1']['stage']['right']['live_blade_bonus'] ?? 0)
        );
        $this->assertSame(
            0,
            intval($state['players']['p1']['stage']['left']['live_blade_bonus'] ?? 0)
        );
    }

    public function testSingleWaitKeepsYesNoFlow(): void
    {
        $shiori = $this->cardByNo('PL!N-sd2-010-SD2', 'shiori');
        $ally = $this->cardByNo('PL!N-sd2-004-SD2', 'ally_w');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $shiori;
        $p1['stage']['left'] = $ally;
        $p1['hand'] = [
            ['instance_id' => 'hd1', 'card_type' => 'メンバー', 'name_en' => 'HD', 'group' => 'Nijigasaki'],
        ];

        $state = [
            'room_id' => 'ISSUE197B',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 1,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        waitMember($state['players']['p1']['stage']['left'], $state);
        $state = flushAutoOnWaitAbilities($state);
        $this->assertSame('auto_on_ally_wait_activate_blade', $state['pending_prompt']['type'] ?? null);
        $this->assertNotSame('pick_waited', $state['pending_prompt']['step'] ?? 'x');
        $this->assertSame('ally_w', $state['pending_prompt']['waited_id'] ?? null);

        $state = applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'yes']);
        $this->assertSame('discard', $state['pending_prompt']['step'] ?? null);
        $state = applyAction($state, 'p1', 'resolve_prompt', ['discard_ids' => ['hd1']]);
        $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertSame(2, intval($state['players']['p1']['stage']['left']['live_blade_bonus'] ?? 0));
    }
}
