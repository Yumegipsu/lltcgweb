<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Regression for GitHub issue #54 — Watashi no Symphony Live Start score +1 at 9 Energy
 * must still fire when a Member Live Start prompt (Tomari swap) interrupts first.
 */
final class Issue54SymphonyLiveStartResumeTest extends TestCase
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

    private function energyCards(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'instance_id' => 'issue54_e' . $i,
                'card_type' => 'エネルギー',
                'active' => true,
            ];
        }
        return $out;
    }

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_set',
            'seq' => 1,
            'turn' => 5,
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
                    'energy_zone' => $this->energyCards(9),
                    'energy_deck' => [],
                    'main_deck' => array_fill(0, 10, ['instance_id' => 'deck', 'card_type' => 'メンバー']),
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];
    }

    public function testSymphonyScorePlusOneAtExactlyNineEnergy(): void
    {
        $symphony = $this->cardByNo('PL!SP-sd1-026-SD', 'issue54_symphony');
        $this->assertSame(4, intval($symphony['score'] ?? 0));
        $this->assertSame('score_if_min_energy', $symphony['abilities'][0]['type'] ?? null);
        $this->assertSame(9, intval($symphony['abilities'][0]['min_energy'] ?? 0));

        $state = $this->baseState();
        $state['phase'] = 'live_start_effects';
        $state['players']['p1']['live_zone'] = [$symphony];

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame(5, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));
    }

    public function testSymphonyStillAppliesAfterTomariLiveStartPrompt(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $tomari = $this->cardByNo('PL!SP-pb2-011-PP', 'issue54_tomari');
            $partner = $this->cardByNo('PL!SP-sd1-018-SD', 'issue54_partner');
            $symphony = $this->cardByNo('PL!SP-sd1-026-SD', 'issue54_symphony');

            $state = $this->baseState();
            $state['players']['p1']['stage']['center'] = $tomari;
            $state['players']['p1']['stage']['left'] = $partner;
            $state['players']['p1']['live_zone'] = [$symphony];

            $state = \beginLiveStartEffectPhase($state, true, false);

            $this->assertSame('optional_swap_area_on_enter', $state['pending_prompt']['type'] ?? null);
            $this->assertSame('p1', $state['_live_start_resume_from'] ?? null);
            $this->assertSame(4, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));

            // Skip Tomari's optional move — must still resume Symphony Live Start.
            $state = \actionResolvePrompt($state, 'p1', ['choice' => 'skip']);

            $this->assertSame(5, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0),
                'Symphony +1 must apply after Tomari Live Start prompt resumes');
            $msgs = array_map(
                static fn($e) => is_array($e) ? (string) ($e['msg'] ?? '') : (string) $e,
                $state['log'] ?? []
            );
            $this->assertTrue(
                (bool) array_filter($msgs, static fn($m) => str_contains($m, 'score +1 (Energy)')),
                'Expected Symphony Energy score log; got: ' . implode(' | ', array_slice($msgs, -8))
            );
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
