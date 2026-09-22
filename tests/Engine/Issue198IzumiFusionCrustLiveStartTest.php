<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #198: PL!HS-bp6-008 Live Start must activate when Fusion Crust
 * (printed score 2) is in Live storage — even if runtime score was bumped,
 * or the Live copy was stripped of score / type fields.
 */
final class Issue198IzumiFusionCrustLiveStartTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents(CARDS_FILE), true);
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
            'token' => $id . '-tok',
        ];
    }

    private function liveStartAbility(array $izumi): array
    {
        foreach ($izumi['abilities'] ?? [] as $ab) {
            if (($ab['type'] ?? '') === 'auto_activate_if_live_zone_score_max') {
                return $ab;
            }
        }
        $this->fail('Missing auto_activate_if_live_zone_score_max on Izumi');
    }

    public function testFusionCrustPrintedScore2ClearsWait(): void
    {
        $izumi = $this->cardByNo('PL!HS-bp6-008-R', 'izumi');
        $fusion = $this->cardByNo('PL!HS-bp6-032-L', 'fusion');
        $this->assertSame(2, intval($fusion['score'] ?? 0));

        waitMember($izumi, ['turn' => 1, 'active_player' => 'p1']);
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $izumi;
        $p1['live_zone'] = [$fusion];

        $state = [
            'room_id' => 'ISSUE198-FUSION',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $state = resolveAbilityEffect(
            $state,
            'p1',
            $izumi,
            $this->liveStartAbility($izumi),
            ['phase' => 'live_start']
        );

        $stood = $state['players']['p1']['stage']['center'];
        $this->assertFalse(memberIsInWait($stood));
        $this->assertTrue($stood['active'] ?? false);
    }

    public function testRuntimeScoreBumpStillUsesPrintedScore(): void
    {
        $izumi = $this->cardByNo('PL!HS-bp6-008-R', 'izumi');
        $fusion = $this->cardByNo('PL!HS-bp6-032-L', 'fusion');
        // Earlier Live Start / aura bumped the performing score above 2.
        $fusion['_printed_score'] = 2;
        $fusion['score'] = 3;
        $fusion['_effect_score_bonus'] = 1;

        waitMember($izumi, ['turn' => 1, 'active_player' => 'p1']);
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $izumi;
        $p1['live_zone'] = [$fusion];

        $state = [
            'room_id' => 'ISSUE198-BUMP',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $state = resolveAbilityEffect(
            $state,
            'p1',
            $izumi,
            $this->liveStartAbility($izumi),
            ['phase' => 'live_start']
        );

        $stood = $state['players']['p1']['stage']['center'];
        $this->assertFalse(
            memberIsInWait($stood),
            'Printed score 2 must qualify even when runtime score is 3'
        );
    }

    public function testStrippedFusionFieldsStillQualifyViaCatalog(): void
    {
        $izumi = $this->cardByNo('PL!HS-bp6-008-R', 'izumi');
        $fusion = [
            'card_no' => 'PL!HS-bp6-032-L',
            'instance_id' => 'fusion_thin',
            'card_type' => 'ライブ',
        ];

        waitMember($izumi, ['turn' => 1, 'active_player' => 'p1']);
        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $izumi;
        $p1['live_zone'] = [$fusion];

        $state = [
            'room_id' => 'ISSUE198-THIN',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $state = resolveAbilityEffect(
            $state,
            'p1',
            $izumi,
            $this->liveStartAbility($izumi),
            ['phase' => 'live_start']
        );

        $stood = $state['players']['p1']['stage']['center'];
        $this->assertFalse(memberIsInWait($stood));
    }

    public function testFullMatchFlowPlayIzumiSetFusionAckReveal(): void
    {
        $izumi = $this->cardByNo('PL!HS-bp6-008-R', 'izumi');
        $fusion = $this->cardByNo('PL!HS-bp6-032-L', 'fusion');
        $wrLive = $this->cardByNo('PL!HS-bp5-022-L', 'wr_retro');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['hand'] = [$izumi, $fusion];
        $p1['waiting_room'] = [$wrLive];
        $p1['energy_zone'] = array_map(
            static fn(int $i): array => ['instance_id' => "e$i", 'active' => true],
            range(0, 14)
        );
        $p1['main_deck'] = array_fill(0, 20, [
            'instance_id' => 'deck_e',
            'card_type' => 'エネルギー',
        ]);

        $p2 = $this->emptyPlayer('p2', 'CPU Bot');
        $p2['deck_choice'] = 'cpu';

        $state = [
            'room_id' => 'ISSUE198-FLOW',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];

        $state = applyAction($state, 'p1', 'play_member', [
            'card_id' => 'izumi',
            'slot' => 'center',
        ]);
        if (($state['pending_prompt']['type'] ?? '') === 'pick_wr_to_hand') {
            $state = applyAction($state, 'p1', 'resolve_prompt', [
                'card_id' => 'wr_retro',
            ]);
        }

        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['center']));

        $state['phase'] = 'live_set';
        $state['live_ready'] = [];
        $state = applyAction($state, 'p1', 'set_live_cards', [
            'card_ids' => ['fusion'],
        ]);
        $state = applyAction($state, 'p1', 'end_live_set', []);
        if (($state['phase'] ?? '') === 'live_set') {
            $state = applyAction($state, 'p2', 'end_live_set', []);
        }

        $this->assertSame('reveal', $state['live_show']['stage'] ?? null);
        $seq = intval($state['live_show']['stage_seq'] ?? 0);
        $state = applyAction($state, 'p1', 'live_show_ack', [
            'stage' => 'reveal',
            'stage_seq' => $seq,
        ]);

        $stood = $state['players']['p1']['stage']['center'];
        $this->assertFalse(
            memberIsInWait($stood),
            'After reveal ack → Live Start, Izumi must leave Wait with Fusion Crust'
        );
        $this->assertTrue($stood['active'] ?? false);
    }
}
