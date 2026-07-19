<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class MakiPb1015TimeoutTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function member(string $id, int $cost, string $subunit = ''): array
    {
        return [
            'instance_id' => $id,
            'name' => $id,
            'name_en' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'μ\'s',
            'subunit' => $subunit,
            'cost' => $cost,
            'active' => true,
        ];
    }

    private function opponentPickState(): array
    {
        $maki = $this->cardByNo('PL!-pb1-015-R', 'maki');
        $state = [
            'room_id' => 'maki-timeout-test',
            'mode' => 'pvp',
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'phase_timer_cfg' => ['enabled' => true, 'duration' => 45],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'Maki Player',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $this->member('bibi_cost', 4, 'BiBi'),
                        'center' => $maki,
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [['instance_id' => 'maki_draw_card', 'name_en' => 'Drawn Card']],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'Opponent',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $this->member('opp_left', 3),
                        'center' => $this->member('opp_center', 6),
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];

        $state = \resolveOnEnterAbilities($state, 'p1', $maki, 'center');
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $state = \actionResolvePrompt($state, 'p1', ['member_ids' => ['bibi_cost']]);
        $this->assertSame('opp_pick_stage_active', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);
        $this->assertCount(2, $state['pending_prompt']['stage_members'] ?? []);
        $this->assertNotEmpty(
            \buildTimeoutPromptResolution($state, 'p2', $state['pending_prompt'])['member_id'] ?? null
        );
        return $state;
    }

    public function testOpponentTimeoutStillWaitsOneActiveMember(): void
    {
        $state = $this->opponentPickState();
        \refreshPvpPhaseTimers($state);
        $state['phase_timer']['deadlines']['p2'] = time() - 1;

        $this->assertTrue(\applyPhaseTimeouts($state));
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertCount(1, array_filter(
            $state['players']['p2']['stage'],
            fn($member) => $member && \memberIsInWait($member)
        ));
        $this->assertSame(['maki_draw_card'], array_column($state['players']['p1']['hand'], 'instance_id'));
    }

    public function testAntiSoftlockCannotDismissMandatoryOpponentPick(): void
    {
        $state = $this->opponentPickState();

        $state = \actionAntiSoftlockSkipPrompt($state, 'p2');

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertCount(1, array_filter(
            $state['players']['p2']['stage'],
            fn($member) => $member && \memberIsInWait($member)
        ));
        $this->assertSame(['maki_draw_card'], array_column($state['players']['p1']['hand'], 'instance_id'));
    }
}
