<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Issue #62 — PL!S-bp6-002 Riko Live Start + leftover Live drain. */
final class RikoBp6002LiveStartTest extends TestCase
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

    public function testLiveStartHydratesStrippedLiveRequiredHearts(): void
    {
        $riko = $this->cardByNo('PL!S-bp6-002-P', 'riko1');
        // Stripped Live as often stored from hand/deck without hearts/group.
        $live = [
            'instance_id' => 'live1',
            'card_no' => 'PL!S-bp6-021-L',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'MIRAI TICKET',
            'revealed' => true,
        ];
        $state = [
            'phase' => 'live_start_effects',
            'seq' => 1,
            'first_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'stage' => ['left' => null, 'center' => $riko, 'right' => null],
                    'live_zone' => [$live],
                    'success_lives' => [],
                ],
                'p2' => [
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $state = \resolveLiveStartAbilities($state, 'p1');
        // Official text: gains 2<allH> — must not grant gray/wild (#328).
        $hearts = \getBonusHeartsFlat($state, 'p1');
        $this->assertSame(['all', 'all'], $hearts);
    }

    public function testDrainDoesNotLeaveNonAqoursStuckWhenAqoursPromptOpens(): void
    {
        $member = [
            'instance_id' => 'riko_m',
            'card_type' => 'メンバー',
            'group' => 'Sunshine',
            'name_en' => 'Riko Sakurauchi',
            'active' => true,
            'abilities' => [[
                'trigger' => 'auto',
                'type' => 'auto_on_live_wr_deck_position',
                'group' => 'Sunshine',
                'once_per_turn' => true,
            ]],
        ];
        $museLive = [
            'instance_id' => 'muse_live',
            'card_type' => 'ライブ',
            'group' => 'Muse',
            'name_en' => 'Muse Live',
            'score' => 1,
            'required_hearts' => [],
        ];
        $aqoursLive = [
            'instance_id' => 'aqours_live',
            'card_type' => 'ライブ',
            'group' => 'Sunshine',
            'name_en' => 'Aqours Live',
            'score' => 3,
            'required_hearts' => [],
        ];
        $state = [
            'room_id' => 'R62',
            'status' => 'playing',
            'seq' => 40,
            'turn' => 4,
            'phase' => 'live_judge',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => $member, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => array_fill(0, 20, ['instance_id' => 'd', 'card_type' => 'メンバー']),
                    'energy_deck' => [],
                    'live_zone' => [$museLive, $aqoursLive],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => array_fill(0, 20, ['instance_id' => 'e', 'card_type' => 'メンバー']),
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $state = \finalizeLiveJudge($state, [
            'live_winners' => [],
            'block_tie_success' => false,
            'is_score_tie' => false,
            'success_placed_by' => [],
            'winner_index' => 0,
        ]);

        $this->assertSame('sbp6_live_wr_deck_position', $state['pending_prompt']['type'] ?? null);
        $zoneIds = array_column($state['players']['p1']['live_zone'], 'instance_id');
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('aqours_live', $zoneIds);
        $this->assertNotContains('muse_live', $zoneIds, 'non-Aqours leftover must leave live_zone when Aqours prompt opens');
        $this->assertContains('muse_live', $wrIds);

        $state = \applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'skip']);
        $this->assertSame([], $state['players']['p1']['live_zone']);
        $this->assertContains('aqours_live', array_column($state['players']['p1']['waiting_room'], 'instance_id'));
    }
}
