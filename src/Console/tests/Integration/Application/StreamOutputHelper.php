<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Integration\Application;

use Phalanx\Console\Output\StreamOutput;
use Phalanx\Console\Output\TerminalEnvironment;
use Phalanx\Stream\ResourceHandle;
use Phalanx\Stream\Stream;

final class StreamOutputHelper
{
    public static function open(): ResourceHandle
    {
        return Stream::captureBuffer();
    }

    public static function output(ResourceHandle $stream): StreamOutput
    {
        return new StreamOutput($stream->resource(), new TerminalEnvironment(columns: 80, lines: 24));
    }

    public static function contents(ResourceHandle $stream): string
    {
        return $stream->drain();
    }
}
