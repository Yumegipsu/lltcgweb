<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression coverage for every Sunshine!! starter deck card with abilities (PL!S-sd1-*). */
final class SunshineSdDeckTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                $card['entered_turn'] = 1;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function baseState(string $phase = 'main_first'): array
    {
        return [
            'room_id' => 'SUNSHINE_SD',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => $phase,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hand' => [],
                    'waiting_room' => [],
                    'live_zone' => [null, null, null],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'hand' => [],
                    'waiting_room' => [],
                    'live_zone' => [null, null, null],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    private function activeEnergy(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['instance_id' => 'e' . $i, 'active' => true];
        }
        return $out;
    }

    public function testChikaSd001AutoYellHeartsCapAtThree(): void
    {
        $chika = $this->cardByNo('PL!S-sd1-001-SD', 'chika_sd1');
        $state = $this->baseState('live_performance_first');
        $state['players']['p1']['stage']['center'] = $chika;
        $yellCards = [
            ['card_type' => 'ライブ', 'instance_id' => 'y1'],
            ['card_type' => 'ライブ', 'instance_id' => 'y2'],
            ['card_type' => 'ライブ', 'instance_id' => 'y3'],
            ['card_type' => 'ライブ', 'instance_id' => 'y4'],
        ];
        $state = \sSd1ResolveAutoYell($state, 'p1', $chika, 'center', 0, [
            'type' => 'auto_yell_hearts_per_yell_live',
            'heart_color' => 'red',
            'heart_count' => 1,
            'max_hearts' => 3,
        ], $yellCards);
        $hearts = $state['live_modifiers']['p1']['bonus_hearts'] ?? [];
        $this->assertCount(3, $hearts);
        $this->assertSame('red', $hearts[0]);
    }

    public function testRikoSd002OnEnterOptionalDiscardOpensWrPick(): void
    {
        $riko = $this->cardByNo('PL!S-sd1-002-SD', 'riko_sd1');
        $hand = ['instance_id' => 'h1', 'card_type' => 'エネルギー', 'name_en' => 'Energy'];
        $wr = ['instance_id' => 'wr1', 'card_type' => 'メンバー', 'group' => 'Sunshine', 'name_en' => 'WR'];
        $state = $this->baseState();
        $state['players']['p1']['hand'] = [$hand];
        $state['players']['p1']['waiting_room'] = [$wr];
        $state['players']['p1']['stage']['center'] = $riko;

        $state = \resolveOnEnterAbilities($state, 'p1', $riko, 'center');
        $this->assertSame('optional_discard_prompt', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'yes', 'discard_ids' => ['h1']]);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'wr1']);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('wr1', $handIds);
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testKananSd003OnEnterLookRevealGroupLive(): void
    {
        $kanan = $this->cardByNo('PL!S-sd1-003-SD', 'kanan_sd1');
        $live = $this->cardByNo('PL!S-sd1-019-SD', 'deck_live');
        $live['instance_id'] = 'deck_live';
        $filler = ['instance_id' => 'f1', 'card_type' => 'メンバー', 'group' => 'Sunshine'];
        $state = $this->baseState();
        $state['players']['p1']['main_deck'] = [$live, $filler, $filler, $filler, $filler];
        $state['players']['p1']['stage']['center'] = $kanan;

        $state = \resolveOnEnterAbilities($state, 'p1', $kanan, 'center');
        $this->assertSame('pick_looked_deck_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('live', $state['pending_prompt']['ability']['filter'] ?? null);
    }

    public function testDiaSd004LiveStartOptionalDrawPrompt(): void
    {
        $dia = $this->cardByNo('PL!S-sd1-004-SD', 'dia_sd1');
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['stage']['center'] = $dia;
        $state['live_start_optional_queue'] = [];

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame('ssd1_live_start_draw', $state['pending_prompt']['type'] ?? null);
    }

    public function testYouSd005ActivatedPayDiscardAddWrLive(): void
    {
        $you = $this->cardByNo('PL!S-sd1-005-SD', 'you_sd1');
        $hand = ['instance_id' => 'h1', 'card_type' => 'エネルギー'];
        $live = $this->cardByNo('PL!S-sd1-021-SD', 'wr_live');
        $live['instance_id'] = 'wr_live';
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $you;
        $state['players']['p1']['hand'] = [$hand];
        $state['players']['p1']['waiting_room'] = [$live];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(2);

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'you_sd1',
            'ability_index' => 0,
            'discard_ids' => ['h1'],
        ]);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('live', $state['pending_prompt']['wr_pick_cfg']['filter'] ?? null);
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'wr_live']);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('wr_live', $handIds);
    }

    public function testHanamaruSd007ActivatedDiscardAddsScoredLive(): void
    {
        $hanamaru = $this->cardByNo('PL!S-sd1-007-SD', 'hana_sd1');
        $discard1 = ['instance_id' => 'd1', 'card_type' => 'エネルギー'];
        $discard2 = ['instance_id' => 'd2', 'card_type' => 'エネルギー'];
        $liveA = $this->cardByNo('PL!S-sd1-019-SD', 'wr_live_a');
        $liveA['instance_id'] = 'wr_live_a';
        $liveB = $this->cardByNo('PL!S-sd1-021-SD', 'wr_live_b');
        $liveB['instance_id'] = 'wr_live_b';
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $hanamaru;
        $state['players']['p1']['hand'] = [$discard1, $discard2];
        $state['players']['p1']['waiting_room'] = [$liveA, $liveB];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'hana_sd1',
            'ability_index' => 0,
            'discard_ids' => ['d1', 'd2'],
        ]);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
        $candIds = array_column($state['pending_prompt']['candidates'] ?? [], 'instance_id');
        $this->assertContains('wr_live_a', $candIds);
        $this->assertContains('wr_live_b', $candIds);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'wr_live_b']);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('wr_live_b', $handIds);
        $this->assertNotContains('wr_live_a', $handIds);
        $this->assertContains('wr_live_a', array_column($state['players']['p1']['waiting_room'], 'instance_id'));
    }

    public function testRubySd009LiveStartRevealDeckBladePrompt(): void
    {
        $ruby = $this->cardByNo('PL!S-sd1-009-SD', 'ruby_sd1');
        $handCard = ['instance_id' => 'hq', 'card_type' => 'メンバー', 'group' => 'Sunshine', 'name_en' => 'Hand'];
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['stage']['center'] = $ruby;
        $state['players']['p1']['hand'] = [$handCard];
        $state['live_start_optional_queue'] = [];

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame('ssd1_reveal_group_deck', $state['pending_prompt']['type'] ?? null);
    }

    public function testDiaSd013OnEnterMillsFiveToWr(): void
    {
        $dia = $this->cardByNo('PL!S-sd1-013-SD', 'dia_mill');
        $deck = [];
        for ($i = 0; $i < 6; $i++) {
            $deck[] = ['instance_id' => 'd' . $i, 'card_type' => 'エネルギー'];
        }
        $state = $this->baseState();
        $state['players']['p1']['main_deck'] = $deck;
        $state['players']['p1']['stage']['center'] = $dia;

        $state = \resolveOnEnterAbilities($state, 'p1', $dia, 'center');
        $this->assertCount(5, $state['players']['p1']['waiting_room']);
        $this->assertCount(1, $state['players']['p1']['main_deck']);
    }

    public function testYouSd014LiveSuccessDrawAndDiscardPrompt(): void
    {
        $you = $this->cardByNo('PL!S-sd1-014-SD', 'you_ls');
        $successLive = $this->cardByNo('PL!S-sd1-021-SD', 'succ_live');
        $successLive['instance_id'] = 'succ_live';
        $state = $this->baseState('live_success_effects');
        $state['players']['p1']['stage']['center'] = $you;
        $state['players']['p1']['hand'] = [
            ['instance_id' => 'keep', 'card_type' => 'エネルギー'],
            ['instance_id' => 'drop', 'card_type' => 'エネルギー'],
        ];
        $state['players']['p1']['main_deck'] = [['instance_id' => 'drawn', 'card_type' => 'エネルギー']];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$successLive], 0, [], []);
        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertGreaterThanOrEqual(2, count($state['players']['p1']['hand']));
    }

    public function testYoshikoSd015LeaveStageAddsLiveFromWr(): void
    {
        $yoshiko = $this->cardByNo('PL!S-sd1-015-SD', 'yo_ls');
        $wrLive = $this->cardByNo('PL!S-sd1-019-SD', 'wr_live');
        $wrLive['instance_id'] = 'wr_live';
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $yoshiko;
        $state['players']['p1']['waiting_room'] = [$wrLive];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'yo_ls',
            'ability_index' => 0,
        ]);
        $this->assertSame('pick_wr_leave_stage_add', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('live', $state['pending_prompt']['ability']['filter'] ?? null);
        $this->assertSame('live', $state['pending_prompt']['wr_pick_cfg']['filter'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'wr_live']);
        $this->assertContains('wr_live', array_column($state['players']['p1']['hand'], 'instance_id'));
        $this->assertNull($state['players']['p1']['stage']['center']);
    }

    public function testMariSd017OnEnterDrawAndDeckBottomPrompt(): void
    {
        $mari = $this->cardByNo('PL!S-sd1-017-SD', 'mari_enter');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $mari;
        $state['players']['p1']['main_deck'] = [['instance_id' => 'draw1', 'card_type' => 'エネルギー']];
        $state['players']['p1']['hand'] = [['instance_id' => 'h1', 'card_type' => 'エネルギー']];

        $state = \resolveOnEnterAbilities($state, 'p1', $mari, 'center');
        $this->assertSame('sbp5_draw_deck_bottom', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(1, $state['pending_prompt']['bottom_count'] ?? 0);
    }

    public function testMiraiSd019LiveSuccessPickYellLive(): void
    {
        $mirai = $this->cardByNo('PL!S-sd1-019-SD', 'mirai_ls');
        $yellLiveA = $this->cardByNo('PL!S-sd1-021-SD', 'yell_live_a');
        $yellLiveA['instance_id'] = 'yell_live_a';
        $yellLiveB = $this->cardByNo('PL!S-sd1-019-SD', 'yell_live_b');
        $yellLiveB['instance_id'] = 'yell_live_b';
        $yellMember = ['instance_id' => 'yell_m', 'card_type' => 'メンバー', 'group' => 'Sunshine'];
        $state = $this->baseState('live_success_effects');
        $state['players']['p1']['_pending_yell_wr'] = [$yellLiveA, $yellLiveB, $yellMember];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$mirai], 0, [], [$yellLiveA, $yellLiveB, $yellMember]);
        $this->assertSame('live_success_pick_yell_live', $state['pending_prompt']['type'] ?? null);
        $candidates = $state['pending_prompt']['candidates'] ?? [];
        $this->assertGreaterThanOrEqual(2, count($candidates));
        foreach ($candidates as $c) {
            $this->assertSame('ライブ', $c['card_type'] ?? '');
        }
    }

    public function testMiraiSd019LiveSuccessAutoAddsSingleYellLive(): void
    {
        $mirai = $this->cardByNo('PL!S-sd1-019-SD', 'mirai_auto');
        $yellLive = $this->cardByNo('PL!S-sd1-021-SD', 'yell_only');
        $yellLive['instance_id'] = 'yell_only';
        $state = $this->baseState('live_success_effects');
        $state['players']['p1']['_pending_yell_wr'] = [$yellLive];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$mirai], 0, [], [$yellLive]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertContains('yell_only', array_column($state['players']['p1']['hand'], 'instance_id'));
    }

    public function testJimoAiSd020DrawPerStageThenDiscard(): void
    {
        $jimo = $this->cardByNo('PL!S-sd1-020-SD', 'jimo_ls');
        $member = $this->cardByNo('PL!S-sd1-010-SD', 'stage_m');
        $member['instance_id'] = 'stage_m';
        $state = $this->baseState('live_success_effects');
        $state['players']['p1']['stage']['left'] = $member;
        $state['players']['p1']['main_deck'] = [
            ['instance_id' => 'c1', 'card_type' => 'エネルギー'],
        ];
        $state['players']['p1']['hand'] = [
            ['instance_id' => 'h1', 'card_type' => 'エネルギー'],
        ];

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$jimo], 0, [], []);
        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(1, $state['pending_prompt']['count'] ?? 0);
    }

    public function testJumpUpHighSd022MemberBladeBonusOnLiveStart(): void
    {
        $jump = $this->cardByNo('PL!S-sd1-022-SD', 'jump_live');
        $chika = $this->cardByNo('PL!S-sd1-010-SD', 'chika_stage');
        $chika['instance_id'] = 'chika_stage';
        $riko = $this->cardByNo('PL!S-sd1-011-SD', 'riko_stage');
        $riko['instance_id'] = 'riko_stage';
        $riko['group'] = 'Liella!';
        $state = $this->baseState('live_start_effects');
        $state['players']['p1']['live_zone'] = [$jump, null, null];
        $state['players']['p1']['stage']['left'] = $chika;
        $state['players']['p1']['stage']['center'] = $riko;
        $state['live_start_optional_queue'] = [];

        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame(1, intval($state['players']['p1']['stage']['left']['live_blade_bonus'] ?? 0));
        $this->assertSame(0, intval($state['players']['p1']['stage']['center']['live_blade_bonus'] ?? 0));
    }

    public function testAllSunshineSdCardsHaveCatalogEntries(): void
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        $byNo = [];
        foreach ($data['cards'] ?? [] as $card) {
            $byNo[$card['card_no']] = $card;
        }
        $deckIds = array_values(array_unique($data['starter_decks']['sunshine']['main_deck'] ?? []));
        $this->assertCount(22, $deckIds);
        foreach ($deckIds as $id) {
            $this->assertArrayHasKey($id, $byNo, "Missing catalog entry for $id");
        }
    }
}
