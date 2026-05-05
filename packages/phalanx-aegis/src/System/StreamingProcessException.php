<?php

declare(strict_types=1);

namespace Phalanx\System;

use RuntimeException;
use Throwable;

final class StreamingProcessException extends RuntimeException
{
    public static function startFailed(string $command, string $reason): self
    {
        return new self("Failed to start streaming process '{$command}': {$reason}");
    }

    public static function unsupportedPlatform(): self
    {
        return new self('Streaming process pipes are not supported on Windows.');
    }

    public static function invalidCommand(): self
    {
        return new self('Streaming process command must contain at least one non-empty argument.');
    }

    public static function invalidEnvironment(string $key): self
    {
        return new self("Streaming process environment value '{$key}' must be scalar or null.");
    }

    public static function readFailed(string $stream, ?Throwable $previous = null): self
    {
        return new self("Failed to read streaming process {$stream}.", previous: $previous);
    }

    public static function writeFailed(?Throwable $previous = null): self
    {
        return new self('Failed to write streaming process stdin.', previous: $previous);
    }

    public static function writeTimedOut(int $written, int $expected): self
    {
        return new self("Timed out writing streaming process stdin after {$written}/{$expected} bytes.");
    }

    public static function lineTooLong(int $limit): self
    {
        return new self("Streaming process line exceeded {$limit} bytes.");
    }

    public static function alreadyStarted(): self
    {
        return new self('Streaming process has already been started.');
    }

    public static function pipeClosed(string $stream): self
    {
        return new self("Streaming process {$stream} pipe is closed.");
    }

    public static function notRunning(): self
    {
        return new self('Streaming process is not running.');
    }

    public static function stopTimedOut(float $seconds): self
    {
        return new self("Streaming process did not stop within {$seconds}s after SIGKILL.");
    }

    public static function statusFailed(): self
    {
        return new self('Failed to read streaming process status.');
    }
}
