<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Lanzhu replace + Eli/Kotori per-success hearts must feed Performance stage totals.
 */
final class LiveStartHeartDisplayTest extends TestCase
{
    public function testReplacedHeartsChangePrintedColorKeepingCount(): void
    {
        $member = [
            'instance_id' => 'lanzhu',
            'hearts' => [
                ['color' => 'pink', 'count' => 2],
            ],
            'replaced_hearts' => ['purple'],
        ];
        $this->assertSame(['purple', 'purple'], memberPerformanceHeartsFlat($member));

        $stage = ['center' => $member];
        $agg = aggregateStageHeartsByColor($stage);
        $this->assertSame([['color' => 'purple', 'count' => 2]], $agg);
    }

    public function testModifierBonusHeartsMergeIntoStageBoardTotals(): void
    {
        $state = [
            'players' => [
                'p1' => [
                    'stage' => [
                        'center' => [
                            'instance_id' => 'eli',
                            'hearts' => [
                                ['color' => 'pink', 'count' => 1],
                                ['color' => 'purple', 'count' => 1],
                            ],
                        ],
                    ],
                ],
                'p2' => ['stage' => []],
            ],
            'live_modifiers' => [
                'p1' => [
                    'bonus_hearts' => ['yellow', 'yellow'],
                ],
                'p2' => ['bonus_hearts' => []],
            ],
        ];
        $stage = aggregateStageHeartsByColor($state['players']['p1']['stage']);
        $merged = mergeHeartColorCounts(
            $stage,
            aggregateFlatHeartColors(getBonusHeartsFlat($state, 'p1'))
        );
        $byColor = [];
        foreach ($merged as $row) {
            $byColor[$row['color']] = $row['count'];
        }
        $this->assertSame(1, $byColor['pink'] ?? 0);
        $this->assertSame(1, $byColor['purple'] ?? 0);
        $this->assertSame(2, $byColor['yellow'] ?? 0);
    }

    public function testChooseHeartPerSuccessWritesModifierHearts(): void
    {
        $state = [
            'players' => ['p1' => [], 'p2' => []],
            'live_modifiers' => [
                'p1' => ['bonus_hearts' => []],
                'p2' => ['bonus_hearts' => []],
            ],
        ];
        // Mirror choose_heart_per_success: 3 Success Lives × yellow.
        addBonusHeartsToModifier($state, 'p1', [['color' => 'yellow', 'count' => 3]]);
        $this->assertSame(['yellow', 'yellow', 'yellow'], getBonusHeartsFlat($state, 'p1'));
    }

    public function testChooseReplaceMemberHeartsSetsOverride(): void
    {
        $member = [
            'instance_id' => 'lanzhu',
            'name_en' => 'Lanzhu Zhong',
            'hearts' => [['color' => 'pink', 'count' => 1]],
        ];
        $member['replaced_hearts'] = ['red'];
        $this->assertSame(['red'], memberPerformanceHeartsFlat($member));
        $this->assertSame(
            [['color' => 'red', 'count' => 1]],
            aggregateStageHeartsByColor(['center' => $member])
        );
    }
}
