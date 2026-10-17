<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Bokutachi wa Hitotsu no Hikari (PL!-pb2-039) — #248.
 *
 * Live Start sets extra_yell_reveal when ≥2 μ's Success Lives; Yell draw must
 * actually reveal Blade + that extra (modifier was written but never read).
 * Live Success bumps this card's score per distinct μ's name on Stage + Yell.
 */
final class Issue248BokutachiYellExtraTest extends TestCase
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

    private function museMember(string $id, string $nameEn, int $blade = 1): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => $nameEn,
            'name' => $nameEn,
            'group' => "μ's",
            'blade' => $blade,
            'active' => true,
            'hearts' => [['color' => 'pink', 'count' => 1]],
        ];
    }

    private function deckCards(string $prefix, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'instance_id' => $prefix . $i,
                'card_type' => 'メンバー',
                'card_type_en' => 'Member',
                'name_en' => 'Deck' . $i,
                'group' => "μ's",
                'blade' => 1,
            ];
        }
        return $out;
    }

    public function testCatalogAbilityWiring(): void
    {
        $live = $this->cardByNo('PL!-pb2-039-L', 'bokutachi');
        $this->assertSame('increase_yell_reveal_if_success_group', $live['abilities'][0]['type'] ?? null);
        $this->assertSame('live_start', $live['abilities'][0]['trigger'] ?? null);
        $this->assertSame(2, intval($live['abilities'][0]['min_success'] ?? 0));
        $this->assertSame(10, intval($live['abilities'][0]['extra_yell'] ?? 0));
        $this->assertSame('score_per_distinct_group_name_stage_and_yell', $live['abilities'][1]['type'] ?? null);
        $this->assertSame('live_success', $live['abilities'][1]['trigger'] ?? null);
    }

    public function testLiveStartSetsExtraYellWithTwoMuseSuccess(): void
    {
        $bokutachi = $this->cardByNo('PL!-pb2-039-L', 'bokutachi');
        $s1 = $this->cardByNo('PL!-pb2-038-L', 's1');
        $s2 = $this->cardByNo('PL!-pb1-028-L', 's2');

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [$bokutachi],
                    'success_lives' => [$s1, $s2],
                    'energy_zone' => [],
                    'main_deck' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                ],
            ],
        ];

        $state = resolveAbilityEffect($state, 'p1', $bokutachi, $bokutachi['abilities'][0], [
            'phase' => 'live_start',
        ]);

        $this->assertSame(10, intval($state['live_modifiers']['p1']['extra_yell_reveal'] ?? 0));
    }

    public function testLiveStartSkipsExtraWithOneMuseSuccess(): void
    {
        $bokutachi = $this->cardByNo('PL!-pb2-039-L', 'bokutachi');
        $s1 = $this->cardByNo('PL!-pb2-038-L', 's1');

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [$bokutachi],
                    'success_lives' => [$s1],
                    'energy_zone' => [],
                    'main_deck' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                ],
            ],
        ];

        $state = resolveAbilityEffect($state, 'p1', $bokutachi, $bokutachi['abilities'][0], [
            'phase' => 'live_start',
        ]);

        $this->assertSame(0, intval($state['live_modifiers']['p1']['extra_yell_reveal'] ?? 0));
    }

    public function testYellDrawIncludesExtraReveal(): void
    {
        $bokutachi = $this->cardByNo('PL!-pb2-039-L', 'bokutachi');
        $honoka = $this->museMember('honoka', 'Honoka Kosaka', 3);

        $state = [
            'status' => 'playing',
            'phase' => 'live_performance_first',
            'seq' => 2,
            'turn' => 3,
            'active_player' => 'p1',
            'log' => [],
            'live_modifiers' => [
                'p1' => array_merge(liveModifierDefaults(), ['extra_yell_reveal' => 10]),
                'p2' => liveModifierDefaults(),
            ],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $honoka,
                        'right' => null,
                    ],
                    'live_zone' => [$bokutachi],
                    'success_lives' => [],
                    'energy_zone' => [],
                    'main_deck' => $this->deckCards('d', 40),
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                ],
            ],
        ];

        $this->assertSame(3, computeYellBladeTotal($state, 'p1'));
        $this->assertSame(13, computeYellRevealCount($state, 'p1'));

        [$state, $yellCards, $totalBlade, $drawBlade] = drawYellCardsForPlayer($state, 'p1');
        $this->assertSame(3, $totalBlade);
        $this->assertSame(13, $drawBlade);
        $this->assertCount(13, $yellCards);
        $this->assertSame(13, yellBladeDrawnTotal($state, 'p1'));
    }

    public function testLiveSuccessBumpsScorePerDistinctMuseName(): void
    {
        $bokutachi = $this->cardByNo('PL!-pb2-039-L', 'bokutachi');
        $printed = intval($bokutachi['score'] ?? 0);
        $honoka = $this->museMember('honoka', 'Honoka Kosaka', 1);
        $umi = $this->museMember('umi', 'Umi Sonoda', 1);
        // Yell reveals another Honoka (same name) + Kotori (new name).
        $yellHonoka = $this->museMember('yh', 'Honoka Kosaka', 1);
        $yellKotori = $this->museMember('yk', 'Kotori Minami', 1);

        $state = [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 3,
            'turn' => 3,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $honoka,
                        'center' => $umi,
                        'right' => null,
                    ],
                    'live_zone' => [$bokutachi],
                    'success_lives' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'yell_cards' => [$yellHonoka, $yellKotori],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                ],
            ],
            'yell_reveal' => [
                'p1' => [$yellHonoka, $yellKotori],
            ],
            '_last_yell_cards' => [$yellHonoka, $yellKotori],
        ];

        $state = resolveAbilityEffect($state, 'p1', $bokutachi, $bokutachi['abilities'][1], [
            'phase' => 'live_success',
        ]);

        $live = $state['players']['p1']['live_zone'][0];
        // Distinct: Honoka, Umi, Kotori → +3 on this card.
        $this->assertSame(3, intval($live['_effect_score_bonus'] ?? 0));
        $this->assertSame($printed + 3, intval($live['score'] ?? 0));
    }

    public function testAnjuAndCompoundNameDoNotAddExtraPeople(): void
    {
        $bokutachi = $this->cardByNo('PL!-pb2-039-L', 'bokutachi');
        $printed = intval($bokutachi['score'] ?? 0);
        $honoka = $this->museMember('honoka', 'Honoka Kosaka', 1);
        $eli = $this->museMember('eli', 'Eli Ayase', 1);
        // Stale deck copy tagged μ's. Catalog group is A-RISE.
        $anju = $this->cardByNo('PL!-bp5-222-R', 'anju');
        $anju['group'] = "μ's";
        $trio = $this->cardByNo('LL-bp4-001-R＋', 'trio');

        $state = $this->successState($bokutachi, [
            'left' => $honoka,
            'center' => $eli,
            'right' => null,
        ], [$anju, $trio]);

        $state = resolveAbilityEffect($state, 'p1', $bokutachi, $bokutachi['abilities'][1], [
            'phase' => 'live_success',
            'yell_cards' => [$anju, $trio],
        ]);

        $live = $state['players']['p1']['live_zone'][0];
        // Honoka + Eli. Anju is A-RISE. The trio is Eli, not a tenth name.
        $this->assertSame(2, intval($live['_effect_score_bonus'] ?? 0));
        $this->assertSame($printed + 2, intval($live['score'] ?? 0));
    }

    public function testNineMusePlusCompoundCardIsNine(): void
    {
        $bokutachi = $this->cardByNo('PL!-pb2-039-L', 'bokutachi');
        $printed = intval($bokutachi['score'] ?? 0);
        $names = [
            'Honoka Kosaka', 'Eli Ayase', 'Kotori Minami', 'Umi Sonoda',
            'Rin Hoshizora', 'Maki Nishikino', 'Nozomi Tojo', 'Hanayo Koizumi',
            'Nico Yazawa',
        ];
        $stage = ['left' => null, 'center' => null, 'right' => null];
        $slots = ['left', 'center', 'right'];
        $yell = [];
        foreach ($names as $i => $name) {
            $card = $this->museMember('m' . $i, $name, 1);
            if ($i < 3) {
                $stage[$slots[$i]] = $card;
            } else {
                $yell[] = $card;
            }
        }
        $yell[] = $this->cardByNo('LL-bp4-001-R＋', 'trio');

        $state = $this->successState($bokutachi, $stage, $yell);
        $state = resolveAbilityEffect($state, 'p1', $bokutachi, $bokutachi['abilities'][1], [
            'phase' => 'live_success',
            'yell_cards' => $yell,
        ]);

        $live = $state['players']['p1']['live_zone'][0];
        $this->assertSame(9, intval($live['_effect_score_bonus'] ?? 0));
        $this->assertSame($printed + 9, intval($live['score'] ?? 0));
    }

    public function testSuccessUsesHeartCheckYellNotOtherPerformer(): void
    {
        $bokutachi = $this->cardByNo('PL!-pb2-039-L', 'bokutachi');
        $printed = intval($bokutachi['score'] ?? 0);
        $honoka = $this->museMember('honoka', 'Honoka Kosaka', 1);
        $mine = [$this->museMember('yk', 'Kotori Minami', 1)];
        $theirs = [];
        foreach ([
            'Eli Ayase', 'Umi Sonoda', 'Rin Hoshizora', 'Maki Nishikino',
            'Nozomi Tojo', 'Hanayo Koizumi', 'Nico Yazawa',
        ] as $i => $name) {
            $theirs[] = $this->museMember('o' . $i, $name, 1);
        }

        $state = $this->successState($bokutachi, [
            'left' => $honoka,
            'center' => null,
            'right' => null,
        ], null);
        unset($state['players']['p1']['yell_cards']);
        $state['yell_reveal']['p1'] = $theirs;
        $state['_last_yell_cards'] = $theirs;
        $state['players']['p1']['_pending_yell_wr'] = $theirs;

        $state = resolveAbilityEffect($state, 'p1', $bokutachi, $bokutachi['abilities'][1], [
            'phase' => 'live_success',
            'yell_cards' => $mine,
        ]);

        $live = $state['players']['p1']['live_zone'][0];
        // Honoka on stage + Kotori in this heart-check Yell. Not the other 7.
        $this->assertSame(2, intval($live['_effect_score_bonus'] ?? 0));
        $this->assertSame($printed + 2, intval($live['score'] ?? 0));
    }

    private function successState(array $bokutachi, array $stage, ?array $yell): array
    {
        $p1 = [
            'id' => 'p1',
            'name' => 'P1',
            'hand' => [],
            'waiting_room' => [],
            'stage' => $stage,
            'live_zone' => [$bokutachi],
            'success_lives' => [],
            'energy_zone' => [],
            'main_deck' => [],
        ];
        if ($yell !== null) {
            $p1['yell_cards'] = $yell;
        }
        return [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 3,
            'turn' => 3,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                ],
            ],
        ];
    }
}
