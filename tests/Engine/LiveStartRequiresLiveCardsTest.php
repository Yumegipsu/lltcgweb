<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Live Start must not fire for a player who only set Member bluffs
 * (no Live cards in storage) while the opponent is performing.
 */
final class LiveStartRequiresLiveCardsTest extends TestCase
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

    private function bluffMember(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Hasunosora',
            'name_en' => 'Bluff',
            'cost' => 2,
            'blade' => 1,
            'hearts' => [['color' => 'pink', 'count' => 1]],
            'active' => true,
        ];
    }

    private function plainLive(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => "μ's",
            'name_en' => 'Dummy Live',
            'score' => 1,
            'required_hearts' => [['color' => 'any', 'count' => 1]],
            'abilities' => [],
        ];
    }

    /** Kaho with enough stacked Blade for her Live Start draw/discard. */
    private function kahoWithBlades(): array
    {
        $kaho = $this->cardByNo('PL!HS-pb1-009-R', 'kaho');
        // Bypass Always math — force ≥8 Blades for the Live Start gate check.
        $kaho['printed_blade_override'] = 8;
        $kaho['live_blade_bonus'] = 0;
        return $kaho;
    }

    private function baseState(array $p1Live, array $p2Live, array $p2Center): array
    {
        $deck = [];
        for ($i = 1; $i <= 12; $i++) {
            $deck[] = [
                'instance_id' => 'deck' . $i,
                'card_type' => 'メンバー',
                'card_type_en' => 'Member',
                'group' => 'Hasunosora',
                'name_en' => 'Deck' . $i,
                'blade' => 0,
            ];
        }
        return [
            'room_id' => 'LSLIVE',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 3,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'Performer',
                    'hand' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $this->bluffMember('p1c'),
                        'right' => null,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => $deck,
                    'live_zone' => $p1Live,
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'BluffOnly',
                    'hand' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $p2Center,
                        'right' => null,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => $deck,
                    'live_zone' => $p2Live,
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testMemberBluffOnlySeatSkipsStageLiveStart(): void
    {
        $kaho = $this->kahoWithBlades();
        $state = $this->baseState(
            [$this->plainLive('p1live')],
            [$this->bluffMember('p2bluff')],
            $kaho
        );

        $this->assertTrue(\playerParticipatingInLiveRound($state, 'p2'));
        $this->assertFalse(\playerAttemptingLivePerformance($state, 'p2'));

        $handBefore = count($state['players']['p2']['hand']);
        $deckBefore = count($state['players']['p2']['main_deck']);
        $wrBefore = count($state['players']['p2']['waiting_room']);

        $state = \resolveLiveStartAbilities($state, 'p2');

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame($handBefore, count($state['players']['p2']['hand']));
        $this->assertSame($deckBefore, count($state['players']['p2']['main_deck']));
        $this->assertSame($wrBefore, count($state['players']['p2']['waiting_room']));
        $this->assertSame([], \collectOptionalLiveStartAbilities($state));
    }

    public function testLivePerformerStillGetsStageLiveStart(): void
    {
        $kaho = $this->kahoWithBlades();
        // Swap: p2 has the Live + Kaho; p1 only a bluff.
        $state = $this->baseState(
            [$this->bluffMember('p1bluff')],
            [$this->plainLive('p2live')],
            $kaho
        );
        $state['live_attempt'] = ['p2', 'p1'];
        $state['_live_start_perf_pid'] = 'p2';

        $this->assertTrue(\playerAttemptingLivePerformance($state, 'p2'));

        $deckBefore = count($state['players']['p2']['main_deck']);
        $state = \resolveLiveStartAbilities($state, 'p2');

        // Kaho Live Start: draw 2 then mandatory discard 1 (prompt or applied).
        $drewOrPrompted = !empty($state['pending_prompt'])
            || count($state['players']['p2']['main_deck']) < $deckBefore
            || count($state['players']['p2']['hand']) > 0
            || count($state['players']['p2']['waiting_room']) > 0;
        $this->assertTrue($drewOrPrompted, 'Performing player must still resolve Kaho Live Start');
    }

    public function testBeginLiveStartForPerformerSkipsBluffOnly(): void
    {
        $kaho = $this->kahoWithBlades();
        $state = $this->baseState(
            [$this->plainLive('p1live')],
            [$this->bluffMember('p2bluff')],
            $kaho
        );
        $state['live_show'] = [
            'turn' => 3,
            'stage' => 'performance',
            'performer' => 'p2',
            'started_at' => time(),
            'stage_seq' => 2,
            'acks' => [],
            'played_lives' => ['p1' => ['p1live'], 'p2' => []],
        ];
        $state['_live_start_done'] = ['p1' => true];

        $handBefore = count($state['players']['p2']['hand']);
        $state = \beginLiveStartForPerformer($state, 'p2');

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame($handBefore, count($state['players']['p2']['hand']));
        $log = implode("\n", array_map(
            static fn($e) => is_array($e) ? (string)($e['msg'] ?? '') : (string)$e,
            $state['log'] ?? []
        ));
        $this->assertStringNotContainsString('Live Start Effects (BluffOnly)', $log);
    }

    /** Issue #166 — after milling Member bluffs, empty zone must not open Stage Live Start. */
    public function testBeginLiveStartEffectPhaseSkipsBluffOnlyFirstPerformer(): void
    {
        $kaho = $this->kahoWithBlades();
        $state = $this->baseState(
            [$this->bluffMember('p1bluff')],
            [],
            $kaho
        );
        // Only p1 attempts; storage is Member bluff only.
        $state['live_attempt'] = ['p1'];
        $state['players']['p1']['stage']['center'] = $kaho;
        $state['players']['p2']['stage']['center'] = null;

        $this->assertFalse(\playerAttemptingLivePerformance($state, 'p1'));
        $this->assertFalse(\playerShouldResolveLiveStart($state, 'p1'));

        // Keep finishLiveStartEffects from running Yell → next-turn Draw (would grow hand).
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $handBefore = count($state['players']['p1']['hand']);
            $deckBefore = count($state['players']['p1']['main_deck']);
            $state = \beginLiveStartEffectPhase($state, true, false);

            $this->assertNull($state['pending_prompt'] ?? null, 'Must not open Kaho (or any) Live Start after bluff mill');
            $this->assertSame($handBefore, count($state['players']['p1']['hand']));
            $this->assertSame($deckBefore, count($state['players']['p1']['main_deck']));
            $this->assertCount(0, $state['players']['p1']['live_zone']);
            $this->assertContains('p1bluff', array_column($state['players']['p1']['waiting_room'], 'instance_id'));
            $this->assertFalse(\playerShouldResolveLiveStart($state, 'p1'));
            $log = implode("\n", array_map(
                static fn($e) => is_array($e) ? (string)($e['msg'] ?? '') : (string)$e,
                $state['log'] ?? []
            ));
            $this->assertStringNotContainsString('Live Start Effects (Performer)', $log);
            $this->assertStringNotContainsString('[Live Start]', $log);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testEmptyStorageStillAllowsIsolatedLiveStartSkillTests(): void
    {
        $kaho = $this->kahoWithBlades();
        $state = $this->baseState([], [], $kaho);
        $state['live_attempt'] = ['p2'];
        // No _live_start_perf_pid — isolated resolveLiveStartAbilities call.
        $deckBefore = count($state['players']['p2']['main_deck']);
        $state = \resolveLiveStartAbilities($state, 'p2');
        $drewOrPrompted = !empty($state['pending_prompt'])
            || count($state['players']['p2']['main_deck']) < $deckBefore
            || count($state['players']['p2']['hand']) > 0;
        $this->assertTrue($drewOrPrompted, 'Empty live_zone must not block isolated Live Start tests');
    }

    public function testPlayedLivesSnapshotGatesAfterMill(): void
    {
        $kaho = $this->kahoWithBlades();
        $state = $this->baseState(
            [$this->bluffMember('p1bluff')],
            [],
            $kaho
        );
        $state['players']['p1']['stage']['center'] = $kaho;
        $state['live_attempt'] = ['p1'];
        $state['_live_start_perf_pid'] = 'p1';
        $state['live_show'] = [
            'played_lives' => ['p1' => [], 'p2' => []],
        ];
        // Zone still has bluff, but snapshot says no Lives were played.
        $this->assertFalse(\playerShouldResolveLiveStart($state, 'p1'));
        $state = \discardLiveZoneMembersToWaitingRoom($state, 'p1');
        $this->assertFalse(\playerShouldResolveLiveStart($state, 'p1'));
    }
}
