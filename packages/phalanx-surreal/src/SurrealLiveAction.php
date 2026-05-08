<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

enum SurrealLiveAction: string
{
    case Create = 'CREATE';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
    case Close = 'CLOSE';

    public static function fromPayload(mixed $value): self
    {
        if (!is_string($value)) {
            throw new SurrealException('Surreal live notification action was missing.');
        }

        return self::tryFrom(strtoupper($value))
            ?? throw new SurrealException("Unknown Surreal live notification action: {$value}");
    }
}
