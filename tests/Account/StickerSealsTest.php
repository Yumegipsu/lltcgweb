<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

final class StickerSealsTest extends TestCase
{
    private string $discordId;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/seals.php';
        require_once dirname(__DIR__, 2) . '/deck_validate.php';
        $this->discordId = 'test_seals_' . bin2hex(random_bytes(4));
        tcgEnsureUser($this->discordId, ['username' => 'Seal Tester']);
    }

    private function cardsData(): array
    {
        return json_decode((string)file_get_contents(TCG_CARDS_FILE), true) ?: ['cards' => []];
    }

    private function findGachaCard(string $tierWant): ?array
    {
        $map = tcgBuildCardMap($this->cardsData());
        foreach ($map as $c) {
            if (!tcgCardConvertibleToSeal($c)) {
                continue;
            }
            if (tcgSealTierForCard($c) === $tierWant) {
                return $c;
            }
        }
        return null;
    }

    private function findPrCard(): ?array
    {
        $map = tcgBuildCardMap($this->cardsData());
        foreach ($map as $c) {
            if (($c['booster_pack'] ?? '') === 'PRカード' || in_array(tcgNormalizePoolRarity($c['rarity'] ?? '', $c['card_no'] ?? ''), ['PR', 'PR+'], true)) {
                return $c;
            }
        }
        return null;
    }

    public function testPrCardsCannotConvert(): void
    {
        $pr = $this->findPrCard();
        $this->assertNotNull($pr);
        $this->assertFalse(tcgCardConvertibleToSeal($pr));
        tcgAddCardsToCollection($this->discordId, [$pr['card_no']]);
        $this->expectException(\Exception::class);
        tcgConvertCardsToSeals($this->discordId, $pr['card_no'], 1, tcgBuildCardMap($this->cardsData()));
    }

    public function testConvertCreditsOneSeal(): void
    {
        $card = $this->findGachaCard('N') ?: $this->findGachaCard('R');
        $this->assertNotNull($card);
        $tier = tcgSealTierForCard($card);
        tcgAddCardsToCollection($this->discordId, [$card['card_no'], $card['card_no']]);
        $before = tcgSealBalances($this->discordId);
        $out = tcgConvertCardsToSeals($this->discordId, $card['card_no'], 1, tcgBuildCardMap($this->cardsData()));
        $this->assertSame(1, $out['seals_gained']);
        $this->assertSame($tier, $out['tier']);
        $key = strtolower($tier);
        $this->assertSame(($before[$key] ?? 0) + 1, $out['seals'][$key]);
        $this->assertSame(1, tcgGetCollectionMap($this->discordId)[$card['card_no']] ?? 0);
    }

    public function testReservedDeckBlocksConvert(): void
    {
        $card = $this->findGachaCard('R') ?: $this->findGachaCard('N');
        $this->assertNotNull($card);
        $no = $card['card_no'];
        tcgAddCardsToCollection($this->discordId, [$no, $no]);
        $db = tcgDb();
        $main = array_fill(0, 60, $no);
        // Invalid deck size intentionally avoided — store 2 copies in a tiny JSON list for reserved count.
        $db->prepare('INSERT INTO tcg_deck_presets (discord_id, slot, name, main_deck, energy_deck, equipped, updated_at)
            VALUES (?, 1, ?, ?, ?, 0, ?)')
            ->execute([
                $this->discordId,
                'Reserve Test',
                json_encode([$no, $no]),
                json_encode([]),
                time(),
            ]);
        $this->assertSame(2, tcgMinReservedCopies($this->discordId, $no));
        $this->expectException(\Exception::class);
        tcgConvertCardsToSeals($this->discordId, $no, 1, tcgBuildCardMap($this->cardsData()));
    }

    public function testBuyDeductsCorrectSealCost(): void
    {
        $card = $this->findGachaCard('P') ?: $this->findGachaCard('R') ?: $this->findGachaCard('N');
        $this->assertNotNull($card);
        $tier = tcgSealTierForCard($card);
        $cost = tcgSealBuyCostForTier($tier);
        tcgAddSeals($this->discordId, $tier, $cost);
        $cardsData = $this->cardsData();
        // Ensure accessible: gacha products always allowed
        $this->assertTrue(tcgCardInAccessibleStickerShop($this->discordId, $card['card_no'], $cardsData)
            || tcgIsGachaBoosterCard($card));
        // Force accessibility by using gacha card from a listed box
        if (!tcgCardInAccessibleStickerShop($this->discordId, $card['card_no'], $cardsData)) {
            $this->markTestSkipped('Could not find accessible shop card for buy test');
        }
        $before = tcgSealBalances($this->discordId);
        $out = tcgStickerBuyCard($this->discordId, $card['card_no'], tcgBuildCardMap($cardsData), $cardsData);
        $key = strtolower($tier);
        $this->assertSame($cost, $out['cost']);
        $this->assertSame(($before[$key] ?? 0) - $cost, $out['seals'][$key]);
        $this->assertSame(1, $out['owned_qty']);
    }

    public function testBuyAtMaxCopiesFails(): void
    {
        $card = $this->findGachaCard('N') ?: $this->findGachaCard('R');
        $this->assertNotNull($card);
        $no = $card['card_no'];
        $max = tcgGetDeckMaxCopies($card, $no);
        $tier = tcgSealTierForCard($card);
        $copies = array_fill(0, $max, $no);
        tcgAddCardsToCollection($this->discordId, $copies);
        tcgAddSeals($this->discordId, $tier, 100);
        $cardsData = $this->cardsData();
        if (!tcgCardInAccessibleStickerShop($this->discordId, $no, $cardsData)) {
            $this->markTestSkipped('Card not in accessible shop');
        }
        $this->expectException(\Exception::class);
        tcgStickerBuyCard($this->discordId, $no, tcgBuildCardMap($cardsData), $cardsData);
    }

    public function testBuyCostsMatchPlan(): void
    {
        $this->assertSame(20, tcgSealBuyCostForTier('N'));
        $this->assertSame(15, tcgSealBuyCostForTier('R'));
        $this->assertSame(10, tcgSealBuyCostForTier('P'));
        $this->assertSame(5, tcgSealBuyCostForTier('SEC'));
    }
}
