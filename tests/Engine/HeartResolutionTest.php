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

    public function testCheckHeartsUsesAllBladeForMissingColorsBeforeGenericAnySlots(): void {
        $required = [
            ['color' => 'pink', 'count' => 1],
            ['color' => 'purple', 'count' => 1],
            ['color' => 'green', 'count' => 1],
        ];
        // ALL may pay purple; colorless "any" may not (#296).
        [$ok, $remaining] = checkHearts(['pink', 'all', 'green'], $required);

        $this->assertTrue($ok);
        $this->assertSame([], $remaining);
    }

    public function testCheckHeartsPrefersExactMatchesForColoredRequirements(): void {
        $required = [
            ['color' => 'pink', 'count' => 1],
            ['color' => 'purple', 'count' => 1],
        ];
        [$ok] = checkHearts(['pink', 'all'], $required);

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

    /**
     * Issue #130: fixed-color Yell blades before an ALL must count toward the
     * resolve pool, else ALL fills a color those blades already supply.
     * Glow room 0873CF — Proof needs purple (supplied by earlier Yell), COMPASS
     * still needs green; ALL must become green, not purple.
     */
    public function testAllBladeSeesPriorFixedColorYellHeartsInResolvePool(): void {
        $proof = [
            'required_hearts' => [
                ['color' => 'green', 'count' => 1],
                ['color' => 'blue', 'count' => 4],
                ['color' => 'purple', 'count' => 1],
                ['color' => 'any', 'count' => 4],
            ],
        ];
        $compass = [
            'required_hearts' => [
                ['color' => 'pink', 'count' => 1],
                ['color' => 'green', 'count' => 1],
                ['color' => 'blue', 'count' => 7],
                ['color' => 'any', 'count' => 7],
            ],
        ];
        $birdcage = [
            'required_hearts' => [
                ['color' => 'blue', 'count' => 2],
                ['color' => 'any', 'count' => 1],
            ],
        ];
        $lives = [$proof, $compass, $birdcage];
        // Stage + bonus (no purple) — purple arrives from an earlier Yell blade.
        $pool = array_merge(array_fill(0, 14, 'blue'), ['green', 'pink']);

        getHeartIconsFromBladeHeart('purple', $pool, $lives);
        getHeartIconsFromBladeHeart('blue', $pool, $lives);
        getHeartIconsFromBladeHeart('pink', $pool, $lives);
        $all = getHeartIconsFromBladeHeart('all', $pool, $lives);

        $this->assertSame(['green'], $all);
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

    /** COMPASS-sized Live (16 slots) with a short pool must fail fast — not DFS for minutes. */
    public function testCheckHeartsFailsFastWhenPoolSmallerThanSlots(): void {
        $required = [
            ['color' => 'pink', 'count' => 1],
            ['color' => 'green', 'count' => 1],
            ['color' => 'blue', 'count' => 7],
            ['color' => 'any', 'count' => 7],
        ];
        $owned = array_merge(
            ['pink', 'green', 'purple'],
            array_fill(0, 10, 'blue'),
            ['any']
        ); // 14 < 16
        $t0 = microtime(true);
        [$ok] = checkHearts($owned, $required);
        $elapsed = microtime(true) - $t0;
        $this->assertFalse($ok);
        $this->assertLessThan(0.05, $elapsed, 'undersized pool must not combinatorial-explode');
    }

    /** 19-heart PvP pools + mixed color/"any" must not DFS into nginx 504s. */
    public function testCheckHeartsLargeWildPoolFailsFastWhenLastColorMissing(): void {
        $required = [
            ['color' => 'red', 'count' => 15],
            ['color' => 'pink', 'count' => 1],
        ];
        // 15 wilds cover reds; leftover greens cannot pay pink. Old index DFS was 15!.
        $owned = array_merge(array_fill(0, 15, 'any'), array_fill(0, 4, 'green'));
        $t0 = microtime(true);
        [$ok] = checkHearts($owned, $required);
        $elapsed = microtime(true) - $t0;
        $this->assertFalse($ok);
        $this->assertLessThan(0.05, $elapsed, 'wild-heavy miss must not factorial-explode');
    }

    public function testCheckHeartsLargeMixedPoolSucceedsQuickly(): void {
        $required = [
            ['color' => 'pink', 'count' => 1],
            ['color' => 'green', 'count' => 1],
            ['color' => 'blue', 'count' => 7],
            ['color' => 'any', 'count' => 7],
        ];
        $owned = array_merge(
            ['pink', 'green'],
            array_fill(0, 10, 'blue'),
            array_fill(0, 7, 'red')
        );
        $t0 = microtime(true);
        [$ok] = checkHearts($owned, $required);
        $elapsed = microtime(true) - $t0;
        $this->assertTrue($ok);
        $this->assertLessThan(0.05, $elapsed);
    }

    public function testPoppinUpAllBladeReminderIsNotYellWildcard(): void
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $poppin = null;
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === 'PL!N-bp1-026-L') {
                $poppin = $card;
                break;
            }
        }
        $this->assertNotNull($poppin);
        $this->assertFalse(
            \liveCardsGrantYellHeartsWildcard([$poppin]),
            'Poppin\' Up ALL-blade reminder must not remap printed Yell hearts'
        );
    }

    public function testPrintedRedYellHeartStaysRedWithPoppinUp(): void
    {
        $poppin = [
            'card_no' => 'PL!N-bp1-026-L',
            'name_en' => "Poppin' Up!",
            'required_hearts' => [
                ['color' => 'yellow', 'count' => 1],
                ['color' => 'any', 'count' => 2],
            ],
            'text' => "[Live Success] If your Live total score is higher than your opponent's, add 1 Nijigasaki card revealed for Yell to your hand.",
            'text_jp' => "[ライブ成功時] ライブの合計スコアが相手より高い場合、エールにより公開された自分のカードの中から、『虹ヶ咲』のカードを1枚手札に加える。\n\n(必要ハートを確認する時、エールで出たALLブレードは任意の色のハートとして扱う。)",
            'abilities' => [[
                'trigger' => 'live_success',
                'type' => 'live_success_add_yell_group_to_hand',
                'group' => 'Nijigasaki',
                'count' => 1,
            ]],
        ];
        $this->assertFalse(\liveCardsGrantYellHeartsWildcard([$poppin]));

        $pool = []; // no stage yellow yet
        $icons = \getHeartIconsFromBladeHeart('red', $pool, [$poppin]);
        $this->assertSame(['red'], $icons, 'printed red blade must stay red');

        if (\liveCardsGrantYellHeartsWildcard([$poppin])) {
            $resolved = \resolveSmartYellWildcardHeartColors(['red'], $pool, [$poppin]);
            $this->assertSame(['red'], $resolved);
        }
    }

    public function testExplicitYellHeartsWildcardAbilityStillDetected(): void
    {
        $live = [
            'abilities' => [['type' => 'yell_hearts_wildcard']],
            'text' => '',
            'text_jp' => '',
        ];
        $this->assertTrue(\liveCardsGrantYellHeartsWildcard([$live]));
    }

    public function testReminderWordingWithoutAbilityIsNotYellWildcard(): void
    {
        $reminderOnly = [
            'abilities' => [],
            'text' => '(When checking Required Hearts, Blade hearts revealed for Yell count as any color.)',
            'text_jp' => '(必要ハートを確認する時、エールで出たALLブレードは任意の色のハートとして扱う。)',
        ];
        $this->assertFalse(\liveCardsGrantYellHeartsWildcard([$reminderOnly]));

        $legacyWording = [
            'abilities' => [],
            'text' => 'Hearts revealed for Yell may be treated as any color.',
            'text_jp' => 'エールで出たハートは任意の色として扱う。',
        ];
        $this->assertFalse(
            \liveCardsGrantYellHeartsWildcard([$legacyWording]),
            'Wildcard wording without yell_hearts_wildcard IR is not a card skill'
        );
    }
}
