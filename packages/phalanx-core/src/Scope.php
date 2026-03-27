<?php

declare(strict_types=1);

namespace Phalanx;

use Phalanx\Trace\Trace;

/**
 * Generic scope for service resolution and attribute passing.
 *
 * The minimal interface for most code that doesn't need concurrency primitives.
 * Handlers, middleware, and most tasks type-hint this interface.
 *
 * @see ExecutionScope For concurrency, cancellation, and disposal
 */
interface Scope
{
    /**
     * @template T
     * @param class-string<T> $type
     * @return T
     */
    public function service(string $type): object;

    public function attribute(string $key, mixed $default = null): mixed;

    public function withAttribute(string $key, mixed $value): Scope;

    public function trace(): Trace;
}
