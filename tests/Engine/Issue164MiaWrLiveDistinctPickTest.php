<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #164 — PL!N-bp5-011 Mia On Enter must let the player choose which WR Live(s)
 * are added to hand (not silently auto-pick the first matching cards).
 */
final class Issue164MiaWrLiveDistinctPickTest extends TestCase
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

    private function emptyPlayer(string $id): array
    {
        return [
            'id' => $id,
            'name' => strtoupper($id),
            'hand' => [],
            'waiting_room' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'energy_zone' => [],
            'main_deck' => [
                ['instance_id' => $id . '_deck1', 'card_type' => 'メンバー', 'name_en' => 'Deck'],
            ],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $this->emptyPlayer('p1'),
                'p2' => $this->emptyPlayer('p2'),
            ],
        ];
    }

    public function testNamesOnlyOpensWrLivePickNotAutoAdd(): void
    {
        $mia = $this->cardByNo('PL!N-bp5-011-P', 'mia164');
        // Three differently named Lives, same/empty group → by_name only.
        $liveA = $this->cardByNo('LL-PR-004-PR', 'live_a');
        $liveB = $this->cardByNo('LL-bp5-001-L', 'live_b');
        $liveC = $this->cardByNo('LL-bp5-002-L', 'live_c');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $mia;
        $state['players']['p1']['waiting_room'] = [$liveA, $liveB, $liveC];

        $state = \resolveOnEnterAbilities($state, 'p1', $mia, 'center');

        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pick_wr_to_hand', $pr['type'] ?? null);
        $this->assertSame(1, intval($pr['pick_count'] ?? 0));
        $this->assertFalse(!empty($pr['up_to']));
        $candIds = array_column($pr['candidates'] ?? [], 'instance_id');
        $this->assertEqualsCanonicalizing(['live_a', 'live_b', 'live_c'], $candIds);
        // Nothing auto-added yet.
        $this->assertCount(3, $state['players']['p1']['waiting_room']);
        $this->assertCount(0, $state['players']['p1']['hand']);

        // Player picks the third Live, not the first.
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'live_c']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertSame(['live_c'], $handIds);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertEqualsCanonicalizing(['live_a', 'live_b'], $wrIds);
    }

    public function testBothModesThenPickExactTwoLives(): void
    {
        $mia = $this->cardByNo('PL!N-bp5-011-P', 'mia164b');
        // Three distinct names AND three distinct groups → mode choice first.
        $liveN = $this->cardByNo('PL!N-bp1-025-L', 'live_niji');
        $liveS = $this->cardByNo('PL!S-PR-022-PR', 'live_sun');
        $liveH = $this->cardByNo('PL!HS-PR-010-PR', 'live_hasu');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $mia;
        $state['players']['p1']['waiting_room'] = [$liveN, $liveS, $liveH];

        $state = \resolveOnEnterAbilities($state, 'p1', $mia, 'center');

        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('bp5_wr_live_distinct_choice', $pr['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'by_group']);
        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pick_wr_to_hand', $pr['type'] ?? null);
        $this->assertSame(2, intval($pr['pick_count'] ?? 0));
        $this->assertFalse(!empty($pr['up_to']));
        $this->assertCount(3, $state['players']['p1']['waiting_room']);
        $this->assertCount(0, $state['players']['p1']['hand']);

        // Pick two specific Lives (not first-two auto order).
        $state = \actionResolvePrompt($state, 'p1', [
            'card_ids' => ['live_hasu', 'live_sun'],
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertEqualsCanonicalizing(['live_hasu', 'live_sun'], $handIds);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertSame(['live_niji'], $wrIds);
    }
}
