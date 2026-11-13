<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #94: AWOKE (PL!HS-cl1-010-CL) must see Live-temporary cost
 * (Fantasy Sayaka stacks → live_cost_bonus), not printed cost alone.
 */
final class Issue94AwokeEffectiveCostTest extends TestCase
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

    private function emptyPlayer(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'hand' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'waiting_room' => [],
            'energy_zone' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    public function testAwokeSeesFantasySayakaLiveCostBonus(): void
    {
        $awoke = $this->cardByNo('PL!HS-cl1-010-CL', 'awoke');
        $sayaka = $this->cardByNo('PL!HS-pb1-002-R', 'sayaka');
        // 3 stacked Members → Live Start +4×3 = +12 → effective 14.
        $sayaka['stacked_members'] = [
            ['instance_id' => 'stack1', 'name' => '村野さやか', 'name_en' => 'Sayaka Murano', 'card_type' => 'メンバー'],
            ['instance_id' => 'stack2', 'name' => '村野さやか', 'name_en' => 'Sayaka Murano', 'card_type' => 'メンバー'],
            ['instance_id' => 'stack3', 'name' => '村野さやか', 'name_en' => 'Sayaka Murano', 'card_type' => 'メンバー'],
        ];

        $filler = $this->cardByNo('PL!HS-bp1-001-P', 'filler');
        $filler['cost'] = 2;
        $filler['group'] = 'Hasunosora';

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['left'] = $filler;
        $p1['stage']['center'] = $sayaka;
        $p1['live_zone'] = [$awoke];

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_attempt' => ['p1'],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            // Sayaka + AWOKE both have Live Start → order prompt before either fires.
            if (($state['pending_prompt']['type'] ?? '') === 'live_start_order_sources') {
                $state = applyAction($state, 'p1', 'resolve_prompt', [
                    'card_ids' => ['sayaka', 'awoke'],
                ]);
            }

            $this->assertSame(
                14,
                \getEffectiveStageMemberCost($state, 'p1', $state['players']['p1']['stage']['center']),
                'Fantasy Sayaka Live Start must raise effective cost first'
            );

            // AWOKE should open a pick (filler printed 2, Sayaka effective 14) or auto-apply on Sayaka.
            $prompt = $state['pending_prompt'] ?? null;
            if ($prompt !== null) {
                $this->assertSame('cl1_pick_stage_member_blade', $prompt['type'] ?? null);
                $slots = array_column($prompt['candidates'] ?? [], 'slot');
                $this->assertContains('center', $slots, 'Boosted Sayaka must be an AWOKE candidate (#94)');
                $this->assertNotContains('left', $slots, 'Printed cost 2 filler must not qualify');
            } else {
                $center = $state['players']['p1']['stage']['center'];
                $this->assertSame(
                    2,
                    intval($center['live_blade_bonus'] ?? 0),
                    'Solo eligible Sayaka should receive +2 Blade from AWOKE'
                );
            }
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testAwokeSeesLiveCostOverride(): void
    {
        $awoke = $this->cardByNo('PL!HS-cl1-010-CL', 'awoke');
        // Use a plain Hasunosora body; Aurora-style absolute cost for this Live.
        $member = $this->cardByNo('PL!HS-bp1-001-P', 'boosted');
        $member['group'] = 'Hasunosora';
        $member['cost'] = 4;
        $member['abilities'] = [];
        $member['live_cost_override'] = 11;

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $member;
        $p1['live_zone'] = [$awoke];

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_attempt' => ['p1'],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            $state = \tcgTestPickOnlyOption($state); // picks always prompt now, even with one option
            $center = $state['players']['p1']['stage']['center'];
            $this->assertSame(
                2,
                intval($center['live_blade_bonus'] ?? 0),
                'live_cost_override ≥10 must qualify for AWOKE (#94)'
            );
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
