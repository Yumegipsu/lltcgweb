<?php

declare(strict_types=1);

namespace LLTCG\Tests\Booster;

use PHPUnit\Framework\TestCase;

final class BoosterSmokeTest extends TestCase
{
    private function loadBooster(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/booster.php';
    }

    public function testCatalogMatchesOfficialPackAndBoxSizes(): void
    {
        $this->loadBooster();
        $byId = [];
        foreach (tcgBoosterBoxes() as $box) {
            $byId[$box['id']] = tcgEnrichBoosterBoxPublic($box);
        }

        $this->assertSame(5, $byId['bp_vol1']['pack_size']);
        $this->assertSame(10, $byId['bp_vol1']['packs_per_box']);
        $this->assertSame(100, $byId['bp_vol1']['star_gems_pack_cost']);
        $this->assertSame(1000, $byId['bp_vol1']['star_gems_box_cost']);

        $this->assertSame(5, $byId['bp_mellow']['pack_size']);
        $this->assertSame(10, $byId['bp_mellow']['packs_per_box']);
        $this->assertSame(1000, $byId['bp_mellow']['star_gems_box_cost']);

        $this->assertSame(3, $byId['pb_niji']['pack_size']);
        $this->assertSame(20, $byId['pb_niji']['packs_per_box']);
        $this->assertSame(60, $byId['pb_niji']['star_gems_pack_cost']);
        $this->assertSame(1200, $byId['pb_niji']['star_gems_box_cost']);

        $this->assertSame(3, $byId['pb_superstar_duo']['pack_size']);
        $this->assertSame(20, $byId['pb_superstar_duo']['packs_per_box']);
        $this->assertSame(60, $byId['pb_superstar_duo']['star_gems_pack_cost']);
        $this->assertSame(1200, $byId['pb_superstar_duo']['star_gems_box_cost']);

        $this->assertSame(3, $byId['pb_muse_duo']['pack_size']);
        $this->assertSame(20, $byId['pb_muse_duo']['packs_per_box']);
        $this->assertSame(60, $byId['pb_muse_duo']['star_gems_pack_cost']);
        $this->assertSame(1200, $byId['pb_muse_duo']['star_gems_box_cost']);
        $this->assertSame('pb_duo', $byId['pb_muse_duo']['kind']);
    }

    public function testPbSuperstarDuoPackHasThreeCards(): void
    {
        $this->loadBooster();
        $cardsData = json_decode((string)file_get_contents(CARDS_FILE), true);
        $discordId = 'test_booster_' . bin2hex(random_bytes(4));
        tcgEnsureUser($discordId, ['username' => 'Booster Test']);
        $db = tcgDb();
        $db->prepare('UPDATE tcg_users SET star_gems = 5000, updated_at = ? WHERE discord_id = ?')
            ->execute([time(), $discordId]);

        $out = tcgOpenBoosterPack($discordId, 'pb_superstar_duo', $cardsData, 'gems');
        $this->assertSame('pack', $out['mode'] ?? '');
        $this->assertSame('pb_superstar_duo', $out['box']['id'] ?? '');
        $this->assertCount(3, $out['card_nos'] ?? []);
        $this->assertSame(60, $out['star_gems_spent'] ?? 0);
    }

    public function testPbMuseDuoPackHasThreeCards(): void
    {
        $this->loadBooster();
        $cardsData = json_decode((string)file_get_contents(CARDS_FILE), true);
        $discordId = 'test_booster_muse_duo_' . bin2hex(random_bytes(4));
        tcgEnsureUser($discordId, ['username' => 'Booster Muse Duo']);
        $db = tcgDb();
        $db->prepare('UPDATE tcg_users SET star_gems = 5000, updated_at = ? WHERE discord_id = ?')
            ->execute([time(), $discordId]);

        $out = tcgOpenBoosterPack($discordId, 'pb_muse_duo', $cardsData, 'gems');
        $this->assertSame('pack', $out['mode'] ?? '');
        $this->assertSame('pb_muse_duo', $out['box']['id'] ?? '');
        $this->assertCount(3, $out['card_nos'] ?? []);
        $this->assertSame(60, $out['star_gems_spent'] ?? 0);
        foreach ($out['card_nos'] ?? [] as $no) {
            $card = null;
            foreach ($cardsData['cards'] ?? [] as $c) {
                if (($c['card_no'] ?? '') === $no) {
                    $card = $c;
                    break;
                }
            }
            $this->assertNotNull($card, $no);
            $this->assertSame('プレミアムブースター ラブライブ！DUO', $card['booster_pack'] ?? '');
        }
    }

    public function testPremiumBoosterNijiPackHasThreeCardsAndCostsSixty(): void
    {
        $this->loadBooster();
        $cardsData = json_decode((string)file_get_contents(CARDS_FILE), true);
        $discordId = 'test_booster_niji_' . bin2hex(random_bytes(4));
        tcgEnsureUser($discordId, ['username' => 'Booster Niji']);
        $db = tcgDb();
        $db->prepare('UPDATE tcg_users SET star_gems = 5000, updated_at = ? WHERE discord_id = ?')
            ->execute([time(), $discordId]);

        $out = tcgOpenBoosterPack($discordId, 'pb_niji', $cardsData, 'gems');
        $this->assertSame('pack', $out['mode'] ?? '');
        $this->assertSame('pb_niji', $out['box']['id'] ?? '');
        $this->assertCount(3, $out['card_nos'] ?? []);
        $this->assertSame(60, $out['star_gems_spent'] ?? 0);
    }

    public function testStandardBoosterPackHasFiveCardsAndCostsOneHundred(): void
    {
        $this->loadBooster();
        $cardsData = json_decode((string)file_get_contents(CARDS_FILE), true);
        $discordId = 'test_booster_bp_' . bin2hex(random_bytes(4));
        tcgEnsureUser($discordId, ['username' => 'Booster BP']);
        $db = tcgDb();
        $db->prepare('UPDATE tcg_users SET star_gems = 5000, updated_at = ? WHERE discord_id = ?')
            ->execute([time(), $discordId]);

        $out = tcgOpenBoosterPack($discordId, 'bp_vol1', $cardsData, 'gems');
        $this->assertSame('pack', $out['mode'] ?? '');
        $this->assertCount(5, $out['card_nos'] ?? []);
        $this->assertSame(100, $out['star_gems_spent'] ?? 0);
    }

    /** Dual-holo PB packs must advance pe_pity once per pack, not once per holo. */
    public function testPremiumBoosterPityAdvancesOncePerPack(): void
    {
        $this->loadBooster();
        $cardsData = json_decode((string) file_get_contents(CARDS_FILE), true);
        $box = tcgBoosterBoxById('pb_niji');
        $this->assertNotNull($box);
        $pools = tcgBuildBoxPools($cardsData, $box);
        $progress = [
            'pe_pity' => 5,
            'pplus_pity' => 5,
            'sec_pity' => 5,
            'rm_pity' => 5,
            'live_pity' => 0,
            'packs_in_box' => 0,
            'boxes_opened' => 0,
        ];

        tcgPickPbHolo($pools, $progress, 20, true);
        $afterFirst = intval($progress['pe_pity']);
        tcgPickPbHolo($pools, $progress, 20, false);
        $this->assertSame(
            $afterFirst,
            intval($progress['pe_pity']),
            'Second holo slot must not advance pe_pity'
        );

        $progress['rm_pity'] = 3;
        tcgPickPbHolo($pools, $progress, 20, true);
        $rmAfterFirst = intval($progress['rm_pity']);
        tcgPickPbHolo($pools, $progress, 20, false);
        $this->assertSame($rmAfterFirst, intval($progress['rm_pity']));
    }

    public function testDuoPePlusWeightMatchesStandardPbScarcity(): void
    {
        $this->loadBooster();
        $duo = null;
        foreach (tcgPbDuoHoloSlotRarityWeights() as $row) {
            if (($row['r'] ?? '') === 'PE+') {
                $duo = intval($row['w']);
                break;
            }
        }
        $pb = null;
        foreach (tcgPbHoloSlotRarityWeights() as $row) {
            if (($row['r'] ?? '') === 'PE+') {
                $pb = intval($row['w']);
                break;
            }
        }
        $this->assertSame(25, $duo);
        $this->assertSame(25, $pb);
    }
}
