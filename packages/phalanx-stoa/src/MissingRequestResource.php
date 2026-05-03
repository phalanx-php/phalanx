<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use RuntimeException;

final class MissingRequestResource extends RuntimeException
{
    public static function forScopeKey(string $key): self
    {
        return new self("Stoa request scope is missing managed resource attribute '{$key}'.");
    }
}
