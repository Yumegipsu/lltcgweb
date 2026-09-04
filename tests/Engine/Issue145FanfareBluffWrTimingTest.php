<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #145 — Fanfare!!! (PL!HS-bp6-031-L): Member bluffs must reach WR
 * before Live Start resolves (official 8.3.4 → 8.3.8), so a Mira-Cra Park!
 * bluff can count toward the 15-card deck-bottom threshold.
 */
final class Issue145FanfareBluffWrTimingTest extends TestCase
{
    private function miraMember(string $id, string $name = 'Member'): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => $name,
            'group' => 'Hasunosora',
            'subunit' => 'みらくらぱーく!',
            'cost' => 2,
            'blade' => 1,
            'hearts' => [],
            'active' => true,
        ];
    }

    private function fanfareLive(string $id): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === 'PL!HS-bp6-031-L') {
                $card['instance_id'] = $id;
                $card['revealed'] = false;
                return $card;
            }
        }
        $this->fail('Missing Fanfare!!! PL!HS-bp6-031-L');
    }

    private function baseState(array $liveZone, array $wrMembers): array
    {
        $hime = [
            'instance_id' => 'hime_stage',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Hime Anyoji',
            'group' => 'Hasunosora',
            'subunit' => 'みらくらぱーく!',
            'cost' => 5,
            'blade' => 2,
            'hearts' => [['color' => 'pink', 'count' => 2]],
            'active' => true,
        ];
        return [
            'room_id' => 'FANFARE145',
            'status' => 'playing',
            'seq' => 10,
            'turn' => 3,
            'phase' => 'live_set',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_ready' => ['p1' => true, 'p2' => true],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => $hime, 'right' => null],
                    'waiting_room' => $wrMembers,
                    'energy_zone' => array_fill(0, 6, ['card_type' => 'エネルギー', 'active' => true]),
                    'main_deck' => array_map(
                        fn($i) => ['instance_id' => "d$i", 'card_type' => 'メンバー'],
                        range(1, 10)
                    ),
                    'energy_deck' => [],
                    'live_zone' => $liveZone,
                    'success_lives' => [['instance_id' => 'succ1', 'card_type' => 'ライブ']],
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
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    private function ackBoth(array $state): array
    {
        $seq = intval($state['live_show']['stage_seq'] ?? 0);
        $state = \actionLiveShowAck($state, 'p1', ['stage_seq' => $seq]);
        return \actionLiveShowAck($state, 'p2', ['stage_seq' => $seq]);
    }

    public function testBluffCountsTowardFanfareFifteenBeforeLiveStartPrompt(): void
    {
        $wr = [];
        for ($i = 1; $i <= 14; $i++) {
            $wr[] = $this->miraMember("wr$i");
        }
        $bluffHime = $this->miraMember('bluff_hime', 'Hime Anyoji');
        $fanfare = $this->fanfareLive('fanfare');

        $state = $this->baseState([$fanfare, $bluffHime], $wr);
        $state = \beginPerformancePhase($state);
        $this->assertSame('reveal', $state['live_show']['stage'] ?? null);

        // Bluffs still in storage during the reveal beat.
        $this->assertContains(
            'bluff_hime',
            array_column($state['players']['p1']['live_zone'] ?? [], 'instance_id')
        );

        $state = $this->ackBoth($state);

        $this->assertNotContains(
            'bluff_hime',
            array_column($state['players']['p1']['live_zone'] ?? [], 'instance_id'),
            'Official 8.3.4: non-Live bluffs leave storage before Live Start'
        );
        $this->assertContains(
            'bluff_hime',
            array_column($state['players']['p1']['waiting_room'] ?? [], 'instance_id')
        );

        $this->assertSame(
            'optional_shuffle_wr_members_deck_bottom',
            $state['pending_prompt']['type'] ?? null
        );

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);

        $this->assertSame(
            'pick_named_member_blade',
            $state['pending_prompt']['type'] ?? null,
            '15th Mira-Cra Park! bluff must count so Fanfare can grant +3 Blade'
        );
    }

    public function testFourteenWithoutBluffDoesNotQualify(): void
    {
        $wr = [];
        for ($i = 1; $i <= 14; $i++) {
            $wr[] = $this->miraMember("wr$i");
        }
        $fanfare = $this->fanfareLive('fanfare');

        $state = $this->baseState([$fanfare], $wr);
        $state = \beginPerformancePhase($state);
        $state = $this->ackBoth($state);

        $this->assertSame(
            'optional_shuffle_wr_members_deck_bottom',
            $state['pending_prompt']['type'] ?? null
        );

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);

        $this->assertNotSame(
            'pick_named_member_blade',
            $state['pending_prompt']['type'] ?? null,
            '14 Mira-Cra Park! Members alone must not qualify'
        );
    }
}
