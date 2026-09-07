<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #120 / #166 — PL!-PR-007-PR Nozomi [Live Start]:
 * Stage Live Start fires only when this seat is attempting a Live (has Live
 * card(s) in storage). Member-bluff-only seats participate in the round but
 * must not open Live Start prompts (#166).
 */
final class Issue120NozomiMemberBluffLiveStartTest extends TestCase
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

    private function memberBluff(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Bluff',
            'cost' => 2,
            'active' => true,
            'blade' => 1,
            'hearts' => [],
        ];
    }

    private function liveCard(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => 'Rise Up High!',
            'score' => 1,
            'required_hearts' => [['color' => 'any', 'count' => 1]],
            'abilities' => [],
            'revealed' => false,
        ];
    }

    private function oppTarget(string $id, int $cost = 4): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Kanata Konoe',
            'cost' => $cost,
            'active' => true,
            'blade' => 1,
            'hearts' => [],
        ];
    }

    private function performanceState(array $p1LiveZone): array
    {
        $nozomi = $this->cardByNo('PL!-PR-007-PR', 'nozomi_left');
        return [
            'status' => 'playing',
            'phase' => 'live_set',
            'seq' => 8,
            'turn' => 1,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_ready' => ['p1' => true, 'p2' => true],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => [
                        'left' => $nozomi,
                        'center' => null,
                        'right' => null,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => $p1LiveZone,
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => [
                        'left' => null,
                        'center' => null,
                        'right' => $this->oppTarget('kanata'),
                    ],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [$this->liveCard('live_p2')],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testMemberBluffOnlyStillParticipatesInLiveRound(): void
    {
        $state = [
            'status' => 'playing',
            'phase' => 'live_set',
            'seq' => 5,
            'turn' => 1,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_ready' => ['p1' => true, 'p2' => true],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [$this->memberBluff('b1'), $this->memberBluff('b2')],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [$this->liveCard('live_p2')],
                    'success_lives' => [],
                ],
            ],
        ];

        $after = \beginPerformancePhase($state);

        $this->assertSame(['p1', 'p2'], $after['live_attempt'] ?? null);
        $this->assertTrue(\playerParticipatingInLiveRound($after, 'p1'));
        $this->assertFalse(\playerAttemptingLivePerformance($after, 'p1'));
        $this->assertTrue(\playerAttemptingLivePerformance($after, 'p2'));
    }

    /** #166 — bluff-only first performer must not open Nozomi Live Start. */
    public function testNozomiLiveStartDoesNotPromptForMemberBluffOnly(): void
    {
        $state = $this->performanceState([
            $this->memberBluff('b1'),
            $this->memberBluff('b2'),
        ]);

        $state = \beginPerformancePhase($state);
        $this->assertSame('reveal', $state['live_show']['stage'] ?? null);
        $this->assertSame('p1', $state['live_show']['performer'] ?? null);

        $seq = intval($state['live_show']['stage_seq'] ?? 0);
        $state = \actionLiveShowAck($state, 'p1', ['stage_seq' => $seq]);
        $state = \actionLiveShowAck($state, 'p2', ['stage_seq' => $seq]);

        $prompt = $state['pending_prompt'] ?? null;
        $isNozomiLiveStart = is_array($prompt)
            && !empty($prompt['live_start'])
            && (($prompt['owner'] ?? '') === 'p1');
        $this->assertFalse($isNozomiLiveStart, 'Bluff-only seat must not open Stage Live Start');
        $logText = json_encode($state['log'] ?? []);
        $this->assertStringNotContainsString('optional Wait effect (choose)', (string)$logText);
    }

    /** #120 — with a Live in storage, Nozomi Live Start must still prompt. */
    public function testNozomiLiveStartPromptsWhenLiveInStorage(): void
    {
        $state = $this->performanceState([$this->liveCard('live_p1')]);

        $state = \beginPerformancePhase($state);
        $seq = intval($state['live_show']['stage_seq'] ?? 0);
        $state = \actionLiveShowAck($state, 'p1', ['stage_seq' => $seq]);
        $state = \actionLiveShowAck($state, 'p2', ['stage_seq' => $seq]);

        $this->assertSame('optional_wait_self', $state['pending_prompt']['type'] ?? null);
        $this->assertTrue(!empty($state['pending_prompt']['live_start']));
        $this->assertSame('p1', $state['pending_prompt']['owner'] ?? null);
        $logText = json_encode($state['log'] ?? []);
        $this->assertStringContainsString('Nozomi', $logText);
        $this->assertStringContainsString('optional Wait effect (choose)', $logText);
    }
}
