<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Replay 797F5C — skipping Hanamaru's optional_reveal_live_deck_bottom_surveil
 * must resume Live Start (not leave phase with no pending prompt).
 */
final class HanamaruLiveStartSkipResumeTest extends TestCase
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

    public function testSkipOptionalRevealSurveilResumesLiveStart(): void
    {
        $hanamaru = $this->cardByNo('PL!S-bp2-007-P', 'hana_center');
        $hasSurveil = false;
        foreach ($hanamaru['abilities'] ?? [] as $ab) {
            if (($ab['type'] ?? '') === 'optional_reveal_live_deck_bottom_surveil'
                && ($ab['trigger'] ?? '') === 'live_start') {
                $hasSurveil = true;
                break;
            }
        }
        $this->assertTrue($hasSurveil, 'PL!S-bp2-007-P should have Live Start surveil optional');

        $live = [
            'instance_id' => 'live_p1',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'Test Live',
            'score' => 1,
            'revealed' => true,
            'abilities' => [],
        ];
        $handLive = [
            'instance_id' => 'hand_live',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'Hand Live',
            'score' => 1,
        ];

        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 10,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$handLive],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $hanamaru,
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => [
                        ['instance_id' => 'd1', 'card_type' => 'メンバー'],
                        ['instance_id' => 'd2', 'card_type' => 'メンバー'],
                        ['instance_id' => 'd3', 'card_type' => 'メンバー'],
                    ],
                    'success_lives' => [],
                    'live_zone' => [$live],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [['instance_id' => 'p2h', 'card_type' => 'メンバー', 'name_en' => 'M']],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => [
                            'instance_id' => 'ginko',
                            'card_type' => 'メンバー',
                            'card_type_en' => 'Member',
                            'name_en' => 'Ginko Momose',
                            'active' => true,
                            'abilities' => [[
                                'trigger' => 'live_start',
                                'type' => 'optional_discard_blade_named_extra',
                                'named' => 'Ginko Momose',
                                'amount' => 1,
                                'extra_amount' => 1,
                            ]],
                        ],
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [[
                        'instance_id' => 'live_p2',
                        'card_type' => 'ライブ',
                        'card_type_en' => 'Live',
                        'name_en' => 'P2 Live',
                        'score' => 1,
                        'revealed' => true,
                        'abilities' => [],
                    ]],
                ],
            ],
        ];

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame('optional_reveal_live_deck_bottom_surveil', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p1', $state['pending_prompt']['responder'] ?? null);
        $this->assertSame('p1', $state['_live_start_resume_from'] ?? null);

        $state = \applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'no']);

        // Must not softlock in live_start_effects with an empty prompt.
        $this->assertNotEmpty($state['pending_prompt'] ?? null, 'Expected next Live Start prompt after skip');
        $this->assertSame('live_start_effects', $state['phase'] ?? null);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);
        $this->assertSame(
            'optional_live_start',
            $state['pending_prompt']['type'] ?? null
        );
    }
}
