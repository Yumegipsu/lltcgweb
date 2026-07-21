<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Kaho PL!HS-bp6-001 Live Success must not softlock when resolving Yell deck-top. */
final class KahoBp6001LiveSuccessYellDeckTopTest extends TestCase
{
    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_success_effects',
            'turn' => 1,
            'first_player' => 'p1',
            'seq' => 1,
            '_performance_continue' => 'p1',
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'CPU (Easy)',
                    'hand' => [],
                    'main_deck' => [],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                    'stage' => [
                        'center' => [
                            'instance_id' => 'kaho',
                            'card_no' => 'PL!HS-bp6-001-SEC',
                            'name_en' => 'Kaho Hinoshita',
                            'card_type' => 'メンバー',
                            'group' => 'Hasunosora',
                            'cost' => 4,
                            'active' => true,
                            'abilities' => [[
                                'trigger' => 'live_success',
                                'type' => 'live_success_pick_yell_deck_top',
                            ]],
                        ],
                        'left' => null,
                        'right' => null,
                    ],
                    '_pending_yell_wr' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'Human',
                    'hand' => [],
                    'main_deck' => [],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                    'stage' => ['center' => null, 'left' => null, 'right' => null],
                ],
            ],
        ];
    }

    private function yellCard(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!HS-bp6-027-L',
            'name_en' => 'Tsukuyomi Kurage',
            'card_type' => 'ライブ',
            'group' => 'Hasunosora',
            'score' => 1,
        ];
    }

    public function testLiveSuccessOpensOptionalYellDeckTopPrompt(): void
    {
        $state = $this->baseState();
        $yell = $this->yellCard('yell_a');
        $state['players']['p1']['_pending_yell_wr'] = [$yell];
        $live = [
            'instance_id' => 'live_ok',
            'card_no' => 'PL!HS-bp6-028-L',
            'name_en' => 'Dummy Live',
            'card_type' => 'ライブ',
            'group' => 'Hasunosora',
            'score' => 1,
        ];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$live], 0, [], [$yell]);
        $this->assertSame('live_success_pick_yell_deck_top', $state['pending_prompt']['type'] ?? null);
        $this->assertTrue(!empty($state['pending_prompt']['optional']));
        $this->assertContains('skip', $state['pending_prompt']['choices'] ?? []);
    }

    public function testPickPutsYellOnDeckTopAndContinues(): void
    {
        $state = $this->baseState();
        $yell = $this->yellCard('yell_a');
        $state['players']['p1']['_pending_yell_wr'] = [$yell];
        $state['pending_prompt'] = [
            'type' => 'live_success_pick_yell_deck_top',
            'owner' => 'p1',
            'responder' => 'p1',
            'optional' => true,
            'source_name' => 'Kaho Hinoshita',
            'candidates' => [cardPromptSummary($yell)],
            'choices' => ['pick', 'skip'],
        ];

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'yell_a']);
        $this->assertNull($state['pending_prompt'] ?? null);
        // finishLiveSuccessEffects advances the turn; the deck-top card is drawn into hand.
        $handIds = array_column($state['players']['p1']['hand'] ?? [], 'instance_id');
        $this->assertContains('yell_a', $handIds);
        $this->assertSame([], $state['players']['p1']['_pending_yell_wr'] ?? []);
    }

    public function testBarePickChoiceWithSingleCandidateAutoPicks(): void
    {
        $state = $this->baseState();
        $yell = $this->yellCard('yell_a');
        $state['players']['p1']['_pending_yell_wr'] = [$yell];
        $state['pending_prompt'] = [
            'type' => 'live_success_pick_yell_deck_top',
            'owner' => 'p1',
            'responder' => 'p1',
            'optional' => true,
            'source_name' => 'Kaho Hinoshita',
            'candidates' => [cardPromptSummary($yell)],
            'choices' => ['pick', 'skip'],
        ];

        // Single candidate + bare pick is safe to auto-resolve (CPU scored-choice path).
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'pick']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'] ?? [], 'instance_id');
        $this->assertContains('yell_a', $handIds);
    }

    public function testBarePickChoiceWithMultipleCandidatesKeepsPrompt(): void
    {
        $state = $this->baseState();
        $yellA = $this->yellCard('yell_a');
        $yellB = $this->yellCard('yell_b');
        $state['players']['p1']['_pending_yell_wr'] = [$yellA, $yellB];
        $state['pending_prompt'] = [
            'type' => 'live_success_pick_yell_deck_top',
            'owner' => 'p1',
            'responder' => 'p1',
            'optional' => true,
            'source_name' => 'Kaho Hinoshita',
            'candidates' => [cardPromptSummary($yellA), cardPromptSummary($yellB)],
            'choices' => ['pick', 'skip'],
        ];

        // Human two-step UI may send choice=pick before card_id — must not dismiss.
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'pick']);
        $this->assertSame('live_success_pick_yell_deck_top', $state['pending_prompt']['type'] ?? null);
        $this->assertCount(2, $state['players']['p1']['_pending_yell_wr'] ?? []);
    }

    public function testSkipClearsPrompt(): void
    {
        $state = $this->baseState();
        $yell = $this->yellCard('yell_a');
        $state['players']['p1']['_pending_yell_wr'] = [$yell];
        $state['pending_prompt'] = [
            'type' => 'live_success_pick_yell_deck_top',
            'owner' => 'p1',
            'responder' => 'p1',
            'optional' => true,
            'source_name' => 'Kaho Hinoshita',
            'candidates' => [cardPromptSummary($yell)],
            'choices' => ['pick', 'skip'],
        ];

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'skip']);
        $this->assertNull($state['pending_prompt'] ?? null);
        // Yell cards are flushed out of the pending pool when Live Success finishes.
        $this->assertSame([], $state['players']['p1']['_pending_yell_wr'] ?? []);
    }

    public function testMissingPoolCardSkipsInsteadOfThrowing(): void
    {
        $state = $this->baseState();
        $yell = $this->yellCard('yell_a');
        // Pool empty but candidate still listed — previously threw and hung the CPU.
        $state['players']['p1']['_pending_yell_wr'] = [];
        $state['pending_prompt'] = [
            'type' => 'live_success_pick_yell_deck_top',
            'owner' => 'p1',
            'responder' => 'p1',
            'optional' => true,
            'source_name' => 'Kaho Hinoshita',
            'candidates' => [cardPromptSummary($yell)],
            'choices' => ['pick', 'skip'],
        ];

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'yell_a']);
        $this->assertNull($state['pending_prompt'] ?? null);
    }
}
