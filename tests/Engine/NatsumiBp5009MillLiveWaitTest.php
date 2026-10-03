<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!SP-bp5-009 Natsumi — [Live Start] mill top for Blade; if Live, Wait self.
 * Regression: Live Start omitted ctx.slot so member_slot was empty and Wait never applied.
 */
final class NatsumiBp5009MillLiveWaitTest extends TestCase
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

    private function baseState(array $natsumi, array $deck): array
    {
        return [
            'room_id' => 'NAT009',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 3,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_attempt' => ['p1'],
            '_live_start_perf_pid' => 'p1',
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $natsumi,
                        'center' => null,
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'main_deck' => $deck,
                    'live_zone' => [[
                        'instance_id' => 'live1',
                        'card_type' => 'ライブ',
                        'card_type_en' => 'Live',
                        'name_en' => 'Test Live',
                        'required_hearts' => [],
                    ]],
                    'success_lives' => [],
                    'yell_cards' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testMillingLivePutsNatsumiIntoWaitEvenWithoutMemberSlot(): void
    {
        $natsumi = $this->cardByNo('PL!SP-bp5-009-R', 'natsumi');
        $live = $this->cardByNo('LL-bp5-001-L', 'milled_live');
        // Extra deck card so finishing this mill keeps the repeat prompt (no Live auto-resolve).
        $filler = $this->cardByNo('PL!SP-bp5-001-AR', 'deck_filler');
        $state = $this->baseState($natsumi, [$live, $filler]);
        // Simulate the buggy prompt: empty member_slot (Live Start used to omit ctx.slot).
        $state['pending_prompt'] = [
            'type' => 'spbp5_repeat_mill_blade',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_id' => 'natsumi',
            'source_name' => 'Natsumi Onitsuka',
            'repeat' => 0,
            'max_repeats' => 5,
            'blade_per' => 1,
            'member_id' => '',
            'member_slot' => '',
            'prompt' => 'Mill?',
            'choices' => ['yes', 'no'],
            'ability' => $natsumi['abilities'][0],
        ];

        $out = \spBp5ResolvePrompt($state, 'p1', $state['pending_prompt'], 'yes', []);
        $this->assertIsArray($out);
        $mbr = $out['players']['p1']['stage']['left'] ?? null;
        $this->assertNotNull($mbr);
        $this->assertTrue(\memberIsInWait($mbr), 'Natsumi must Wait when milled card is a Live');
        $this->assertSame('milled_live', $out['players']['p1']['waiting_room'][0]['instance_id'] ?? null);
        $this->assertSame('spbp5_repeat_mill_blade', $out['pending_prompt']['type'] ?? null);
    }

    public function testLiveStartOpensPromptWithMemberSlotResolved(): void
    {
        $natsumi = $this->cardByNo('PL!SP-bp5-009-R', 'natsumi');
        $live = $this->cardByNo('LL-bp5-001-L', 'milled_live');
        $state = $this->baseState($natsumi, [$live]);

        $out = \resolveLiveStartAbilities($state, 'p1');
        $pr = $out['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('spbp5_repeat_mill_blade', $pr['type'] ?? null);
        $this->assertSame('left', $pr['member_slot'] ?? null);
        $this->assertSame('natsumi', $pr['member_id'] ?? null);

        $out = \actionResolvePrompt($out, 'p1', ['choice' => 'yes']);
        $mbr = $out['players']['p1']['stage']['left'] ?? null;
        $this->assertTrue(\memberIsInWait($mbr), 'Full Live Start path must Wait on milled Live');
    }

    public function testMillingNonLiveDoesNotWait(): void
    {
        $natsumi = $this->cardByNo('PL!SP-bp5-009-R', 'natsumi');
        $member = $this->cardByNo('PL!SP-bp5-001-AR', 'milled_member');
        $state = $this->baseState($natsumi, [$member]);
        $state['pending_prompt'] = [
            'type' => 'spbp5_repeat_mill_blade',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_id' => 'natsumi',
            'source_name' => 'Natsumi Onitsuka',
            'repeat' => 0,
            'max_repeats' => 5,
            'blade_per' => 1,
            'member_slot' => 'left',
            'member_id' => 'natsumi',
            'choices' => ['yes', 'no'],
            'ability' => $natsumi['abilities'][0],
        ];

        $out = \spBp5ResolvePrompt($state, 'p1', $state['pending_prompt'], 'yes', []);
        $this->assertIsArray($out);
        $this->assertFalse(\memberIsInWait($out['players']['p1']['stage']['left']));
    }
}
