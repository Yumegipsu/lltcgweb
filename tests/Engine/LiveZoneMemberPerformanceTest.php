<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Issue #45: Member bluffs in Live storage count for zone skills during Performance. */
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
                    'main_deck' => array_fill(0, 20, ['card_type' => 'メンバー', 'blade' => 0]),
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

    public function testResolvePerformancePhaseKeepsMembersUntilHeartCheck(): void
    {
        $zone = [
            $this->member('m1'),
            $this->member('m2'),
            $this->live('live1'),
        ];
        $state = $this->performanceState($zone);

        $afterYell = resolvePerformancePhase($state, 'p1', false);

        $this->assertCount(3, $afterYell['players']['p1']['live_zone']);
        $this->assertSame(0, count(array_filter(
            $afterYell['players']['p1']['waiting_room'],
            fn($c) => ($c['instance_id'] ?? '') === 'm1' || ($c['instance_id'] ?? '') === 'm2'
        )));
    }

    public function testLanzhuContinuousHeartsCountMemberBluffsInLiveZone(): void
    {
        $state = $this->performanceState([
            $this->member('m1'),
            $this->member('m2'),
            $this->live('live1'),
        ]);
        $state['phase'] = 'live_performance_first';

        $hearts = getContinuousPerformanceHearts($state, 'p1');
        $anyCount = 0;
        foreach ($hearts as $color) {
            if ($color === 'any') {
                $anyCount++;
            }
        }
        $this->assertSame(2, $anyCount, 'Lanzhu should grant 2 wild hearts with 3 cards incl. a Live');
    }

    public function testEutopiaLiveStartScoresWithMemberBluffsInZone(): void
    {
        $live = $this->live('eutopia');
        $state = $this->performanceState([
            $this->member('m1', 'Superstar'),
            $this->member('m2', 'Superstar'),
            $live,
        ]);
        $state['phase'] = 'live_start_effects';
        $state['live_attempt'] = ['p1'];

        $after = resolveLiveStartAbilities($state, 'p1');
        $stored = null;
        foreach ($after['players']['p1']['live_zone'] as $c) {
            if (($c['instance_id'] ?? '') === 'eutopia') {
                $stored = $c;
                break;
            }
        }
        $this->assertNotNull($stored);
        $this->assertSame(7, intval($stored['score'] ?? 0), 'Eutopia score should be 5+2 with 3 cards in Live storage');
    }
}
