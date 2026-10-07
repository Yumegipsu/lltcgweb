<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Wonderful Rush (PL!-bp6-021-L): after leaving a μ's Member, must open WR Live pick
 * (used to unset pending_prompt immediately after opening it).
 */
final class WonderfulRushLiveSuccessTest extends TestCase
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

    public function testLeaveMemberThenOpensWrLivePick(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $rush = $this->cardByNo('PL!-bp6-021-L', 'rush_1');
            $rush['face_up'] = true;
            $stageMember = $this->cardByNo('PL!-PR-014-PR', 'stage_mus');
            $wrLive = $this->cardByNo('PL!-bp6-022-L', 'wr_live');

            $state = [
                'status' => 'playing',
                'phase' => 'live_success_effects',
                'seq' => 1,
                'turn' => 4,
                'first_player' => 'p1',
                'active_player' => 'p1',
                'log' => [],
                'players' => [
                    'p1' => [
                        'id' => 'p1',
                        'name' => 'P1',
                        'hand' => [],
                        'waiting_room' => [$wrLive],
                        'stage' => [
                            'left' => $stageMember,
                            'center' => null,
                            'right' => null,
                        ],
                        'energy_zone' => [],
                        'main_deck' => [],
                        'success_lives' => [],
                        'live_zone' => [$rush],
                    ],
                    'p2' => [
                        'id' => 'p2',
                        'name' => 'P2',
                        'hand' => [],
                        'waiting_room' => [],
                        'stage' => ['left' => null, 'center' => null, 'right' => null],
                        'energy_zone' => [],
                        'main_deck' => [],
                        'success_lives' => [],
                        'live_zone' => [],
                    ],
                ],
            ];

            $state = \resolveLiveSuccessAbilities($state, 'p1', [$rush], 0, [], []);
            $this->assertSame(
                'optional_leave_mus_score_add_wr_live',
                $state['pending_prompt']['type'] ?? null
            );

            $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
            $this->assertSame('pick_member', $state['pending_prompt']['step'] ?? null);

            $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'stage_mus']);
            $this->assertSame(
                'pick_wr_to_hand',
                $state['pending_prompt']['type'] ?? null,
                'After leaving Member, WR Live pick must remain open'
            );
            $this->assertNull(
                $state['players']['p1']['stage']['left'] ?? null,
                'Chosen Stage Member must leave'
            );
            $this->assertSame(
                8,
                intval($state['players']['p1']['live_zone'][0]['score'] ?? 0),
                'Wonderful Rush printed score 7 +1'
            );
            // Must not park the Live on a bogus stage key.
            $this->assertArrayNotHasKey('', $state['players']['p1']['stage']);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testYelledLiveIsAWaitingRoomChoice(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $rush = $this->cardByNo('PL!-bp6-021-L', 'rush_1');
            $rush['face_up'] = true;
            $stageMember = $this->cardByNo('PL!-PR-014-PR', 'stage_mus');
            $yelled = $this->cardByNo('PL!-bp6-022-L', 'yelled_live');

            $state = [
                'status' => 'playing',
                'phase' => 'live_success_effects',
                'seq' => 1,
                'turn' => 4,
                'first_player' => 'p1',
                'active_player' => 'p1',
                'log' => [],
                'players' => [
                    'p1' => [
                        'id' => 'p1',
                        'name' => 'P1',
                        'hand' => [],
                        'waiting_room' => [],
                        '_pending_yell_wr' => [$yelled],
                        'stage' => [
                            'left' => $stageMember,
                            'center' => null,
                            'right' => null,
                        ],
                        'energy_zone' => [],
                        'main_deck' => [],
                        'success_lives' => [],
                        'live_zone' => [$rush],
                    ],
                    'p2' => [
                        'id' => 'p2',
                        'name' => 'P2',
                        'hand' => [],
                        'waiting_room' => [],
                        'stage' => ['left' => null, 'center' => null, 'right' => null],
                        'energy_zone' => [],
                        'main_deck' => [],
                        'success_lives' => [],
                        'live_zone' => [],
                    ],
                ],
            ];

            $state = \resolveLiveSuccessAbilities($state, 'p1', [$rush], 0, [], [$yelled]);
            $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
            $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'stage_mus']);

            $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
            $ids = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
            $this->assertContains('yelled_live', $ids);

            $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'yelled_live']);
            $handIds = array_column($state['players']['p1']['hand'] ?? [], 'instance_id');
            $this->assertContains('yelled_live', $handIds);
            $pendingIds = array_column($state['players']['p1']['_pending_yell_wr'] ?? [], 'instance_id');
            $this->assertNotContains('yelled_live', $pendingIds);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
