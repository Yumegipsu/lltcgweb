<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class HasunosoraPb1IzumiOriginalBladeTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function member(string $id, int $blade, string $bladeHeart): array
    {
        return [
            'instance_id' => $id,
            'name' => $id,
            'name_en' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'blade' => $blade,
            'blade_hearts' => [$bladeHeart],
            'active' => true,
        ];
    }

    public function testOnEnterWaitsOnlyMembersWithThreeOrFewerPrintedBlades(): void
    {
        $izumi = $this->cardByNo('PL!HS-pb1-008-R', 'izumi');
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $this->member('own_three', 3, 'pink'),
                        'center' => $izumi,
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $this->member('opp_four', 4, 'blue'),
                        'right' => $this->member('opp_two', 2, 'purple'),
                    ],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $izumi, 'center');

        $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertFalse(\memberIsInWait($state['players']['p1']['stage']['center']));
        $this->assertFalse(\memberIsInWait($state['players']['p2']['stage']['center']));
        $this->assertTrue(\memberIsInWait($state['players']['p2']['stage']['right']));
    }
}
