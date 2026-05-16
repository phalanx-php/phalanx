<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

use InvalidArgumentException;
use OpenSwoole\Runtime;

enum RuntimeCapability: int
{
    case Files = Runtime::HOOK_FILE;
    case Sleep = Runtime::HOOK_SLEEP;
    case Network = Runtime::HOOK_TCP | Runtime::HOOK_UNIX | Runtime::HOOK_SSL | Runtime::HOOK_TLS;
    case Streams = Runtime::HOOK_STREAM_FUNCTION;
    case Sockets = Runtime::HOOK_SOCKETS;
    case Datagrams = Runtime::HOOK_UDP | Runtime::HOOK_UDG;
    case Processes = Runtime::HOOK_PROC;
    case HttpClient = Runtime::HOOK_TCP
        | Runtime::HOOK_SSL
        | Runtime::HOOK_TLS
        | Runtime::HOOK_CURL
        | Runtime::HOOK_NATIVE_CURL;
    case InteractiveStdio = Runtime::HOOK_STDIO;
    case BlockingFunctions = Runtime::HOOK_BLOCKING_FUNCTION;

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
