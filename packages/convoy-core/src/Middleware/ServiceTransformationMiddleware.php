<?php

declare(strict_types=1);

namespace Convoy\Middleware;

use Convoy\Service\ServiceDefinition;

interface ServiceTransformationMiddleware
{
    public function __invoke(ServiceDefinition $def): ServiceDefinition;
}
