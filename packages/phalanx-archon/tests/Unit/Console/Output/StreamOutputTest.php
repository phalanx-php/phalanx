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
            new TerminalEnvironment(columns: 80, lines: 24, termProgram: 'Apple_Terminal'),
        );

        try {
            $output->persist('hello');
            rewind($stream);

            self::assertSame("hello\n", stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }
}
