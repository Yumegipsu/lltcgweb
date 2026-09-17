<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #189: PL!HS-bp6-008-R Live Start must clear Wait when a score≤2 Live
 * is in Live storage (e.g. Landing action Yeah!!).
 */
final class Issue189HsBp6IzumiLiveStartActivateTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents(CARDS_FILE), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function emptyPlayer(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'hand' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'waiting_room' => [],
            'energy_zone' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    private function liveStartAbility(array $izumi): array
    {
        foreach ($izumi['abilities'] ?? [] as $ab) {
            if (($ab['type'] ?? '') === 'auto_activate_if_live_zone_score_max') {
                return $ab;
            }
        }
        $this->fail('Missing auto_activate_if_live_zone_score_max on Izumi');
    }

    public function testLiveStartClearsWaitWithLandingActionYeah(): void
    {
        $izumi = $this->cardByNo('PL!HS-bp6-008-R', 'izumi');
        $live = $this->cardByNo('PL!S-bp5-020-L', 'landing_yeah'); // score 1

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p2 = $this->emptyPlayer('p2', 'P2');

        $state = [
            'room_id' => 'ISSUE189-IZUMI',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];

        waitMember($izumi, $state);
        $this->assertTrue(memberIsInWait($izumi));
        $this->assertFalse($izumi['active'] ?? true);

        $state['players']['p1']['stage']['center'] = $izumi;
        $state['players']['p1']['live_zone'] = [$live];

        $ab = $this->liveStartAbility($izumi);
        $state = resolveAbilityEffect($state, 'p1', $izumi, $ab, ['phase' => 'live_start']);

        $stood = $state['players']['p1']['stage']['center'];
        $this->assertNotNull($stood);
        $this->assertFalse(memberIsInWait($stood), 'Live Start must clear in_wait, not only set active');
        $this->assertTrue($stood['active'] ?? false);
    }

    public function testLiveStartDoesNotActivateWhenOnlyHighScoreLive(): void
    {
        $izumi = $this->cardByNo('PL!HS-bp6-008-R', 'izumi');
        $highLive = $this->cardByNo('PL!S-bp5-020-L', 'high_live');
        $highLive['score'] = 3;

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p2 = $this->emptyPlayer('p2', 'P2');

        $state = [
            'room_id' => 'ISSUE189-IZUMI-HIGH',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];

        waitMember($izumi, $state);
        $state['players']['p1']['stage']['center'] = $izumi;
        $state['players']['p1']['live_zone'] = [$highLive];

        $ab = $this->liveStartAbility($izumi);
        $state = resolveAbilityEffect($state, 'p1', $izumi, $ab, ['phase' => 'live_start']);

        $still = $state['players']['p1']['stage']['center'];
        $this->assertTrue(memberIsInWait($still));
        $this->assertFalse($still['active'] ?? true);
    }
}
