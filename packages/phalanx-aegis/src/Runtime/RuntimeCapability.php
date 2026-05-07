<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

use InvalidArgumentException;

enum RuntimeCapability: string
{
    case Network = 'network';
    case HttpClient = 'http-client';
    case Streams = 'streams';
    case Files = 'files';
    case Sockets = 'sockets';
    case Datagrams = 'datagrams';
    case Processes = 'processes';
    case InteractiveStdio = 'interactive-stdio';
    case Sleep = 'sleep';
    case BlockingFunctions = 'blocking-functions';

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
            $caseValue = strtolower(str_replace(['-', '_', ' '], '', $case->value));

            if ($normalized === $caseName || $normalized === $caseValue) {
                return $case;
            }
        }

        throw new InvalidArgumentException("Unknown runtime capability: {$value}");
    }
}
