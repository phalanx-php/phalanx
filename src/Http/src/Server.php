<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\Boot\AppContext;

final readonly class Server
{
    private function __construct()
    {
    }

    /** @param array<string,mixed> $context */
    public static function starting(array $context = []): \Phalanx\Http\ApplicationBuilder
    {
        return new \Phalanx\Http\ApplicationBuilder(new AppContext($context));
    }
}
