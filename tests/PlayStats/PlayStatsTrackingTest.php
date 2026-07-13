<?php

declare(strict_types=1);

namespace LLTCG\Tests\PlayStats;

use PHPUnit\Framework\TestCase;

/** Stage / Live Success play counters for signed-in humans. */
final class PlayStatsTrackingTest extends TestCase
{
    private string $discordId;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/play_stats.php';
        require_once dirname(__DIR__, 2) . '/missions.php';
        $this->discordId = 'test_playstats_' . bin2hex(random_bytes(4));
        tcgEnsureUser($this->discordId, ['username' => 'PlayStats Test']);
    }

    public function testCardPlayTrackTagsSplitsMultiIdolAndSkipsEnergy(): void
    {
        $tags = \cardPlayTrackTags([
            'card_type' => 'メンバー',
            'name_en' => 'Honoka Kosaka & Umi Sonoda',
            'group' => "μ's",
            'subunit' => 'Printemps',
            'card_no' => 'TEST-MULTI',
        ]);
        $this->assertSame(['Honoka Kosaka', 'Umi Sonoda'], $tags['idols']);
        $this->assertSame(["μ's"], $tags['units']);
        $this->assertSame(['Printemps'], $tags['subunits']);
        $this->assertSame(['TEST-MULTI'], $tags['card_nos']);

        $this->assertSame([], \tcgPlayStatIncrementsForCard(TCG_PLAY_TRACKER_STAGE, [
            'card_type' => 'エネルギー',
            'name_en' => 'Smile',
            'card_no' => 'E-1',
        ]));
    }

    public function testExplicitPlayTrackOverridesDefaults(): void
    {
        $tags = \cardPlayTrackTags([
            'card_type' => 'ライブ',
            'name_en' => 'Some Live',
            'group' => 'Sunshine',
            'card_no' => 'LIVE-1',
            'play_track' => [
                'members' => ['Chika Takami'],
                'unit' => 'Aqours',
                'live_name' => 'Aozora Jumping Heart',
            ],
        ]);
        $this->assertSame(['Chika Takami'], $tags['idols']);
        $this->assertSame(['Aqours'], $tags['units']);
        $this->assertSame(['Aozora Jumping Heart'], $tags['live_names']);
    }

    public function testStageEnterIncrementsIdolUnitAndCard(): void
    {
        $member = [
            'instance_id' => 'ps_honoka',
            'card_type' => 'メンバー',
            'name_en' => 'Honoka Kosaka',
            'group' => "μ's",
            'subunit' => 'Printemps',
            'card_no' => 'PL!-TEST-HONOKA',
            'active' => true,
        ];
        $state = [
            'status' => 'playing',
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'Human',
                    'discord_id' => $this->discordId,
                    'deck_choice' => 'muse',
                    'stage' => ['left' => null, 'center' => $member, 'right' => null],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'CPU (Easy)',
                    'deck_choice' => 'cpu:easy',
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];

        \notifyMemberEnteredStage($state, 'p1', $member);
        \notifyMemberEnteredStage($state, 'p1', $member);

        $this->assertSame(2, \tcgGetPlayStat($this->discordId, TCG_PLAY_TRACKER_STAGE, TCG_PLAY_DIM_IDOL, 'Honoka Kosaka'));
        $this->assertSame(2, \tcgGetPlayStat($this->discordId, TCG_PLAY_TRACKER_STAGE, TCG_PLAY_DIM_UNIT, "μ's"));
        $this->assertSame(2, \tcgGetPlayStat($this->discordId, TCG_PLAY_TRACKER_STAGE, TCG_PLAY_DIM_SUBUNIT, 'Printemps'));
        $this->assertSame(2, \tcgGetPlayStat($this->discordId, TCG_PLAY_TRACKER_STAGE, TCG_PLAY_DIM_CARD, 'PL!-TEST-HONOKA'));
    }

    public function testCpuSeatIsNotTracked(): void
    {
        $member = [
            'instance_id' => 'ps_cpu_m',
            'card_type' => 'メンバー',
            'name_en' => 'Honoka Kosaka',
            'group' => "μ's",
            'card_no' => 'PL!-TEST-CPU',
        ];
        $state = [
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'CPU (Easy)',
                    'discord_id' => $this->discordId,
                    'deck_choice' => 'cpu:easy',
                ],
            ],
        ];
        \notifyMemberEnteredStage($state, 'p1', $member);
        $this->assertSame(0, \tcgGetPlayStat($this->discordId, TCG_PLAY_TRACKER_STAGE, TCG_PLAY_DIM_IDOL, 'Honoka Kosaka'));
    }

    public function testLiveSuccessTracksLiveNameSeparatelyFromStage(): void
    {
        $live = [
            'instance_id' => 'ps_live',
            'card_type' => 'ライブ',
            'name_en' => 'START:DASH!!',
            'group' => "μ's",
            'card_no' => 'PL!-TEST-LIVE',
        ];
        $state = [
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'Human',
                    'discord_id' => $this->discordId,
                    'deck_choice' => 'muse',
                ],
            ],
        ];
        \notifyLiveEnteredSuccess($state, 'p1', $live);

        $this->assertSame(1, \tcgGetPlayStat($this->discordId, TCG_PLAY_TRACKER_LIVE_SUCCESS, TCG_PLAY_DIM_LIVE_NAME, 'START:DASH!!'));
        $this->assertSame(1, \tcgGetPlayStat($this->discordId, TCG_PLAY_TRACKER_LIVE_SUCCESS, TCG_PLAY_DIM_UNIT, "μ's"));
        $this->assertSame(1, \tcgGetPlayStat($this->discordId, TCG_PLAY_TRACKER_LIVE_SUCCESS, TCG_PLAY_DIM_CARD, 'PL!-TEST-LIVE'));
        $this->assertSame(0, \tcgGetPlayStat($this->discordId, TCG_PLAY_TRACKER_STAGE, TCG_PLAY_DIM_LIVE_NAME, 'START:DASH!!'));
        $this->assertSame(0, \tcgGetPlayStat($this->discordId, TCG_PLAY_TRACKER_LIVE_SUCCESS, TCG_PLAY_DIM_IDOL, 'Honoka Kosaka'));
    }

    public function testResolveOnEnterAbilitiesRecordsStagePlay(): void
    {
        $member = [
            'instance_id' => 'ps_enter',
            'card_type' => 'メンバー',
            'name_en' => 'Umi Sonoda',
            'group' => "μ's",
            'subunit' => 'lily white',
            'card_no' => 'PL!-TEST-UMI',
            'abilities' => [],
            'active' => true,
        ];
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'turn' => 1,
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'Human',
                    'discord_id' => $this->discordId,
                    'deck_choice' => 'muse',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => $member, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'Opp',
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
        \resolveOnEnterAbilities($state, 'p1', $member, 'center');
        $this->assertSame(1, \tcgGetPlayStat($this->discordId, TCG_PLAY_TRACKER_STAGE, TCG_PLAY_DIM_IDOL, 'Umi Sonoda'));
    }
}
