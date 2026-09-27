<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * #206 Karin Live Start is "up to 1" active opponent, and must finish.
 * #207 Wien's activated skill must still offer the Waiting Room add when the deck is empty.
 */
final class Issue206207SoftlockTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        unset($GLOBALS['_lltcg_in_live_start_resolve']);
        unset($GLOBALS['_lltcg_ls_attempts']);
        unset($GLOBALS['_lltcg_ls_resume_depth']);
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

    private function energyCard(string $instanceId, bool $active = true): array
    {
        return [
            'instance_id' => $instanceId,
            'card_no' => 'PL!N-E-001',
            'name' => 'Energy',
            'card_type' => 'energy',
            'active' => $active,
        ];
    }

    public function testKarinWaitIsUpToAndSkippable(): void
    {
        $karin = $this->cardByNo('PL!N-bp4-004-P', 'karin');
        $wait = null;
        foreach ($karin['abilities'] ?? [] as $ab) {
            if (($ab['type'] ?? '') === 'wait_opponent_stage_max_cost') {
                $wait = $ab;
            }
        }
        $this->assertIsArray($wait);
        $this->assertTrue(!empty($wait['up_to']));
        $this->assertTrue(!empty($wait['active_only']));

        $oppA = $this->cardByNo('PL!N-bp4-005-P', 'opp-a');
        $oppB = $this->cardByNo('PL!HS-bp6-012-R', 'opp-b');
        $state = [
            'phase' => 'live_start_effects',
            'turn' => 2,
            'active_player' => 'p2',
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'name' => 'Opp',
                    'stage' => ['left' => $oppA, 'center' => $oppB, 'right' => null],
                    'main_deck' => [], 'hand' => [], 'waiting_room' => [],
                    'energy_zone' => [], 'energy_deck' => [], 'lives' => [], 'live_zone' => [],
                ],
                'p2' => [
                    'name' => 'You',
                    'stage' => ['left' => null, 'center' => $karin, 'right' => null],
                    'main_deck' => [], 'hand' => [], 'waiting_room' => [],
                    'energy_zone' => [], 'energy_deck' => [], 'lives' => [], 'live_zone' => [],
                ],
            ],
        ];
        $state = resolveLiveStartAbilities($state, 'p2');
        $prompt = $state['pending_prompt'] ?? null;
        $this->assertIsArray($prompt);
        $this->assertSame('wait_opponent_stage_pick', $prompt['type']);
        $this->assertTrue(!empty($prompt['up_to']));
        $slots = array_column($prompt['candidates'] ?? [], 'slot');
        $this->assertContains('left', $slots);
        $this->assertContains('center', $slots);

        $state = actionResolvePrompt($state, 'p2', ['choice' => 'skip']);
        $this->assertNotSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? '');
        $this->assertSame('left', $this->slotOf($state, 'p1', 'opp-a'));
        $this->assertLessThan(12, count($state['log'] ?? []));
        $this->assertArrayNotHasKey('_lltcg_in_live_start_resolve', $GLOBALS);
    }

    public function testKarinSkipsMembersAlreadyInWait(): void
    {
        $karin = $this->cardByNo('PL!N-bp4-004-P', 'karin');
        $oppA = $this->cardByNo('PL!N-bp4-005-P', 'opp-a');
        $oppB = $this->cardByNo('PL!N-bp4-005-P', 'opp-b');
        $oppB['in_wait'] = true;
        $state = [
            'phase' => 'live_start_effects',
            'turn' => 2,
            'active_player' => 'p2',
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'name' => 'Opp',
                    'stage' => ['left' => $oppA, 'center' => $oppB, 'right' => null],
                    'main_deck' => [], 'hand' => [], 'waiting_room' => [],
                    'energy_zone' => [], 'energy_deck' => [], 'lives' => [], 'live_zone' => [],
                ],
                'p2' => [
                    'name' => 'You',
                    'stage' => ['left' => null, 'center' => $karin, 'right' => null],
                    'main_deck' => [], 'hand' => [], 'waiting_room' => [],
                    'energy_zone' => [], 'energy_deck' => [], 'lives' => [], 'live_zone' => [],
                ],
            ],
        ];
        $state = resolveLiveStartAbilities($state, 'p2');
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertTrue(!empty($state['players']['p1']['stage']['left']['in_wait']));
        $this->assertTrue(!empty($state['players']['p1']['stage']['center']['in_wait']));
        $this->assertLessThan(12, count($state['log'] ?? []));
    }

    public function testWienEmptyDeckStillOffersWaitingRoomAdd(): void
    {
        $wien = $this->cardByNo('PL!SP-bp7-010-R', 'wien');
        $tomari = $this->cardByNo('PL!SP-bp7-011-P', 'tomari');
        $state = [
            'phase' => 'main_first',
            'turn' => 3,
            'active_player' => 'p1',
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'name' => 'You',
                    'stage' => ['left' => null, 'center' => $wien, 'right' => null],
                    'main_deck' => [],
                    'hand' => [],
                    'waiting_room' => [$tomari],
                    'energy_zone' => [$this->energyCard('e1', true)],
                    'energy_deck' => [$this->energyCard('e2', false)],
                    'lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'name' => 'CPU',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'main_deck' => [], 'hand' => [], 'waiting_room' => [],
                    'energy_zone' => [], 'energy_deck' => [], 'lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];
        $state = actionActivateAbility($state, 'p1', [
            'card_id' => 'wien',
            'ability_index' => 0,
        ]);
        $prompt = $state['pending_prompt'] ?? null;
        $this->assertIsArray($prompt);
        $this->assertSame('pick_wr_to_hand', $prompt['type']);
        $ids = array_column($prompt['candidates'] ?? [], 'instance_id');
        $this->assertContains('wien', $ids);
        $this->assertContains('tomari', $ids);
        $this->assertSame([], $state['players']['p1']['main_deck']);
        $this->assertCount(0, $state['players']['p1']['energy_zone']);
        $this->assertGreaterThanOrEqual(2, count($state['players']['p1']['energy_deck']));

        $state = actionResolvePrompt($state, 'p1', ['card_id' => 'tomari']);
        $handIds = array_column($state['players']['p1']['hand'] ?? [], 'instance_id');
        $this->assertContains('tomari', $handIds);
        $wrIds = array_column($state['players']['p1']['waiting_room'] ?? [], 'instance_id');
        $this->assertContains('wien', $wrIds);
        $this->assertNull($state['pending_prompt'] ?? null);
        $state = refreshEmptyMainDecks($state);
        $deckIds = array_column($state['players']['p1']['main_deck'] ?? [], 'instance_id');
        $this->assertContains('wien', $deckIds);
    }

    private function slotOf(array $state, string $pid, string $instanceId): ?string
    {
        foreach ($state['players'][$pid]['stage'] as $slot => $card) {
            if (is_array($card) && ($card['instance_id'] ?? '') === $instanceId) {
                return (string) $slot;
            }
        }
        return null;
    }
}
