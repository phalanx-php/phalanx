<?php

declare(strict_types=1);

namespace Phalanx\Middleware;

use Phalanx\Service\ServiceDefinition;

abstract class TagBasedTransformationMiddleware implements ConditionalTransformationMiddleware
{
    public function __construct(
        private readonly string $tag,
    ) {
    }

    public function applies(ServiceDefinition $def): bool
    {
        return $def->hasTag($this->tag);
    }
}
