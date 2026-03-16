<?php

declare(strict_types=1);

namespace Convoy\Middleware;

use Convoy\Service\ServiceDefinition;

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
