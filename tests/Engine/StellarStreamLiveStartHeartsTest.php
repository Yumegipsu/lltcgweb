<?php
/**
 * PL!N-pb1-039-L Stellar Stream — Live Start needs a Niji Live with exactly 3 pink required hearts
 * (PHOENIX). Stellar Stream itself has 4 pink and must not self-enable.
 */
declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class StellarStreamLiveStartHeartsTest extends TestCase
{
    private function stellar(): array
    {
        return [
            'instance_id' => 'stellar_1',
            'card_no' => 'PL!N-pb1-039-L',
            'name_en' => 'Stellar Stream',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Nijigasaki',
            'score' => 5,
            // Intentionally omit `hearts` — live-zone cards often only keep required_hearts.
            'required_hearts' => [
                ['color' => 'pink', 'count' => 4],
                ['color' => 'purple', 'count' => 4],
                ['color' => 'any', 'count' => 6],
            ],
            'abilities' => [[
                'trigger' => 'live_start',
                'type' => 'member_hearts_if_live_zone_heart_color',
                'group' => 'Nijigasaki',
                'check_color' => 'pink',
                'exact_count' => 3,
                'member_color' => 'purple',
                'member_min_hearts' => 1,
                'grant_hearts' => [['color' => 'purple', 'count' => 4]],
                'max_members' => 1,
            ]],
        ];
    }

    private function phoenix(): array
    {
        return [
            'instance_id' => 'phoenix_1',
            'card_no' => 'PL!N-pb1-038-L',
            'name_en' => 'PHOENIX',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Nijigasaki',
            'score' => 3,
            'required_hearts' => [
                ['color' => 'pink', 'count' => 3],
                ['color' => 'red', 'count' => 1],
                ['color' => 'purple', 'count' => 1],
                ['color' => 'any', 'count' => 2],
            ],
            'abilities' => [],
        ];
    }

    private function purpleMember(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!N-MEMBER',
            'name_en' => 'Purple Niji',
            'card_type' => 'メンバー',
            'group' => 'Nijigasaki',
            'hearts' => [
                ['color' => 'purple', 'count' => 1],
                ['color' => 'pink', 'count' => 1],
            ],
        ];
    }

    private function baseState(): array
    {
        return [
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
                    'main_deck' => [['instance_id' => 'd1', 'card_type' => 'メンバー']],
                    'energy_zone' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $this->purpleMember('m_purple'),
                        'right' => null,
                    ],
                    'live_zone' => [$this->stellar()],
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
    }

    public function testDoesNotGrantWhenOnlyStellarStreamPresent(): void
    {
        $state = $this->baseState();
        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertEmpty($state['players']['p1']['stage']['center']['bonus_hearts'] ?? []);
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testGrantsPurpleHeartsWhenPhoenixInLiveZone(): void
    {
        $state = $this->baseState();
        $state['players']['p1']['live_zone'] = [$this->stellar(), $this->phoenix()];
        $state = \resolveLiveStartAbilities($state, 'p1');
        $bonus = $state['players']['p1']['stage']['center']['bonus_hearts'] ?? [];
        $purple = count(array_filter($bonus, static fn($c) => $c === 'purple'));
        $this->assertSame(4, $purple);
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testGrantsWhenPhoenixInSuccessLives(): void
    {
        $state = $this->baseState();
        $state['players']['p1']['success_lives'] = [$this->phoenix()];
        $state = \resolveLiveStartAbilities($state, 'p1');
        $bonus = $state['players']['p1']['stage']['center']['bonus_hearts'] ?? [];
        $this->assertSame(4, count(array_filter($bonus, static fn($c) => $c === 'purple')));
    }

    public function testPromptsWhenMultiplePurpleMembers(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = $this->baseState();
            $state['players']['p1']['live_zone'] = [$this->stellar(), $this->phoenix()];
            $state['players']['p1']['stage']['left'] = $this->purpleMember('m_purple_2');
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame('pick_member_grant_hearts', $state['pending_prompt']['type'] ?? null);
            $state = \actionResolvePrompt($state, 'p1', ['member_id' => 'm_purple_2']);
            $bonus = $state['players']['p1']['stage']['left']['bonus_hearts'] ?? [];
            $this->assertSame(4, count(array_filter($bonus, static fn($c) => $c === 'purple')));
            $this->assertEmpty($state['players']['p1']['stage']['center']['bonus_hearts'] ?? []);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testPromptResolvesWithCardIdPayload(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = $this->baseState();
            $state['players']['p1']['live_zone'] = [$this->stellar(), $this->phoenix()];
            $state['players']['p1']['stage']['left'] = $this->purpleMember('m_purple_2');
            $state = \resolveLiveStartAbilities($state, 'p1');
            $this->assertSame('pick_member_grant_hearts', $state['pending_prompt']['type'] ?? null);
            // Client Stage pick sends card_id (openStageMemberPickById).
            $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'm_purple_2']);
            $bonus = $state['players']['p1']['stage']['left']['bonus_hearts'] ?? [];
            $this->assertSame(4, count(array_filter($bonus, static fn($c) => $c === 'purple')));
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
