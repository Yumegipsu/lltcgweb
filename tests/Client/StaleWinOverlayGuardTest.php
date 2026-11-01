<?php

declare(strict_types=1);

namespace LLTCG\Tests\Client;

use PHPUnit\Framework\TestCase;

/**
 * Finish presentation awaits can outlive hub / rematch / spectate boots. Guards ensure
 * overlay-win is not reopened for a room the client already left (random resign/win flash).
 */
final class StaleWinOverlayGuardTest extends TestCase
{
    private function read(string $rel): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel));
    }

    public function testApplyFinishedStateGuardsSessionEpochAndRoom(): void
    {
        $src = $this->read('client/js/state-apply.js');
        $this->assertStringContainsString('finished apply superseded — skip showWin', $src);
        $this->assertStringContainsString('expectRoomId: finishRoomId', $src);
        $this->assertStringContainsString('expectEpoch: finishEpoch', $src);
        $this->assertStringContainsString('finishStillCurrent', $src);
    }

    public function testShowWinRejectsStaleRoomOrEpoch(): void
    {
        $html = $this->read('index.html');
        $this->assertStringContainsString('function showWin(s, opts = {}){', $html);
        foreach ([
            'skip showWin — session epoch changed',
            'skip showWin — room changed',
            'skip showWin — finished payload room mismatch',
            'skip showWin — no active room session',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
    }

    public function testResetMatchClearsWinOverlayAndFinishedSeen(): void
    {
        $html = $this->read('index.html');
        $fn = $this->body($html, 'function resetMatchTransientState() {');
        $this->assertStringContainsString("el('overlay-win')?.classList.remove('open')", $fn);
        $this->assertStringContainsString('G._finishedSeenState = null', $fn);
        $this->assertStringContainsString('matchWatchdogReset', $fn);
    }

    public function testTabCatchUpShowWinPassesExpectGuards(): void
    {
        $src = $this->read('client/js/game-sync.js');
        $this->assertSame(3, substr_count($src, 'expectRoomId: pollRoomId'));
        $this->assertSame(3, substr_count($src, 'expectEpoch: pollEpoch'));
    }

    private function body(string $src, string $start): string
    {
        $i = strpos($src, $start);
        if ($i === false) {
            return '';
        }
        $depth = 0;
        $started = false;
        $out = '';
        $len = strlen($src);
        for ($p = $i; $p < $len; $p++) {
            $ch = $src[$p];
            $out .= $ch;
            if ($ch === '{') {
                $depth++;
                $started = true;
            } elseif ($ch === '}') {
                $depth--;
                if ($started && $depth === 0) {
                    break;
                }
            }
        }
        return $out;
    }
}
