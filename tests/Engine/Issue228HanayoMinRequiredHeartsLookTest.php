<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-008 Hanayo — On Enter look may only pick μ's Lives with 8+ required hearts (#228).
 */
final class Issue228HanayoMinRequiredHeartsLookTest extends TestCase
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

    private function live(string $id, int $heartTotal, string $group = "μ's"): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-test-live-' . $id,
            'name_en' => 'Test Live ' . $id,
            'card_type' => 'ライブ',
            'group' => $group,
            'score' => 1,
            'required_hearts' => [
                ['color' => 'pink', 'count' => $heartTotal],
            ],
            'hearts' => [
                ['color' => 'pink', 'count' => $heartTotal],
            ],
            'abilities' => [],
        ];
    }

    private function member(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-test-member-' . $id,
            'name_en' => 'Test Member',
            'card_type' => 'メンバー',
            'group' => "μ's",
            'cost' => 1,
            'active' => true,
            'abilities' => [],
        ];
    }

    public function testCatalogRequiresEightHearts(): void
    {
        $hanayo = $this->cardByNo('PL!-pb2-008-R', 'hanayo');
        $ab = $hanayo['abilities'][0] ?? [];
        $this->assertSame('optional_wait_self_look_reveal', $ab['type'] ?? null);
        $min = intval($ab['min_required_hearts'] ?? $ab['min_required_hearts_total'] ?? 0);
        $this->assertSame(8, $min);
        $this->assertSame('live', $ab['filter'] ?? null);
        $this->assertSame("μ's", $ab['group'] ?? null);
    }

    public function testLookPickEligibleOnlyLivesWithEightPlusHearts(): void
    {
        $hanayo = $this->cardByNo('PL!-pb2-008-R', 'hanayo');
        $small = $this->live('small', 4);
        $big = $this->live('big', 8);
        $huge = $this->live('huge', 12);
        $otherGroup = $this->live('aqours', 10, 'Aqours');
        $deckMember = $this->member('m1');

        $state = [
            'room_id' => 'ISSUE228',
            'status' => 'playing',
            'seq' => 10,
            'turn' => 2,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => $hanayo, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => array_fill(0, 12, ['card_type' => 'エネルギー', 'active' => true]),
                    'main_deck' => [$small, $big, $otherGroup, $deckMember],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $hanayo, 'center');
        $this->assertSame('optional_wait_self_look_reveal', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pick_looked_deck_hand', $pr['type'] ?? null);
        $eligible = $pr['eligible_ids'] ?? [];
        $this->assertContains('big', $eligible, '8-heart μ\'s Live should be eligible');
        $this->assertNotContains('small', $eligible, '4-heart Live must not be eligible');
        $this->assertNotContains('aqours', $eligible, 'Non-μ\'s Live must not be eligible');
        $this->assertNotContains('m1', $eligible, 'Member must not be eligible');

        // Also include a 12-heart Live by replacing deck and re-running filter unit-style.
        $cfg = $hanayo['abilities'][0];
        $this->assertTrue(cardMatchesLookPick($huge, $cfg));
        $this->assertFalse(cardMatchesLookPick($small, $cfg));
        $this->assertTrue(cardMatchesLookPick($big, $cfg));
    }

    public function testMinRequiredHeartsTotalAliasWorks(): void
    {
        $cfg = [
            'filter' => 'live',
            'group' => "μ's",
            'min_required_hearts_total' => 8,
        ];
        $this->assertFalse(cardMatchesLookPick($this->live('a', 7), $cfg));
        $this->assertTrue(cardMatchesLookPick($this->live('b', 8), $cfg));
    }
}
