<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-bp3-008 Hanayo Live Start — choose which μ's Member to Wait (#235).
 * Previously auto-Waited the first Stage μ's Member (herself if Active/leftmost).
 */
final class Issue235HanayoMusWaitPickTest extends TestCase
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
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function fillerLive(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => $id,
            'score' => 1,
            'revealed' => true,
            'abilities' => [],
        ];
    }

    private function baseState(array $hanayo, array $honoka): array
    {
        return [
            'room_id' => 'ISSUE235',
            'status' => 'playing',
            'seq' => 10,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => [
                        'left' => $hanayo,
                        'center' => $honoka,
                        'right' => null,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => array_fill(0, 12, ['card_type' => 'エネルギー', 'active' => true]),
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [$this->fillerLive('live_p1')],
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
                    'live_zone' => [$this->fillerLive('live_p2')],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    private function yellowBonus(array $state, string $pid): int
    {
        $n = 0;
        foreach ($state['live_modifiers'][$pid]['bonus_hearts'] ?? [] as $h) {
            $color = is_array($h) ? (string)($h['color'] ?? '') : (string)$h;
            $count = is_array($h) ? intval($h['count'] ?? 1) : 1;
            if (strcasecmp($color, 'yellow') === 0) {
                $n += $count;
            }
        }
        return $n;
    }

    public function testYesWithMultipleMembersOpensPickThenWaitsChosen(): void
    {
        $hanayo = $this->cardByNo('PL!-bp3-008-P', 'hanayo');
        $honoka = $this->cardByNo('PL!-bp3-001-R', 'honoka');
        $state = $this->baseState($hanayo, $honoka);
        $ab = null;
        foreach ($hanayo['abilities'] as $a) {
            if (($a['type'] ?? '') === 'optional_wait_mus_member_hearts') {
                $ab = $a;
                break;
            }
        }
        $this->assertNotNull($ab);

        $state = \resolveAbilityEffect($state, 'p1', $hanayo, $ab, ['phase' => 'live_start']);
        $this->assertSame('optional_wait_mus_hearts', $state['pending_prompt']['type'] ?? null);
        $this->assertArrayNotHasKey('step', $state['pending_prompt']);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertSame('optional_wait_mus_hearts', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('pick_member', $state['pending_prompt']['step'] ?? null);
        $ids = array_column($state['pending_prompt']['stage_members'] ?? [], 'instance_id');
        $this->assertContains('hanayo', $ids);
        $this->assertContains('honoka', $ids);

        // Choose Honoka (center), not leftmost Hanayo.
        $state = \actionResolvePrompt($state, 'p1', ['member_id' => 'honoka']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['center']));
        $this->assertGreaterThanOrEqual(2, $this->yellowBonus($state, 'p1'));
    }

    public function testSkipDoesNotWaitAnyone(): void
    {
        $hanayo = $this->cardByNo('PL!-bp3-008-P', 'hanayo');
        $honoka = $this->cardByNo('PL!-bp3-001-R', 'honoka');
        $state = $this->baseState($hanayo, $honoka);
        $ab = null;
        foreach ($hanayo['abilities'] as $a) {
            if (($a['type'] ?? '') === 'optional_wait_mus_member_hearts') {
                $ab = $a;
                break;
            }
        }
        $this->assertNotNull($ab);

        $state = \resolveAbilityEffect($state, 'p1', $hanayo, $ab, ['phase' => 'live_start']);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['center']));
        $this->assertSame(0, $this->yellowBonus($state, 'p1'));
    }

    public function testSingleActiveMemberAutoWaitsWithoutPick(): void
    {
        $hanayo = $this->cardByNo('PL!-bp3-008-P', 'hanayo');
        $state = $this->baseState($hanayo, $this->cardByNo('PL!-bp3-001-R', 'honoka'));
        // Put Honoka into Wait so only Hanayo is Active.
        waitMember($state['players']['p1']['stage']['center'], $state);

        $ab = null;
        foreach ($hanayo['abilities'] as $a) {
            if (($a['type'] ?? '') === 'optional_wait_mus_member_hearts') {
                $ab = $a;
                break;
            }
        }
        $this->assertNotNull($ab);

        $state = \resolveAbilityEffect($state, 'p1', $hanayo, $ab, ['phase' => 'live_start']);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertGreaterThanOrEqual(2, $this->yellowBonus($state, 'p1'));
    }
}
