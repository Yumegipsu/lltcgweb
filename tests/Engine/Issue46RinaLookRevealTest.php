<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression: PL!N-pb1-021-R On Enter optional deck look pick (issue #46). */
final class Issue46RinaLookRevealTest extends TestCase
{
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

    private function baseState(string $phase = 'main_first'): array
    {
        return [
            'status' => 'playing',
            'phase' => $phase,
            'seq' => 1,
            'turn' => 1,
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
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];
    }

    private function rinaDeckSetup(): array
    {
        $rina = $this->cardByNo('PL!N-pb1-021-R', 'issue46_rina_stage');
        $shizuku = $this->cardByNo('PL!N-PR-005-PR', 'issue46_deck_shizuku');
        $deckRina = $this->cardByNo('PL!N-PR-011-PR', 'issue46_deck_rina');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $rina;
        $state['players']['p1']['main_deck'] = [$shizuku, $deckRina];

        return [$state, $rina, $shizuku, $deckRina];
    }

    public function testOnEnterOpensOptionalLookPickWithRinaOnlyEligible(): void
    {
        [$state, , $shizuku, $deckRina] = $this->rinaDeckSetup();

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');

        $this->assertSame('pick_looked_deck_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertTrue(!empty($state['pending_prompt']['optional']));
        $eligible = $state['pending_prompt']['eligible_ids'] ?? [];
        $this->assertSame(['issue46_deck_rina'], $eligible);
        $this->assertNotContains('issue46_deck_shizuku', $eligible);
        $this->assertCount(2, $state['pending_prompt']['candidates'] ?? []);
        $this->assertSame('issue46_deck_shizuku', $state['pending_prompt']['candidates'][0]['instance_id'] ?? null);
        $this->assertSame('issue46_deck_rina', $state['pending_prompt']['candidates'][1]['instance_id'] ?? null);
        $this->assertEmpty($state['players']['p1']['hand']);
        $this->assertEmpty($state['players']['p1']['waiting_room']);
    }

    public function testSkipPutsAllLookedCardsInWaitingRoom(): void
    {
        [$state] = $this->rinaDeckSetup();

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');
        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'skip']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertEmpty($state['players']['p1']['hand']);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('issue46_deck_shizuku', $wrIds);
        $this->assertContains('issue46_deck_rina', $wrIds);
    }

    public function testPickAddsRinaToHandAndOtherToWaitingRoom(): void
    {
        [$state, , , $deckRina] = $this->rinaDeckSetup();

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');
        $state = \actionResolvePrompt($state, 'p1', ['card_id' => 'issue46_deck_rina']);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertContains('issue46_deck_rina', array_column($state['players']['p1']['hand'], 'instance_id'));
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('issue46_deck_shizuku', $wrIds);
        $this->assertNotContains('issue46_deck_rina', $wrIds);
    }

    public function testTimeoutOnOptionalPromptSkipsInsteadOfPickingIneligible(): void
    {
        [$state] = $this->rinaDeckSetup();

        $state = \resolveOnEnterAbilities($state, 'p1', $state['players']['p1']['stage']['center'], 'center');
        $prompt = $state['pending_prompt'] ?? [];
        $this->assertTrue(!empty($prompt['optional']));

        $data = \buildTimeoutPromptResolution($state, 'p1', $prompt);
        $this->assertSame('skip', $data['choice'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', $data);
        $this->assertEmpty($state['players']['p1']['hand']);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertContains('issue46_deck_shizuku', $wrIds);
        $this->assertContains('issue46_deck_rina', $wrIds);
    }
}
