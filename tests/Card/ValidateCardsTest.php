<?php

declare(strict_types=1);

namespace LLTCG\Tests\Card;

use PHPUnit\Framework\TestCase;

final class ValidateCardsTest extends TestCase
{
    public function testValidateCardsScriptExitsZero(): void {
        $root = dirname(__DIR__, 2);
        $script = $root . '/scripts/validate_cards.php';
        $this->assertFileExists($script);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        $this->assertSame(
            0,
            $code,
            "validate_cards.php failed:\n" . implode("\n", $output)
        );
    }
}
