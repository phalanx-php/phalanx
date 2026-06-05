<?php

declare(strict_types=1);

namespace Phalanx\DevServer;

use Phalanx\Boot\AppContext;

final class DevServer
{
    private function __construct()
    {
    }

    /** @param array<string,mixed> $context */
    public static function starting(array $context = []): DevServerApplicationBuilder
    {
        return new DevServerApplicationBuilder(AppContext::fromProject($context));
    }
}
