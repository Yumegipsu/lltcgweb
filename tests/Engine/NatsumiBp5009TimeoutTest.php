<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Natsumi PL!SP-bp5-009 — Live Start repeat mill timeout must skip (not auto-yes). */
final class NatsumiBp5009TimeoutTest extends TestCase
{
    public function testTimeoutChoosesNoForRepeatMillBlade(): void
    {
        $prompt = [
            'type' => 'spbp5_repeat_mill_blade',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_name' => 'Natsumi Onitsuka',
            'choices' => ['yes', 'no'],
            'choice_labels' => ['Yes — Mill top', 'No — Stop'],
            'repeat' => 0,
            'max_repeats' => 5,
            'blade_per' => 1,
        ];
        $state = [
            'players' => [
                'p1' => ['id' => 'p1', 'name' => 'P1', 'hand' => [], 'main_deck' => []],
            ],
            'pending_prompt' => $prompt,
        ];

        $data = \buildTimeoutPromptResolution($state, 'p1', $prompt);
        $this->assertSame('no', $data['choice'] ?? null);
    }

    public function testYesNoOptionalIsNotForcedToFirstChoice(): void
    {
        // Regression: yes/no was treated as mandatory → timeout picked choices[0] = yes.
        $this->assertTrue(\isMandatorySkillPrompt([
            'type' => 'spbp5_repeat_mill_blade',
            'choices' => ['yes', 'no'],
        ]));
        $data = \buildTimeoutPromptResolution(
            ['players' => ['p1' => ['hand' => [], 'main_deck' => []]]],
            'p1',
            [
                'type' => 'spbp5_repeat_mill_blade',
                'choices' => ['yes', 'no'],
            ]
        );
        $this->assertNotSame('yes', $data['choice'] ?? 'yes');
    }
}
