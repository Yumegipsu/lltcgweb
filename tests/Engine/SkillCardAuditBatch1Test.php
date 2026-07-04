<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class SkillCardAuditBatch1Test extends TestCase
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

    private function baseState(string $phase = 'main_first'): array
    {
        return [
            'phase' => $phase,
            'seq' => 1,
            'turn' => 1,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'Audit P1',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hand' => [],
                    'waiting_room' => [],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'Audit P2',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hand' => [],
                    'waiting_room' => [],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testAiScreamLiveStartOpponentTextAnswerAppliesBothStageBladeBonus(): void
    {
        $aiScream = $this->cardByNo('LL-PR-004-PR', 'audit_ai_scream');
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['live_zone'] = [$aiScream];
        $state['live_start_optional_queue'] = [];

        $state = \resolveLiveStartAbilities($state, 'p1');

        $this->assertSame('opponent_text_answer', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);
        $this->assertSame('What do you like?', $state['pending_prompt']['prompt'] ?? null);

        $state = \actionResolvePrompt($state, 'p2', ['answer_text' => 'ramen']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(1, \getBothStagesBladeBonus($state));
        $this->assertSame('live_start_effects', $state['phase'] ?? null);
    }

    public function testRinPlayerChoiceCanWaitAllOpponentLowCostMembers(): void
    {
        $rin = $this->cardByNo('PL!-PR-005-PR', 'audit_rin');
        $lowLeft = $this->cardByNo('PL!SP-sd1-020-SD', 'audit_low_left');
        $lowCenter = $this->cardByNo('PL!SP-sd1-019-SD', 'audit_low_center');
        $highRight = $this->cardByNo('PL!-PR-005-PR', 'audit_high_right');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $rin;
        $state['players']['p2']['stage'] = [
            'left' => $lowLeft,
            'center' => $lowCenter,
            'right' => $highRight,
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $rin, 'center');

        $this->assertSame('player_choice', $state['pending_prompt']['type'] ?? null);
        $this->assertContains('wait_low', $state['pending_prompt']['choices'] ?? []);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'wait_low']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertFalse($state['players']['p2']['stage']['left']['active'] ?? true);
        $this->assertFalse($state['players']['p2']['stage']['center']['active'] ?? true);
        $this->assertTrue($state['players']['p2']['stage']['right']['active'] ?? false);
    }

    public function testNicoLiveStartOptionalWaitSelfWaitsOneOpponentMember(): void
    {
        $nico = $this->cardByNo('PL!-PR-009-PR', 'audit_nico');
        $oppLow = $this->cardByNo('PL!SP-sd1-020-SD', 'audit_opp_low');
        $oppHigh = $this->cardByNo('PL!-PR-005-PR', 'audit_opp_high');

        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['stage']['center'] = $nico;
        $state['players']['p2']['stage'] = [
            'left' => $oppLow,
            'center' => $oppHigh,
            'right' => null,
        ];
        $state['live_start_optional_queue'] = [];

        $state = \resolveLiveStartAbilities($state, 'p1');

        $this->assertSame('optional_wait_self', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('audit_nico', $state['pending_prompt']['source_id'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertFalse($state['players']['p1']['stage']['center']['active'] ?? true);
        $this->assertFalse($state['players']['p2']['stage']['left']['active'] ?? true);
        $this->assertTrue($state['players']['p2']['stage']['center']['active'] ?? false);
        $this->assertSame('live_start_effects', $state['phase'] ?? null);
    }
}
