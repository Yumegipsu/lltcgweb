<?php

declare(strict_types=1);

namespace LLTCG\Tests\Replay;

use PHPUnit\Framework\TestCase;

/**
 * Client credential preference for finished-room autosave vs rematch steal.
 * Mirrors getReplayExportCredentials preferFinished logic in replay-debug.js.
 */
final class ReplayAutosaveCredentialLockTest extends TestCase
{
    /** @return array{roomId:?string,token:?string} */
    private function resolveCreds(
        ?string $liveRoom,
        ?string $liveToken,
        ?array $finished,
        ?string $pendingRoom,
        string $status
    ): array {
        $preferFinished = $pendingRoom !== null && $pendingRoom !== ''
            || $status === 'finished';
        if ($preferFinished && !empty($finished['roomId']) && !empty($finished['token'])) {
            if ($pendingRoom === null || $pendingRoom === '' || $pendingRoom === $finished['roomId']) {
                return ['roomId' => $finished['roomId'], 'token' => $finished['token']];
            }
        }
        if ($pendingRoom && !empty($finished['roomId'])
            && $finished['roomId'] === $pendingRoom && !empty($finished['token'])) {
            return ['roomId' => $finished['roomId'], 'token' => $finished['token']];
        }
        if ($liveRoom && $liveToken) {
            if ($preferFinished && !empty($finished['roomId'])
                && $finished['roomId'] !== $liveRoom && !empty($finished['token'])) {
                return ['roomId' => $finished['roomId'], 'token' => $finished['token']];
            }
            return ['roomId' => $liveRoom, 'token' => $liveToken];
        }
        if (!empty($finished['roomId']) && !empty($finished['token'])) {
            return ['roomId' => $finished['roomId'], 'token' => $finished['token']];
        }
        return ['roomId' => null, 'token' => null];
    }

    public function testRematchRoomDoesNotStealFinishedCredentials(): void
    {
        $creds = $this->resolveCreds(
            'NEWR01',
            'new-token',
            ['roomId' => 'OLD001', 'token' => 'old-token'],
            'OLD001',
            'waiting'
        );
        $this->assertSame('OLD001', $creds['roomId']);
        $this->assertSame('old-token', $creds['token']);
    }

    public function testFinishedStatusPrefersStashOverLiveRoom(): void
    {
        $creds = $this->resolveCreds(
            'NEWR02',
            'new-token',
            ['roomId' => 'FIN001', 'token' => 'fin-token'],
            null,
            'finished'
        );
        $this->assertSame('FIN001', $creds['roomId']);
        $this->assertSame('fin-token', $creds['token']);
    }

    public function testLiveCredsUsedWhenNoFinishedStash(): void
    {
        $creds = $this->resolveCreds('LIVE01', 'live-token', null, null, 'playing');
        $this->assertSame('LIVE01', $creds['roomId']);
        $this->assertSame('live-token', $creds['token']);
    }
}
