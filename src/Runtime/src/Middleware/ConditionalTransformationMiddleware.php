<?php

declare(strict_types=1);

namespace Phalanx\Middleware;

use Phalanx\Service\CompiledServiceConfig;

interface ConditionalTransformationMiddleware extends ServiceTransformationMiddleware
{
    public function applies(CompiledServiceConfig $config): bool;
}
