<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * WAO-WAO then PL!-pb2-028 Honoka Live Starts (#247).
 *
 * Ordering WAO first Activates Wait Printemps; Honoka optional Wait-self must still
 * open (board copies were stale Wait snapshots and auto-skipped). WAO's +1 score
 * for Activating 3+ stays even if Honoka Wait again afterward.
 */
final class Issue247WaoThenHonokaWaitTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
    }

    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    public function testWaoFirstThenBothHonokaOptionalWaitPrompts(): void
    {
        $kotori = $this->cardByNo('PL!-pb2-012-P+', 'kotori');
        $h1 = $this->cardByNo('PL!-pb2-028-N', 'h1');
        $h2 = $this->cardByNo('PL!-pb2-028-N', 'h2');
        $wao = $this->cardByNo('PL!-pb1-028-L', 'wao');
        waitMember($kotori, ['turn' => 1]);
        waitMember($h1, ['turn' => 1]);
        waitMember($h2, ['turn' => 1]);

        $state = [
            'room_id' => 'ISSUE247',
            'status' => 'playing',
            'seq' => 10,
            'turn' => 3,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => [
                        'left' => $h1,
                        'center' => $kotori,
                        'right' => $h2,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => array_fill(0, 12, ['card_type' => 'エネルギー', 'active' => true]),
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [$wao],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $state = resolveLiveStartAbilities($state, 'p1');
        $this->assertSame('live_start_order_sources', $state['pending_prompt']['type'] ?? null);

        $state = actionResolvePrompt($state, 'p1', [
            'card_ids' => ['wao', 'h1', 'h2'],
        ]);

        // WAO Activates 3 → score +1 already applied; first Honoka Wait prompt opens.
        $this->assertSame('optional_wait_self', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('h1', $state['pending_prompt']['source_id'] ?? null);
        $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['right']));
        $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['center']));
        $this->assertSame(1, intval($state['players']['p1']['live_zone'][0]['_effect_score_bonus'] ?? 0));
        $this->assertSame(6, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));

        $state = actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['left']));
        // Score bonus must survive Waiting after WAO (#247 note).
        $this->assertSame(1, intval($state['players']['p1']['live_zone'][0]['_effect_score_bonus'] ?? 0));
        $this->assertSame(6, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));

        $this->assertSame('optional_wait_self', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('h2', $state['pending_prompt']['source_id'] ?? null);

        $state = actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['right']));
        $this->assertSame(1, intval($state['players']['p1']['live_zone'][0]['_effect_score_bonus'] ?? 0));
        $this->assertNull($state['pending_prompt'] ?? null);
    }
}
