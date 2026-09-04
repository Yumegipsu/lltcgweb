<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Hanamusubi (PL!HS-bp5-019-L): −2 green per other Hasunosora Live in storage.
 * Zone-count skills ignore Member bluffs (#45). Production discards bluffs before
 * Live Start (#145 / official 8.3.4); tests may still place bluffs in-zone to
 * prove they never inflate the reduction (FBEF4E).
 */
final class HanamusubiLiveZoneReduceTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function hasunosoraMember(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Hasunosora',
            'name' => 'Bluff',
            'name_en' => 'Bluff',
            'cost' => 2,
            'blade' => 1,
            'hearts' => [['color' => 'green', 'count' => 1]],
            'active' => true,
        ];
    }

    private function baseState(array $liveZone): array
    {
        return [
            'room_id' => 'HANA1',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 4,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
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
                    'live_zone' => $liveZone,
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
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    private function findLive(array $state, string $instanceId): array
    {
        foreach ($state['players']['p1']['live_zone'] ?? [] as $lc) {
            if (($lc['instance_id'] ?? '') === $instanceId) {
                return $lc;
            }
        }
        $this->fail('Live card not found: ' . $instanceId);
    }

    private function greenRequired(array $live): int
    {
        $req = \applyLiveHeartReductions(
            $live['required_hearts'] ?? $live['hearts'] ?? [],
            $live
        );
        $n = 0;
        foreach ($req as $h) {
            if (\normalizeRequiredHeartColor((string)($h['color'] ?? '')) === 'green') {
                $n += intval($h['count'] ?? 0);
            }
        }
        return $n;
    }

    private function anyRequired(array $live): int
    {
        $req = \applyLiveHeartReductions(
            $live['required_hearts'] ?? $live['hearts'] ?? [],
            $live
        );
        $n = 0;
        foreach ($req as $h) {
            if (\normalizeRequiredHeartColor((string)($h['color'] ?? '')) === 'any') {
                $n += intval($h['count'] ?? 0);
            }
        }
        return $n;
    }

    public function testAloneWithHasunosoraMemberBluffsDoesNotReduce(): void
    {
        $hana = $this->cardByNo('PL!HS-bp5-019-L', 'hana1');
        $state = $this->baseState([
            $this->hasunosoraMember('m1'),
            $this->hasunosoraMember('m2'),
            $hana,
        ]);

        $after = \resolveLiveStartAbilities($state, 'p1');
        $lc = $this->findLive($after, 'hana1');

        $this->assertSame(0, intval($lc['hearts_color_reduction']['green'] ?? 0));
        $this->assertSame(9, $this->greenRequired($lc));
        $this->assertSame(5, $this->anyRequired($lc));
    }

    public function testOtherHasunosoraLiveReducesGreenByTwoIgnoresMemberBluff(): void
    {
        $hana = $this->cardByNo('PL!HS-bp5-019-L', 'hana1');
        $other = $this->cardByNo('PL!HS-bp6-027-L', 'kurage1');
        $state = $this->baseState([
            $this->hasunosoraMember('m1'),
            $hana,
            $other,
        ]);

        $after = \resolveLiveStartAbilities($state, 'p1');
        $lc = $this->findLive($after, 'hana1');

        $this->assertSame(2, intval($lc['hearts_color_reduction']['green'] ?? 0));
        $this->assertSame(7, $this->greenRequired($lc), '9 − 2 for one other Hasunosora Live');
        $this->assertSame(5, $this->anyRequired($lc), 'gray/any hearts unchanged');
    }

    public function testTwoOtherHasunosoraLivesReduceGreenByFour(): void
    {
        $hana = $this->cardByNo('PL!HS-bp5-019-L', 'hana1');
        // Plain Lives (no Live Start) so resolve does not stop on order prompt.
        $a = [
            'instance_id' => 'hs_live_a',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Hasunosora',
            'name_en' => 'HS Live A',
            'score' => 3,
            'required_hearts' => [['color' => 'green', 'count' => 1]],
            'abilities' => [],
        ];
        $b = [
            'instance_id' => 'hs_live_b',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => 'Hasunosora',
            'name_en' => 'HS Live B',
            'score' => 3,
            'required_hearts' => [['color' => 'pink', 'count' => 1]],
            'abilities' => [],
        ];
        $state = $this->baseState([$hana, $a, $b]);

        $after = \resolveLiveStartAbilities($state, 'p1');
        $lc = $this->findLive($after, 'hana1');

        $this->assertSame(4, intval($lc['hearts_color_reduction']['green'] ?? 0));
        $this->assertSame(5, $this->greenRequired($lc));
        $this->assertSame(5, $this->anyRequired($lc));
    }
}
