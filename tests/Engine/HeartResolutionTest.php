<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class HeartResolutionTest extends TestCase
{
    public function testAllBladeHeartResolvesToFirstMissingColoredRequirement(): void {
        $live = [
            'required_hearts' => [
                ['color' => 'pink', 'count' => 1],
                ['color' => 'green', 'count' => 1],
                ['color' => 'blue', 'count' => 1],
                ['color' => 'purple', 'count' => 1],
                ['color' => 'any', 'count' => 8],
            ],
        ];
        $pool = ['red', 'red', 'green', 'green', 'blue', 'blue', 'blue', 'red', 'yellow', 'green', 'green'];

        $this->assertSame('pink', resolveAllBladeHeartColor($pool, [$live]));
    }

    public function testAllBladeHeartDoesNotSpendExistingWildcardWhenChoosingColor(): void {
        $live = [
            'required_hearts' => [
                ['color' => 'pink', 'count' => 1],
                ['color' => 'purple', 'count' => 1],
            ],
        ];
        $pool = ['any'];

        $this->assertSame('pink', resolveAllBladeHeartColor($pool, [$live]));
    }

    public function testCheckHeartsUsesWildcardsForMissingColorsBeforeGenericAnySlots(): void {
        $required = [
            ['color' => 'pink', 'count' => 1],
            ['color' => 'purple', 'count' => 1],
            ['color' => 'green', 'count' => 1],
        ];
        [$ok, $remaining] = checkHearts(['pink', 'any', 'green'], $required);

        $this->assertTrue($ok);
        $this->assertSame([], $remaining);
    }

    public function testCheckHeartsPrefersExactMatchesForColoredRequirements(): void {
        $required = [
            ['color' => 'pink', 'count' => 1],
            ['color' => 'purple', 'count' => 1],
        ];
        [$ok] = checkHearts(['pink', 'any'], $required);

        $this->assertTrue($ok);
    }

    public function testResolveSmartYellWildcardAssignsMissingColorsInOrder(): void {
        $live = [
            'required_hearts' => [
                ['color' => 'pink', 'count' => 1],
                ['color' => 'purple', 'count' => 1],
            ],
        ];
        $pool = [];
        $resolved = resolveSmartYellWildcardHeartColors(['red', 'blue'], $pool, [$live]);

        $this->assertSame(['pink', 'purple'], $resolved);
        $this->assertSame(['pink', 'purple'], $pool);
    }

    public function testGetHeartIconsFromAllBladeUsesMissingColor(): void {
        $live = [
            'required_hearts' => [
                ['color' => 'pink', 'count' => 1],
            ],
        ];
        $pool = ['green', 'blue'];

        $icons = getHeartIconsFromBladeHeart('all', $pool, [$live]);

        $this->assertSame(['pink'], $icons);
        $this->assertContains('pink', $pool);
    }

    public function testAllBladeReservesEarlierLiveColoredHeartsForLaterLives(): void {
        // Shared pool can cover Live1's colors, but those same hearts cannot also
        // cover Live2 — ALL must resolve to Live2's missing pink, not "any".
        $live1 = [
            'required_hearts' => [
                ['color' => 'red', 'count' => 1],
                ['color' => 'yellow', 'count' => 1],
                ['color' => 'purple', 'count' => 1],
                ['color' => 'any', 'count' => 2],
            ],
        ];
        $live2 = [
            'required_hearts' => [
                ['color' => 'pink', 'count' => 1],
                ['color' => 'any', 'count' => 2],
            ],
        ];
        $pool = ['red', 'yellow', 'purple'];

        $this->assertSame('pink', resolveAllBladeHeartColor($pool, [$live1, $live2]));
    }

    public function testSecondAllBladeAlsoPrioritizesRemainingColoredNeed(): void {
        $live1 = [
            'required_hearts' => [
                ['color' => 'red', 'count' => 1],
                ['color' => 'yellow', 'count' => 1],
                ['color' => 'any', 'count' => 1],
            ],
        ];
        $live2 = [
            'required_hearts' => [
                ['color' => 'pink', 'count' => 1],
                ['color' => 'purple', 'count' => 1],
            ],
        ];
        $pool = ['red', 'yellow'];
        $resolved = resolveSmartYellWildcardHeartColors(['all', 'all'], $pool, [$live1, $live2]);

        $this->assertSame(['pink', 'purple'], $resolved);
    }

    public function testMultiLiveAnySlotsDoNotStealLaterColoredNeeds(): void {
        $reqAny = [['color' => 'any', 'count' => 5]];
        $reqRed = [['color' => 'red', 'count' => 5]];
        $pool = array_merge(array_fill(0, 5, 'red'), array_fill(0, 5, 'green'));
        $reserve = coloredHeartDemandFromRequirements($reqRed);
        [$ok1, $rem] = checkHearts($pool, $reqAny, $reserve);
        [$ok2] = checkHearts($rem, $reqRed);
        $this->assertTrue($ok1 && $ok2);
    }
}
