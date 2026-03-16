<?php

declare(strict_types=1);

namespace Convoy\Exception;

use Throwable;

class ServiceNotFoundException extends \RuntimeException
{
    public function __construct(
        public readonly string $serviceType,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Service not found: $serviceType", 0, $previous);
    }
}
