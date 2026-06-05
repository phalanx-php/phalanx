<?php

declare(strict_types=1);

namespace Phalanx\Http\Contract;

enum InputSource
{
    case Body;
    case Query;

    public static function fromMethod(string $method): self
    {
        return match (strtoupper($method)) {
            'POST', 'PUT', 'PATCH' => self::Body,
            default => self::Query,
        };
    }
}
