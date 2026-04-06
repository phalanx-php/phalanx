<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Closure;
use Phalanx\Http\Contract\InputHydrator;
use Phalanx\Scope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * HTTP route handler as an invokable with fn + config.
 *
 * Routes are defined with a closure, Scopeable, Executable, or any invokable
 * class that receives ExecutionScope at dispatch time. The original handler is
 * preserved for introspection (SelfDescribed, OpenAPI generation).
 */
final readonly class Route implements Scopeable
{
    public Closure $callable;

    /** @param Closure|Scopeable|Executable|object $fn Handler: closure, Scopeable, Executable, or any invokable class */
    public function __construct(
        public object $fn,
        public RouteConfig $config = new RouteConfig(),
    ) {
        /** @phpstan-ignore callable.nonCallable */
        $this->callable = $fn instanceof Closure ? $fn : $fn(...);
    }

    public static function of(callable $fn, ?RouteConfig $config = null): self
    {
        return new self($fn(...), $config ?? new RouteConfig());
    }

    public function __invoke(Scope $scope): mixed
    {
        if ($scope instanceof RequestScope) {
            $args = InputHydrator::resolve($this->callable, $scope);

            return ($this->callable)(...$args);
        }

        return ($this->callable)($scope);
    }
}
