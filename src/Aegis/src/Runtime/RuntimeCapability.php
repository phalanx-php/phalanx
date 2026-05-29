<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

use InvalidArgumentException;

enum RuntimeCapability: int
{
    case Files = SWOOLE_HOOK_FILE;
    case Sleep = SWOOLE_HOOK_SLEEP;
    case Network = SWOOLE_HOOK_TCP | SWOOLE_HOOK_UNIX | SWOOLE_HOOK_SSL | SWOOLE_HOOK_TLS;
    case Streams = SWOOLE_HOOK_STREAM_FUNCTION;
    case Sockets = SWOOLE_HOOK_SOCKETS;
    case Datagrams = SWOOLE_HOOK_UDP | SWOOLE_HOOK_UDG;
    case Processes = SWOOLE_HOOK_PROC;
    case HttpClient = SWOOLE_HOOK_TCP
        | SWOOLE_HOOK_SSL
        | SWOOLE_HOOK_TLS
        | SWOOLE_HOOK_CURL
        | SWOOLE_HOOK_NATIVE_CURL;
    case InteractiveStdio = SWOOLE_HOOK_STDIO;
    // OpenSwoole called this HOOK_BLOCKING_FUNCTION; Swoole 6 exposes the same
    // functionality (hooking gethostbyname, getaddrinfo, etc.) as SWOOLE_HOOK_NET_FUNCTION.
    case BlockingFunctions = SWOOLE_HOOK_NET_FUNCTION;

    public static function fromContextValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_int($value)) {
            return self::tryFrom($value)
                ?? throw new InvalidArgumentException("Unknown runtime capability: {$value}");
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('Runtime capability must be a string, int, or RuntimeCapability.');
        }

        $normalized = strtolower(str_replace(['-', '_', ' '], '', $value));
        foreach (self::cases() as $case) {
            $caseName = strtolower($case->name);

            if ($normalized === $caseName) {
                return $case;
            }
        }

        throw new InvalidArgumentException("Unknown runtime capability: {$value}");
    }
}
