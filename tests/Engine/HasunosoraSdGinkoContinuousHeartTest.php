<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

final class HasunosoraSdGinkoContinuousHeartTest extends TestCase
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

    private function state(array $ginko, ?array $partner): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_performance_first',
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'stage' => ['left' => $ginko, 'center' => $partner, 'right' => null],
                    'live_zone' => [],
                    'energy_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'energy_zone' => [],
                ],
            ],
        ];
    }

    public function testGinkoGrantsOneGreenHeartWhileKahoIsOnStage(): void
    {
        $ginko = $this->cardByNo('PL!HS-sd1-004-SD', 'ginko_sd1');
        $kaho = $this->cardByNo('PL!HS-sd1-001-SD', 'kaho_sd1');
        $state = $this->state($ginko, $kaho);

        $grants = \collectContinuousPerformanceHeartGrants($state, 'p1');

        $this->assertCount(1, $grants);
        $this->assertSame('ginko_sd1', $grants[0]['instance_id'] ?? null);
        $this->assertSame(['green'], $grants[0]['hearts'] ?? null);
        $this->assertSame(['green'], \getContinuousPerformanceHearts($state, 'p1'));

        $printedGreen = count(array_filter(
            array_merge(\memberPerformanceHeartsFlat($ginko), \memberPerformanceHeartsFlat($kaho)),
            static fn(string $color): bool => $color === 'green'
        ));
        $resolvedPool = \collectStageHeartPoolForYellResolve($state, 'p1');
        $this->assertSame(
            $printedGreen + 1,
            count(array_filter($resolvedPool, static fn(string $color): bool => $color === 'green'))
        );
    }

    public function testGinkoDoesNotGrantHeartWithoutNamedPartner(): void
    {
        $ginko = $this->cardByNo('PL!HS-sd1-004-SD', 'ginko_sd1');
        $state = $this->state($ginko, null);

        $this->assertSame([], \getContinuousPerformanceHearts($state, 'p1'));
    }
}
