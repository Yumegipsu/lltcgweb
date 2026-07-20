<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!HS-bp1-002 Sayaka — WR play must enter Active so Blades count for Yell. */
final class SayakaBp1002WrPlayBladeTest extends TestCase
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

    private function energy(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'instance_id' => 'en' . $i,
                'card_type' => 'エネルギー',
                'card_type_en' => 'Energy',
                'active' => true,
            ];
        }
        return $out;
    }

    public function testSingleWrTargetEntersActiveAndContributesBlade(): void
    {
        $sayaka = $this->cardByNo('PL!HS-bp1-002-P', 'sayaka');
        $fromWr = $this->cardByNo('PL!HS-bp1-005-R', 'fromwr');
        $printedBlade = intval($fromWr['blade'] ?? 0);
        $this->assertGreaterThan(0, $printedBlade);

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
                    'waiting_room' => [$fromWr],
                    'stage' => [
                        'left' => null,
                        'center' => $sayaka,
                        'right' => null,
                    ],
                    'energy_zone' => $this->energy(4),
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'sayaka',
            'ability_index' => 0,
        ]);

        $played = $state['players']['p1']['stage']['center'] ?? null;
        $this->assertNotNull($played);
        $this->assertSame('fromwr', $played['instance_id'] ?? null);
        $this->assertTrue($played['active'] ?? false);
        $this->assertFalse(\memberIsInWait($played));
        $this->assertTrue(\memberContributesBladeToYell($played));
        $this->assertSame(
            $printedBlade,
            \getMemberBlade($played, $state, 'p1', 'center')
        );
        $this->assertSame(
            $printedBlade,
            \computeYellBladeTotal($state, 'p1')
        );
    }

    public function testMultiWrOpensPickThenActiveBlade(): void
    {
        $sayaka = $this->cardByNo('PL!HS-bp1-002-P', 'sayaka');
        $a = $this->cardByNo('PL!HS-bp1-005-R', 'wr_a');
        $b = $this->cardByNo('PL!HS-bp1-007-R', 'wr_b');

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
                    'waiting_room' => [$a, $b],
                    'stage' => [
                        'left' => null,
                        'center' => $sayaka,
                        'right' => null,
                    ],
                    'energy_zone' => $this->energy(4),
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'sayaka',
            'ability_index' => 0,
        ]);
        $this->assertSame('hs_leave_play_wr_slot', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'card_id' => 'wr_b',
        ]);

        $played = $state['players']['p1']['stage']['center'] ?? null;
        $this->assertSame('wr_b', $played['instance_id'] ?? null);
        $this->assertTrue($played['active'] ?? false);
        $this->assertTrue(\memberContributesBladeToYell($played));
        $this->assertGreaterThan(0, \computeYellBladeTotal($state, 'p1'));
    }
}
