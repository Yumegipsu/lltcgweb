<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!N-bp5-029-L Muteki-kyuu*Believer — hearts go to ONE Stage Kasumi, not all (#185).
 */
final class Issue185MutekiKyuuBelieverOneKasumiTest extends TestCase
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

    private function ability(array $live): array
    {
        foreach ($live['abilities'] as $row) {
            if (($row['type'] ?? '') === 'live_start_reveal_pick_named_hearts') {
                return $row;
            }
        }
        $this->fail('Missing live_start_reveal_pick_named_hearts');
    }

    private function kasumiDeckCard(string $id): array
    {
        return [
            'instance_id' => $id,
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
    }

    private function filler(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'name_en' => 'Filler',
            'name' => 'フィラー',
            'group' => 'Nijigasaki',
        ];
    }

    private function baseState(array $stage, array $deck, array $live): array
    {
        return [
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
                    'stage' => $stage,
                    'energy_zone' => [],
                    'main_deck' => $deck,
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
    }

    public function testTwoStageKasumisOpensPickAndBuffsOnlyChosen(): void
    {
        $live = $this->cardByNo('PL!N-bp5-029-L', 'believer');
        $ab = $this->ability($live);
        $this->assertSame(1, intval($ab['max_members'] ?? 0));

        $kasumiL = $this->cardByNo('PL!N-bp1-002-P', 'kasumi_left');
        $kasumiR = $this->cardByNo('PL!N-bp1-002-P', 'kasumi_right');
        $this->assertSame('Kasumi Nakasu', $kasumiL['name_en'] ?? '');

        $state = $this->baseState(
            [
                'left' => $kasumiL,
                'center' => null,
                'right' => $kasumiR,
            ],
            [$this->kasumiDeckCard('kasumi_deck'), $this->filler('f1'), $this->filler('f2'), $this->filler('f3')],
            $live
        );

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveAbilityEffect($state, 'p1', $live, $ab, ['phase' => 'live_start']);
            $pr = $state['pending_prompt'] ?? null;
            $this->assertIsArray($pr);
            $this->assertSame('bp5_pick_kasumi_stage_hearts', $pr['type'] ?? '');
            $this->assertSame(1, intval($pr['max_members'] ?? 0));
            $this->assertCount(2, $pr['stage_members'] ?? []);
            $this->assertEmpty($state['players']['p1']['stage']['left']['bonus_hearts'] ?? []);
            $this->assertEmpty($state['players']['p1']['stage']['right']['bonus_hearts'] ?? []);

            $state = \actionResolvePrompt($state, 'p1', ['member_ids' => ['kasumi_left']]);
            $this->assertNull($state['pending_prompt'] ?? null);

            $leftBonus = $state['players']['p1']['stage']['left']['bonus_hearts'] ?? [];
            $rightBonus = $state['players']['p1']['stage']['right']['bonus_hearts'] ?? [];
            $leftCounts = array_count_values(array_map('strval', $leftBonus));
            $this->assertSame(1, intval($leftCounts['pink'] ?? 0));
            $this->assertSame(1, intval($leftCounts['yellow'] ?? 0));
            $this->assertCount(2, $leftBonus);
            $this->assertEmpty($rightBonus, 'Unchosen Stage Kasumi must not gain hearts');
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testEnglishSkillSaysOneKasumiNotEach(): void
    {
        $live = $this->cardByNo('PL!N-bp5-029-L', 'believer');
        $en = (string)($live['text'] ?? '');
        $jp = (string)($live['text_jp'] ?? '');
        $this->assertStringContainsString('1 "Kasumi Nakasu" on your Stage', $en);
        $this->assertStringNotContainsString('each "Kasumi Nakasu"', $en);
        $this->assertStringContainsString('「中須かすみ」1人', $jp);
        $this->assertStringNotContainsString('each Kasumi', strtolower($en));
    }
}
