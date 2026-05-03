<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Input;

use Phalanx\Archon\Input\RawInput;
use Phalanx\Console\Input\ConsoleInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Thin facade over Aegis ConsoleInput. The KeyReader contract is exercised
 * end-to-end by every prompt test via FakeKeyReader. The real read path
 * (System::waitEvent + non-blocking fread) is OpenSwoole-runtime dependent
 * and lives in the integration suite.
 *
 * Unit-level concerns left to verify here:
 *   - non-interactive mirroring from ConsoleInput
 *   - restoreOnDispose registers a teardown callback
 */
final class RawInputTest extends TestCase
{
    #[Test]
    public function isInteractiveMirrorsConsoleInput(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $consoleInput = new ConsoleInput($stream);
        $rawInput     = new RawInput($consoleInput);

        self::assertSame($consoleInput->isInteractive, $rawInput->isInteractive);
        self::assertFalse($rawInput->isInteractive);

        fclose($stream);
    }

    #[Test]
    public function restoreOnDisposeRegistersDisposalCallback(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $consoleInput = new ConsoleInput($stream);
        $rawInput     = new RawInput($consoleInput);
        $scope        = new StubScope();

        $rawInput->restoreOnDispose($scope);

        self::assertCount(1, $scope->disposeCallbacks);

        $scope->dispose();

        self::assertCount(0, $scope->disposeCallbacks);

        fclose($stream);
    }
}
