<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Regression: GitHub issue #63 — You Watanabe RGB hearts, SUKI Live Start freeze,
 * Sayaka Murano up-to-2 WR pick.
 */
final class Issue63CardBugsTest extends TestCase
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

    private function basePlayers(): array
    {
        return [
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
        ];
    }

    public function testYouWatanabeAddsRgbHeartMemberFromLook(): void
    {
        $you = $this->cardByNo('PL!S-bp6-005-P', 'you_enter');
        $rgb = $this->cardByNo('PL!S-PR-013-PR', 'rgb_top');
        $other = $this->cardByNo('PL!S-bp6-005-R', 'other_top');

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 1,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['stage']['center'] = $you;
        $state['players']['p1']['main_deck'] = [$rgb, $other];

        $state = \resolveOnEnterAbilities($state, 'p1', $you, 'center');

        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('rgb_top', $handIds);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('other_top', $wrIds);
        $this->assertEmpty($state['pending_prompt'] ?? null);
    }

    public function testSukiLiveStartListsAnyAqoursThenScoresOnBlades(): void
    {
        $live = $this->cardByNo('PL!S-bp3-025-L', 'suki_live');
        $lowBlade = $this->cardByNo('PL!S-bp6-005-P', 'aqours_low');
        $highBlade = $this->cardByNo('PL!S-bp6-005-P', 'aqours_high');
        $highBlade['blade'] = 6;
        $lowBlade['blade'] = 2;

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['stage']['center'] = $highBlade;
        $state['players']['p1']['stage']['left'] = $lowBlade;

        $state = \resolveLiveStartAbilities($state, 'p1');

        $this->assertSame('score_if_stage_member_hearts', $state['pending_prompt']['type'] ?? null);
        $slots = array_column($state['pending_prompt']['candidates'] ?? [], 'slot');
        sort($slots);
        $this->assertSame(['center', 'left'], $slots);

        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'center']);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $msgs = array_map(
            static fn($line) => is_array($line) ? (string)($line['msg'] ?? '') : (string)$line,
            $state['log'] ?? []
        );
        $this->assertTrue(
            (bool)array_filter($msgs, static fn($m) => str_contains($m, 'score +1')),
            'Expected score +1 log after choosing 6+ Blade Member'
        );
    }

    public function testSukiNoScoreWhenChosenMemberUnderBladeThreshold(): void
    {
        $live = $this->cardByNo('PL!S-bp3-025-L', 'suki_live2');
        $lowBlade = $this->cardByNo('PL!S-bp6-005-P', 'aqours_low2');
        $lowBlade['blade'] = 2;

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['stage']['center'] = $lowBlade;

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame('score_if_stage_member_hearts', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'center']);
        $msgs = array_map(
            static fn($line) => is_array($line) ? (string)($line['msg'] ?? '') : (string)$line,
            $state['log'] ?? []
        );
        $this->assertTrue(
            (bool)array_filter($msgs, static fn($m) => str_contains($m, 'no score')),
            'Expected no-score log when Member is under Blade threshold'
        );
        $this->assertFalse(
            (bool)array_filter($msgs, static fn($m) => str_contains($m, 'score +1')),
            'Must not bump Live score when under threshold'
        );
    }

    public function testSayakaOpensUpToTwoWrMemberPick(): void
    {
        $sayaka = $this->cardByNo('PL!HS-bp2-002-P', 'sayaka1');
        $wrA = [
            'instance_id' => 'wr_a',
            'card_no' => 'HS-WR-A',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'WR A',
            'group' => 'Hasunosora',
            'cost' => 1,
        ];
        $wrB = [
            'instance_id' => 'wr_b',
            'card_no' => 'HS-WR-B',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'WR B',
            'group' => 'Hasunosora',
            'cost' => 2,
        ];
        $wrHigh = [
            'instance_id' => 'wr_high',
            'card_no' => 'HS-WR-HIGH',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'WR High',
            'group' => 'Hasunosora',
            'cost' => 3,
        ];

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 1,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['stage']['center'] = $sayaka;
        $state['players']['p1']['waiting_room'] = [$wrA, $wrB, $wrHigh];

        $state = \resolveOnEnterAbilities($state, 'p1', $sayaka, 'center');

        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(2, intval($state['pending_prompt']['pick_count'] ?? 0));
        $this->assertTrue(!empty($state['pending_prompt']['up_to']));
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        sort($candIds);
        $this->assertSame(['wr_a', 'wr_b'], $candIds);
        // Still in WR until the player resolves the pick.
        $this->assertCount(0, $state['players']['p1']['hand']);
        $this->assertCount(3, $state['players']['p1']['waiting_room']);

        $state = \actionResolvePrompt($state, 'p1', ['card_ids' => ['wr_b', 'wr_a']]);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        sort($handIds);
        $this->assertSame(['wr_a', 'wr_b'], $handIds);
        $this->assertSame(['wr_high'], array_column($state['players']['p1']['waiting_room'], 'instance_id'));
    }
}
