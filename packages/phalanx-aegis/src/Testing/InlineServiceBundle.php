<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Closure;
use InvalidArgumentException;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use ReflectionFunction;

final class InlineServiceBundle implements ServiceBundle
{
    public function __construct(
        private readonly Closure $registrar,
    ) {
        $rf = new ReflectionFunction($registrar);
        if ($rf->getClosureThis() !== null) {
            throw new InvalidArgumentException(
                'Services closure must be static to prevent reference cycles.',
            );
        }
    }

    public function services(Services $services, array $context): void
    {
        ($this->registrar)($services, $context);
    }
}
