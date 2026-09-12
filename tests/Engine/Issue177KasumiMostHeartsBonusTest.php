<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Regression for GitHub issue #177 —
 * Kasumi PL!N-bp5-002 [Always] most-hearts Live score must count Believer bonus hearts,
 * not printed hearts only.
 */
final class Issue177KasumiMostHeartsBonusTest extends TestCase
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
        $this->fail('Missing card ' . $cardNo);
    }

    private function baseState(array $kasumi, array $other): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_performance_first',
            'seq' => 1,
            'turn' => 3,
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
                    'stage' => [
                        'left' => null,
                        'center' => $kasumi,
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $other,
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testBelieverBonusHeartsEnableKasumiMostHeartsScore(): void
    {
        $kasumi = $this->cardByNo('PL!N-bp5-002-P', 'kasumi');
        // Printed: 3+1+1+1 = 6. Opponent printed 7 — Kasumi loses on raw hearts.
        $other = [
            'instance_id' => 'you',
            'card_type' => 'メンバー',
            'name_en' => 'You Watanabe',
            'name' => '渡辺曜',
            'group' => 'Sunshine',
            'cost' => 10,
            'blade' => 3,
            'active' => true,
            'hearts' => [
                ['color' => 'yellow', 'count' => 3],
                ['color' => 'green', 'count' => 1],
                ['color' => 'blue', 'count' => 1],
                ['color' => 'purple', 'count' => 1],
                ['color' => 'pink', 'count' => 1],
            ],
            'abilities' => [],
        ];
        $this->assertSame(6, \memberHeartCount($kasumi));
        $this->assertSame(7, \memberHeartCount($other));

        $state = $this->baseState($kasumi, $other);
        $this->assertFalse(
            \nBp5MemberHasMostHearts($state, 'p1', $state['players']['p1']['stage']['center']),
            'Without Believer bonuses Kasumi must not win most-hearts'
        );
        $bonus = \getLiveScoreBonusBreakdown($state, 'p1');
        $this->assertSame(0, intval($bonus['total'] ?? 0));

        // Muteki-kyuu*Believer style: +1 yellow, +1 purple, +1 pink → 9 total.
        $state['players']['p1']['stage']['center']['bonus_hearts'] = ['yellow', 'purple', 'pink'];
        $this->assertTrue(
            \nBp5MemberHasMostHearts($state, 'p1', $state['players']['p1']['stage']['center']),
            'Believer bonus hearts must count toward most-hearts (#177)'
        );
        $bonus = \getLiveScoreBonusBreakdown($state, 'p1');
        $this->assertSame(1, intval($bonus['total'] ?? 0));
    }

    public function testTieStillDoesNotGrantScore(): void
    {
        $kasumi = $this->cardByNo('PL!N-bp5-002-P', 'kasumi_tie');
        $other = [
            'instance_id' => 'twin',
            'card_type' => 'メンバー',
            'name_en' => 'Other',
            'group' => 'Nijigasaki',
            'active' => true,
            'hearts' => [
                ['color' => 'yellow', 'count' => 3],
                ['color' => 'green', 'count' => 1],
                ['color' => 'blue', 'count' => 1],
                ['color' => 'purple', 'count' => 1],
            ],
            'abilities' => [],
        ];
        $state = $this->baseState($kasumi, $other);
        $this->assertFalse(\nBp5MemberHasMostHearts($state, 'p1', $state['players']['p1']['stage']['center']));
        $this->assertSame(0, intval(\getLiveScoreBonusBreakdown($state, 'p1')['total'] ?? 0));
    }

    public function testPrintedLeadStillWorksWithoutBonus(): void
    {
        $kasumi = $this->cardByNo('PL!N-bp5-002-P', 'kasumi_lead');
        $other = [
            'instance_id' => 'small',
            'card_type' => 'メンバー',
            'name_en' => 'Small',
            'group' => 'Nijigasaki',
            'active' => true,
            'hearts' => [['color' => 'pink', 'count' => 2]],
            'abilities' => [],
        ];
        $state = $this->baseState($kasumi, $other);
        $this->assertTrue(\nBp5MemberHasMostHearts($state, 'p1', $state['players']['p1']['stage']['center']));
        $this->assertSame(1, intval(\getLiveScoreBonusBreakdown($state, 'p1')['total'] ?? 0));
    }
}
