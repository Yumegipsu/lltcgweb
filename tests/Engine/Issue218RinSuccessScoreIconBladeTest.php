<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-005 Rin — On Enter grants until-Live +1 Blade to μ's Stage Members
 * when Success Live has a μ's Score-icon card (#218).
 */
final class Issue218RinSuccessScoreIconBladeTest extends TestCase
{
    private function rin(): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === 'PL!-pb2-005-R') {
                $card['instance_id'] = 'rin005';
                $card['active'] = true;
                $card['blade'] = intval($card['blade'] ?? 0);
                return $card;
            }
        }
        $this->fail('Missing PL!-pb2-005-R');
    }

    private function scoreIconLive(): array
    {
        return [
            'instance_id' => 'succ_score',
            'card_no' => 'PL!-sd1-019-SD',
            'name_en' => 'START:DASH!!',
            'card_type' => 'ライブ',
            'group' => "μ's",
            'score' => 1,
            'yell_score_icon' => true,
            'special_heart' => 'icon_score.png',
        ];
    }

    private function ally(): array
    {
        return [
            'instance_id' => 'honoka',
            'card_no' => 'PL!-sd1-001-SD',
            'name_en' => 'Honoka',
            'card_type' => 'メンバー',
            'group' => "μ's",
            'blade' => 1,
            'active' => true,
        ];
    }

    public function testGrantsStageGroupBladeAuraWhenSuccessHasScoreIcon(): void
    {
        $rin = $this->rin();
        $ally = $this->ally();
        $state = [
            'phase' => 'main',
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'success_lives' => [$this->scoreIconLive()],
                    'waiting_room' => [],
                    'deck' => [],
                    'stage' => ['left' => $ally, 'center' => $rin, 'right' => null],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'success_lives' => [],
                    'waiting_room' => [],
                    'deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];
        $ab = $rin['abilities'][0] ?? null;
        $this->assertIsArray($ab);
        $this->assertSame('grant_stage_group_blade_if_success_score_icon', $ab['type'] ?? '');

        $beforeAlly = getMemberBlade($ally, $state, 'p1', 'left');
        $beforeRin = getMemberBlade($rin, $state, 'p1', 'center');

        $out = plMusePb2ResolveEffect($state, 'p1', $rin, $ab);
        $auras = $out['live_modifiers']['p1']['stage_group_blade'] ?? [];
        $this->assertNotEmpty($auras, 'should store until-Live stage group blade aura');
        $this->assertSame("μ's", $auras[0]['group'] ?? '');
        $this->assertSame(1, intval($auras[0]['blade'] ?? 0));

        $allyOut = $out['players']['p1']['stage']['left'];
        $rinOut = $out['players']['p1']['stage']['center'];
        $this->assertSame(
            $beforeAlly + 1,
            getMemberBlade($allyOut, $out, 'p1', 'left'),
            'μ\'s ally should gain +1 Blade from aura'
        );
        $this->assertSame(
            $beforeRin + 1,
            getMemberBlade($rinOut, $out, 'p1', 'center'),
            'Rin herself is μ\'s and should gain +1 Blade'
        );
    }

    public function testNoAuraWithoutScoreIconSuccess(): void
    {
        $rin = $this->rin();
        $plainLive = [
            'instance_id' => 'succ_plain',
            'card_no' => 'PL!-bp3-020-L',
            'name_en' => 'Plain Live',
            'card_type' => 'ライブ',
            'group' => "μ's",
            'score' => 2,
            'yell_score_icon' => false,
            'special_heart' => null,
        ];
        $state = [
            'phase' => 'main',
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'success_lives' => [$plainLive],
                    'waiting_room' => [],
                    'deck' => [],
                    'stage' => ['left' => null, 'center' => $rin, 'right' => null],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'success_lives' => [],
                    'waiting_room' => [],
                    'deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];
        $out = plMusePb2ResolveEffect($state, 'p1', $rin, $rin['abilities'][0]);
        $this->assertSame([], $out['live_modifiers']['p1']['stage_group_blade'] ?? []);
        $this->assertSame(
            getMemberBlade($rin, $state, 'p1', 'center'),
            getMemberBlade($out['players']['p1']['stage']['center'], $out, 'p1', 'center')
        );
    }
}
