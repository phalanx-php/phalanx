<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb;

enum SurrealDbLiveAction: string
{
    case Create = 'CREATE';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
    case Close = 'CLOSE';

    public static function fromPayload(mixed $value): self
    {
        if (!is_string($value)) {
            throw new SurrealDbException('SurrealDb live notification action was missing.');
        }

        return self::tryFrom(strtoupper($value))
            ?? throw new SurrealDbException("Unknown SurrealDb live notification action: {$value}");
    }
}
