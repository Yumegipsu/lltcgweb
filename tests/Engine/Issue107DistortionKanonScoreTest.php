<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #107 — Distortion (pb1/pb2) / 11-cost Kanon +1 Live Score.
 *
 * Root cause for Distortion: spBp2RefreshLiveZoneScores reset card.score to
 * printed + continuous only, wiping Live Start bumps from bumpLiveCardScore.
 */
final class Issue107DistortionKanonScoreTest extends TestCase
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

    private function member(string $nameEn, string $subunit, string $iid): array
    {
        return [
            'instance_id' => $iid,
            'card_no' => 'TEST-' . $iid,
            'name' => $nameEn,
            'name_en' => $nameEn,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Superstar',
            'subunit' => $subunit,
            'cost' => 3,
            'active' => true,
            'hearts' => [['color' => 'red', 'count' => 1]],
            'abilities' => [],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function energy(int $active, int $resting = 0, string $prefix = 'e'): array
    {
        $out = [];
        for ($i = 0; $i < $active; $i++) {
            $out[] = ['instance_id' => $prefix . 'a' . $i, 'card_type' => 'エネルギー', 'active' => true];
        }
        for ($i = 0; $i < $resting; $i++) {
            $out[] = ['instance_id' => $prefix . 'r' . $i, 'card_type' => 'エネルギー', 'active' => false];
        }
        return $out;
    }

    private function baseLiveStart(array $live, array $stage, array $energy): array
    {
        return [
            'room_id' => 'I107',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 3,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_attempt' => ['p1'],
            '_live_start_perf_pid' => 'p1',
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => $stage,
                    'energy_zone' => $energy,
                    'main_deck' => [],
                    'live_zone' => [$live],
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
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testOldDistortionScoresAfterActivatingRestingEnergy(): void
    {
        $live = $this->cardByNo('PL!SP-pb1-023-L', 'dist_old');
        $printed = intval($live['score'] ?? 0);
        $state = $this->baseLiveStart($live, [
            'left' => $this->member('Kanon Shibuya', 'CatChu!', 'm1'),
            'center' => $this->member('Sumire Heanna', 'CatChu!', 'm2'),
            'right' => null,
        ], $this->energy(4, 2));

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame('activate_energy_up_to', $state['pending_prompt']['type'] ?? null);
        $this->assertSame($printed, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));

        $state = \actionResolvePrompt($state, 'p1', ['choice' => '2']);
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertSame(
            $printed + 1,
            intval($state['players']['p1']['live_zone'][0]['score'] ?? 0),
            'Distortion +1 must apply after Energy activation when all Energy is active'
        );
    }

    public function testOldDistortionScoreSurvivesSpBp2ScoreRefresh(): void
    {
        $live = $this->cardByNo('PL!SP-pb1-023-L', 'dist_refresh');
        $printed = intval($live['score'] ?? 0);
        $state = $this->baseLiveStart($live, [
            'left' => $this->member('Kanon Shibuya', 'CatChu!', 'm1'),
            'center' => $this->member('Sumire Heanna', 'CatChu!', 'm2'),
            'right' => null,
        ], $this->energy(6, 0));

        $state = \resolveLiveStartAbilities($state, 'p1');
        // CatChu present → activate prompt even if already all active; choose 0.
        if (($state['pending_prompt']['type'] ?? '') === 'activate_energy_up_to') {
            $state = \actionResolvePrompt($state, 'p1', ['choice' => '0']);
        }
        $this->assertSame($printed + 1, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));

        // Formation / stack refresh previously wiped Live Start +score (issue #107).
        \spBp2RefreshLiveZoneScores($state, 'p1');
        $this->assertSame(
            $printed + 1,
            intval($state['players']['p1']['live_zone'][0]['score'] ?? 0),
            'Live Start score bump must survive spBp2RefreshLiveZoneScores'
        );
        $this->assertSame(1, intval($state['players']['p1']['live_zone'][0]['_effect_score_bonus'] ?? 0));
    }

    public function testOldDistortionScoresWithoutCatChuWhenEnergyAlreadyActive(): void
    {
        // Official Q97: CatChu gate only skips the activate step; score check still runs.
        $live = $this->cardByNo('PL!SP-pb1-023-L', 'dist_q97');
        $printed = intval($live['score'] ?? 0);
        $state = $this->baseLiveStart($live, [
            'left' => $this->member('Kinako', '5yncri5e!', 'm1'),
            'center' => null,
            'right' => null,
        ], $this->energy(6, 0));

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertSame($printed + 1, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));
    }

    public function testNewDistortionScoresWithThreeCatChuAndSurvivesRefresh(): void
    {
        $live = $this->cardByNo('PL!SP-pb2-048-L', 'dist_new');
        $printed = intval($live['score'] ?? 0);
        $state = $this->baseLiveStart($live, [
            'left' => $this->member('Kanon Shibuya', 'CatChu!', 'm1'),
            'center' => $this->member('Sumire Heanna', 'CatChu!', 'm2'),
            'right' => $this->member('Mei Yoneme', 'CatChu!', 'm3'),
        ], []);

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame($printed + 1, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));
        \spBp2RefreshLiveZoneScores($state, 'p1');
        $this->assertSame($printed + 1, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));
    }

    public function testKanon11PayEnergyAddsLiveScoreBonus(): void
    {
        $kanon = $this->cardByNo('PL!SP-pb1-001-R', 'kanon11');
        $live = $this->cardByNo('PL!SP-pb1-023-L', 'live_filler');
        $live['abilities'] = [];

        $state = $this->baseLiveStart($live, [
            'left' => null,
            'center' => $kanon,
            'right' => null,
        ], $this->energy(6, 0));
        $state['phase'] = 'live_success_effects';
        $before = \getLiveTotalScore($state, 'p1');

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$live], 0, [], []);
        $this->assertSame('optional_pay_energy_live_success', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes']);
        $this->assertSame(
            $before + 1,
            \getLiveTotalScore($state, 'p1'),
            'Paying 6 Energy on 11-cost Kanon must +1 total Live Score'
        );
        $this->assertSame(1, intval($state['live_modifiers']['p1']['score_bonus'] ?? 0));
    }

    /**
     * Umi PL!-bp6-013-N sums Success Live scores. Arashi (score 5) is 6 while her
     * Always +1 is active. Refs #287.
     */
    public function testUmiCountsArashiSuccessScoreAsSix(): void
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $byNo = [];
        foreach ($data['cards'] as $c) {
            $byNo[$c['card_no']] = $c;
        }
        $take = static function (array $byNo, string $no, string $id): array {
            $c = $byNo[$no];
            $c['instance_id'] = $id;
            $c['active'] = true;
            return $c;
        };
        $umi = $take($byNo, 'PL!-bp6-013-N', 'umi');
        $arashi = $take($byNo, 'PL!-pb2-038-L', 'ar');
        $wr = $take($byNo, 'PL!-pb2-038-L', 'wr');
        $empty = ['left' => null, 'center' => null, 'right' => null];
        $state = [
            'status' => 'playing', 'phase' => 'main_first', 'turn' => 4,
            'active_player' => 'p1', 'first_player' => 'p1', 'seq' => 1,
            'log' => [], 'live_modifiers' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1', 'name' => 'A',
                    'stage' => [
                        'left' => null,
                        'center' => $take($byNo, 'PL!-bp6-003-P', 'k'),
                        'right' => $take($byNo, 'PL!-sd1-005-SD', 'o'),
                    ],
                    'hand' => [], 'waiting_room' => [$wr], 'main_deck' => [],
                    'energy_zone' => [], 'live_zone' => [], 'success_lives' => [$arashi],
                ],
                'p2' => [
                    'id' => 'p2', 'name' => 'B', 'stage' => $empty, 'hand' => [],
                    'waiting_room' => [], 'main_deck' => [], 'energy_zone' => [],
                    'live_zone' => [], 'success_lives' => [],
                ],
            ],
        ];
        $this->assertSame(6, \sumSuccessLiveScores($state['players']['p1'], $state, 'p1'));
        $out = \plMuseGapResolveEffect($state, 'p1', $umi, $umi['abilities'][0], ['slot' => 'left']);
        $this->assertSame('pick_wr_to_hand', $out['pending_prompt']['type'] ?? null);
        $this->assertCount(1, $out['pending_prompt']['candidates'] ?? []);

        $state['players']['p1']['stage']['left'] = $take($byNo, 'PL!-sd1-002-SD', 'u');
        $this->assertSame(5, \sumSuccessLiveScores($state['players']['p1'], $state, 'p1'));
        $blocked = \plMuseGapResolveEffect($state, 'p1', $umi, $umi['abilities'][0], ['slot' => 'center']);
        $this->assertArrayNotHasKey('pending_prompt', $blocked);
    }
}
