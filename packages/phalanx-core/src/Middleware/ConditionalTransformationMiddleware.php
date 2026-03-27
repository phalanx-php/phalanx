<?php

declare(strict_types=1);

namespace Phalanx\Middleware;

use Phalanx\Service\ServiceDefinition;

interface ConditionalTransformationMiddleware extends ServiceTransformationMiddleware
{
    public function applies(ServiceDefinition $def): bool;
}
