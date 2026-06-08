<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Input;

use Phalanx\Console\Input\RawInput;
use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Stream\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RawInputTest extends TestCase
{
    #[Test]
    public function isInteractiveMirrorsConsoleInput(): void
    {
        $stream = Stream::memoryInput();

        $consoleInput = new ConsoleInput($stream->resource());
        $rawInput = new RawInput($consoleInput);

        self::assertSame($consoleInput->isInteractive, $rawInput->isInteractive);
        self::assertFalse($rawInput->isInteractive);

        $stream->close();
    }

    #[Test]
    public function restoreOnDisposeRegistersDisposalCallback(): void
    {
        $stream = Stream::memoryInput();

        $consoleInput = new ConsoleInput($stream->resource());
        $rawInput = new RawInput($consoleInput);
        $scope = new StubScope();

        $rawInput->restoreOnDispose($scope);

        self::assertCount(1, $scope->disposeCallbacks);

        $scope->dispose();

        self::assertCount(0, $scope->disposeCallbacks);

        $stream->close();
    }
}
