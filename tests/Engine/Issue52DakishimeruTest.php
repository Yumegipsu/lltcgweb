<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression for GitHub issue #52 — Dakishimeru Hanabira Live Start pick advances phase. */
final class Issue52DakishimeruTest extends TestCase
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

    /** @return list<array<string, mixed>> */
    private function wrHasunosoraMembers(int $count, string $prefix): array
    {
        $member = $this->cardByNo('PL!HS-pb1-001-R', $prefix . '_tpl');
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $c = $member;
            $c['instance_id'] = $prefix . $i;
            $c['group'] = 'Hasunosora';
            $c['card_type'] = 'メンバー';
            $out[] = $c;
        }
        return $out;
    }

    private function baseLiveStartState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
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
                    'main_deck' => [['instance_id' => 'deck_top']],
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

    public function testLiveStartPickMemberAdvancesToPerformance(): void
    {
        $live = $this->cardByNo('PL!HS-pb1-025-L', 'issue52_live');
        $stageMember = $this->cardByNo('PL!HS-pb1-001-R', 'issue52_stage');
        $stageMember['group'] = 'Hasunosora';
        $stageMember['abilities'] = [];

        $state = $this->baseLiveStartState();
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['stage']['center'] = $stageMember;
        $state['players']['p1']['waiting_room'] = $this->wrHasunosoraMembers(10, 'issue52_wr');

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame(
            'live_start_wr_group_member_count_pick_heart',
            $state['pending_prompt']['type'] ?? null
        );
        $this->assertNotEmpty($state['pending_prompt']['candidates'] ?? []);

        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'center']);
        $this->assertNotSame(
            'live_start_wr_group_member_count_pick_heart',
            $state['pending_prompt']['type'] ?? null
        );
        $this->assertNotSame('live_start_effects', $state['phase'] ?? null);
    }

    public function testLiveStartPickCancelAlsoAdvancesPhase(): void
    {
        $live = $this->cardByNo('PL!HS-pb1-025-L', 'issue52_live_cancel');
        $stageMember = $this->cardByNo('PL!HS-pb1-001-R', 'issue52_stage_cancel');
        $stageMember['group'] = 'Hasunosora';
        $stageMember['abilities'] = [];

        $state = $this->baseLiveStartState();
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['stage']['center'] = $stageMember;
        $state['players']['p1']['waiting_room'] = $this->wrHasunosoraMembers(10, 'issue52_wr_cancel');

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame(
            'live_start_wr_group_member_count_pick_heart',
            $state['pending_prompt']['type'] ?? null
        );

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'cancel']);
        $this->assertNotSame(
            'live_start_wr_group_member_count_pick_heart',
            $state['pending_prompt']['type'] ?? null
        );
        $this->assertNotSame('live_start_effects', $state['phase'] ?? null);
    }
}
