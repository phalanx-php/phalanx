<?php

declare(strict_types=1);

namespace Phalanx\System;

use RuntimeException;

/**
 * Raised by HttpClient on transport-level failures (DNS, connect, send,
 * receive). Application-level HTTP errors (4xx, 5xx) are returned as
 * regular HttpResponse instances with the appropriate status code so
 * callers can branch on `successful`. This exception is reserved for
 * cases where the round-trip itself did not happen.
 */
final class HttpException extends RuntimeException
{
    public static function connectFailed(string $host, int $port, int $errCode, string $errMsg): self
    {
        return new self(
            "HttpClient: connect to {$host}:{$port} failed (errCode={$errCode}, errMsg={$errMsg})",
        );
    }

    public static function sendFailed(string $method, string $path, int $errCode, string $errMsg): self
    {
        return new self(
            "HttpClient: send {$method} {$path} failed (errCode={$errCode}, errMsg={$errMsg})",
        );
    }

    public static function recvFailed(int $errCode, string $errMsg): self
    {
        return new self(
            "HttpClient: recv failed (errCode={$errCode}, errMsg={$errMsg})",
        );
    }

    public static function streamClosed(): self
    {
        return new self('HttpClient: stream already closed');
    }
}
