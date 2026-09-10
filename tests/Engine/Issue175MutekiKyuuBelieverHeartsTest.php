<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!N-bp5-029-L Muteki-kyuu*Believer — grant 1 of each Heart color, not full counts (#175).
 */
final class Issue175MutekiKyuuBelieverHeartsTest extends TestCase
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

    public function testGrantsOneOfEachColorNotFullHeartCounts(): void
    {
        $live = $this->cardByNo('PL!N-bp5-029-L', 'believer');
        $kasumiStage = $this->cardByNo('PL!N-bp1-002-P', 'kasumi_stage');
        $this->assertSame('Kasumi Nakasu', $kasumiStage['name_en'] ?? '');
        // Multi-count printed hearts: must become one pink + one yellow, not 3+2.
        $kasumiDeck = [
            'instance_id' => 'kasumi_deck',
            'card_no' => 'TEST-KASUMI',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name' => '中須かすみ',
            'name_en' => 'Kasumi Nakasu',
            'group' => 'Nijigasaki',
            'cost' => 5,
            'blade' => 1,
            'active' => true,
            'hearts' => [
                ['color' => 'pink', 'count' => 3],
                ['color' => 'yellow', 'count' => 2],
            ],
            'abilities' => [],
        ];
        $filler = static function (string $id): array {
            return [
                'instance_id' => $id,
                'card_type' => 'メンバー',
                'name_en' => 'Filler',
                'name' => 'フィラー',
                'group' => 'Nijigasaki',
            ];
        };

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            '_live_start_perf_pid' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $kasumiStage,
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [$kasumiDeck, $filler('f1'), $filler('f2'), $filler('f3')],
                    'live_zone' => [$live],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $ab = null;
        foreach ($live['abilities'] as $row) {
            if (($row['type'] ?? '') === 'live_start_reveal_pick_named_hearts') {
                $ab = $row;
                break;
            }
        }
        $this->assertNotNull($ab);

        $state = \resolveAbilityEffect($state, 'p1', $live, $ab, ['phase' => 'live_start']);
        $mbr = $state['players']['p1']['stage']['center'];
        $bonus = $mbr['bonus_hearts'] ?? [];
        $counts = array_count_values(array_map('strval', $bonus));
        $this->assertSame(1, intval($counts['pink'] ?? 0), 'Must gain 1 pink, not 3');
        $this->assertSame(1, intval($counts['yellow'] ?? 0), 'Must gain 1 yellow, not 2');
        $this->assertCount(2, $bonus);
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testDistinctPrintedHeartColorsHelper(): void
    {
        $colors = \nBp5DistinctPrintedHeartColors([
            'hearts' => [
                ['color' => 'pink', 'count' => 4],
                ['color' => 'green', 'count' => 1],
                ['color' => 'pink', 'count' => 1],
            ],
        ]);
        sort($colors);
        $this->assertSame(['green', 'pink'], $colors);
    }
}
