<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/api.php';

/**
 * #316: MIRAI TICKET (PL!S-bp6-021-L) "[Automatic][Once per turn] When you Yell, you may put up to 1 Bladeless Aqours
 * Member card revealed by Yell into the Waiting Room. If you do, perform 1 additional Yell for every 5 cost..." is a
 * Live card's ability, but only Stage Members' auto Yell abilities were ever resolved, so no prompt appeared.
 */
final class Issue316MiraiTicketAutoYellTest extends TestCase
{
    private function card(string $no, string $id): array
    {
        $c = \tcgCatalogCardByNo($no);
        $this->assertIsArray($c, $no);
        $c['instance_id'] = $id;
        $c['active'] = true;
        return $c;
    }

    /** @param list<array<string,mixed>> $yell */
    private function state(array $yell, int $liveCopies = 1): array
    {
        $lives = [];
        for ($i = 1; $i <= $liveCopies; $i++) {
            $lives[] = $this->card('PL!S-bp6-021-L', 'mirai' . $i);
        }
        $deck = [];
        for ($i = 1; $i <= 8; $i++) {
            $deck[] = ['instance_id' => 'deck' . $i, 'card_no' => 'PL!S-PR-014-PR', 'card_type' => 'メンバー', 'name_en' => 'Deck ' . $i, 'blade_hearts' => ['blue']];
        }
        return [
            'room_id' => 'T316', 'status' => 'playing', 'phase' => 'live_performance_first', 'turn' => 3, 'seq' => 1,
            'first_player' => 'p1', 'active_player' => 'p1', 'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1', 'name' => 'P1', 'hand' => [], 'waiting_room' => [], 'main_deck' => $deck,
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [], 'live_zone' => $lives, 'success_lives' => [], 'yell_cards' => $yell,
                ],
                'p2' => [
                    'id' => 'p2', 'name' => 'P2', 'hand' => [], 'waiting_room' => [], 'main_deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [], 'live_zone' => [], 'success_lives' => [],
                ],
            ],
            'yell_reveal' => ['p1' => $yell, 'p2' => []],
            '_last_yell_cards' => $yell,
        ];
    }

    public function testOffersTheYellMillWhenABladelessAqoursMemberWasRevealed(): void
    {
        $chika = $this->card('PL!S-PR-013-PR', 'chika');
        $state = \resolveAutoYellAbilities($this->state([$chika]), 'p1', [$chika]);
        $this->assertSame('sbp6_yell_mill_extra_yell', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(['chika'], array_column($state['pending_prompt']['candidates'], 'instance_id'));
    }

    public function testNoPromptWithoutABladelessAqoursMember(): void
    {
        $withBlade = $this->card('PL!S-PR-014-PR', 'riko');       // Aqours but has a Blade Heart
        $other = $this->card('PL!N-bp1-007-P', 'nijigasaki');      // bladeless but not Aqours
        $state = \resolveAutoYellAbilities($this->state([$withBlade, $other]), 'p1', [$withBlade, $other]);
        $this->assertEmpty($state['pending_prompt'] ?? null);
    }

    public function testOncePerTurnPerCopyAndAnswerYesPerformsTheExtraYells(): void
    {
        $chika = $this->card('PL!S-PR-013-PR', 'chika');
        $state = \resolveAutoYellAbilities($this->state([$chika]), 'p1', [$chika]);
        $prompt = $state['pending_prompt'];
        $this->assertTrue(\isAbilityUsed($state['players']['p1']['live_zone'][0], 0), 'once per turn is spent');

        $state['pending_prompt'] = null;
        $res = \sBp6ResolvePrompt($state, 'p1', $prompt, 'yes', ['card_ids' => ['chika']]);
        $this->assertIsArray($res);
        // Chika costs 11 -> 2 extra Yells drawn from the deck.
        $msgs = implode("
", array_column($res['log'], 'msg'));
        $this->assertStringContainsString('milled for +2 extra Yell', $msgs);
        $this->assertStringContainsString('Extra Yell [MIRAI TICKET]: drew 2 card(s)', $msgs);
        $this->assertSame('chika', $res['players']['p1']['waiting_room'][0]['instance_id'] ?? null);

        // A repeat pass this turn (the extra Yells run the auto abilities again) does not re-offer it.
        $again = \resolveAutoYellAbilities($res, 'p1', array_merge([$chika], []));
        $this->assertEmpty($again['pending_prompt'] ?? null);
    }

    public function testSecondCopyOfTheLiveGetsItsOwnOffer(): void
    {
        $chika = $this->card('PL!S-PR-013-PR', 'chika');
        $state = \resolveAutoYellAbilities($this->state([$chika], 2), 'p1', [$chika]);
        $this->assertSame('sbp6_yell_mill_extra_yell', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('mirai1', $state['_auto_yell_resume'] ? $state['players']['p1']['live_zone'][$state['_auto_yell_resume']['li']]['instance_id'] : null);
        $prompt = $state['pending_prompt'];
        $state['pending_prompt'] = null;
        $resumed = \resumeAutoYellLiveZoneAbilities($state, 'p1', $state['_auto_yell_resume']);
        $this->assertSame('sbp6_yell_mill_extra_yell', $resumed['pending_prompt']['type'] ?? null, 'the second copy still triggers');
        $this->assertNotSame($prompt, null);
    }
}
