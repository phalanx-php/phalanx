<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

use InvalidArgumentException;

enum RuntimeCapability
{
    case Files;
    case Sleep;
    case Network;
    case Streams;
    case Sockets;
    case Datagrams;
    case Processes;
    case HttpClient;
    case InteractiveStdio;
    case BlockingFunctions;
    case PdoPgsql;
    case PdoSqlite;
    case PdoOdbc;
    case PdoOracle;
    case PdoFirebird;
    case MongoDb;

    public static function fromContextValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('Runtime capability must be a string or RuntimeCapability.');
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
