<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-004 Umi — Always blade per Success Score-icon + Auto extra Yell (#219).
 */
final class Issue219UmiScoreIconYellBladeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
    }

    private function umi(): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === 'PL!-pb2-004-R') {
                $card['instance_id'] = 'umi004';
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing PL!-pb2-004-R');
    }

    private function scoreIconLive(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-sd1-019-SD',
            'name_en' => 'START:DASH!!',
            'card_type' => 'ライブ',
            'group' => "μ's",
            'score' => 1,
            'yell_score_icon' => true,
            'special_heart' => 'icon_score.png',
        ];
    }

    public function testAlwaysBladeScalesWithSuccessScoreIcons(): void
    {
        $umi = $this->umi();
        $state = [
            'phase' => 'main',
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'success_lives' => [
                        $this->scoreIconLive('s1'),
                        $this->scoreIconLive('s2'),
                    ],
                    'waiting_room' => [],
                    'deck' => [],
                    'stage' => ['left' => null, 'center' => $umi, 'right' => null],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'success_lives' => [],
                    'waiting_room' => [],
                    'deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];
        $printed = intval($umi['blade'] ?? 0);
        $this->assertSame(
            $printed + 2,
            getMemberBlade($umi, $state, 'p1', 'center'),
            'Always should add +1 Blade per Success Score-icon μ\'s Live'
        );
    }

    public function testAutoYellAddsExtraPerScoreIconInReveal(): void
    {
        $umi = $this->umi();
        $deck = [];
        for ($i = 0; $i < 8; $i++) {
            $deck[] = [
                'instance_id' => 'deck' . $i,
                'card_no' => 'PL!-sd1-001-SD',
                'name_en' => 'Filler',
                'card_type' => 'メンバー',
                'group' => "μ's",
            ];
        }
        $yell = [
            $this->scoreIconLive('y1'),
            [
                'instance_id' => 'y_plain',
                'card_no' => 'PL!-bp3-020-L',
                'name_en' => 'Plain Live',
                'card_type' => 'ライブ',
                'group' => "μ's",
                'score' => 2,
                'yell_score_icon' => false,
            ],
            $this->scoreIconLive('y2'),
        ];
        $state = [
            'phase' => 'live_performance_first',
            'seq' => 1,
            'log' => [],
            'yell_reveal' => ['p1' => $yell, 'p2' => []],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'success_lives' => [],
                    'waiting_room' => [],
                    'deck' => $deck,
                    'main_deck' => $deck,
                    'yell_cards' => $yell,
                    'stage' => ['left' => null, 'center' => $umi, 'right' => null],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'success_lives' => [],
                    'waiting_room' => [],
                    'deck' => [],
                    'yell_cards' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];
        $before = count($state['players']['p1']['yell_cards']);
        $out = resolveAutoYellAbilities($state, 'p1', $yell);
        $after = count($out['players']['p1']['yell_cards'] ?? []);
        $this->assertSame(
            $before + 2,
            $after,
            'should draw +2 extra Yell for two Score-icon μ\'s cards in the reveal'
        );
        $this->assertTrue(
            isAbilityUsed($out['players']['p1']['stage']['center'], 1),
            'once_per_turn Auto should be marked used'
        );
    }
}
