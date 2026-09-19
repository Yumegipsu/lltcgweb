<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #194: PL!HS-bp6-008 On Enter must let the player choose which
 * Hasunosora Live (score ≤4) from WR to add — never auto-first-match.
 */
final class Issue194IzumiOnEnterWrPickTest extends TestCase
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

    private function onEnterAbility(array $izumi): array
    {
        foreach ($izumi['abilities'] ?? [] as $ab) {
            if (($ab['type'] ?? '') === 'mandatory_wait_self_add_wr_live') {
                return $ab;
            }
        }
        $this->fail('Missing mandatory_wait_self_add_wr_live ability');
    }

    public function testOnEnterOpensWrLivePickNotAutoAdd(): void
    {
        $izumi = $this->cardByNo('PL!HS-bp6-008-R', 'izumi');
        $liveLow = $this->cardByNo('PL!HS-bp1-020-L', 'live_low');
        $liveAlt = $this->cardByNo('PL!HS-bp1-021-L', 'live_alt');
        $liveHigh = $this->cardByNo('PL!HS-bp6-027-L', 'live_high');
        // Force a failing score so the filter exclusion is explicit.
        $liveHigh['score'] = 6;
        $liveHigh['group'] = 'Hasunosora';
        $liveHigh['card_type'] = 'ライブ';

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $izumi;
        $p1['waiting_room'] = [$liveLow, $liveAlt, $liveHigh];

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 1,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $ab = $this->onEnterAbility($izumi);
        $state = \resolveAbilityEffect($state, 'p1', $izumi, $ab, [
            'phase' => 'on_enter',
            'slot' => 'center',
            'ability_index' => 0,
        ]);

        $stageIzumi = $state['players']['p1']['stage']['center'] ?? null;
        $this->assertNotNull($stageIzumi);
        $this->assertTrue(\memberIsInWait($stageIzumi), 'Izumi must Wait herself first');

        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains('live_low', $candIds);
        $this->assertContains('live_alt', $candIds);
        $this->assertNotContains('live_high', $candIds, 'score 6 Live must not be a candidate');

        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertNotContains('live_low', $handIds);
        $this->assertNotContains('live_alt', $handIds);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'live_alt']);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('live_alt', $handIds);
        $this->assertNotContains('live_low', $handIds);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('live_low', $wrIds);
        $this->assertContains('live_high', $wrIds);
        $this->assertNotContains('live_alt', $wrIds);
    }

    public function testOnEnterWithNoMatchingLiveStillWaits(): void
    {
        $izumi = $this->cardByNo('PL!HS-bp6-008-R', 'izumi2');
        $liveHigh = [
            'instance_id' => 'live_hi',
            'card_no' => 'PL!HS-bp6-027-L',
            'name_en' => 'High Score Live',
            'card_type' => 'ライブ',
            'group' => 'Hasunosora',
            'score' => 7,
        ];

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $izumi;
        $p1['waiting_room'] = [$liveHigh];

        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 1,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $ab = $this->onEnterAbility($izumi);
        $state = \resolveAbilityEffect($state, 'p1', $izumi, $ab, [
            'phase' => 'on_enter',
            'slot' => 'center',
            'ability_index' => 0,
        ]);

        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['center']));
        $this->assertSame(['live_hi'], array_column($state['players']['p1']['waiting_room'], 'instance_id'));
        $this->assertSame([], $state['players']['p1']['hand']);
    }
}
