<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Regression: GitHub #97 — Mia WR deck-top, Mia mill Live Success,
 * EMOTION gray hearts, Ayumu energy-stack auto.
 */
final class Issue97CardBugsTest extends TestCase
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

    private function baseState(string $phase = 'main_first'): array
    {
        return [
            'status' => 'playing',
            'phase' => $phase,
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'log' => [],
            'players' => [
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
            ],
        ];
    }

    public function testMiaBp7011LiveSuccessWrToDeckTopWithCardId(): void
    {
        $mia = $this->cardByNo('PL!N-bp7-011-SEC', 'issue97_mia7');
        $niji = $this->cardByNo('PL!N-bp1-002-P', 'issue97_niji_wr');
        $other = [
            'instance_id' => 'issue97_muse_wr',
            'card_type' => 'メンバー',
            'group' => "μ's",
            'name_en' => 'Muse Member',
        ];

        $state = $this->baseState('main_first');
        $state['players']['p1']['stage']['center'] = $mia;
        $state['players']['p1']['waiting_room'] = [$niji, $other];
        $state['players']['p1']['main_deck'] = [['instance_id' => 'deck_pad']];

        $state = \resolveAbilityEffect($state, 'p1', $mia, [
            'trigger' => 'live_success',
            'type' => 'optional_wr_to_deck_top',
            'group' => 'Nijigasaki',
            'count' => 1,
        ], ['phase' => 'live_success']);

        $this->assertSame('optional_wr_to_deck_top', $state['pending_prompt']['type'] ?? null);
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains('issue97_niji_wr', $candIds);
        $this->assertNotContains('issue97_muse_wr', $candIds, 'Only Nijigasaki WR cards');

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'card_id' => 'issue97_niji_wr',
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('issue97_niji_wr', $state['players']['p1']['main_deck'][0]['instance_id'] ?? null);
        $wrIds = array_column($state['players']['p1']['waiting_room'] ?? [], 'instance_id');
        $this->assertNotContains('issue97_niji_wr', $wrIds);
        $this->assertContains('issue97_muse_wr', $wrIds);
    }

    public function testMiaBp7011BareYesOpensPickStep(): void
    {
        $mia = $this->cardByNo('PL!N-bp7-011-SEC', 'issue97_mia7b');
        $niji = $this->cardByNo('PL!N-bp1-002-P', 'issue97_niji_wr2');

        $state = $this->baseState('main_first');
        $state['players']['p1']['stage']['center'] = $mia;
        $state['players']['p1']['waiting_room'] = [$niji];
        $state['players']['p1']['main_deck'] = [['instance_id' => 'deck_pad']];

        $state = \resolveAbilityEffect($state, 'p1', $mia, [
            'trigger' => 'live_success',
            'type' => 'optional_wr_to_deck_top',
            'group' => 'Nijigasaki',
            'count' => 1,
        ], ['phase' => 'live_success']);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertSame('optional_wr_to_deck_top', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('pick', $state['pending_prompt']['step'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'card_id' => 'issue97_niji_wr2',
        ]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('issue97_niji_wr2', $state['players']['p1']['main_deck'][0]['instance_id'] ?? null);
    }

    public function testMiaBp4011MillThenPickWrLive(): void
    {
        $mia = $this->cardByNo('PL!N-bp4-011-SEC', 'issue97_mia4');
        $lives = [];
        foreach (['A', 'B', 'C'] as $i => $letter) {
            $lives[] = [
                'instance_id' => 'issue97_live_' . $letter,
                'card_type' => 'ライブ',
                'group' => 'Nijigasaki',
                'name_en' => 'Niji Live ' . $letter,
                'name' => 'Niji Live ' . $letter,
            ];
        }
        $millPad = [];
        for ($i = 0; $i < 5; $i++) {
            $millPad[] = [
                'instance_id' => 'issue97_mill_' . $i,
                'card_type' => 'メンバー',
                'group' => 'Nijigasaki',
                'name_en' => 'Pad ' . $i,
            ];
        }

        $state = $this->baseState('live_success_effects');
        $state['players']['p1']['stage']['center'] = $mia;
        $state['players']['p1']['waiting_room'] = $lives;
        $state['players']['p1']['main_deck'] = $millPad;
        $state['_live_success_ctx'] = [
            'pid' => 'p1',
            'success_cards' => [],
            'excess_hearts' => 0,
            'excess_colors' => [],
            'yell_cards' => [],
        ];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [], 0, [], []);
        // Live Start optional is ability 0; Live Success mill is ability 1 — mill first if LS already done.
        // resolveLiveSuccessAbilities only runs live_success triggers.
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null, json_encode($state['pending_prompt'] ?? null));
        $this->assertCount(8, $state['players']['p1']['waiting_room']); // 3 lives + 5 milled

        $pickId = $state['pending_prompt']['candidates'][0]['instance_id'] ?? '';
        $this->assertNotSame('', $pickId);
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => $pickId]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'] ?? [], 'instance_id');
        $this->assertContains($pickId, $handIds);
    }

    public function testEmotionGrayHeartIncreaseAppliesToRequirements(): void
    {
        $emotion = $this->cardByNo('PL!N-bp4-027-L', 'issue97_emotion');
        $prior = $this->cardByNo('PL!N-bp4-027-L', 'issue97_emotion_prior');

        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['live_zone'] = [$emotion];
        $state['players']['p1']['success_lives'] = [$prior];

        $printed = $emotion['required_hearts'] ?? $emotion['hearts'] ?? [];
        $beforeAny = 0;
        foreach ($printed as $h) {
            if (in_array($h['color'] ?? '', ['any', 'gray', 'wild', ''], true)) {
                $beforeAny += intval($h['count'] ?? 1);
            }
        }

        $state = \resolveLiveStartAbilities($state, 'p1');
        $live = $state['players']['p1']['live_zone'][0] ?? null;
        $this->assertNotNull($live);
        $this->assertGreaterThan(0, intval($live['hearts_increase_gray'] ?? 0));
        $this->assertGreaterThan(
            intval($emotion['score'] ?? 2),
            intval($live['score'] ?? 0),
            'Score should bump +2 per EMOTION in Success'
        );

        $effective = \applyLiveHeartReductions(
            $live['required_hearts'] ?? $live['hearts'] ?? [],
            $live
        );
        $afterAny = 0;
        foreach ($effective as $h) {
            if (\normalizeRequiredHeartColor((string)($h['color'] ?? '')) === 'any') {
                $afterAny += intval($h['count'] ?? 1);
            }
        }
        $this->assertSame($beforeAny + 3, $afterAny, 'Required gray hearts +3 per prior EMOTION');
    }

    public function testEmotionHeartIncreaseDoesNotStackFromPriorPlayLeftovers(): void
    {
        $emotion = $this->cardByNo('PL!N-bp4-027-L', 'issue174_emotion');
        $prior = $this->cardByNo('PL!N-bp4-027-L', 'issue174_emotion_prior');
        // Leftover from a previous Live attempt on this physical card (WR reuse bug).
        $emotion['hearts_increase_gray'] = 9;
        $emotion['score'] = 20;
        $emotion['_effect_score_bonus'] = 18;

        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['live_zone'] = [$emotion];
        $state['players']['p1']['success_lives'] = [$prior];
        // WR copies must never inflate the Success Live count.
        $state['players']['p1']['waiting_room'] = [
            $this->cardByNo('PL!N-bp4-027-L', 'issue174_wr_a'),
            $this->cardByNo('PL!N-bp4-027-L', 'issue174_wr_b'),
        ];

        $printed = $emotion['required_hearts'] ?? $emotion['hearts'] ?? [];
        $beforeAny = 0;
        foreach ($printed as $h) {
            if (in_array($h['color'] ?? '', ['any', 'gray', 'wild', ''], true)) {
                $beforeAny += intval($h['count'] ?? 1);
            }
        }

        $state = \resolveLiveStartAbilities($state, 'p1');
        $live = $state['players']['p1']['live_zone'][0] ?? null;
        $this->assertNotNull($live);
        $this->assertSame(3, intval($live['hearts_increase_gray'] ?? 0), 'Must set from Success count, not stack leftovers');
        $this->assertSame(4, intval($live['score'] ?? 0), 'Printed 2 + 2 for one Success EMOTION');

        $effective = \applyLiveHeartReductions(
            $live['required_hearts'] ?? $live['hearts'] ?? [],
            $live
        );
        $afterAny = 0;
        foreach ($effective as $h) {
            if (\normalizeRequiredHeartColor((string) ($h['color'] ?? '')) === 'any') {
                $afterAny += intval($h['count'] ?? 1);
            }
        }
        $this->assertSame($beforeAny + 3, $afterAny);
    }

    public function testEmotionRestoreClearsHeartIncreaseBeforeWaitingRoom(): void
    {
        $emotion = $this->cardByNo('PL!N-bp4-027-L', 'issue174_restore');
        $emotion['hearts_increase_gray'] = 6;
        $emotion['score'] = 8;
        $emotion['_effect_score_bonus'] = 6;

        $restored = \liveCardRestorePrintedScore($emotion);
        $this->assertSame(2, intval($restored['score'] ?? 0));
        $this->assertArrayNotHasKey('hearts_increase_gray', $restored);
        $this->assertArrayNotHasKey('_effect_score_bonus', $restored);
    }

    public function testAyumuAutoOnNijiEnergyStack(): void
    {
        $ayumu = $this->cardByNo('PL!N-bp7-001-P', 'issue97_ayumu');
        $mia = $this->cardByNo('PL!N-pb1-011-R', 'issue97_mia_stack');
        $live = [
            'instance_id' => 'issue97_niji_live',
            'card_type' => 'ライブ',
            'group' => 'Nijigasaki',
            'name_en' => 'Niji Live',
        ];
        $waitEnergy = [
            'instance_id' => 'issue97_edeck',
            'card_type' => 'エネルギー',
            'card_type_en' => 'Energy',
            'name_en' => 'Energy',
        ];

        $state = $this->baseState();
        $state['players']['p1']['stage']['left'] = $ayumu;
        $state['players']['p1']['stage']['center'] = $mia;
        $state['players']['p1']['waiting_room'] = [$live];
        $state['players']['p1']['energy_zone'] = [
            ['instance_id' => 'e_free', 'active' => true, 'card_type' => 'エネルギー'],
        ];
        $state['players']['p1']['energy_deck'] = [$waitEnergy];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'issue97_mia_stack',
            'ability_index' => 1,
        ]);

        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        // "Wait" = rested Energy in the Energy zone (not Waiting Room).
        $zoneIds = array_column($state['players']['p1']['energy_zone'] ?? [], 'instance_id');
        $this->assertContains('issue97_edeck', $zoneIds, 'Ayumu should put Energy deck card into Wait (Energy zone)');
        $this->assertCount(0, $state['players']['p1']['energy_deck']);
        $edeckCard = null;
        foreach ($state['players']['p1']['energy_zone'] as $e) {
            if (($e['instance_id'] ?? '') === 'issue97_edeck') {
                $edeckCard = $e;
                break;
            }
        }
        $this->assertNotNull($edeckCard);
        $this->assertFalse(!empty($edeckCard['active']), 'Wait Energy should be inactive');
    }

    public function testAiMiyashitaDrawOnThirdEnter(): void
    {
        $ai = $this->cardByNo('PL!N-bp3-005-SEC', 'issue97_ai');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $ai;
        $state['players']['p1']['members_entered_this_turn'] = 2;
        $state['players']['p1']['hand'] = [
            ['instance_id' => 'h1', 'card_type' => 'メンバー'],
            ['instance_id' => 'h2', 'card_type' => 'メンバー'],
        ];
        $state['players']['p1']['main_deck'] = [
            ['instance_id' => 'd1', 'card_type' => 'メンバー'],
            ['instance_id' => 'd2', 'card_type' => 'メンバー'],
            ['instance_id' => 'd3', 'card_type' => 'メンバー'],
        ];

        $entered = $this->cardByNo('PL!N-bp1-002-P', 'issue97_enter3');
        $state['players']['p1']['stage']['left'] = $entered;
        $state = \nijiOnMemberEntered($state, 'p1', $entered);

        $this->assertSame(3, intval($state['players']['p1']['members_entered_this_turn'] ?? 0));
        $this->assertCount(5, $state['players']['p1']['hand']);
    }
}
