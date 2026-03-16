<?php

declare(strict_types=1);

namespace Convoy\Middleware;

use Convoy\Service\ServiceDefinition;

interface ConditionalTransformationMiddleware extends ServiceTransformationMiddleware
{
    public function applies(ServiceDefinition $def): bool;
}
