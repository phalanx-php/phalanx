<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration\Application;

use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use PHPUnit\Framework\Assert;

/**
 * Shared php://temp + StreamOutput plumbing for integration tests that
 * want to assert on what a command wrote. Static helpers, no state.
 */
final class StreamOutputHelper
{
    /** @return resource */
    public static function open(): mixed
    {
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            Assert::fail('Unable to open memory stream.');
        }

        return $stream;
    }

    /** @param resource $stream */
    public static function output(mixed $stream): StreamOutput
    {
        return new StreamOutput($stream, new TerminalEnvironment(columns: 80, lines: 24));
    }

    /** @param resource $stream */
    public static function contents(mixed $stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
