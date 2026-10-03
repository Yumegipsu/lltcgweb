<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-028 Honoka — Live Start optional Wait self gains Yellow heart (#229).
 */
final class Issue229HonokaOptionalWaitHeartTest extends TestCase
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

    private function baseState(array $honoka, ?array $extraLive = null): array
    {
        $lives = [$this->fillerLive('live_p1')];
        if ($extraLive) {
            array_unshift($lives, $extraLive);
        }
        return [
            'room_id' => 'ISSUE229',
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
                    'stage' => ['left' => null, 'center' => $honoka, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => array_fill(0, 12, ['card_type' => 'エネルギー', 'active' => true]),
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => $lives,
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

    private function yellowHeartTotal(array $state, string $pid): int
    {
        $n = 0;
        $mod = $state['live_modifiers'][$pid]['bonus_hearts'] ?? [];
        foreach ($mod as $h) {
            $color = is_array($h) ? (string)($h['color'] ?? '') : (string)$h;
            $count = is_array($h) ? intval($h['count'] ?? 1) : 1;
            if (strcasecmp($color, 'yellow') === 0) {
                $n += $count;
            }
        }
        foreach ($state['players'][$pid]['stage'] ?? [] as $m) {
            if (!$m) {
                continue;
            }
            foreach ($m['bonus_hearts'] ?? [] as $h) {
                $color = is_array($h) ? (string)($h['color'] ?? '') : (string)$h;
                $count = is_array($h) ? intval($h['count'] ?? 1) : 1;
                if (strcasecmp($color, 'yellow') === 0) {
                    $n += $count;
                }
            }
        }
        return $n;
    }

    public function testCatalogIsOptionalWaitSelfWithYellowThen(): void
    {
        $honoka = $this->cardByNo('PL!-pb2-028-N', 'honoka');
        $ab = $honoka['abilities'][0] ?? [];
        $this->assertSame('live_start', $ab['trigger'] ?? null);
        $this->assertSame('optional_wait_self', $ab['type'] ?? null);
        $this->assertSame('grant_bonus_hearts', $ab['then']['type'] ?? null);
        $this->assertSame('yellow', $ab['then']['hearts'][0]['color'] ?? null);
    }

    public function testLiveStartOpensWaitPromptAndGrantsYellowHeart(): void
    {
        $honoka = $this->cardByNo('PL!-pb2-028-N', 'honoka');
        $state = $this->baseState($honoka);
        $ab = $honoka['abilities'][0];

        $state = \resolveAbilityEffect($state, 'p1', $honoka, $ab, ['phase' => 'live_start']);
        $this->assertSame('optional_wait_self', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('optional_wait_self', $state['pending_prompt']['ability']['type'] ?? null);
        $this->assertNotEmpty($state['pending_prompt']['live_start'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $logText = json_encode($state['log'] ?? []);
        $this->assertTrue(
            str_contains($logText, 'Waited self'),
            'Expected Waited self in log'
        );
        $this->assertGreaterThanOrEqual(
            1,
            $this->yellowHeartTotal($state, 'p1'),
            'Should gain 1 Yellow heart until Live ends'
        );
    }

    public function testSkipDoesNotGrantHeart(): void
    {
        $honoka = $this->cardByNo('PL!-pb2-028-N', 'honoka');
        $state = $this->baseState($honoka);
        $ab = $honoka['abilities'][0];

        $state = \resolveAbilityEffect($state, 'p1', $honoka, $ab, ['phase' => 'live_start']);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);
        $this->assertSame(0, $this->yellowHeartTotal($state, 'p1'));
        $logText = json_encode($state['log'] ?? []);
        $this->assertTrue(str_contains($logText, 'skipped optional Wait'));
    }

    public function testYellowHeartRemainsAfterWaoWaoActivatesHer(): void
    {
        $honoka = $this->cardByNo('PL!-pb2-028-N', 'honoka');
        $wao = $this->cardByNo('PL!-pb1-028-L', 'wao');
        $state = $this->baseState($honoka);
        $ab = $honoka['abilities'][0];

        $state = \resolveAbilityEffect($state, 'p1', $honoka, $ab, ['phase' => 'live_start']);
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertGreaterThanOrEqual(1, $this->yellowHeartTotal($state, 'p1'));

        // Force Wait state if phase resume cleared markers (assert hearts still survive Activate).
        $center = &$state['players']['p1']['stage']['center'];
        if ($center && !\memberIsInWait($center)) {
            \waitMember($center, $state);
        }
        unset($center);
        $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['center']));

        $waoAb = $wao['abilities'][0];
        $state = \resolveAbilityEffect($state, 'p1', $wao, $waoAb, ['phase' => 'live_start']);
        $this->assertFalse(
            \memberIsInWait($state['players']['p1']['stage']['center']),
            'WAO-WAO should Activate Printemps Honoka from Wait'
        );
        $this->assertGreaterThanOrEqual(
            1,
            $this->yellowHeartTotal($state, 'p1'),
            'Yellow heart from Honoka Wait cost must remain after WAO-WAO Activates her'
        );
    }
}
