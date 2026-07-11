<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!HS-cl1-002-CL Sayaka — optional pay, add DOLLCHESTRA from WR (subunit filter). */
final class SayakaClWrPickTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                $card['entered_turn'] = 1;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    /** @return list<array<string, mixed>> */
    private function activeEnergy(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'instance_id' => 'hs002_en' . $i,
                'card_type' => 'エネルギー',
                'active' => true,
            ];
        }
        return $out;
    }

    public function testWrPickCfgCopiesSubunitAndEmptyFilter(): void
    {
        $cfg = \wrPickCfgFromAbility([
            'type' => 'add_from_wr',
            'subunit' => 'DOLLCHESTRA',
            'filter' => '',
            'count' => 1,
        ]);

        $this->assertSame('DOLLCHESTRA', $cfg['subunit'] ?? null);
        $this->assertSame('', $cfg['filter'] ?? 'missing');
    }

    public function testSayakaPayYesOpensSubunitFilteredWrPick(): void
    {
        $sayaka = $this->cardByNo('PL!HS-cl1-002-CL', 'sayaka_1');
        $dollA = $this->cardByNo('PL!HS-cl1-002-CL', 'doll_wr_a');
        $dollB = $this->cardByNo('PL!HS-cl1-002-CL', 'doll_wr_b');
        $other = [
            'instance_id' => 'other_wr',
            'card_no' => 'OTHER-MEMBER',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Other Member',
            'group' => 'Hasunosora',
            'subunit' => 'スリーズブーケ',
        ];

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'turn' => 2,
            'seq' => 5,
            'active_player' => 'p1',
            'first_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$sayaka],
                    'main_deck' => [
                        ['instance_id' => 'deck1', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                        ['instance_id' => 'deck2', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                        ['instance_id' => 'deck3', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                    ],
                    'energy_deck' => [],
                    'energy_zone' => $this->activeEnergy(6),
                    'waiting_room' => [$dollA, $dollB, $other],
                    'live_zone' => [],
                    'stage' => [
                        'center' => null,
                        'left' => null,
                        'right' => null,
                    ],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'main_deck' => [
                        ['instance_id' => 'deck1', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                        ['instance_id' => 'deck2', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                        ['instance_id' => 'deck3', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                    ],
                    'energy_deck' => [],
                    'energy_zone' => [],
                    'waiting_room' => [],
                    'live_zone' => [],
                    'stage' => ['center' => null, 'left' => null, 'right' => null],
                    'success_lives' => [],
                ],
            ],
        ];

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'sayaka_1',
            'slot' => 'center',
        ]);
        $this->assertSame('optional_pay_energy_on_enter', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pick_wr_to_hand', $pr['type'] ?? null);
        $this->assertSame('DOLLCHESTRA', $pr['wr_pick_cfg']['subunit'] ?? null);
        $this->assertSame('', $pr['wr_pick_cfg']['filter'] ?? 'missing');

        $ids = array_column($pr['candidates'] ?? [], 'instance_id');
        $this->assertContains('doll_wr_a', $ids);
        $this->assertContains('doll_wr_b', $ids);
        $this->assertNotContains('other_wr', $ids);

        $summary = $pr['candidates'][0] ?? [];
        $this->assertNotEmpty($summary['subunit'] ?? $summary['subunits'] ?? null);
    }

    public function testSayakaPayYesAutoAddsWhenSingleDollchestraInWr(): void
    {
        $sayaka = $this->cardByNo('PL!HS-cl1-002-CL', 'sayaka_auto');
        $doll = $this->cardByNo('PL!HS-cl1-002-CL', 'doll_only');
        $other = [
            'instance_id' => 'bouquet_wr',
            'card_no' => 'OTHER-MEMBER',
            'card_type' => 'メンバー',
            'name_en' => 'Bouquet',
            'group' => 'Hasunosora',
            'subunit' => 'スリーズブーケ',
        ];

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'turn' => 2,
            'seq' => 5,
            'active_player' => 'p1',
            'first_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$sayaka],
                    'main_deck' => [
                        ['instance_id' => 'deck1', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                        ['instance_id' => 'deck2', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                        ['instance_id' => 'deck3', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                    ],
                    'energy_deck' => [],
                    'energy_zone' => $this->activeEnergy(6),
                    'waiting_room' => [$doll, $other],
                    'live_zone' => [],
                    'stage' => ['center' => null, 'left' => null, 'right' => null],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'main_deck' => [
                        ['instance_id' => 'deck1', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                        ['instance_id' => 'deck2', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                        ['instance_id' => 'deck3', 'card_type' => 'メンバー', 'group' => 'Hasunosora'],
                    ],
                    'energy_deck' => [],
                    'energy_zone' => [],
                    'waiting_room' => [],
                    'live_zone' => [],
                    'stage' => ['center' => null, 'left' => null, 'right' => null],
                    'success_lives' => [],
                ],
            ],
        ];

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'sayaka_auto',
            'slot' => 'center',
        ]);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('doll_only', $handIds);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertNotContains('doll_only', $wrIds);
        $this->assertContains('bouquet_wr', $wrIds);
    }
}
