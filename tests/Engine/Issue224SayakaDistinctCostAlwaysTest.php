<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!HS-bp5-002 Sayaka — Always +1 blue / +1 Blade with ≥3 distinct Stage costs (#224).
 */
final class Issue224SayakaDistinctCostAlwaysTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function filler(string $id, int $cost): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!HS-sd1-001-SD',
            'name_en' => 'Filler' . $cost,
            'card_type' => 'メンバー',
            'group' => 'Hasunosora',
            'cost' => $cost,
            'blade' => 1,
            'hearts' => [['color' => 'pink', 'count' => 1]],
            'active' => true,
            'abilities' => [],
        ];
    }

    private function state(array $stage): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main',
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'deck' => [],
                    'waiting_room' => [],
                    'energy_zone' => array_fill(0, 4, ['active' => true]),
                    'stage' => $stage,
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'deck' => [],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testAlwaysBladeAndBlueHeartWithThreeDistinctCosts(): void
    {
        $sayaka = $this->cardByNo('PL!HS-bp5-002-SEC', 'sayaka_sec');
        $printedBlade = intval($sayaka['blade'] ?? 0);
        $state = $this->state([
            'left' => $this->filler('f2', 2),
            'center' => $sayaka,
            'right' => $this->filler('f9', 9),
        ]);

        $this->assertSame(3, countDistinctCostsOnStage($state['players']['p1']));
        $this->assertSame(
            $printedBlade + 1,
            getMemberBlade($sayaka, $state, 'p1', 'center'),
            'Always should grant +1 Blade with 3 distinct Stage costs'
        );

        $grants = collectContinuousPerformanceHeartGrants($state, 'p1');
        $sayakaGrant = null;
        foreach ($grants as $g) {
            if (($g['instance_id'] ?? '') === 'sayaka_sec') {
                $sayakaGrant = $g;
                break;
            }
        }
        $this->assertNotNull($sayakaGrant, 'Sayaka should receive a continuous heart grant');
        $this->assertContains('blue', $sayakaGrant['hearts'] ?? []);
    }

    public function testAlwaysInactiveWithOnlyTwoDistinctCosts(): void
    {
        $sayaka = $this->cardByNo('PL!HS-bp5-002-SEC', 'sayaka_sec');
        $printedBlade = intval($sayaka['blade'] ?? 0);
        $state = $this->state([
            'left' => $this->filler('f2a', 2),
            'center' => $sayaka,
            'right' => $this->filler('f2b', 2),
        ]);

        $this->assertSame(2, countDistinctCostsOnStage($state['players']['p1']));
        $this->assertSame(
            $printedBlade,
            getMemberBlade($sayaka, $state, 'p1', 'center'),
            'Always must not grant Blade with only 2 distinct costs'
        );
        foreach (collectContinuousPerformanceHeartGrants($state, 'p1') as $g) {
            if (($g['instance_id'] ?? '') === 'sayaka_sec') {
                $this->assertNotContains('blue', $g['hearts'] ?? []);
            }
        }
    }

    public function testActivatedBlockReasonReportsFullStage(): void
    {
        $sayaka = $this->cardByNo('PL!HS-bp5-002-SEC', 'sayaka_sec');
        $ab = null;
        foreach ($sayaka['abilities'] ?? [] as $a) {
            if (($a['type'] ?? '') === 'pay_energy_play_wr_empty') {
                $ab = $a;
                break;
            }
        }
        $this->assertNotNull($ab);
        $p = [
            'stage' => [
                'left' => $this->filler('a', 2),
                'center' => $sayaka,
                'right' => $this->filler('b', 9),
            ],
            'waiting_room' => [$this->filler('wr2', 2)],
            'energy_zone' => array_fill(0, 4, ['active' => true]),
        ];
        $this->assertSame('no empty Stage area.', activatedAbilityWrBlockReason($p, $ab));
    }
}
