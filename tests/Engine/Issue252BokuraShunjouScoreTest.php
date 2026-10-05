<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * #252 — Bokura no LIVE (PL!-bp3-019) vs Shunjou Romantic (PL!-pb2-041).
 *
 * Bokura Live Start counts μ's cards in Live storage (ライブ中), not Success.
 * Shunjou's count-as-2 Always only applies to lily white Success counts.
 * Shunjou Live Start "this card's score +1" must bump that Live, not total Live Score.
 */
final class Issue252BokuraShunjouScoreTest extends TestCase
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

    private function plainMuseLive(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-test-muse-live',
            'name_en' => 'Plain Muse Live',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => "μ's",
            'score' => 1,
            'abilities' => [],
        ];
    }

    private function baseState(array $liveZone, array $successLives = []): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 2,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => $liveZone,
                    'success_lives' => $successLives,
                    'energy_zone' => [],
                    'main_deck' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                ],
            ],
        ];
    }

    public function testCatalogWiring(): void
    {
        $bokura = $this->cardByNo('PL!-bp3-019-SRL', 'bokura');
        $this->assertSame('score_if_live_zone_group', $bokura['abilities'][0]['type'] ?? null);
        $this->assertSame('live_start', $bokura['abilities'][0]['trigger'] ?? null);
        $this->assertSame(2, intval($bokura['abilities'][0]['min_count'] ?? 0));

        $shunjou = $this->cardByNo('PL!-pb2-041-L', 'shunjou');
        $this->assertSame('success_count_as_two_for_subunit_effects', $shunjou['abilities'][0]['type'] ?? null);
        $this->assertSame('score_if_success_subunit_min', $shunjou['abilities'][1]['type'] ?? null);
    }

    public function testShunjouWeightRequiresLilyWhiteSource(): void
    {
        $bokura = $this->cardByNo('PL!-bp3-019-SRL', 'bokura');
        $shunjou = $this->cardByNo('PL!-pb2-041-L', 'shunjou');
        $umi = $this->cardByNo('PL!-pb2-004-R', 'umi');

        $this->assertSame(1, plMusePb2SuccessCardCountWeight($shunjou, null));
        $this->assertSame(1, plMusePb2SuccessCardCountWeight($shunjou, $bokura));
        $this->assertSame(2, plMusePb2SuccessCardCountWeight($shunjou, $umi));
    }

    public function testBokuraDoesNotScoreFromShunjouAloneInSuccess(): void
    {
        $bokura = $this->cardByNo('PL!-bp3-019-SRL', 'bokura');
        $shunjou = $this->cardByNo('PL!-pb2-041-L', 'shunjou');
        $printed = intval($bokura['score'] ?? 0);

        $state = $this->baseState([$bokura], [$shunjou]);
        $state = resolveLiveStartAbilities($state, 'p1');

        $live = $state['players']['p1']['live_zone'][0];
        $this->assertSame($printed, intval($live['score'] ?? 0));
        $this->assertSame(0, intval($live['_effect_score_bonus'] ?? 0));
        $this->assertSame(0, intval($state['live_modifiers']['p1']['score_bonus'] ?? 0));
    }

    public function testBokuraScoresWithTwoMuseInLiveZone(): void
    {
        $bokura = $this->cardByNo('PL!-bp3-019-SRL', 'bokura');
        $other = $this->plainMuseLive('other');
        $printed = intval($bokura['score'] ?? 0);

        $state = $this->baseState([$bokura, $other], []);
        $state = resolveLiveStartAbilities($state, 'p1');

        $byId = [];
        foreach ($state['players']['p1']['live_zone'] as $lc) {
            $byId[$lc['instance_id']] = $lc;
        }
        $this->assertSame($printed + 1, intval($byId['bokura']['score'] ?? 0));
        $this->assertSame(1, intval($byId['bokura']['_effect_score_bonus'] ?? 0));
    }

    public function testShunjouLiveStartBumpsCardNotGlobalScore(): void
    {
        $shunjou = $this->cardByNo('PL!-pb2-041-L', 'shunjou_live');
        // Prior Success copy — Always counts as 2 for this lily white Live Start.
        $prior = $this->cardByNo('PL!-pb2-041-L', 'shunjou_success');
        $printed = intval($shunjou['score'] ?? 0);

        $state = $this->baseState([$shunjou], [$prior]);
        $state = resolveLiveStartAbilities($state, 'p1');

        $live = $state['players']['p1']['live_zone'][0];
        $this->assertSame($printed + 1, intval($live['score'] ?? 0));
        $this->assertSame(1, intval($live['_effect_score_bonus'] ?? 0));
        $this->assertSame(
            0,
            intval($state['live_modifiers']['p1']['score_bonus'] ?? 0),
            'Shunjou Live Start must not add global Live Score'
        );
    }

    public function testShunjouLiveStartWithBokuraDoesNotLeakGlobalBonus(): void
    {
        $bokura = $this->cardByNo('PL!-bp3-019-SRL', 'bokura');
        $shunjou = $this->cardByNo('PL!-pb2-041-L', 'shunjou_live');
        $prior = $this->cardByNo('PL!-pb2-041-L', 'shunjou_success');
        $bokuraPrinted = intval($bokura['score'] ?? 0);
        $shunjouPrinted = intval($shunjou['score'] ?? 0);

        $state = $this->baseState([$bokura, $shunjou], [$prior]);
        // Skip Order of Activation — resolve both Live Starts in Live-zone order.
        $state['_live_start_order'] = ['p1' => ['bokura', 'shunjou_live']];
        $state['_live_start_order_asked'] = ['p1' => true];
        $state = resolveLiveStartAbilities($state, 'p1');

        $byId = [];
        foreach ($state['players']['p1']['live_zone'] as $lc) {
            $byId[$lc['instance_id']] = $lc;
        }
        // Bokura: 2 μ's in Live zone → +1 on Bokura only.
        $this->assertSame($bokuraPrinted + 1, intval($byId['bokura']['score'] ?? 0));
        // Shunjou: prior Success Shunjou counts as 2 lily white → +1 on Shunjou only.
        $this->assertSame($shunjouPrinted + 1, intval($byId['shunjou_live']['score'] ?? 0));
        $this->assertSame(0, intval($state['live_modifiers']['p1']['score_bonus'] ?? 0));
    }
}
