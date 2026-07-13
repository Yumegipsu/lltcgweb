<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!-bp6-003-P Kotori — Live Start stack+heart, Live Success play from under. */
final class KotoriBp6003StackUnderTest extends TestCase
{
    private function kotoriAbilities(): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === 'PL!-bp6-003-P') {
                return $card['abilities'] ?? [];
            }
        }
        $this->fail('Missing PL!-bp6-003-P');
    }

    private function museMember(string $id, int $cost = 2): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'name_en' => 'μ\'s Member',
            'group' => "μ's",
            'cost' => $cost,
            'active' => true,
        ];
    }

    private function baseState(array $kotori, array $hand = []): array
    {
        return [
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => $hand,
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $kotori,
                        'right' => null,
                    ],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testLiveStartOffersStackOnlyWhenCenter(): void
    {
        $abilities = $this->kotoriAbilities();
        $ab = $abilities[0];
        $this->assertSame('reveal_hand_named_stack_under', $ab['type'] ?? null);
        $this->assertTrue(!empty($ab['center_only']));
        $this->assertTrue(!empty($ab['grant_heart_choice']));

        $handCard = $this->museMember('hand_m', 2);
        $kotori = [
            'instance_id' => 'kotori',
            'name_en' => 'Kotori Minami',
            'card_type' => 'メンバー',
            'group' => "μ's",
            'cost' => 15,
            'abilities' => $abilities,
            'active' => true,
        ];

        $state = $this->baseState($kotori, [$handCard]);
        $state['players']['p1']['stage']['left'] = $kotori;
        $state['players']['p1']['stage']['center'] = null;
        $state = plMuseGapResolveEffect($state, 'p1', $kotori, $ab, ['slot' => 'left']);
        $this->assertNull($state['pending_prompt'] ?? null);

        $state = $this->baseState($kotori, [$handCard]);
        $state = plMuseGapResolveEffect($state, 'p1', $kotori, $ab, ['slot' => 'center']);
        $this->assertSame('reveal_hand_named_stack_under', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(['yes', 'no'], $state['pending_prompt']['choices'] ?? null);
    }

    public function testLiveStartStackThenHeartChoice(): void
    {
        $abilities = $this->kotoriAbilities();
        $ab = $abilities[0];
        $handCard = $this->museMember('hand_m', 1);
        $kotori = [
            'instance_id' => 'kotori',
            'name_en' => 'Kotori Minami',
            'card_type' => 'メンバー',
            'group' => "μ's",
            'cost' => 15,
            'abilities' => $abilities,
            'active' => true,
            'stacked_members' => [],
        ];
        $state = $this->baseState($kotori, [$handCard]);
        $state = plMuseGapResolveEffect($state, 'p1', $kotori, $ab, ['slot' => 'center']);
        $state['phase'] = 'main_first';
        $state = actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'card_id' => 'hand_m',
        ]);

        $this->assertSame('pl_muse_stack_heart_choice', $state['pending_prompt']['type'] ?? null);
        $this->assertContains('pink', $state['pending_prompt']['choices'] ?? []);
        $stacked = $state['players']['p1']['stage']['center']['stacked_members'] ?? [];
        $this->assertCount(1, $stacked);
        $this->assertSame('hand_m', $stacked[0]['instance_id'] ?? null);
        $this->assertCount(0, $state['players']['p1']['hand']);

        $state = actionResolvePrompt($state, 'p1', ['choice' => 'blue', 'heart_choice' => 'blue']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $bonus = getBonusHeartsFlat($state, 'p1');
        $this->assertContains('blue', $bonus);
    }

    public function testLiveStartSkipDoesNotStack(): void
    {
        $abilities = $this->kotoriAbilities();
        $ab = $abilities[0];
        $handCard = $this->museMember('hand_m', 2);
        $kotori = [
            'instance_id' => 'kotori',
            'name_en' => 'Kotori Minami',
            'card_type' => 'メンバー',
            'group' => "μ's",
            'cost' => 15,
            'abilities' => $abilities,
            'active' => true,
        ];
        $state = $this->baseState($kotori, [$handCard]);
        $state = plMuseGapResolveEffect($state, 'p1', $kotori, $ab, ['slot' => 'center']);
        $state['phase'] = 'main_first';
        $state = actionResolvePrompt($state, 'p1', ['choice' => 'no']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('hand_m', $state['players']['p1']['hand'][0]['instance_id'] ?? null);
        $this->assertEmpty($state['players']['p1']['stage']['center']['stacked_members'] ?? []);
    }

    public function testLiveSuccessPlaysStackedMemberOntoEmptySlot(): void
    {
        $abilities = $this->kotoriAbilities();
        $ab = $abilities[1];
        $this->assertSame('play_stacked_member_from_under', $ab['type'] ?? null);

        $stacked = $this->museMember('under_m', 2);
        $kotori = [
            'instance_id' => 'kotori',
            'name_en' => 'Kotori Minami',
            'card_type' => 'メンバー',
            'group' => "μ's",
            'cost' => 15,
            'abilities' => $abilities,
            'active' => true,
            'stacked_members' => [$stacked],
        ];
        $state = $this->baseState($kotori);
        $state['phase'] = 'live_success';
        $state = plMuseGapResolveEffect($state, 'p1', $kotori, $ab, ['slot' => 'center']);
        $this->assertSame('play_stacked_member_from_under', $state['pending_prompt']['type'] ?? null);
        $this->assertContains('left', $state['pending_prompt']['empty_slots'] ?? []);

        $state = actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'card_id' => 'under_m',
            'slot' => 'left',
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('under_m', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertTrue($state['players']['p1']['stage']['left']['active'] ?? false);
        $this->assertEmpty($state['players']['p1']['stage']['center']['stacked_members'] ?? []);
    }

    public function testLiveSuccessRejectsOccupiedSlot(): void
    {
        $abilities = $this->kotoriAbilities();
        $ab = $abilities[1];
        $stacked = $this->museMember('under_m', 2);
        $kotori = [
            'instance_id' => 'kotori',
            'name_en' => 'Kotori Minami',
            'card_type' => 'メンバー',
            'group' => "μ's",
            'cost' => 15,
            'abilities' => $abilities,
            'active' => true,
            'stacked_members' => [$stacked],
        ];
        $state = $this->baseState($kotori);
        $state['players']['p1']['stage']['left'] = $this->museMember('occ', 3);
        $state = plMuseGapResolveEffect($state, 'p1', $kotori, $ab, ['slot' => 'center']);
        $this->assertContains('right', $state['pending_prompt']['empty_slots'] ?? []);
        $this->assertNotContains('left', $state['pending_prompt']['empty_slots'] ?? []);

        $this->expectException(\Exception::class);
        actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'card_id' => 'under_m',
            'slot' => 'left',
        ]);
    }
}
