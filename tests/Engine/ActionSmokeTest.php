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
