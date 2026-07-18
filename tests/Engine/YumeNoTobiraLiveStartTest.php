<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class YumeNoTobiraLiveStartTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function member(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => $id,
            'active' => true,
        ];
    }

    public function testRevealsPerMemberMovesCardsToWaitingRoomAndAddsScore(): void
    {
        $yume = $this->cardByNo('PL!-bp3-022-L', 'yume_no_tobira');
        $revealedLiveA = $this->cardByNo('PL!-bp3-023-L', 'revealed_live_a');
        // Compact/imported game states may retain only the normalized English type.
        unset($revealedLiveA['card_type']);
        $revealedMember = $this->member('revealed_member');
        $revealedLiveB = $this->cardByNo('PL!-bp3-024-L', 'revealed_live_b');
        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 1,
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
                    'stage' => [
                        'left' => $this->member('p1_left'),
                        'center' => $this->member('p1_center'),
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [$revealedLiveA, $revealedMember, $revealedLiveB],
                    'live_zone' => [$yume],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $this->member('p2_center'),
                        'right' => null,
                    ],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $state = \resolveLiveStartAbilities($state, 'p1');

        $this->assertSame(7, $state['players']['p1']['live_zone'][0]['score'] ?? null);
        $this->assertSame(
            ['revealed_live_a', 'revealed_member', 'revealed_live_b'],
            array_column($state['players']['p1']['waiting_room'], 'instance_id')
        );
        $this->assertSame([], $state['players']['p1']['main_deck']);
    }
}
