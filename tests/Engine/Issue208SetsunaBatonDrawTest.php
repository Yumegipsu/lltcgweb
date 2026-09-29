<?php

declare(strict_types=1);

namespace Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #208 — PL!N-PR-025-PR Setsuna: draw when this or another Member enters via Baton Touch.
 */
final class Issue208SetsunaBatonDrawTest extends TestCase
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
        $this->fail('Missing test card ' . $cardNo);
    }

    private function filler(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Filler ' . $id,
            'cost' => 3,
            'active' => true,
        ];
    }

    private function energy(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'instance_id' => 'en_' . $i,
                'card_type' => 'エネルギー',
                'active' => true,
            ];
        }
        return $out;
    }

    private function baseState(array $setsuna, array $incoming, array $host, array $deck): array
    {
        $setsuna['entered_turn'] = 1;
        $host['entered_turn'] = 1;
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 4,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$incoming],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $setsuna,
                        'center' => $host,
                        'right' => null,
                    ],
                    'energy_zone' => $this->energy(20),
                    'main_deck' => $deck,
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
    }

    public function testOtherMemberBatonDrawsWhileSetsunaOnAnotherSlot(): void
    {
        $setsuna = $this->cardByNo('PL!N-PR-025-PR', 'setsuna208');
        $host = $this->filler('host208');
        $incoming = $this->filler('incoming208');
        $incoming['cost'] = 5;
        $deck = [$this->filler('deck208a'), $this->filler('deck208b')];

        $state = $this->baseState($setsuna, $incoming, $host, $deck);
        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'incoming208',
            'slot' => 'center',
            'baton_id' => 'host208',
        ]);

        $this->assertSame('incoming208', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('deck208a', $handIds, 'Setsuna baton draw while on another slot');
        $logText = implode("\n", array_column($state['log'], 'msg'));
        $this->assertStringContainsString('Setsuna', $logText);
        $this->assertStringContainsString('via Baton', $logText);
    }

    public function testSetsunaBatonSelfEnterDraws(): void
    {
        $host = $this->filler('host209');
        $setsuna = $this->cardByNo('PL!N-PR-025-PR', 'setsuna209');
        $deck = [$this->filler('deck209a')];
        $host['entered_turn'] = 1;

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 4,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$setsuna],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $host,
                        'right' => null,
                    ],
                    'energy_zone' => $this->energy(20),
                    'main_deck' => $deck,
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

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'setsuna209',
            'slot' => 'center',
            'baton_id' => 'host209',
        ]);

        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('deck209a', $handIds);
    }

    public function testBatonOntoSetsunaSlotShouldDrawBeforeSheLeaves(): void
    {
        $setsuna = $this->cardByNo('PL!N-PR-025-PR', 'setsuna210');
        $setsuna['entered_turn'] = 1;
        $incoming = $this->filler('incoming210');
        $incoming['cost'] = 5;
        $deck = [$this->filler('deck210a')];

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 4,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$incoming],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $setsuna,
                        'right' => null,
                    ],
                    'energy_zone' => $this->energy(20),
                    'main_deck' => $deck,
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

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'incoming210',
            'slot' => 'center',
            'baton_id' => 'setsuna210',
        ]);

        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains(
            'deck210a',
            $handIds,
            'Replacing Setsuna via baton should still trigger her Automatic draw'
        );
    }

    public function testNormalPlayFromHandDoesNotTriggerSetsunaDraw(): void
    {
        $setsuna = $this->cardByNo('PL!N-PR-025-PR', 'setsuna211');
        $deck = [$this->filler('deck211a')];
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 4,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$setsuna],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => $this->energy(20),
                    'main_deck' => $deck,
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

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'setsuna211',
            'slot' => 'center',
        ]);

        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertNotContains('deck211a', $handIds);
    }
}
