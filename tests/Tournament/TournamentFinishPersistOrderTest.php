<?php

declare(strict_types=1);

namespace LLTCG\Tests\Tournament;

use PHPUnit\Framework\TestCase;

/**
 * Natural 3-Live finishes must persist Redis before Hostinger tournament/ranked apply,
 * matching resign — otherwise replay_export races an unfinished room snapshot.
 */
final class TournamentFinishPersistOrderTest extends TestCase
{
    public function testNaturalFinishPersistsBeforeOnGameFinished(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/api.php');
        $pos = strpos($src, '} elseif ($justFinished) {');
        $this->assertNotFalse($pos, 'natural justFinished branch missing');
        $chunk = substr($src, $pos, 1800);
        $savePos = strpos($chunk, 'saveGame($roomId, $state);');
        $onPos = strpos($chunk, 'tcgOnGameFinished($state);');
        $this->assertNotFalse($savePos, 'saveGame missing in natural finish branch');
        $this->assertNotFalse($onPos, 'tcgOnGameFinished missing in natural finish branch');
        $this->assertLessThan(
            $onPos,
            $savePos,
            'Natural finish must saveGame before tcgOnGameFinished (tournament replay archive race)'
        );
    }
}
