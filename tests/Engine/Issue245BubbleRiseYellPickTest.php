<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Bubble Rise (PL!SP-bp2-025) — Live Success Yell pick when ≥2 of Kanon/Wien/Tomari
 * are on Stage (#245). Catalog mixed EN+JP ability names; countDistinctNamedOnStage
 * used to compare name_en only, so Kanon+Tomari counted as 1 and the pick never opened.
 */
final class Issue245BubbleRiseYellPickTest extends TestCase
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

    private function yellMember(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Yell Member',
            'name' => 'エールメンバー',
            'group' => 'Superstar',
            'cost' => 2,
        ];
    }

    public function testCountDistinctMatchesJpAbilityNamesAgainstEnStage(): void
    {
        $kanon = $this->cardByNo('PL!SP-PR-003-PR', 'kanon');
        $tomari = $this->cardByNo('PL!SP-PR-013-PR', 'tomari');
        $p = ['stage' => ['left' => $kanon, 'center' => $tomari, 'right' => null]];

        // Legacy mixed catalog (EN + JP) — the failing case from the report.
        $legacy = ['Kanon Shibuya', 'ウィーン・マルガレーテ', '鬼塚冬毬'];
        $this->assertSame(2, countDistinctNamedOnStage($p, $legacy));

        $en = ['Kanon Shibuya', 'Wien Margarete', 'Tomari Onitsuka'];
        $this->assertSame(2, countDistinctNamedOnStage($p, $en));

        $jp = ['澁谷かのん', 'ウィーン・マルガレーテ', '鬼塚冬毬'];
        $this->assertSame(2, countDistinctNamedOnStage($p, $jp));
    }

    public function testCatalogUsesEnglishAbilityNames(): void
    {
        $bubble = $this->cardByNo('PL!SP-bp2-025-L', 'bubble');
        $ab = $bubble['abilities'][0] ?? [];
        $this->assertSame('live_success_pick_yell_card', $ab['type'] ?? null);
        $this->assertSame(2, intval($ab['min_distinct_named_on_stage'] ?? 0));
        $this->assertSame(
            ['Kanon Shibuya', 'Wien Margarete', 'Tomari Onitsuka'],
            $ab['names'] ?? []
        );
    }

    public function testKanonPlusTomariOpensYellPick(): void
    {
        $bubble = $this->cardByNo('PL!SP-bp2-025-SRL', 'bubble');
        $kanon = $this->cardByNo('PL!SP-PR-003-PR', 'kanon');
        $tomari = $this->cardByNo('PL!SP-PR-013-PR', 'tomari');
        $yellA = $this->yellMember('ya');
        $yellB = $this->yellMember('yb');

        $state = [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 2,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $kanon,
                        'center' => $tomari,
                        'right' => null,
                    ],
                    'live_zone' => [],
                    'success_lives' => [$bubble],
                    'energy_zone' => [],
                    'main_deck' => [],
                    '_pending_yell_wr' => [$yellA, $yellB],
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

        $state = resolveAbilityEffect($state, 'p1', $bubble, $bubble['abilities'][0], [
            'phase' => 'live_success',
            'yell_cards' => [$yellA, $yellB],
        ]);

        $prompt = $state['pending_prompt'] ?? null;
        $this->assertIsArray($prompt);
        $this->assertSame('pick_yell_member', $prompt['type'] ?? null);
        $ids = array_column($prompt['candidates'] ?? [], 'instance_id');
        $this->assertContains('ya', $ids);
        $this->assertContains('yb', $ids);
    }

    public function testSingleNamedMemberDoesNotOpenPick(): void
    {
        $bubble = $this->cardByNo('PL!SP-bp2-025-L', 'bubble');
        $kanon = $this->cardByNo('PL!SP-PR-003-PR', 'kanon');
        $yellA = $this->yellMember('ya');
        $yellB = $this->yellMember('yb');

        $state = [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 2,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $kanon,
                        'center' => null,
                        'right' => null,
                    ],
                    'live_zone' => [],
                    'success_lives' => [$bubble],
                    'energy_zone' => [],
                    'main_deck' => [],
                    '_pending_yell_wr' => [$yellA, $yellB],
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

        $state = resolveAbilityEffect($state, 'p1', $bubble, $bubble['abilities'][0], [
            'phase' => 'live_success',
            'yell_cards' => [$yellA, $yellB],
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame([], $state['players']['p1']['hand']);
    }
}
