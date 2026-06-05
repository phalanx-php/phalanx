<?php

declare(strict_types=1);

namespace Phalanx\Http;

use RuntimeException;

final class MissingRequestResource extends RuntimeException
{
    public static function forScopeKey(string $key): self
    {
        return new self("Http request scope is missing managed request resource '{$key}'.");
    }
}
