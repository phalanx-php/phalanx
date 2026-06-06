<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Input;

use Phalanx\Console\Input\RawInput;
use Phalanx\Console\Input\ConsoleInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RawInputTest extends TestCase
{
    #[Test]
    public function isInteractiveMirrorsConsoleInput(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $consoleInput = new ConsoleInput($stream);
        $rawInput = new RawInput($consoleInput);

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
        $rawInput = new RawInput($consoleInput);
        $scope = new StubScope();

        $rawInput->restoreOnDispose($scope);

        self::assertCount(1, $scope->disposeCallbacks);

        $scope->dispose();

        self::assertCount(0, $scope->disposeCallbacks);

        fclose($stream);
    }
}
