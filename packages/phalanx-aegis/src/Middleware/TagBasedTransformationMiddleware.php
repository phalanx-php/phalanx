<?php

declare(strict_types=1);

namespace Phalanx\Middleware;

use Phalanx\Service\CompiledServiceConfig;

abstract class TagBasedTransformationMiddleware implements ConditionalTransformationMiddleware
{
    public function __construct(
        private readonly string $tag,
    ) {
    }

    public function applies(CompiledServiceConfig $config): bool
    {
        return in_array($this->tag, $config->tagsList, true);
    }
}
