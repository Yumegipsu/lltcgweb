<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb1-029 Shiranai Love — Live Start +1 iff Success is empty and Stage is
 * lily white only. Each Live-zone copy gets its own +1 when eligible (#243).
 *
 * Investigation (Kyra BBA826 2026-10-04): the reported “mystery +1” matched a
 * legal Shiranai Live Start (empty Success, all-lily-white Stage). Only one copy
 * was in Live storage that round, so +1 (not +2) is correct. Yell score icons are
 * separate (Shiranai also has yell_score_icon when revealed as Yell).
 */
final class Issue243ShiranaiScoreIfSubunitOnlyTest extends TestCase
{
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

    private function lilyMember(string $cardNo, string $instanceId): array
    {
        $card = $this->cardByNo($cardNo, $instanceId);
        $this->assertTrue(cardMatchesSubunit($card, 'lily white'), $cardNo . ' must be lily white');
        return $card;
    }

    private function baseState(array $stage, array $liveZone, array $success = []): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => $stage,
                    'live_zone' => $liveZone,
                    'success_lives' => $success,
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
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
                    'energy_deck' => [],
                ],
            ],
        ];
    }

    /** Resolve Live Start order prompt when multiple sources need ordering. */
    private function resolveLiveStarts(array $state): array
    {
        $state = resolveLiveStartAbilities($state, 'p1');
        $guard = 0;
        while (!empty($state['pending_prompt']) && $guard++ < 8) {
            $pr = $state['pending_prompt'];
            $type = $pr['type'] ?? '';
            if ($type === 'live_start_order_sources') {
                $ids = array_values(array_filter(array_column($pr['candidates'] ?? [], 'instance_id')));
                $state = actionResolvePrompt($state, 'p1', ['card_ids' => $ids]);
                continue;
            }
            // Ignore unrelated follow-on prompts for this unit test.
            break;
        }
        return $state;
    }

    public function testTwoCopiesEachGainPlusOneWhenConditionsMet(): void
    {
        $s1 = $this->cardByNo('PL!-pb1-029-L', 's1');
        $s2 = $this->cardByNo('PL!-pb1-029-SRL', 's2');
        $umi = $this->lilyMember('PL!-PR-004-PR', 'umi');
        $rin = $this->lilyMember('PL!-PR-005-PR', 'rin');

        $base1 = intval($s1['score'] ?? 1);
        $base2 = intval($s2['score'] ?? 1);
        $state = $this->baseState(
            ['left' => $umi, 'center' => $rin, 'right' => null],
            [$s1, $s2]
        );
        $this->assertTrue(stageAllMembersInSubunit($state['players']['p1'], 'lily white'));
        $state = $this->resolveLiveStarts($state);

        $byId = [];
        foreach ($state['players']['p1']['live_zone'] as $lc) {
            $byId[$lc['instance_id']] = $lc;
        }
        $this->assertSame($base1 + 1, intval($byId['s1']['score'] ?? 0));
        $this->assertSame($base2 + 1, intval($byId['s2']['score'] ?? 0));
        $this->assertSame(1, intval($byId['s1']['_effect_score_bonus'] ?? 0));
        $this->assertSame(1, intval($byId['s2']['_effect_score_bonus'] ?? 0));
        $log = implode("\n", array_map(static fn($e) => (string)($e['msg'] ?? ''), $state['log'] ?? []));
        $this->assertSame(2, substr_count($log, 'lily white only, no Success Lives'));
    }

    public function testNoBonusWhenSuccessLivesPresent(): void
    {
        $s1 = $this->cardByNo('PL!-pb1-029-L', 's1');
        $umi = $this->lilyMember('PL!-PR-004-PR', 'umi');
        $base = intval($s1['score'] ?? 1);
        $otherLive = [
            'instance_id' => 'old',
            'card_no' => 'PL!-TEST-OLD',
            'name_en' => 'Old Live',
            'card_type' => 'ライブ',
            'score' => 1,
            'subunit' => 'lily white',
        ];
        $state = $this->baseState(
            ['left' => $umi, 'center' => null, 'right' => null],
            [$s1],
            [$otherLive]
        );
        $state = $this->resolveLiveStarts($state);
        $this->assertSame($base, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));
        $this->assertSame(0, intval($state['players']['p1']['live_zone'][0]['_effect_score_bonus'] ?? 0));
        $log = implode("\n", array_map(static fn($e) => (string)($e['msg'] ?? ''), $state['log'] ?? []));
        $this->assertStringNotContainsString('lily white only, no Success Lives', $log);
    }

    public function testNoBonusWhenNonLilyWhiteOnStage(): void
    {
        $s1 = $this->cardByNo('PL!-pb1-029-L', 's1');
        $umi = $this->lilyMember('PL!-PR-004-PR', 'umi');
        // Eli (BiBi) — no Live Start, so resolveLiveStarts stays in Live Start phase
        // and does not auto-advance into Live Show (unlike Honoka bp3-001).
        $eli = $this->cardByNo('PL!-PR-002-PR', 'eli');
        $base = intval($s1['score'] ?? 1);
        $state = $this->baseState(
            ['left' => $umi, 'center' => $eli, 'right' => null],
            [$s1]
        );
        $this->assertFalse(cardMatchesSubunit($eli, 'lily white'));
        $this->assertFalse(stageAllMembersInSubunit($state['players']['p1'], 'lily white'));
        $state = $this->resolveLiveStarts($state);
        $this->assertSame($base, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));
        $this->assertSame(0, intval($state['players']['p1']['live_zone'][0]['_effect_score_bonus'] ?? 0));
        $log = implode("\n", array_map(static fn($e) => (string)($e['msg'] ?? ''), $state['log'] ?? []));
        $this->assertStringNotContainsString('lily white only, no Success Lives', $log);
    }

    public function testYellScoreIconCountsOnceNotTwice(): void
    {
        $s = $this->cardByNo('PL!-pb1-029-L', 'yell');
        // Catalog has both yell_score_icon and special_heart score icon — must count as 1.
        $this->assertTrue(!empty($s['yell_score_icon']));
        $this->assertSame('icon_score.png', $s['special_heart'] ?? null);
        $this->assertSame(1, cardYellScoreIconCount($s));
    }

    public function testSingleCopyAutoAppliesWithoutOrderPrompt(): void
    {
        $s1 = $this->cardByNo('PL!-pb1-029-SRL', 'only');
        $umi = $this->lilyMember('PL!-PR-004-PR', 'umi');
        $base = intval($s1['score'] ?? 1);
        $state = $this->baseState(
            ['left' => $umi, 'center' => null, 'right' => null],
            [$s1]
        );
        $state = resolveLiveStartAbilities($state, 'p1');
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame($base + 1, intval($state['players']['p1']['live_zone'][0]['score'] ?? 0));
    }
}
