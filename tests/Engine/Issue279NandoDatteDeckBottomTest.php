<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!S-bp7-019-L Nando Datte Yakusoku!
 * Live Success: up to 2 of your own Aqours cards, bottom of your deck, chosen order.
 * It must not ask whose Waiting Room, and Lives count as well as Members (#279).
 */
final class Issue279NandoDatteDeckBottomTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function ability(array $card, string $type): array
    {
        foreach ($card['abilities'] ?? [] as $ab) {
            if (($ab['type'] ?? '') === $type) {
                return $ab;
            }
        }
        $this->fail('Missing ability ' . $type);
    }

    private function state(array $wr, array $yell, array $deck): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => $wr,
                    '_pending_yell_wr' => $yell,
                    'main_deck' => $deck,
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testOwnAqoursCardsOpenADeckBottomPick(): void
    {
        $live = $this->cardByNo('PL!S-bp7-019-L', 'nando');
        $ruby = $this->cardByNo('PL!S-bp7-018-N', 'ruby');
        $aqLive = $this->cardByNo('PL!S-bp7-020-L', 'train');
        $muse = $this->cardByNo('PL!-pb2-012-R', 'kotori');
        $yelled = $this->cardByNo('PL!S-bp7-018-N', 'yelled-ruby');
        $top = ['instance_id' => 'deck-top', 'card_type' => 'メンバー', 'name_en' => 'Top'];

        $state = $this->state([$ruby, $muse, $aqLive], [$yelled], [$top]);
        $state = nijiResolveNijigasakiEffect($state, 'p1', $live, $this->ability($live, 'player_choice_wr_members_deck_bottom'), []);

        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pick_members', $pr['step'] ?? null);
        $this->assertSame('p1', $pr['target'] ?? null);
        $this->assertArrayNotHasKey('choices', $pr);
        $ids = array_column($pr['candidates'] ?? [], 'instance_id');
        $this->assertSame(['ruby', 'train', 'yelled-ruby'], $ids);
        $this->assertNotSame('', $pr['candidates'][0]['image'] ?? '');
        $this->assertSame('Sunshine', $pr['wr_pick_cfg']['group'] ?? null);
        $this->assertSame('', $pr['wr_pick_cfg']['filter'] ?? null);

        $state = nijiHandlePrompt($state, $pr['type'], $pr, '', ['card_ids' => ['train', 'ruby']]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $deckIds = array_column($state['players']['p1']['main_deck'], 'instance_id');
        $this->assertSame(['deck-top', 'train', 'ruby'], $deckIds);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertSame(['kotori'], $wrIds);
        $yellIds = array_column($state['players']['p1']['_pending_yell_wr'], 'instance_id');
        $this->assertSame(['yelled-ruby'], $yellIds);
    }

    public function testWaitingRoomStubStillMatchesAqours(): void
    {
        $live = $this->cardByNo('PL!S-bp7-019-L', 'nando');
        $stub = [
            'instance_id' => 'stub-ruby',
            'card_no' => 'PL!S-bp7-018-N',
        ];
        $state = $this->state([$stub], [], []);
        $state = nijiResolveNijigasakiEffect($state, 'p1', $live, $this->ability($live, 'player_choice_wr_members_deck_bottom'), []);
        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pick_members', $pr['step'] ?? null);
        $this->assertSame(['stub-ruby'], array_column($pr['candidates'] ?? [], 'instance_id'));
        $this->assertSame('Sunshine', $pr['candidates'][0]['group'] ?? null);
        $this->assertNotSame('', $pr['candidates'][0]['image'] ?? '');
    }

    public function testSkippingPutsNothingOnTheDeck(): void
    {
        $live = $this->cardByNo('PL!S-bp7-019-L', 'nando');
        $ruby = $this->cardByNo('PL!S-bp7-018-N', 'ruby');
        $state = $this->state([$ruby], [], [['instance_id' => 'deck-top']]);
        $state = nijiResolveNijigasakiEffect($state, 'p1', $live, $this->ability($live, 'player_choice_wr_members_deck_bottom'), []);
        $pr = $state['pending_prompt'];
        $state = nijiHandlePrompt($state, $pr['type'], $pr, '', ['card_ids' => []]);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(['ruby'], array_column($state['players']['p1']['waiting_room'], 'instance_id'));
        $this->assertSame(['deck-top'], array_column($state['players']['p1']['main_deck'], 'instance_id'));
    }

    public function testShiorikoStillChoosesAPlayer(): void
    {
        $shioriko = $this->cardByNo('PL!N-bp3-010-R', 'shioriko');
        $state = $this->state([], [], []);
        $state['players']['p1']['waiting_room'] = [
            ['instance_id' => 'm1', 'card_type' => 'メンバー', 'name_en' => 'Member'],
        ];
        $state = nijiResolveNijigasakiEffect(
            $state,
            'p1',
            $shioriko,
            $this->ability($shioriko, 'player_choice_wr_members_deck_bottom'),
            []
        );
        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame(['self', 'opp'], $pr['choices'] ?? null);
        $this->assertArrayNotHasKey('step', $pr);
    }
}
