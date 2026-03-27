<?php

declare(strict_types=1);

namespace Phalanx\Middleware;

use Phalanx\Service\ServiceDefinition;

interface ServiceTransformationMiddleware
{
    public function __invoke(ServiceDefinition $def): ServiceDefinition;
}
