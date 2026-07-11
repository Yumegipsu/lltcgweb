<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class ActionSmokeTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array {
        $data = json_decode((string)file_get_contents(CARDS_FILE), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function joinedMulliganState(): array {
        $created = createRoom(['name' => 'Smoke P1', 'deck' => 'nijigasaki']);
        joinRoom([
            'room_id' => $created['room_id'],
            'name' => 'Smoke P2',
            'deck' => 'cpu',
            'cpu_difficulty' => 'easy',
            'first_player' => 'p1',
        ]);
        $state = loadGame($created['room_id']);
        $this->assertIsArray($state);
        $this->assertSame('setup', $state['phase'] ?? '');
        return $state;
    }

    public function testMulliganKeepAdvancesToMain(): void {
        $state = $this->joinedMulliganState();
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $this->assertSame('setup', $state['phase'] ?? '');
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $this->assertSame('main_first', $state['phase'] ?? '');
        $this->assertTrue($state['players']['p1']['ready_mulligan'] ?? false);
        $this->assertTrue($state['players']['p2']['ready_mulligan'] ?? false);
    }

    public function testResolvePromptClearsLookTopOptionalWr(): void {
        $state = $this->joinedMulliganState();
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $state['pending_prompt'] = [
            'type' => 'look_top_optional_wr',
            'owner' => 'p1',
            'responder' => 'p1',
            'target' => 'p1',
            'source_name' => 'Smoke',
            'choices' => ['yes', 'no'],
        ];
        $state = applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'no']);
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testResolvePromptWrongResponderIsIdempotentNoop(): void {
        $state = $this->joinedMulliganState();
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $prompt = [
            'type' => 'look_top_optional_wr',
            'owner' => 'p1',
            'responder' => 'p1',
            'target' => 'p1',
            'source_name' => 'Smoke',
            'choices' => ['yes', 'no'],
        ];
        $state['pending_prompt'] = $prompt;
        // p2 answers p1's prompt (race / wrong responder): must not throw, must no-op.
        $after = applyAction($state, 'p2', 'resolve_prompt', ['choice' => 'no']);
        $this->assertTrue($after['_resolve_prompt_noop'] ?? false);
        $this->assertSame($prompt, $after['pending_prompt'] ?? null);
    }

    public function testResolvePromptNoPendingIsIdempotentNoop(): void {
        $state = $this->joinedMulliganState();
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        unset($state['pending_prompt']);
        // Duplicate submit after the prompt already resolved: no-op, no exception.
        $after = applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'no']);
        $this->assertTrue($after['_resolve_prompt_noop'] ?? false);
    }

    public function testLiveStartPositionChangeContinuesPromptQueue(): void {
        $tomari = $this->cardByNo('PL!SP-pb2-011-PP', 'test_tomari');
        $natsumi = $this->cardByNo('PL!SP-sd1-020-SD', 'test_natsumi');
        $followupLive = [
            'instance_id' => 'test_followup_live',
            'card_no' => 'TEST-LIVE',
            'name_en' => 'Followup Live',
            'card_type_en' => 'Live',
            'abilities' => [],
        ];

        $state = [
            'phase' => 'live_start_effects',
            'seq' => 10,
            'first_player' => 'p1',
            'live_attempt' => ['p2'],
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hand' => [],
                    'waiting_room' => [],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'name' => 'P2',
                    'stage' => ['left' => null, 'center' => $tomari, 'right' => $natsumi],
                    'hand' => [],
                    'waiting_room' => [],
                    'live_zone' => [$followupLive],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
            ],
            'pending_prompt' => [
                'type' => 'optional_swap_area_on_enter',
                'owner' => 'p2',
                'responder' => 'p2',
                'source_id' => 'test_tomari',
                'source_slot' => 'center',
                'source_name' => 'Tomari Onitsuka',
                'choices' => ['skip', 'left', 'right'],
                'ability' => ['trigger' => 'live_start', 'type' => 'optional_swap_area_on_enter'],
            ],
            'live_start_optional_queue' => [[
                'owner' => 'p2',
                'source_id' => 'test_followup_live',
                'source_name' => 'Followup Live',
                'ability_index' => 0,
                'ability' => ['trigger' => 'live_start', 'type' => 'optional_discard_hand', 'discard' => 1],
            ]],
        ];

        $state = applyAction($state, 'p2', 'resolve_prompt', ['choice' => 'right']);

        $this->assertSame('test_tomari', $state['players']['p2']['stage']['right']['instance_id'] ?? null);
        $this->assertSame('test_natsumi', $state['players']['p2']['stage']['center']['instance_id'] ?? null);
        $this->assertTrue($state['players']['p2']['stage']['right']['moved_this_turn'] ?? false);
        // Tomari left Center — her auto must resolve before the Live Start queue continues.
        $this->assertSame('spbp2_center_move_choose', $state['pending_prompt']['type'] ?? null);

        $state = applyAction($state, 'p2', 'resolve_prompt', ['choice' => 'draw']);
        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('test_followup_live', $state['pending_prompt']['source_id'] ?? null);
    }

    public function testLiveSetRunsSequentiallyByTurnOrder(): void {
        $state = $this->joinedMulliganState();
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p1', 'end_main', []);
        $state = applyAction($state, 'p2', 'end_main', []);

        $this->assertSame('live_set', $state['phase'] ?? '');
        $this->assertSame('p1', $state['active_player'] ?? null);

        $live = [
            'instance_id' => 'test_live_p1',
            'card_no' => 'TEST-LIVE',
            'name_en' => 'Test Live',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'score' => 1,
            'hearts' => [],
            'abilities' => [],
        ];
        $drawn = $this->cardByNo('PL!HS-pb1-011-R', 'test_draw_after_live_set');
        $state['players']['p1']['hand'] = [$live];
        $state['players']['p1']['main_deck'] = [$drawn];

        try {
            applyAction($state, 'p2', 'end_live_set', []);
            $this->fail('Waiting player should not be able to end LIVE Phase first');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Not your turn', $e->getMessage());
        }

        $state = applyAction($state, 'p1', 'set_live_cards', ['card_ids' => ['test_live_p1']]);
        $this->assertSame('test_live_p1', $state['players']['p1']['live_zone'][0]['instance_id'] ?? null);
        $this->assertContains(
            'test_draw_after_live_set',
            array_column($state['players']['p1']['hand'] ?? [], 'instance_id')
        );

        $state = applyAction($state, 'p1', 'end_live_set', []);
        $this->assertSame('live_set', $state['phase'] ?? '');
        $this->assertSame('p2', $state['active_player'] ?? null);
        $this->assertTrue($state['live_ready']['p1'] ?? false);
        $this->assertFalse($state['live_ready']['p2'] ?? true);
        $this->assertFalse($state['players']['p1']['live_zone'][0]['revealed'] ?? true);
        $this->assertFalse($this->logContains($state, 'Both players reveal Live storage simultaneously.'));

        $state = applyAction($state, 'p2', 'end_live_set', []);
        $this->assertNotSame('live_set', $state['phase'] ?? '');
        $this->assertArrayNotHasKey('live_ready', $state);
        $this->assertTrue($this->logContains($state, 'Both players reveal Live storage simultaneously.'));
    }

    public function testPlayMemberLegalFromHand(): void {
        $state = $this->joinedMulliganState();
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $this->assertSame('main_first', $state['phase'] ?? '');

        $member = null;
        $activeEnergy = count(array_filter(
            $state['players']['p1']['energy_zone'] ?? [],
            static fn(array $c): bool => !empty($c['active'])
        ));
        foreach ($state['players']['p1']['hand'] as $c) {
            if (($c['card_type'] ?? '') !== 'メンバー') {
                continue;
            }
            $cost = intval($c['cost'] ?? 99);
            if ($cost <= $activeEnergy) {
                $member = $c;
                break;
            }
        }
        $this->assertNotNull($member, 'Expected a playable member card in opening hand');

        $state = applyAction($state, 'p1', 'play_member', [
            'card_id' => $member['instance_id'],
            'slot' => 'center',
        ]);
        $handIds = array_column($state['players']['p1']['hand'] ?? [], 'instance_id');
        $this->assertNotContains($member['instance_id'], $handIds);
        $this->assertSame($member['instance_id'], $state['players']['p1']['stage']['center']['instance_id'] ?? null);
    }

    public function testPlayedRurinoHydratesOnEnterAbilityFromCatalog(): void {
        $state = $this->joinedMulliganState();
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);
        $this->assertSame('main_first', $state['phase'] ?? '');

        $rurino = $this->cardByNo('PL!HS-pb1-011-R', 'test_rurino_missing_abilities');
        unset($rurino['abilities'], $rurino['text'], $rurino['text_jp']);
        $discardFodder = $this->cardByNo('PL!N-sd1-024-SD', 'test_discard_fodder');

        $state['players']['p1']['hand'] = [$rurino, $discardFodder];
        $state['players']['p1']['energy_zone'] = [];
        for ($i = 0; $i < 7; $i++) {
            $state['players']['p1']['energy_zone'][] = [
                'instance_id' => 'test_energy_' . $i,
                'active' => true,
            ];
        }

        $state = applyAction($state, 'p1', 'play_member', [
            'card_id' => 'test_rurino_missing_abilities',
            'slot' => 'center',
        ]);

        $this->assertSame('test_rurino_missing_abilities', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('test_rurino_missing_abilities', $state['pending_prompt']['source_id'] ?? null);
        $this->assertSame('look_reveal_filter', $state['pending_prompt']['ability']['then']['type'] ?? null);
    }

    public function testAutoGroupEnterBladeFiresWhenEnteringMemberHasNoOnEnter(): void {
        $state = $this->joinedMulliganState();
        $state = applyAction($state, 'p1', 'mulligan', ['card_ids' => []]);
        $state = applyAction($state, 'p2', 'mulligan', ['card_ids' => []]);

        $listener = $this->cardByNo('PL!HS-pb1-009-R', 'test_pb1_kaho_listener');
        $entering = $this->cardByNo('PL!HS-sd1-001-SD', 'test_sd_kaho_enter');
        $this->assertSame([], getAbilitiesByTrigger($entering, 'on_enter'));

        $state['players']['p2']['stage']['center'] = $listener;
        $state['players']['p2']['hand'] = [$entering];
        $state['players']['p2']['energy_zone'] = [];
        for ($i = 0; $i < 12; $i++) {
            $state['players']['p2']['energy_zone'][] = [
                'instance_id' => 'test_p2_energy_' . $i,
                'active' => true,
            ];
        }
        $state['active_player'] = 'p2';
        $state['phase'] = 'main_second';

        $state = applyAction($state, 'p2', 'play_member', [
            'card_id' => 'test_sd_kaho_enter',
            'slot' => 'left',
        ]);

        $center = $state['players']['p2']['stage']['center'] ?? null;
        $this->assertSame('test_pb1_kaho_listener', $center['instance_id'] ?? null);
        $this->assertSame(2, intval($center['live_blade_bonus'] ?? 0));
        $found = false;
        foreach ($state['log'] ?? [] as $entry) {
            $msg = is_array($entry) ? ($entry['msg'] ?? '') : (string)$entry;
            if (str_contains($msg, 'gained +2 Blade') && str_contains($msg, 'Kaho Hinoshita entered')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected auto enter blade log line');
    }

    public function testOptionalLiveStartQueuePayEnergyThenDiscardSkip(): void {
        $linkFuture = $this->cardByNo('PL!HS-sd1-020-SD', 'test_link_future');
        $payLive = [
            'instance_id' => 'test_pay_live',
            'card_no' => 'TEST-PAY-LIVE',
            'name_en' => 'Pay Live',
            'card_type_en' => 'Live',
            'abilities' => [
                ['trigger' => 'live_start', 'type' => 'optional_pay_energy', 'cost' => 1],
            ],
        ];

        $state = [
            'phase' => 'live_start_effects',
            'seq' => 70,
            'first_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hand' => [],
                    'waiting_room' => [],
                    'live_zone' => [$payLive, $linkFuture],
                    'main_deck' => [],
                    'energy_zone' => [
                        ['instance_id' => 'test_energy_0', 'active' => true],
                    ],
                    'success_lives' => [],
                ],
                'p2' => [
                    'name' => 'P2',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hand' => [],
                    'waiting_room' => [],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
            ],
            'live_start_optional_queue' => [[
                'owner' => 'p1',
                'source_id' => 'test_link_future',
                'source_name' => 'Link to the FUTURE (104th Ver.)',
                'ability_index' => 1,
                'ability' => $linkFuture['abilities'][1],
            ]],
            'pending_prompt' => [
                'type' => 'optional_live_start',
                'owner' => 'p1',
                'responder' => 'p1',
                'source_id' => 'test_pay_live',
                'source_name' => 'Pay Live',
                'ability_index' => 0,
                'ability' => $payLive['abilities'][0],
                'choices' => ['yes', 'no'],
                'needs_pay' => true,
                'pay_cost' => 1,
            ],
        ];

        $state = applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'yes', 'pay' => true]);
        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('test_link_future', $state['pending_prompt']['source_id'] ?? null);
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['ability']['type'] ?? null);
        $this->assertContains('p1:test_pay_live:0', $state['live_start_optional_resolved'] ?? []);

        $state = applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'no']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertNotSame('live_start_effects', $state['phase'] ?? null);
        $this->assertArrayNotHasKey('live_start_optional_queue', $state);

        $dup = applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'no']);
        $this->assertTrue($dup['_resolve_prompt_noop'] ?? false);
        $this->assertNull($dup['pending_prompt'] ?? null);
    }

    public function testAntiSoftlockSkipOptionalLiveStartAdvancesToPerformance(): void {
        $karin = [
            'instance_id' => 'karin_live',
            'card_no' => 'PL!N-sd1-004-SD',
            'name_en' => 'Karin Asaka',
            'card_type_en' => 'Member',
            'abilities' => [[
                'trigger' => 'live_start',
                'type' => 'optional_discard_prompt',
                'discard' => 1,
                'then' => ['type' => 'blade_bonus', 'amount' => 2],
            ]],
        ];
        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 80,
            'first_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hand' => [['instance_id' => 'h1', 'card_no' => 'TEST-H1', 'name_en' => 'Hand']],
                    'waiting_room' => [],
                    'live_zone' => [$karin],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'name' => 'P2',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hand' => [],
                    'waiting_room' => [],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
            ],
            'live_start_optional_queue' => [[
                'owner' => 'p1',
                'source_id' => 'karin_live',
                'source_name' => 'Karin Asaka',
                'ability_index' => 0,
                'ability' => $karin['abilities'][0],
            ]],
            'pending_prompt' => [
                'type' => 'optional_live_start',
                'owner' => 'p1',
                'responder' => 'p1',
                'source_id' => 'karin_live',
                'source_name' => 'Karin Asaka',
                'ability_index' => 0,
                'ability' => $karin['abilities'][0],
                'choices' => ['yes', 'no'],
                'discard_count' => 1,
            ],
        ];

        $state = applyAction($state, 'p1', 'anti_softlock_skip', []);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertNotSame('live_start_effects', $state['phase'] ?? null);
        $this->assertArrayNotHasKey('live_start_optional_queue', $state);
    }

    public function testOptionalLiveStartYesCoercesStringDiscardIds(): void {
        $karin = $this->cardByNo('PL!N-sd1-004-SD', 'karin_stage');
        $handCard = [
            'instance_id' => 'karin_discard_hand',
            'card_no' => 'TEST-HAND',
            'name_en' => 'Hand Card',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
        ];
        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 80,
            'first_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hand' => [],
                    'waiting_room' => [],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'name' => 'P2',
                    'stage' => ['left' => null, 'center' => $karin, 'right' => null],
                    'hand' => [$handCard],
                    'waiting_room' => [],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
            ],
            'live_start_optional_queue' => [],
            'pending_prompt' => [
                'type' => 'optional_live_start',
                'owner' => 'p2',
                'responder' => 'p2',
                'source_id' => 'karin_stage',
                'source_name' => 'Karin Asaka',
                'ability_index' => 0,
                'ability' => $karin['abilities'][0],
                'discard_count' => 1,
            ],
        ];

        $state = applyAction($state, 'p2', 'resolve_prompt', [
            'choice' => 'yes',
            'discard_ids' => 'karin_discard_hand',
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertNotSame('live_start_effects', $state['phase'] ?? null);
        $this->assertSame([], $state['players']['p2']['hand'] ?? null);
        $this->assertContains(
            'karin_discard_hand',
            array_column($state['players']['p2']['waiting_room'] ?? [], 'instance_id')
        );
    }

    private function logContains(array $state, string $needle): bool {
        foreach ($state['log'] ?? [] as $entry) {
            $msg = is_array($entry) ? ($entry['msg'] ?? '') : (string)$entry;
            if ($msg === $needle) {
                return true;
            }
        }
        return false;
    }
}
