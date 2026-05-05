<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Console\Output;

use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamOutputTest extends TestCase
{
    #[Test]
    public function terminalDimensionsComeFromInjectedEnvironment(): void
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Unable to open memory stream.');
        }

        $output = new StreamOutput($stream, new TerminalEnvironment(columns: 120, lines: 40));

        try {
            self::assertSame(120, $output->width());
            self::assertSame(40, $output->height());
        } finally {
            fclose($stream);
        }
    }

    #[Test]
    public function appleTerminalDisablesSynchronizedOutputFromInjectedEnvironment(): void
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Unable to open memory stream.');
        }

        $output = new StreamOutput(
            $stream,
            new TerminalEnvironment(columns: 80, lines: 24, isTty: true, termProgram: 'Apple_Terminal'),
        );

        try {
            $output->persist('hello');
            rewind($stream);

            self::assertSame("hello\n", stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    #[Test]
    public function ttyPersistUsesSynchronizedOutputWhenSupported(): void
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Unable to open memory stream.');
        }

        $output = new StreamOutput(
            $stream,
            new TerminalEnvironment(columns: 80, lines: 24, isTty: true),
        );

        try {
            $output->persist('hello');
            rewind($stream);

            self::assertSame("\033[?2026hhello\n\033[?2026l", stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    #[Test]
    public function nonTtyUpdatesRemainTransient(): void
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Unable to open memory stream.');
        }

        $output = new StreamOutput($stream, new TerminalEnvironment(columns: 80, lines: 24));

        try {
            $output->update('frame 1');
            $output->update('frame 2');
            $output->persist('done');
            rewind($stream);

            self::assertSame("done\n", stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    #[Test]
    public function ttyUpdatesClearWrappedVisualRows(): void
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Unable to open memory stream.');
        }

        $output = new StreamOutput(
            $stream,
            new TerminalEnvironment(columns: 5, lines: 24, isTty: true, termProgram: 'Apple_Terminal'),
        );

        try {
            $output->update('abcdefghijkl');
            $output->update('x');
            rewind($stream);

            self::assertStringContainsString("\033[2A\r\033[Jx", (string) stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }
}
