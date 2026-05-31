<?php

declare(strict_types=1);

namespace Phalanx\Harness\Support;

use Phalanx\Panoply\Hash\Canonical;

final class CanonicalHash
{
    /**
     * @param array<int|string, mixed> $canonical
     */
    public static function of(array $canonical): string
    {
        return Canonical::of($canonical);
    }
}
