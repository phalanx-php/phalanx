<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

use Phalanx\Boot\AppContext;

final readonly class Skopos
{
    private function __construct()
    {
    }

    /** @param array<string,mixed> $context */
    public static function starting(array $context = []): SkoposApplicationBuilder
    {
        return new SkoposApplicationBuilder(new AppContext($context));
    }
}
