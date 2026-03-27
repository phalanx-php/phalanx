<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Closure;
use Phalanx\Scope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * HTTP route handler as an invokable with fn + config.
 *
 * Routes are defined with a closure, Scopeable, or Executable that receives
 * ExecutionScope at dispatch time. File loading receives Scope; handler
 * execution receives ExecutionScope.
 */
final readonly class Route implements Scopeable
{
    public function __construct(
        public Closure|Scopeable|Executable $fn,
        public RouteConfig $config = new RouteConfig(),
    ) {}

    public static function of(callable $fn, ?RouteConfig $config = null): self
    {
        return new self($fn(...), $config ?? new RouteConfig());
    }

    public function __invoke(Scope $scope): mixed
    {
        return ($this->fn)($scope);
    }
}
