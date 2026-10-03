<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-013 Umi — On Enter reveal top 4; if all lily white, add 1 Live (#226).
 */
final class Issue226UmiRevealTopLilyWhiteTest extends TestCase
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

    private function lilyMember(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-sd1-006-SD',
            'name_en' => 'Umi Member',
            'card_type' => 'メンバー',
            'group' => "μ's",
            'subunit' => 'lily white',
            'cost' => 3,
            'active' => true,
            'abilities' => [],
        ];
    }

    private function lilyLive(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-pb2-live-test',
            'name_en' => 'Lily White Live',
            'card_type' => 'ライブ',
            'group' => "μ's",
            'subunit' => 'lily white',
            'score' => 1,
            'abilities' => [],
        ];
    }

    private function otherMember(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-sd1-001-SD',
            'name_en' => 'Honoka',
            'card_type' => 'メンバー',
            'group' => "μ's",
            'subunit' => 'Printemps',
            'cost' => 2,
            'active' => true,
            'abilities' => [],
        ];
    }

    private function baseState(array $umi, array $deck): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main',
            'turn' => 1,
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'main_deck' => $deck,
                    'deck' => [], // obsolete key — must NOT be used (#226)
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'stage' => ['left' => null, 'center' => $umi, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'main_deck' => [],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testOnEnterAddsLiveWhenAllLilyWhiteFromMainDeck(): void
    {
        $umi = $this->cardByNo('PL!-pb2-013-R', 'umi013');
        $live = $this->lilyLive('lw_live');
        $deck = [
            $this->lilyMember('lw1'),
            $this->lilyMember('lw2'),
            $live,
            $this->lilyMember('lw3'),
        ];
        $state = $this->baseState($umi, $deck);
        $state = resolveOnEnterAbilities($state, 'p1', $umi, 'center');

        $p = $state['players']['p1'];
        $this->assertCount(1, $p['hand'], 'Should add 1 lily white Live to hand');
        $this->assertSame('lw_live', $p['hand'][0]['instance_id'] ?? null);
        $this->assertCount(3, $p['waiting_room'], 'Rest of reveal goes to WR');
        $this->assertSame([], $p['main_deck']);
        $this->assertNotEmpty($state['skill_reveals']['cards'] ?? [], 'Should publicly reveal the top 4');
        $this->assertEmpty($state['pending_prompt'] ?? null);
    }

    public function testOnEnterDoesNothingUsefulWhenNotAllLilyWhite(): void
    {
        $umi = $this->cardByNo('PL!-pb2-013-R', 'umi013');
        $deck = [
            $this->lilyMember('lw1'),
            $this->otherMember('pr1'),
            $this->lilyLive('lw_live'),
            $this->lilyMember('lw2'),
        ];
        $state = $this->baseState($umi, $deck);
        $state = resolveOnEnterAbilities($state, 'p1', $umi, 'center');

        $p = $state['players']['p1'];
        $this->assertSame([], $p['hand'], 'Must not add Live when not all lily white');
        $this->assertCount(4, $p['waiting_room']);
        $this->assertNotEmpty($state['skill_reveals']['cards'] ?? []);
    }

    public function testOnEnterPromptsWhenMultipleLilyWhiteLives(): void
    {
        $umi = $this->cardByNo('PL!-pb2-013-R', 'umi013');
        $deck = [
            $this->lilyLive('live_a'),
            $this->lilyLive('live_b'),
            $this->lilyMember('lw1'),
            $this->lilyMember('lw2'),
        ];
        $state = $this->baseState($umi, $deck);
        $state = resolveOnEnterAbilities($state, 'p1', $umi, 'center');

        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pb2_pick_revealed_subunit_live', $pr['type'] ?? null);
        $this->assertCount(2, $pr['candidates'] ?? []);

        $state = plMusePb2ResolvePrompt(
            $state,
            'p1',
            $pr,
            'live_b',
            ['instance_id' => 'live_b']
        );
        $p = $state['players']['p1'];
        $this->assertCount(1, $p['hand']);
        $this->assertSame('live_b', $p['hand'][0]['instance_id'] ?? null);
        $this->assertCount(3, $p['waiting_room']);
        $this->assertArrayNotHasKey('pending_prompt', $state);
    }

    public function testIgnoresObsoleteDeckKey(): void
    {
        $umi = $this->cardByNo('PL!-pb2-013-R', 'umi013');
        $state = $this->baseState($umi, []);
        // Poison: only the obsolete key has cards.
        $state['players']['p1']['deck'] = [
            $this->lilyMember('lw1'),
            $this->lilyMember('lw2'),
            $this->lilyLive('lw_live'),
            $this->lilyMember('lw3'),
        ];
        $state = resolveOnEnterAbilities($state, 'p1', $umi, 'center');
        $p = $state['players']['p1'];
        $this->assertSame([], $p['hand'], 'Must not read from obsolete deck key');
        $this->assertCount(4, $p['deck'], 'Obsolete deck key left untouched');
    }
}
