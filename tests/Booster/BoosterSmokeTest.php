<?php

declare(strict_types=1);

namespace LLTCG\Tests\Booster;

use PHPUnit\Framework\TestCase;

final class BoosterSmokeTest extends TestCase
{
    public function testPbSuperstarDuoPackHasThreeCards(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/booster.php';
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
    }
}
