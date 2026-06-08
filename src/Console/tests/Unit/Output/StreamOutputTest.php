<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Output;

use Phalanx\Console\Output\StreamOutput;
use Phalanx\Console\Output\TerminalEnvironment;
use Phalanx\Stream\ResourceHandle;
use Phalanx\Stream\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamOutputTest extends TestCase
{
    #[Test]
    public function terminalDimensionsComeFromInjectedEnvironment(): void
    {
        $stream = Stream::memoryBuffer();
        $output = self::streamOutput($stream, new TerminalEnvironment(columns: 120, lines: 40));

        try {
            self::assertSame(120, $output->width());
            self::assertSame(40, $output->height());
        } finally {
            $stream->close();
        }
    }

    #[Test]
    public function appleTerminalDisablesSynchronizedOutputFromInjectedEnvironment(): void
    {
        $stream = Stream::memoryBuffer();
        $output = self::streamOutput(
            $stream,
            new TerminalEnvironment(columns: 80, lines: 24, isTty: true, termProgram: 'Apple_Terminal'),
        );

        try {
            $output->persist('hello');

            self::assertSame("hello\n", $stream->drain());
        } finally {
            $stream->close();
        }
    }

    #[Test]
    public function ttyPersistUsesSynchronizedOutputWhenSupported(): void
    {
        $stream = Stream::memoryBuffer();
        $output = self::streamOutput(
            $stream,
            new TerminalEnvironment(columns: 80, lines: 24, isTty: true),
        );

        try {
            $output->persist('hello');

            self::assertSame("\033[?2026hhello\n\033[?2026l", $stream->drain());
        } finally {
            $stream->close();
        }
    }

    #[Test]
    public function nonTtyUpdatesRemainTransient(): void
    {
        $stream = Stream::memoryBuffer();
        $output = self::streamOutput($stream, new TerminalEnvironment(columns: 80, lines: 24));

        try {
            $output->update('frame 1');
            $output->update('frame 2');
            $output->persist('done');

            self::assertSame("done\n", $stream->drain());
        } finally {
            $stream->close();
        }
    }

    #[Test]
    public function ttyUpdatesClearWrappedVisualRows(): void
    {
        $stream = Stream::memoryBuffer();
        $output = self::streamOutput(
            $stream,
            new TerminalEnvironment(columns: 5, lines: 24, isTty: true, termProgram: 'Apple_Terminal'),
        );

        try {
            $output->update('abcdefghijkl');
            $output->update('x');

            self::assertStringContainsString("\033[2A\r\033[Jx", $stream->drain());
        } finally {
            $stream->close();
        }
    }

    private static function streamOutput(ResourceHandle $stream, TerminalEnvironment $terminal): StreamOutput
    {
        return new StreamOutput($stream->resource(), $terminal);
    }
}
