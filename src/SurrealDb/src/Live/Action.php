<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Live;

enum Action: string
{
    case Create = 'CREATE';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
    case Close = 'CLOSE';

    public static function fromPayload(mixed $value): self
    {
        if (!is_string($value)) {
            throw new \Phalanx\SurrealDb\Exception('SurrealDb live notification action was missing.');
        }

        return self::tryFrom(strtoupper($value))
            ?? throw new \Phalanx\SurrealDb\Exception("Unknown SurrealDb live notification action: {$value}");
    }
}
