<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

use AegisSwoole\Scope\Scope;
use AegisSwoole\Service\ServiceTransformationMiddleware;
use Closure;

class ReplacingMiddleware implements ServiceTransformationMiddleware
{
    /**
     * @param class-string $targetType
     */
    public function __construct(
        public readonly string $targetType,
        public readonly object $replacement,
    ) {
    }

    public function transform(string $type, Closure $next, Scope $scope): object
    {
        if ($type === $this->targetType) {
            return $this->replacement;
        }
        return $next();
    }
}
