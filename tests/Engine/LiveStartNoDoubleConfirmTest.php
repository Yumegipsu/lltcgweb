<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Live Start optional prompts must not open a second yes/no before Performance. */
final class LiveStartNoDoubleConfirmTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        parent::tearDown();
    }

    private function museMember(string $id, string $name, array $abilities = []): array
    {
        return [
            'instance_id' => $id,
            'name_en' => $name,
            'name' => $name,
            'card_type' => 'メンバー',
            'group' => "μ's",
            'active' => true,
            'abilities' => $abilities,
        ];
    }

    private function baseLiveStartState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_set',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [
                        ['instance_id' => 'e0', 'active' => true, 'card_type' => 'エネルギー'],
                        ['instance_id' => 'e1', 'active' => true, 'card_type' => 'エネルギー'],
                        ['instance_id' => 'e2', 'active' => true, 'card_type' => 'エネルギー'],
                    ],
                    'main_deck' => [['instance_id' => 'd1']],
                    'success_lives' => [],
                    'live_zone' => [[
                        'instance_id' => 'live1',
                        'card_type' => 'ライブ',
                        'name_en' => 'Test Live',
                        'score' => 3,
                        'abilities' => [],
                    ]],
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
    }

    public function testWaitSelfCenterBladeOpensOnlyOneConfirm(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;

        $eli = $this->museMember('eli_ls', 'Eli Ayase', [[
            'trigger' => 'live_start',
            'type' => 'optional_wait_self_center_blade',
            'group' => "μ's",
            'amount' => 1,
        ]]);
        $center = $this->museMember('honoka_c', 'Honoka Kosaka');

        $state = $this->baseLiveStartState();
        $state['players']['p1']['stage']['left'] = $eli;
        $state['players']['p1']['stage']['center'] = $center;

        $state = \beginLiveStartEffectPhase($state, true, false);
        $this->assertSame('optional_wait_self_center_blade', $state['pending_prompt']['type'] ?? null);
        $this->assertEmpty($state['live_start_optional_queue'] ?? [], 'Must not queue a second optional_live_start wrapper');

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertNull($state['pending_prompt'] ?? null, 'Must not open a second yes/no after confirming');
        $this->assertTrue(
            !empty($state['players']['p1']['stage']['left']['in_wait'])
            || ($state['players']['p1']['stage']['left']['active'] ?? true) === false
            || !empty($state['players']['p1']['stage']['left']['wait'])
        );
        $this->assertGreaterThanOrEqual(
            1,
            intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0)
        );
    }

    public function testQueuedPayEnergyStillSingleConfirmThenApplies(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;

        $ceras = [
            'instance_id' => 'ceras_ls',
            'name_en' => 'Ceras Yanagida Lilienfeld',
            'name' => 'Ceras',
            'card_type' => 'メンバー',
            'group' => 'Hasunosora',
            'active' => true,
            'abilities' => [[
                'trigger' => 'live_start',
                'type' => 'optional_pay_energy',
                'cost' => 1,
                'then' => ['type' => 'blade_bonus', 'amount' => 2],
            ]],
        ];
        $state = $this->baseLiveStartState();
        $state['players']['p1']['stage']['center'] = $ceras;
        $state = \beginLiveStartEffectPhase($state, true, false);
        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes', 'pay' => true]);
        $this->assertNull($state['pending_prompt'] ?? null, 'optional_pay_energy must not re-prompt after confirm');
        $blade = intval($state['live_modifiers']['p1']['blade_bonus'] ?? 0);
        $this->assertGreaterThanOrEqual(2, $blade, 'Blade bonus should apply after single confirm');
    }

    public function testPayOrDiscardOpensNativePromptOnceNotWrapper(): void
    {
        $member = $this->museMember('pay_or_disc', 'Test Member', [[
            'trigger' => 'live_start',
            'type' => 'live_start_pay_or_discard',
            'cost' => 2,
            'discard' => 2,
        ]]);
        $state = $this->baseLiveStartState();
        $state['players']['p1']['stage']['center'] = $member;
        $state['players']['p1']['hand'] = [
            ['instance_id' => 'h1', 'card_type' => 'メンバー'],
            ['instance_id' => 'h2', 'card_type' => 'メンバー'],
        ];

        $state = \beginLiveStartEffectPhase($state, true, false);
        $this->assertSame('live_start_pay_or_discard', $state['pending_prompt']['type'] ?? null);
        $this->assertNotSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
    }

    /** Regression: wrapper Yes must not leave another yes/no for the same source. */
    public function testRedundantFollowUpCollapseIfAbilityIgnoresConfirm(): void
    {
        $wrapper = [
            'type' => 'optional_live_start',
            'owner' => 'p1',
            'source_id' => 'src1',
            'source_name' => 'Src',
            'ability_index' => 0,
        ];
        $nested = [
            'type' => 'optional_wait_self_center_blade',
            'owner' => 'p1',
            'source_id' => 'src1',
            'choices' => ['yes', 'no'],
        ];
        $this->assertTrue(\isRedundantOptionalLiveStartYesNoFollowUp($nested, $wrapper));

        $nestedPay = $nested;
        $nestedPay['needs_pay'] = true;
        $this->assertFalse(\isRedundantOptionalLiveStartYesNoFollowUp($nestedPay, $wrapper));
    }
}
