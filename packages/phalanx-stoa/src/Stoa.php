<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\Boot\AppContext;

final readonly class Stoa
{
    private function __construct()
    {
    }

    public static function starting(AppContext $context = new AppContext()): StoaApplicationBuilder
    {
        return new StoaApplicationBuilder($context);
    }
}
