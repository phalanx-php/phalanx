<?php

declare(strict_types=1);

namespace Phalanx;

/**
 * Per-handler middleware declaration.
 *
 * Returns an ordered list of middleware class-strings to wrap the handler
 * with. Middleware are resolved through the service container at dispatch
 * time, the same lifecycle as handlers themselves.
 *
 * Composition order at dispatch (outermost first):
 *   group-level (RouteGroup::wrap / HandlerGroup::wrap)
 *   config-level (HandlerConfig::$middleware)
 *   handler-instance HasMiddleware (innermost, runs immediately before handler)
 *
 * Class-string identity is used to deduplicate across the three sources --
 * a middleware appearing in multiple layers runs exactly ONCE at its
 * innermost declared position.
 */
interface HasMiddleware
{
    /** @var list<class-string> */
    public array $middleware { get; }
}
