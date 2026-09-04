<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Issue #45 / #145: zone-count skills count Live cards only; bluffs leave before Live Start. */
final class LiveZoneMemberPerformanceTest extends TestCase
{
    private function member(string $id, string $group = 'Nijigasaki'): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => $group,
            'name' => 'Bluff',
            'name_en' => 'Bluff',
            'cost' => 2,
            'blade' => 1,
            'hearts' => [['color' => 'pink', 'count' => 1]],
            'active' => true,
        ];
    }

    private function live(string $id, int $score = 5): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Nijigasaki',
            'name' => 'Eutopia',
            'name_en' => 'Eutopia',
            'score' => $score,
            'required_hearts' => [['color' => 'pink', 'count' => 1]],
            'abilities' => [[
                'trigger' => 'live_start',
                'type' => 'score_if_live_zone_min',
                'min_count' => 3,
                'amount' => 2,
            ]],
        ];
    }

    private function lanzhuOnStage(): array
    {
        return [
            'instance_id' => 'lanzhu',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Nijigasaki',
            'name' => 'Lanzhu',
            'name_en' => 'Lanzhu Zhong',
            'cost' => 15,
            'blade' => 4,
            'hearts' => [['color' => 'pink', 'count' => 3]],
            'active' => true,
            'abilities' => [[
                'trigger' => 'continuous',
                'type' => 'blade_if_live_zone_group_live',
                'group' => 'Nijigasaki',
                'min_count' => 3,
                'amount' => 2,
                'hearts' => [['color' => 'any', 'count' => 2]],
            ]],
        ];
    }

    private function performanceState(array $liveZone): array
    {
        return [
            'room_id' => 'LZTEST',
            'status' => 'playing',
            'seq' => 10,
            'turn' => 2,
            'phase' => 'live_performance_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $this->lanzhuOnStage(),
                        'right' => null,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => array_fill(0, 6, ['card_type' => 'エネルギー', 'active' => true]),
                    'main_deck' => array_map(
                        fn($i) => ['instance_id' => 'deck' . $i, 'card_type' => 'メンバー', 'blade' => 0],
                        range(1, 20)
                    ),
                    'energy_deck' => [],
                    'live_zone' => $liveZone,
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testRevealDiscardsMembersWithoutExtraDrawsBeforeLiveStart(): void
    {
        $zone = [
            $this->member('m1'),
            $this->member('m2'),
            $this->live('live1'),
        ];
        $state = $this->performanceState($zone);
        // Replacements were already drawn when these bluffs were placed during LIVE Phase.
        $state['players']['p1']['hand'] = [
            ['instance_id' => 'draw1', 'card_type' => 'メンバー'],
            ['instance_id' => 'draw2', 'card_type' => 'メンバー'],
        ];

        $afterReveal = \revealAllLiveStorage($state);
        $after = \discardLiveZoneMembersToWaitingRoom($afterReveal, 'p1');

        $this->assertCount(1, $after['players']['p1']['live_zone']);
        $this->assertTrue(\isLiveTypeCard($after['players']['p1']['live_zone'][0]));
        $this->assertCount(2, array_filter(
            $after['players']['p1']['waiting_room'],
            fn($c) => ($c['instance_id'] ?? '') === 'm1' || ($c['instance_id'] ?? '') === 'm2'
        ));
        $this->assertCount(2, $after['players']['p1']['hand'], 'Reveal discard must not draw again');
        $this->assertSame('draw1', $after['players']['p1']['hand'][0]['instance_id']);
        $this->assertSame('draw2', $after['players']['p1']['hand'][1]['instance_id']);
    }

    public function testBeginPerformanceKeepsMembersUntilAfterYell(): void
    {
        $zone = [
            $this->member('m1'),
            $this->live('live1'),
        ];
        $state = $this->performanceState($zone);
        $state['phase'] = 'live_set';
        $state['players']['p2']['live_zone'] = [];

        $after = \beginPerformancePhase($state);

        $this->assertSame('reveal', $after['live_show']['stage'] ?? null);
        $iids = array_map(
            fn($c) => $c['instance_id'] ?? '',
            $after['players']['p1']['live_zone'] ?? []
        );
        $this->assertContains('m1', $iids, 'Member bluff must remain through reveal/spectacle');
        $this->assertContains('live1', $iids);
        $this->assertCount(0, array_filter(
            $after['players']['p1']['waiting_room'] ?? [],
            fn($c) => ($c['instance_id'] ?? '') === 'm1'
        ));
    }

    public function testHeartsResolveDiscardsMembersBeforeOutcomes(): void
    {
        $zone = [
            $this->member('m1'),
            $this->live('live1'),
        ];
        $state = $this->performanceState($zone);
        $state['phase'] = 'live_performance_first';
        $state['live_attempt'] = ['p1'];
        $state['live_show'] = [
            'turn' => 2,
            'stage' => 'performance',
            'performer' => 'p1',
            'stage_seq' => 3,
            'acks' => [],
        ];

        $after = \queueLiveShowOutcomes($state);

        $this->assertSame('outcomes', $after['live_show']['stage'] ?? null);
        $this->assertCount(0, array_filter(
            $after['players']['p1']['live_zone'] ?? [],
            fn($c) => ($c['instance_id'] ?? '') === 'm1'
        ));
        $this->assertTrue(
            count(array_filter(
                $after['players']['p1']['waiting_room'] ?? [],
                fn($c) => ($c['instance_id'] ?? '') === 'm1'
            )) >= 1
        );
        $this->assertContains(
            'live1',
            array_map(fn($c) => $c['instance_id'] ?? '', $after['players']['p1']['live_zone'] ?? [])
        );
    }

    public function testLanzhuContinuousHeartsCountOnlyLiveCardsInZone(): void
    {
        $state = $this->performanceState([
            $this->member('m1'),
            $this->member('m2'),
            $this->live('live1'),
        ]);
        $state = \discardLiveZoneMembersToWaitingRoom($state, 'p1');
        $state['phase'] = 'live_performance_first';

        $hearts = \getContinuousPerformanceHearts($state, 'p1');
        $anyCount = 0;
        foreach ($hearts as $color) {
            if ($color === 'any') {
                $anyCount++;
            }
        }
        $this->assertSame(0, $anyCount, 'Lanzhu needs 3 Live cards, not member bluffs');
    }

    public function testLanzhuGrantsHeartsWithThreeLiveCardsInZone(): void
    {
        $state = $this->performanceState([
            $this->live('live1'),
            $this->live('live2'),
            $this->live('live3'),
        ]);
        $state['phase'] = 'live_performance_first';

        $hearts = \getContinuousPerformanceHearts($state, 'p1');
        $anyCount = 0;
        foreach ($hearts as $color) {
            if ($color === 'any') {
                $anyCount++;
            }
        }
        $this->assertSame(2, $anyCount);
    }

    public function testEutopiaLiveStartScoresOnlyLiveCardsInZone(): void
    {
        $live = $this->live('eutopia');
        $state = $this->performanceState([
            $this->member('m1', 'Superstar'),
            $this->member('m2', 'Superstar'),
            $live,
        ]);
        $state = \discardLiveZoneMembersToWaitingRoom($state, 'p1');
        $state['phase'] = 'live_start_effects';
        $state['live_attempt'] = ['p1'];

        $after = \resolveLiveStartAbilities($state, 'p1');
        $stored = null;
        foreach ($after['players']['p1']['live_zone'] as $c) {
            if (($c['instance_id'] ?? '') === 'eutopia') {
                $stored = $c;
                break;
            }
        }
        $this->assertNotNull($stored);
        $this->assertSame(5, intval($stored['score'] ?? 0), 'Eutopia needs 3 Live cards in storage');
    }

    public function testEutopiaBonusWithThreeLiveCardsInZone(): void
    {
        $live = $this->live('eutopia');
        $state = $this->performanceState([
            $this->live('live2'),
            $this->live('live3'),
            $live,
        ]);
        $state['phase'] = 'live_start_effects';
        $state['live_attempt'] = ['p1'];

        $after = \resolveLiveStartAbilities($state, 'p1');
        $stored = null;
        foreach ($after['players']['p1']['live_zone'] as $c) {
            if (($c['instance_id'] ?? '') === 'eutopia') {
                $stored = $c;
                break;
            }
        }
        $this->assertNotNull($stored);
        $this->assertSame(7, intval($stored['score'] ?? 0));
    }

    /** #132 — heart check must not delete Member bluffs when rewriting live_zone. */
    public function testHeartCheckKeepsMemberBluffInStorageOnSuccess(): void
    {
        $zone = [
            $this->member('bluff15'),
            $this->live('live1'),
        ];
        $state = $this->performanceState($zone);
        $state['phase'] = 'live_performance_first';
        $state['live_attempt'] = ['p1'];
        $state['players']['p1']['stage']['center'] = [
            'instance_id' => 'stage1',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'blade' => 0,
            'hearts' => [
                ['color' => 'pink', 'count' => 1],
                ['color' => 'any', 'count' => 2],
            ],
            'blade_hearts' => [],
        ];
        $state['players']['p1']['main_deck'] = array_fill(0, 5, [
            'instance_id' => 'pad',
            'card_type' => 'メンバー',
            'blade_hearts' => [],
        ]);

        $after = \resolvePerformanceHeartCheck($state, 'p1', false);
        $iids = array_map(
            fn($c) => $c['instance_id'] ?? '',
            $after['players']['p1']['live_zone'] ?? []
        );
        $this->assertContains('bluff15', $iids, 'Member bluff must survive heart check');
        $this->assertContains('live1', $iids);
        $this->assertCount(0, array_filter(
            $after['players']['p1']['waiting_room'] ?? [],
            fn($c) => ($c['instance_id'] ?? '') === 'bluff15'
        ), 'bluff stays in storage until outcomes discard');
    }

    public function testHeartCheckKeepsMemberBluffWhenLiveRoundFails(): void
    {
        $hardLive = $this->live('hard');
        $hardLive['required_hearts'] = [
            ['color' => 'red', 'count' => 9],
        ];
        $zone = [
            $this->member('bluff15'),
            $hardLive,
        ];
        $state = $this->performanceState($zone);
        $state['phase'] = 'live_performance_first';
        $state['live_attempt'] = ['p1'];
        $state['players']['p1']['stage']['center'] = [
            'instance_id' => 'stage1',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'blade' => 0,
            'hearts' => [['color' => 'pink', 'count' => 1]],
            'blade_hearts' => [],
        ];
        $state['players']['p1']['main_deck'] = array_fill(0, 5, [
            'instance_id' => 'pad',
            'card_type' => 'メンバー',
            'blade_hearts' => [],
        ]);

        $after = \resolvePerformanceHeartCheck($state, 'p1', false);
        $iids = array_map(
            fn($c) => $c['instance_id'] ?? '',
            $after['players']['p1']['live_zone'] ?? []
        );
        $this->assertContains('bluff15', $iids, 'Failed Live must not delete Member bluffs');
        $this->assertNotContains('hard', $iids);
        $wr = array_map(fn($c) => $c['instance_id'] ?? '', $after['players']['p1']['waiting_room'] ?? []);
        $this->assertContains('hard', $wr);
        $this->assertNotContains('bluff15', $wr);

        $afterOutcomes = \queueLiveShowOutcomes($after);
        $this->assertContains(
            'bluff15',
            array_map(fn($c) => $c['instance_id'] ?? '', $afterOutcomes['players']['p1']['waiting_room'] ?? [])
        );
    }
}
