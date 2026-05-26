<?php

declare(strict_types=1);

namespace Phalanx\Dory;

use Phalanx\Boot\AppContext;

final class Dory
{
    /** @param array<string, mixed> $context */
    public static function starting(array $context = []): DoryBuilder
    {
        return new DoryBuilder(new AppContext($context));
    }
}
