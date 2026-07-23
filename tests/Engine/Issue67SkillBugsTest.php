<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Regression: GitHub issue #67 — Rurino discard softlock, PB1 auto hand→WR,
 * Do! Do! Do! Live Success energy compare.
 */
final class Issue67SkillBugsTest extends TestCase
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

    private function basePlayers(): array
    {
        return [
            'p1' => [
                'id' => 'p1',
                'name' => 'P1',
                'hand' => [],
                'waiting_room' => [],
                'stage' => ['left' => null, 'center' => null, 'right' => null],
                'energy_zone' => [],
                'energy_deck' => [],
                'main_deck' => [],
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
                'energy_deck' => [],
                'main_deck' => [],
                'success_lives' => [],
                'live_zone' => [],
            ],
        ];
    }

    public function testBuffMemberMatchingDiscardedGroupPromptResolvesBySlot(): void
    {
        $rurino = $this->cardByNo('PL!HS-bp5-003-R＋', 'rurino');
        $other = $this->cardByNo('PL!HS-bp1-001-P', 'other_hs'); // Hasunosora Member, no Live Start discard
        if (($other['group'] ?? '') !== 'Hasunosora') {
            $other['group'] = 'Hasunosora';
        }
        $handCard = $this->cardByNo('PL!HS-bp1-023-L', 'hand_hs'); // Hasunosora group

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['stage']['center'] = $rurino;
        $state['players']['p1']['stage']['left'] = $other;
        $state['players']['p1']['hand'] = [$handCard];
        $state['players']['p1']['live_zone'] = [$this->cardByNo('PL!HS-bp1-023-L', 'live1')];

        // Drive optional_discard_prompt → buff_member_matching_discarded_group (issue #67 softlock).
        $state['pending_prompt'] = [
            'type' => 'optional_discard_prompt',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_id' => 'rurino',
            'source_name' => 'Rurino Osawa',
            'live_start' => true,
            'ability' => $rurino['abilities'][1],
            'choices' => ['yes', 'no'],
            'prompt' => 'discard?',
        ];
        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['hand_hs'],
        ]);

        $ptype = $state['pending_prompt']['type'] ?? null;
        // Two Hasunosora Members → pick prompt; one would auto-apply.
        $this->assertSame('buff_member_matching_discarded_group', $ptype);
        $this->assertNotEmpty($state['pending_prompt']['candidates'] ?? []);

        $state = \actionResolvePrompt($state, 'p1', ['slot' => 'center']);
        $this->assertNotSame(
            'buff_member_matching_discarded_group',
            $state['pending_prompt']['type'] ?? null,
            'Stage pick must clear (no softlock on buff_member prompt)'
        );
        $hearts = \memberPerformanceHeartsFlat($state['players']['p1']['stage']['center']);
        $this->assertContains('pink', $hearts);
    }

    public function testPb1OnEnterDiscardFiresAutoBladeAndPinkHeart(): void
    {
        $rurino = $this->cardByNo('PL!HS-pb1-003-P＋', 'pb1_rurino');
        $mate = $this->cardByNo('PL!HS-bp5-003-R＋', 'mate'); // Mira-Cra Park! subunit
        $mate['subunit'] = 'みらくらぱーく!';

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 1,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['stage']['center'] = $rurino;
        $state['players']['p1']['hand'] = [$mate];
        $state['players']['p1']['main_deck'] = [
            $this->cardByNo('PL!HS-bp1-023-L', 'deck1'),
            $this->cardByNo('PL!HS-bp1-023-L', 'deck2'),
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $rurino, 'center');
        $this->assertSame('discard_subunit_hand_draw', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['discard_ids' => ['mate']]);
        $this->assertEmpty($state['pending_prompt'] ?? null);

        $bladeBonus = intval($state['live_modifiers']['p1']['blade_bonus'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $bladeBonus, 'Auto should grant +1 Blade');
        $flat = \getBonusHeartsFlat($state, 'p1');
        $this->assertContains('pink', $flat, 'Auto should grant pink ♡ (not blade-only)');
    }

    public function testDoDoDoDefersEnergyUntilOppRoundResolved(): void
    {
        $live = $this->cardByNo('PL!HS-bp1-023-L', 'dodo');
        $live['score'] = 2;
        $oppPending = $this->cardByNo('PL!HS-bp1-023-L', 'opp_high');
        $oppPending['score'] = 5;
        $hsMember = $this->cardByNo('PL!HS-bp5-003-R＋', 'hs_mbr');

        $state = [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'live_round_success' => ['p1' => true],
            'log' => [],
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['stage']['center'] = $hsMember;
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['energy_deck'] = [
            ['instance_id' => 'ed1', 'card_type' => 'エネルギー', 'card_type_en' => 'Energy'],
        ];
        // Opponent still holding a higher-score Live that has not been checked yet.
        $state['players']['p2']['live_zone'] = [$oppPending];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$live], 0, [], []);
        $this->assertNotEmpty($state['_deferred_energy_wait_if_winning'] ?? []);
        $this->assertCount(0, $state['players']['p1']['energy_zone'] ?? []);

        // Opponent fails hearts — finalized score 0.
        $state['live_round_success']['p2'] = false;
        $state['players']['p2']['live_zone'] = [];
        $state = \flushDeferredLiveSuccessEnergyWaitIfWinning($state);
        $this->assertCount(1, $state['players']['p1']['energy_zone'] ?? []);
        $this->assertEmpty($state['_deferred_energy_wait_if_winning'] ?? []);
    }

    public function testDoDoDoSkipsWhenOppFinalScoreHigher(): void
    {
        $live = $this->cardByNo('PL!HS-bp1-023-L', 'dodo2');
        $live['score'] = 2;
        $oppLive = $this->cardByNo('PL!HS-bp1-023-L', 'opp_ok');
        $oppLive['score'] = 5;
        $hsMember = $this->cardByNo('PL!HS-bp5-003-R＋', 'hs_mbr2');

        $state = [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'live_round_success' => ['p1' => true, 'p2' => true],
            'log' => [],
            'players' => $this->basePlayers(),
        ];
        $state['players']['p1']['stage']['center'] = $hsMember;
        $state['players']['p1']['live_zone'] = [$live];
        $state['players']['p1']['energy_deck'] = [
            ['instance_id' => 'ed2', 'card_type' => 'エネルギー', 'card_type_en' => 'Energy'],
        ];
        $state['players']['p2']['live_zone'] = [$oppLive];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$live], 0, [], []);
        $this->assertEmpty($state['_deferred_energy_wait_if_winning'] ?? []);
        $this->assertCount(0, $state['players']['p1']['energy_zone'] ?? []);
    }
}
