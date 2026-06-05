<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\Boot\AppContext;

final readonly class Http
{
    private function __construct()
    {
    }

    /** @param array<string,mixed> $context */
    public static function starting(array $context = []): HttpApplicationBuilder
    {
        return new HttpApplicationBuilder(new AppContext($context));
    }
}
