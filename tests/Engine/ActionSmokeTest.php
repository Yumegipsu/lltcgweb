<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class ActionSmokeTest extends TestCase
{
    private function joinedMulliganState(): array {
        $created = createRoom(['name' => 'Smoke P1', 'deck' => 'nijigasaki']);
        joinRoom([
            'room_id' => $created['room_id'],
            'name' => 'Smoke P2',
            'deck' => 'cpu',
            'cpu_difficulty' => 'easy',
            'first_player' => 'p1',
        ]);
        $state = loadGame($created['room_id']);
        $this->assertIsArray($state);
        $this->assertSame('setup', $state['phase'] ?? '');
        return $state;
    }

    public function testMulliganKeepAdvancesToMain(): void {
        $state = $this->joinedMulliganState();
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $this->assertSame('setup', $state['phase'] ?? '');
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $this->assertSame('main_first', $state['phase'] ?? '');
        $this->assertTrue($state['players']['p1']['ready_mulligan'] ?? false);
        $this->assertTrue($state['players']['p2']['ready_mulligan'] ?? false);
    }

    public function testResolvePromptClearsLookTopOptionalWr(): void {
        $state = $this->joinedMulliganState();
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $state['pending_prompt'] = [
            'type' => 'look_top_optional_wr',
            'owner' => 'p1',
            'responder' => 'p1',
            'target' => 'p1',
            'source_name' => 'Smoke',
            'choices' => ['yes', 'no'],
        ];
        $state = applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'no']);
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testPlayMemberLegalFromHand(): void {
        $state = $this->joinedMulliganState();
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $this->assertSame('main_first', $state['phase'] ?? '');

        $member = null;
        $activeEnergy = count(array_filter(
            $state['players']['p1']['energy_zone'] ?? [],
            static fn(array $c): bool => !empty($c['active'])
        ));
        foreach ($state['players']['p1']['hand'] as $c) {
            if (($c['card_type'] ?? '') !== 'メンバー') {
                continue;
            }
            $cost = intval($c['cost'] ?? 99);
            if ($cost <= $activeEnergy) {
                $member = $c;
                break;
            }
        }
        $this->assertNotNull($member, 'Expected a playable member card in opening hand');

        $state = applyAction($state, 'p1', 'play_member', [
            'card_id' => $member['instance_id'],
            'slot' => 'center',
        ]);
        $handIds = array_column($state['players']['p1']['hand'] ?? [], 'instance_id');
        $this->assertNotContains($member['instance_id'], $handIds);
        $this->assertSame($member['instance_id'], $state['players']['p1']['stage']['center']['instance_id'] ?? null);
    }
}
