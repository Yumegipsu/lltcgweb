<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #200: PL!SP-bp1-024 Tiny Stars Live Start must grant Blade AND hearts
 * to Kanon (blue) and Keke (pink), not Blade alone.
 */
final class Issue200TinyStarsNamedGrantHeartsTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents(CARDS_FILE), true);
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

    private function firstNamedMember(string $nameEn, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents(CARDS_FILE), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['name_en'] ?? '') === $nameEn
                && (($card['card_type'] ?? '') === 'メンバー'
                    || ($card['card_type_en'] ?? '') === 'Member')) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing Member named ' . $nameEn);
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

    public function testLiveStartGrantsBladeAndHeartsToKanonAndKeke(): void
    {
        $live = $this->cardByNo('PL!SP-bp1-024-L', 'tiny');
        $kanon = $this->firstNamedMember('Kanon Shibuya', 'kanon');
        $keke = $this->firstNamedMember('Keke Tang', 'keke');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['left'] = $kanon;
        $p1['stage']['center'] = $keke;
        $p1['live_zone'] = [$live];

        $state = [
            'room_id' => 'ISSUE200',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $ab = null;
        foreach ($live['abilities'] ?? [] as $row) {
            if (($row['type'] ?? '') === 'grant_named_members_blade') {
                $ab = $row;
                break;
            }
        }
        $this->assertNotNull($ab);

        $state = resolveAbilityEffect($state, 'p1', $live, $ab, ['phase' => 'live_start']);

        $kanonOut = $state['players']['p1']['stage']['left'];
        $kekeOut = $state['players']['p1']['stage']['center'];

        $this->assertSame(1, intval($kanonOut['live_blade_bonus'] ?? 0));
        $this->assertSame(1, intval($kekeOut['live_blade_bonus'] ?? 0));
        $this->assertSame(['blue'], $kanonOut['bonus_hearts'] ?? []);
        $this->assertSame(['pink'], $kekeOut['bonus_hearts'] ?? []);

        $kanonFlat = memberPerformanceHeartsFlat($kanonOut);
        $kekeFlat = memberPerformanceHeartsFlat($kekeOut);
        $this->assertContains('blue', $kanonFlat);
        $this->assertContains('pink', $kekeFlat);
    }

    public function testSrlVariantAlsoGrantsHearts(): void
    {
        $live = $this->cardByNo('PL!SP-bp1-024-SRL', 'tiny_srl');
        $kanon = $this->firstNamedMember('Kanon Shibuya', 'kanon');
        $keke = $this->firstNamedMember('Keke Tang', 'keke');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['left'] = $kanon;
        $p1['stage']['right'] = $keke;
        $p1['live_zone'] = [$live];

        $state = [
            'room_id' => 'ISSUE200-SRL',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];

        $state = resolveLiveStartAbilities($state, 'p1');

        $this->assertSame(['blue'], $state['players']['p1']['stage']['left']['bonus_hearts'] ?? []);
        $this->assertSame(['pink'], $state['players']['p1']['stage']['right']['bonus_hearts'] ?? []);
        $this->assertSame(1, intval($state['players']['p1']['stage']['left']['live_blade_bonus'] ?? 0));
        $this->assertSame(1, intval($state['players']['p1']['stage']['right']['live_blade_bonus'] ?? 0));
    }
}
