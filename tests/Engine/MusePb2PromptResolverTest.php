<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MusePb2PromptResolverTest extends TestCase
{
    public function testResolverHandlesNegateAndSkipStubsAreGone(): void
    {
        $this->assertTrue(function_exists('plMusePb2ResolvePrompt'));
        $this->assertTrue(plMusePb2IsEffectType('pb2_apply_center_group_blade'));
        $this->assertTrue(plMusePb2IsEffectType('pb2_begin_wait_opp_printed_hearts'));

        $state = [
            'seq' => 1,
            'phase' => 'main',
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'hand' => [],
                    'deck' => [],
                    'main_deck' => [],
                    'waiting_room' => [],
                    'success_lives' => [],
                    'stage' => [
                        'center' => [
                            'instance_id' => 'src1',
                            'name_en' => 'Maki',
                            'card_type' => 'メンバー',
                            'group' => "μ's",
                        ],
                        'left' => null,
                        'right' => null,
                    ],
                ],
                'p2' => [
                    'name' => 'P2',
                    'hand' => [],
                    'deck' => [],
                    'main_deck' => [],
                    'waiting_room' => [],
                    'success_lives' => [],
                    'stage' => [
                        'center' => [
                            'instance_id' => 'opp1',
                            'name_en' => 'Opp',
                            'card_type' => 'メンバー',
                            'group' => "μ's",
                            'hearts' => [['color' => 'yellow', 'count' => 1]],
                        ],
                        'left' => null,
                        'right' => null,
                    ],
                ],
            ],
            'pending_prompt' => [
                'type' => 'negate_opp_member_live_success',
                'owner' => 'p1',
                'source_name' => 'Test',
                'then_heart' => ['color' => 'yellow', 'count' => 1],
            ],
            'live_modifiers' => ['p1' => [], 'p2' => []],
            '_live_modifiers' => ['p1' => [], 'p2' => []],
        ];

        $out = plMusePb2ResolvePrompt(
            $state,
            'p1',
            $state['pending_prompt'],
            'center',
            ['slot' => 'center']
        );
        $this->assertIsArray($out);
        $this->assertTrue(
            !empty($out['players']['p2']['stage']['center']['_negate_live_success_until_live_end'])
        );
        $this->assertArrayNotHasKey('pending_prompt', $out);
    }

    public function testOptionalWaitSelfDiscardCenterBladeAppliesBlade(): void
    {
        $state = [
            'seq' => 1,
            'phase' => 'main',
            'turn' => 1,
            'active_player' => 'p1',
            'players' => [
                'p1' => [
                    'name' => 'P1',
                    'hand' => [
                        [
                            'instance_id' => 'h1',
                            'name_en' => 'Dummy',
                            'card_type' => 'メンバー',
                        ],
                    ],
                    'deck' => [],
                    'main_deck' => [],
                    'waiting_room' => [],
                    'success_lives' => [],
                    'energy' => [],
                    'stage' => [
                        'center' => [
                            'instance_id' => 'src1',
                            'name_en' => 'Honoka',
                            'card_type' => 'メンバー',
                            'group' => "μ's",
                            'live_blade_bonus' => 0,
                        ],
                        'left' => [
                            'instance_id' => 'src_side',
                            'name_en' => 'Side',
                            'card_type' => 'メンバー',
                            'group' => "μ's",
                        ],
                        'right' => null,
                    ],
                ],
                'p2' => [
                    'name' => 'P2',
                    'hand' => [],
                    'deck' => [],
                    'main_deck' => [],
                    'waiting_room' => [],
                    'success_lives' => [],
                    'energy' => [],
                    'stage' => ['center' => null, 'left' => null, 'right' => null],
                ],
            ],
            'pending_prompt' => [
                'type' => 'optional_wait_self_discard_center_blade',
                'owner' => 'p1',
                'source_name' => 'Honoka',
                'source_instance_id' => 'src_side',
                'discard' => 1,
                'group' => "μ's",
                'blade' => 2,
            ],
            'live_modifiers' => ['p1' => [], 'p2' => []],
            '_live_modifiers' => ['p1' => [], 'p2' => []],
            'log' => [],
        ];

        // Apply the core pay/effect path without finishing the full phase machine.
        plMusePb2WaitSelfByInstance($state, 'p1', 'src_side');
        plMusePb2DiscardIds($state, 'p1', ['h1'], 'Honoka');
        $this->assertTrue(applyCenterGroupBladeBonus($state, 'p1', "μ's", 2));
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertSame(
            2,
            intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0)
        );
        $this->assertCount(1, $state['players']['p1']['waiting_room']);
    }
}
