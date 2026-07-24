<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Dreamin' Go! Go!! (Success Live −2 gray, does not stack) + ?←HEARTBEAT
 * Live Start −1 gray must both apply. Catalog uses color "gray" while
 * reductions are stored/applied as "any" — those must match.
 */
final class DreaminHeartbeatGrayHeartReductionTest extends TestCase
{
    private function heartbeatCard(string $iid = 'hb1'): array
    {
        return [
            'instance_id' => $iid,
            'card_no' => 'PL!-bp4-021-L',
            'name_en' => '?←HEARTBEAT',
            'card_type' => 'ライブ',
            'group' => "μ's",
            'score' => 6,
            'required_hearts' => [
                ['color' => 'pink', 'count' => 2],
                ['color' => 'yellow', 'count' => 2],
                ['color' => 'purple', 'count' => 2],
                ['color' => 'gray', 'count' => 8],
            ],
            'hearts' => [
                ['color' => 'pink', 'count' => 2],
                ['color' => 'yellow', 'count' => 2],
                ['color' => 'purple', 'count' => 2],
                ['color' => 'gray', 'count' => 8],
            ],
            'abilities' => [
                [
                    'trigger' => 'live_start',
                    'type' => 'reduce_hearts_if_success_score',
                    'min_score_6' => 6,
                    'reduce' => 1,
                    'reduce_heart_color' => 'gray',
                    'min_score_9' => 9,
                    'bonus_score' => 1,
                ],
            ],
        ];
    }

    private function dreaminCard(string $iid = 'dg1'): array
    {
        return [
            'instance_id' => $iid,
            'card_no' => 'PL!-bp6-022-L',
            'name_en' => "Dreamin' Go! Go!!",
            'card_type' => 'ライブ',
            'group' => "μ's",
            'score' => 9,
            'abilities' => [
                [
                    'trigger' => 'continuous',
                    'type' => 'reduce_hearts_mus_live_min_score_success',
                    'group' => "μ's",
                    'min_score' => 5,
                    'reduce' => 2,
                    'heart_color' => 'gray',
                ],
            ],
        ];
    }

    private function counts(array $req): array
    {
        $out = [];
        foreach ($req as $h) {
            $c = \normalizeRequiredHeartColor((string)($h['color'] ?? 'any'));
            $out[$c] = ($out[$c] ?? 0) + intval($h['count'] ?? 0);
        }
        return $out;
    }

    public function testGrayReductionMatchesAnyStoredRequirement(): void
    {
        $req = [
            ['color' => 'pink', 'count' => 2],
            ['color' => 'gray', 'count' => 8],
        ];
        $after = \reduceHeartRequirementsByColor($req, 'any', 3);
        $this->assertSame(['pink' => 2, 'any' => 5], $this->counts($after));
    }

    public function testAnyReductionMatchesGrayStoredRequirement(): void
    {
        $req = [
            ['color' => 'pink', 'count' => 2],
            ['color' => 'any', 'count' => 8],
        ];
        $after = \reduceHeartRequirementsByColor($req, 'gray', 3);
        $this->assertSame(['pink' => 2, 'any' => 5], $this->counts($after));
    }

    public function testDreaminPassivePlusHeartbeatLiveStartEqualsFiveGray(): void
    {
        $hb = $this->heartbeatCard();
        $hb['hearts_color_reduction'] = ['any' => 1]; // Live Start −1 gray stored as any

        $state = [
            'players' => [
                'p1' => [
                    'success_lives' => [$this->dreaminCard()],
                    'live_zone' => [$hb],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];

        $final = \liveHeartRequirementsForCheck($state, 'p1', $hb);
        $counts = $this->counts($final);

        $this->assertSame(2, $counts['pink'] ?? 0);
        $this->assertSame(2, $counts['yellow'] ?? 0);
        $this->assertSame(2, $counts['purple'] ?? 0);
        $this->assertSame(5, $counts['any'] ?? 0, '8 gray −2 Dreamin −1 HEARTBEAT = 5');
    }

    public function testTwoDreaminCopiesDoNotStack(): void
    {
        $hb = $this->heartbeatCard();
        $state = [
            'players' => [
                'p1' => [
                    'success_lives' => [$this->dreaminCard('dg1'), $this->dreaminCard('dg2')],
                    'live_zone' => [$hb],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];

        $final = \liveHeartRequirementsForCheck($state, 'p1', $hb);
        $counts = $this->counts($final);
        $this->assertSame(6, $counts['any'] ?? 0, 'Two Dreamin copies still only −2');
    }

    public function testThirteenHeartsClearHeartbeatWithBothReductions(): void
    {
        // Replay 4DD173BE: 6 purple, 3 pink, 4 yellow — enough for 2/2/2/5, not 2/2/2/8.
        $owned = array_merge(
            array_fill(0, 6, 'purple'),
            array_fill(0, 3, 'pink'),
            array_fill(0, 4, 'yellow')
        );
        $hb = $this->heartbeatCard();
        $hb['hearts_color_reduction'] = ['any' => 1];
        $state = [
            'players' => [
                'p1' => [
                    'success_lives' => [$this->dreaminCard()],
                    'live_zone' => [$hb],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];
        $required = \liveHeartRequirementsForCheck($state, 'p1', $hb);
        [$ok] = \checkHearts($owned, $required);
        $this->assertTrue($ok, '6P+3Pi+4Y must clear HEARTBEAT at 2/2/2/5');
    }
}
