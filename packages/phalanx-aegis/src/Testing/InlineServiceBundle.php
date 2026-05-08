<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Closure;
use InvalidArgumentException;
use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use ReflectionFunction;

class InlineServiceBundle extends ServiceBundle
{
    public function __construct(
        private Closure $registrar,
    ) {
        $rf = new ReflectionFunction($registrar);
        if ($rf->getClosureThis() !== null) {
            throw new InvalidArgumentException(
                'Services closure must be static to prevent reference cycles.',
            );
        }
    }

    public function services(Services $services, AppContext $context): void
    {
        ($this->registrar)($services, $context);
    }
}
