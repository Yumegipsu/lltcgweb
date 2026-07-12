<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression tests for GitHub issue #47 (Live judge bonuses + Yell draw icons). */
final class Issue47LiveJudgeTest extends TestCase
{
    public function testCountYellDrawIconsHydratesCatalogYellDrawIcon(): void {
        $yellCard = [
            'instance_id' => 'yell_draw',
            'card_no' => 'PL!N-bp1-027-L',
            'card_type' => 'ライブ',
        ];
        $batch = [$yellCard];
        hydrateYellCardsForPerformance($batch);
        $this->assertGreaterThan(0, countYellDrawIcons($batch));
    }

    public function testApplyLiveScoreIfYellHasHeartsBumpsLiveZoneScore(): void {
        $live = [
            'instance_id' => 'live_bonus',
            'card_no' => 'PL!N-bp3-030-L',
            'card_type' => 'ライブ',
            'score' => 3,
        ];
        $yellMember = [
            'instance_id' => 'yell_hearts',
            'card_type' => 'メンバー',
            'hearts' => [['color' => 'red', 'count' => 1]],
        ];
        $zone = [$live];
        applyLiveScoreIfYellHasHeartsInZone($zone, [$yellMember]);
        $this->assertSame(4, intval($zone[0]['score'] ?? 0));
        $this->assertSame(4, liveCardScoreForJudge($zone[0]));
    }

    public function testLiveZoneScoreIncludesCardLevelLiveScoreBonus(): void {
        $live = [
            'instance_id' => 'live1',
            'card_type' => 'ライブ',
            'name_en' => 'Bonus Live',
            'score' => 2,
            'live_score_bonus' => 3,
        ];
        $this->assertSame(5, sumLiveZoneCardScores([$live]));
    }

    public function testYellDrawIconsApplyWithoutLiveContinuous(): void {
        $yellCard = [
            'instance_id' => 'yell_draw',
            'card_no' => 'PL!N-bp1-027-L',
            'card_type' => 'ライブ',
            'yell_draw_icon' => true,
        ];
        $batch = [$yellCard];
        hydrateYellCardsForPerformance($batch);
        $this->assertSame(1, countYellDrawIcons($batch));

        // Official rule: Yell draw icons always grant draws (no Live continuous required).
        $this->assertSame(1, countYellDrawIcons([$yellCard]));
    }

    public function testLiveScoreBonusIncludesYellContextDuringMainCarryover(): void {
        $state = [
            'phase' => 'main_second',
            'turn' => 3,
            'live_modifiers' => [
                'p1' => ['score_bonus' => 0, 'blade_bonus' => 0, 'bonus_hearts' => []],
                'p2' => ['score_bonus' => 0, 'blade_bonus' => 0, 'bonus_hearts' => []],
            ],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'stage' => [
                        'center' => [
                            'instance_id' => 'm1',
                            'card_type' => 'メンバー',
                            'group' => 'Sunshine',
                            'abilities' => [
                                ['trigger' => 'continuous', 'type' => 'live_score_bonus', 'amount' => 2],
                            ],
                        ],
                        'left' => null,
                        'right' => null,
                    ],
                    'live_zone' => [],
                    'energy_zone' => [],
                ],
                'p2' => ['id' => 'p2', 'stage' => ['left' => null, 'center' => null, 'right' => null], 'live_zone' => []],
            ],
            '_live_round_success_snapshot' => ['p1' => true, 'p2' => true],
        ];
        $this->assertSame(2, getLiveScoreBonus($state, 'p1'));
    }

    /**
     * Mari PL!S-bp2-008 continuous: per-player Yell Live count must survive the
     * second performer's Yell overwriting the shared _last_yell_live_count (#47).
     */
    public function testMariBp2YellLiveScoreUsesPerPlayerYellLiveCount(): void {
        $mari = [
            'instance_id' => 'mari_bp2',
            'name_en' => 'Mari Ohara',
            'card_type' => 'メンバー',
            'group' => 'Sunshine',
            'abilities' => [[
                'trigger' => 'continuous',
                'type' => 'grant_live_success_yell_live_score_if_full_stage',
                'group' => 'Sunshine',
                'filter' => 'member',
                'tier1_min' => 1,
                'tier1_amount' => 1,
                'tier2_min' => 3,
                'tier2_amount' => 2,
            ]],
        ];
        $aqours = static function (string $id, string $name): array {
            return [
                'instance_id' => $id,
                'name_en' => $name,
                'name' => $name,
                'card_type' => 'メンバー',
                'group' => 'Sunshine',
            ];
        };
        $state = [
            'phase' => 'live_judge',
            'turn' => 4,
            'live_modifiers' => [
                'p1' => ['score_bonus' => 0, 'blade_bonus' => 0, 'bonus_hearts' => []],
                'p2' => ['score_bonus' => 0, 'blade_bonus' => 0, 'bonus_hearts' => []],
            ],
            // Shared key overwritten by p2's empty Yell; p1's per-pid key still has 2 Lives.
            '_last_yell_live_count' => 0,
            '_last_yell_live_count_p1' => 2,
            '_last_yell_live_count_p2' => 0,
            '_last_yell_score_icons' => 0,
            '_last_yell_score_icons_p1' => 0,
            '_last_yell_score_icons_p2' => 0,
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'stage' => [
                        'center' => $mari,
                        'left' => $aqours('chika', 'Chika Takami'),
                        'right' => $aqours('you', 'You Watanabe'),
                    ],
                    'live_zone' => [['instance_id' => 'live1', 'card_type' => 'ライブ', 'score' => 3]],
                    'energy_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                ],
            ],
        ];

        $this->assertSame(2, yellLiveCountForPlayer($state, 'p1'));
        $this->assertSame(0, yellLiveCountForPlayer($state, 'p2'));
        // Printed 3 + Mari tier1 (+1) = 4 total; bonus alone is +1.
        $this->assertSame(1, getLiveScoreBonus($state, 'p1'));
        $this->assertSame(4, getLiveTotalScore($state, 'p1'));
    }

    public function testYellScoreIconsPreferPerPlayerKey(): void {
        $state = [
            '_last_yell_score_icons' => 9,
            '_last_yell_score_icons_p1' => 2,
            '_last_yell_score_icons_p2' => 0,
        ];
        $this->assertSame(2, yellScoreIconsForPlayer($state, 'p1'));
        $this->assertSame(0, yellScoreIconsForPlayer($state, 'p2'));
    }
}
