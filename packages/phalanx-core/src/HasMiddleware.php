<?php

declare(strict_types=1);

namespace Phalanx;

/**
 * Per-handler middleware declaration.
 *
 * Returns an ordered list of middleware class-strings to wrap the handler with.
 * Middleware are resolved through the service container at dispatch time, the
 * same lifecycle as handlers themselves.
 *
 * Composition order at dispatch: group-level (outermost) -> config-level ->
 * handler-level (innermost). Class-string identity is used to deduplicate
 * across sources -- a middleware appearing in multiple sources runs exactly
 * once at its innermost declared position.
 */
interface HasMiddleware
{
    /** @var list<class-string> */
    public array $middleware { get; }
}
