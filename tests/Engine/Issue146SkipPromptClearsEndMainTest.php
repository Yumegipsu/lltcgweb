<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #146 — skipping optional On Enter / confirming a look with no eligible
 * picks must clear pending_prompt so End Main is not blocked.
 */
final class Issue146SkipPromptClearsEndMainTest extends TestCase
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

    private function baseMainState(array $stageCenter, array $deck): array
    {
        return [
            'room_id' => 'ISSUE146',
            'status' => 'playing',
            'seq' => 20,
            'turn' => 2,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => $stageCenter, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => array_fill(0, 12, ['card_type' => 'エネルギー', 'active' => true]),
                    'main_deck' => $deck,
                    'energy_deck' => [],
                    'live_zone' => [],
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

    public function testRinSkipOptionalWaitClearsPromptAndAllowsEndMain(): void
    {
        $rin = $this->cardByNo('PL!-bp3-014-N', 'rin');
        $deck = [];
        for ($i = 1; $i <= 5; $i++) {
            $deck[] = [
                'instance_id' => "d$i",
                'card_type' => 'メンバー',
                'card_type_en' => 'Member',
                'name_en' => "Deck $i",
                'group' => "μ's",
            ];
        }
        $state = $this->baseMainState($rin, $deck);
        $state = \resolveOnEnterAbilities($state, 'p1', $rin, 'center');
        $this->assertSame('optional_wait_self_surveil', $state['pending_prompt']['type'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'no']);
        $this->assertNull($state['pending_prompt'] ?? null, 'Skip must clear pending_prompt');

        $after = \applyAction($state, 'p1', 'end_main', []);
        $this->assertSame('main_second', $after['phase'] ?? null);
        $this->assertNull($after['pending_prompt'] ?? null);
    }

    public function testUmiNoEligibleLookConfirmClearsPromptAndAllowsEndMain(): void
    {
        $umi = $this->cardByNo('PL!-sd1-004-SD', 'umi');
        $deck = [];
        for ($i = 1; $i <= 5; $i++) {
            $deck[] = [
                'instance_id' => "m$i",
                'card_type' => 'メンバー',
                'card_type_en' => 'Member',
                'name_en' => "Member $i",
                'group' => "μ's",
            ];
        }
        $state = $this->baseMainState($umi, $deck);
        $state = \resolveOnEnterAbilities($state, 'p1', $umi, 'center');
        $this->assertSame('pick_looked_deck_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertTrue(!empty($state['pending_prompt']['optional']));
        $this->assertSame([], $state['pending_prompt']['eligible_ids'] ?? null);

        $state = \actionResolvePrompt($state, 'p1', ['choice' => 'skip']);
        $this->assertNull($state['pending_prompt'] ?? null, 'Confirm-with-no-match must clear pending_prompt');
        $this->assertCount(5, $state['players']['p1']['waiting_room'] ?? []);

        $after = \applyAction($state, 'p1', 'end_main', []);
        $this->assertSame('main_second', $after['phase'] ?? null);
        $this->assertNull($after['pending_prompt'] ?? null);
    }
}
