<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-042 PSYCHIC FIRE — Auto Wait after Yell when Nico+Maki+Eli revealed
 * and Center BiBi ≥11 (#220). Fires on this player's Yell before the opponent's.
 */
final class Issue220PsychicFireYellWaitTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
    }

    private function psychicFire(): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === 'PL!-pb2-042-L') {
                $card['instance_id'] = 'psychic';
                $card['revealed'] = true;
                return $card;
            }
        }
        $this->fail('Missing PL!-pb2-042-L');
    }

    private function member(string $id, string $nameEn, string $subunit = 'BiBi', int $cost = 3): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-test-' . $id,
            'name_en' => $nameEn,
            'name' => $nameEn,
            'card_type' => 'メンバー',
            'group' => "μ's",
            'subunit' => $subunit,
            'cost' => $cost,
            'blade' => 1,
            'active' => true,
            'hearts' => [['color' => 'pink', 'count' => 1]],
        ];
    }

    private function baseState(array $psychic, array $center, array $yell, array $oppStage): array
    {
        return [
            'phase' => 'live_performance_first',
            'seq' => 1,
            'first_player' => 'p1',
            'log' => [],
            'yell_reveal' => ['p1' => $yell, 'p2' => []],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'success_lives' => [],
                    'waiting_room' => [],
                    'deck' => [],
                    'live_zone' => [$psychic],
                    'yell_cards' => $yell,
                    'stage' => ['left' => null, 'center' => $center, 'right' => null],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'success_lives' => [],
                    'waiting_room' => [],
                    'deck' => [],
                    'live_zone' => [],
                    'yell_cards' => [],
                    'stage' => $oppStage,
                ],
            ],
        ];
    }

    public function testOpensWaitPickWhenConditionsMet(): void
    {
        $psychic = $this->psychicFire();
        $center = $this->member('bibi11', 'Nozomi Tojo', 'BiBi', 11);
        $yell = [
            $this->member('nico', 'Nico Yazawa'),
            $this->member('maki', 'Maki Nishikino'),
            $this->member('eli', 'Eli Ayase'),
        ];
        $opp = [
            'left' => $this->member('opp_l', 'Opp Low', 'Printemps', 4),
            'center' => $this->member('opp_c', 'Opp High', 'Printemps', 15),
            'right' => null,
        ];
        // Opp Low: 1 printed heart ≤4 — eligible for Wait pick.
        $state = $this->baseState($psychic, $center, $yell, $opp);
        $out = resolveAutoYellAbilities($state, 'p1', $yell);
        $pr = $out['pending_prompt'] ?? null;
        $this->assertIsArray($pr, 'should open Wait pick for Psychic Fire');
        $this->assertSame('wait_opponent_stage_pick', $pr['type'] ?? '');
        $this->assertSame('p1', $pr['owner'] ?? $pr['responder'] ?? null);
        $this->assertNotEmpty($pr['candidates'] ?? []);
        $this->assertTrue(
            isAbilityUsed($out['players']['p1']['live_zone'][0], 0),
            'once_per_turn should mark Psychic Fire used'
        );
    }

    public function testSkipsWithoutAllThreeNames(): void
    {
        $psychic = $this->psychicFire();
        $center = $this->member('bibi11', 'Nozomi Tojo', 'BiBi', 11);
        $yell = [
            $this->member('nico', 'Nico Yazawa'),
            $this->member('maki', 'Maki Nishikino'),
            // missing Eli
        ];
        $opp = [
            'left' => $this->member('opp_l', 'Opp Low', 'Printemps', 4),
            'center' => null,
            'right' => null,
        ];
        $out = resolveAutoYellAbilities(
            $this->baseState($psychic, $center, $yell, $opp),
            'p1',
            $yell
        );
        $this->assertNull($out['pending_prompt'] ?? null);
    }

    public function testSkipsWithoutCenterBibiMinCost(): void
    {
        $psychic = $this->psychicFire();
        $center = $this->member('bibi3', 'Nozomi Tojo', 'BiBi', 3);
        $yell = [
            $this->member('nico', 'Nico Yazawa'),
            $this->member('maki', 'Maki Nishikino'),
            $this->member('eli', 'Eli Ayase'),
        ];
        $opp = [
            'left' => $this->member('opp_l', 'Opp Low', 'Printemps', 4),
            'center' => null,
            'right' => null,
        ];
        $out = resolveAutoYellAbilities(
            $this->baseState($psychic, $center, $yell, $opp),
            'p1',
            $yell
        );
        $this->assertNull($out['pending_prompt'] ?? null);
    }
}
