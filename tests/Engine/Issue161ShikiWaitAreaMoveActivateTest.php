<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #161 — PL!SP-bp7-008 Shiki: Auto activates when she moves while in Wait.
 * Must fire on general Stage position-change / swap, not only BP7-specific helpers.
 */
final class Issue161ShikiWaitAreaMoveActivateTest extends TestCase
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

    private function emptyPlayer(string $id): array
    {
        return [
            'id' => $id,
            'name' => strtoupper($id),
            'hand' => [],
            'waiting_room' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'energy_zone' => [],
            'main_deck' => [
                ['instance_id' => $id . '_deck1', 'card_type' => 'メンバー', 'name_en' => 'Deck'],
            ],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => $this->emptyPlayer('p1'),
                'p2' => $this->emptyPlayer('p2'),
            ],
        ];
    }

    public function testPositionChangeWhileInWaitActivates(): void
    {
        $shiki = $this->cardByNo('PL!SP-bp7-008-R', 'shiki');
        $partner = $this->cardByNo('PL!SP-bp4-011-P', 'partner');

        $state = $this->baseState();
        $state['players']['p1']['stage']['left'] = $shiki;
        $state['players']['p1']['stage']['center'] = $partner;

        // Put Shiki into Wait via her Activated skill.
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'shiki',
            'ability_index' => 0,
        ]);
        $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['left']));

        // Move Wait Shiki to Right (empty) — Auto must activate her (#161).
        $state = \applyStagePositionChange($state, 'p1', 'left', 'right');

        $m = $state['players']['p1']['stage']['right'] ?? null;
        $this->assertNotNull($m);
        $this->assertSame('shiki', $m['instance_id'] ?? null);
        $this->assertFalse(\memberIsInWait($m), 'Shiki must leave Wait after area move');
        $this->assertTrue(!empty($m['active']));
        $this->assertStringContainsString(
            'activated (moved area while in Wait)',
            implode("\n", array_column($state['log'] ?? [], 'msg'))
        );
    }

    public function testSwapWhileInWaitActivates(): void
    {
        $shiki = $this->cardByNo('PL!SP-bp7-008-R', 'shiki_swap');
        $partner = $this->cardByNo('PL!HS-sd1-015-SD', 'partner_swap');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $shiki;
        $state['players']['p1']['stage']['left'] = $partner;
        waitMember($state['players']['p1']['stage']['center'], $state);
        $this->assertTrue(\memberIsInWait($state['players']['p1']['stage']['center']));

        $state = \applyStagePositionChange($state, 'p1', 'center', 'left');

        $m = $state['players']['p1']['stage']['left'] ?? null;
        $this->assertSame('shiki_swap', $m['instance_id'] ?? null);
        $this->assertFalse(\memberIsInWait($m));
    }

    public function testMoveWhileActiveDoesNotLogActivate(): void
    {
        $shiki = $this->cardByNo('PL!SP-bp7-008-R', 'shiki_active');
        $state = $this->baseState();
        $state['players']['p1']['stage']['left'] = $shiki;

        $state = \applyStagePositionChange($state, 'p1', 'left', 'right');
        $this->assertStringNotContainsString(
            'activated (moved area while in Wait)',
            implode("\n", array_column($state['log'] ?? [], 'msg'))
        );
        $this->assertFalse(\memberIsInWait($state['players']['p1']['stage']['right']));
    }
}
