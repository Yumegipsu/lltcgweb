<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * #318: Ginko Momose (PL!HS-PR-035-PR) On Enter —
 * optional put 3 opp WR Members on deck bottom, then Wait 1 Stage Member with
 * original Blade ≤3. The Wait step uses type pr_vol9_wait_opp_max_blade; without
 * a client stage picker the Respond overlay softlocked until the phase timer.
 */
final class Issue318GinkoWaitPickTest extends TestCase
{
    private function ginko(): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $c) {
            if (($c['card_no'] ?? '') === 'PL!HS-PR-035-PR') {
                $c['instance_id'] = 'ginko';
                $c['active'] = true;
                return $c;
            }
        }
        $this->fail('Missing PL!HS-PR-035-PR');
    }

    private function member(string $id, int $blade): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'T-' . $id,
            'name_en' => 'M' . $id,
            'name' => 'M' . $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Hasunosora',
            'cost' => 3,
            'blade' => $blade,
            'active' => true,
        ];
    }

    private function startOptional(): array
    {
        $ginko = $this->ginko();
        $wr = [
            $this->member('w1', 1),
            $this->member('w2', 1),
            $this->member('w3', 1),
            $this->member('w4', 1),
        ];
        $stage = [
            'left' => $this->member('sLow', 2),
            'center' => $this->member('sMid', 3),
            'right' => $this->member('sHigh', 4),
        ];
        $p1 = [
            'id' => 'p1',
            'name' => 'P1',
            'hand' => [],
            'waiting_room' => [],
            'stage' => ['left' => null, 'center' => $ginko, 'right' => null],
            'energy_zone' => [],
            'main_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
        $p2 = [
            'id' => 'p2',
            'name' => 'P2',
            'hand' => [],
            'waiting_room' => $wr,
            'stage' => $stage,
            'energy_zone' => [],
            'main_deck' => [$this->member('d0', 1), $this->member('d1', 1)],
            'live_zone' => [],
            'success_lives' => [],
        ];
        $state = [
            'room_id' => 'G318',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'main',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            return \resolveAbilityEffect(
                $state,
                'p1',
                $ginko,
                $ginko['abilities'][0],
                ['slot' => 'center', 'phase' => 'on_enter']
            );
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    private function answer(array $state, array $data): array
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            return \actionResolvePrompt($state, 'p1', $data);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testCatalogAbility(): void
    {
        $ab = $this->ginko()['abilities'][0] ?? [];
        $this->assertSame('on_enter', $ab['trigger'] ?? null);
        $this->assertSame('optional_opp_wr_members_to_deck_bottom_then_wait', $ab['type'] ?? null);
        $this->assertSame(3, intval($ab['wr_count'] ?? 0));
        $this->assertSame(3, intval($ab['max_original_blade'] ?? 0));
    }

    public function testSelectingWrMembersOpensWaitPickAmongBladeEligible(): void
    {
        $state = $this->startOptional();
        $this->assertSame(
            'optional_opp_wr_members_to_deck_bottom_then_wait',
            $state['pending_prompt']['type'] ?? null
        );
        $state = $this->answer($state, ['choice' => 'yes']);
        $this->assertSame('pick', $state['pending_prompt']['step'] ?? null);
        $state = $this->answer($state, ['card_ids' => ['w1', 'w2', 'w3']]);
        $this->assertSame('pr_vol9_wait_opp_max_blade', $state['pending_prompt']['type'] ?? null);
        $slots = array_column($state['pending_prompt']['candidates'] ?? [], 'slot');
        sort($slots);
        $this->assertSame(['center', 'left'], $slots, 'Blade 4 right is ineligible; left(2)+center(3) are');
        $state = $this->answer($state, ['slot' => 'left']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertTrue(\memberIsInWait($state['players']['p2']['stage']['left']));
        $this->assertFalse(\memberIsInWait($state['players']['p2']['stage']['center']));
    }

    public function testNoEligibleStageMemberSkipsWaitStep(): void
    {
        $ginko = $this->ginko();
        $state = [
            'room_id' => 'G318b',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'main',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => $ginko, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [
                        $this->member('w1', 1),
                        $this->member('w2', 1),
                        $this->member('w3', 1),
                    ],
                    'stage' => [
                        'left' => $this->member('a', 4),
                        'center' => $this->member('b', 5),
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [$this->member('d0', 1)],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveAbilityEffect(
                $state,
                'p1',
                $ginko,
                $ginko['abilities'][0],
                ['slot' => 'center', 'phase' => 'on_enter']
            );
            $state = $this->answer($state, ['choice' => 'yes']);
            $state = $this->answer($state, ['card_ids' => ['w1', 'w2', 'w3']]);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertFalse(\memberIsInWait($state['players']['p2']['stage']['left']));
    }

    public function testClientOpensStagePickerForWaitStep(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/client/js/prompt-renderer.js');
        $this->assertStringContainsString("pr?.type==='pr_vol9_wait_opp_max_blade'", $js);
        $branch = strpos($js, "pr?.type==='pr_vol9_wait_opp_max_blade'");
        $fallback = strpos($js, 'renderBranchChoiceButtons(pr,s,myId,box);');
        $this->assertNotFalse($branch);
        $this->assertNotFalse($fallback);
        $this->assertLessThan($fallback, $branch);
        $this->assertStringContainsString('openStageSlotPick', substr($js, $branch, 500));
    }
}
