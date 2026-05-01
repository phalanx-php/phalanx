<?php

declare(strict_types=1);

namespace Phalanx;

use Phalanx\Scope\CoroutineScopeRegistry;
use Phalanx\Scope\Scope;

/**
 * Static accessor for the currently-installed scope of the running coroutine.
 *
 * Default rule: don't use this. Pass `$scope` explicitly through your call
 * chain — call-site explicitness is a load-bearing part of the framework's
 * DX contract.
 *
 * Reach for `Phalanx::scope()` only when a service class is invoked from a
 * frame that genuinely cannot carry a scope parameter — for example:
 *
 *   - A static logger inside library code that is called from arbitrary
 *     call depths
 *   - A metric client in a third-party adapter where signatures are fixed
 *
 * For service classes you control, prefer constructor-injecting
 * `DeferredScope` (which delegates the same way but is visible at the
 * class signature) over reaching into this static.
 *
 * Throws OutsideScopeException with an actionable message when called
 * outside any installed scope so the failure mode is loud, not silent.
 */
final class Phalanx
{
    /**
     * Returns the scope installed in the current coroutine.
     *
     * @throws OutsideScopeException If not running inside a Phalanx scope.
     */
    public static function scope(): Scope
    {
        $current = CoroutineScopeRegistry::current();
        if ($current === null) {
            throw new OutsideScopeException();
        }
        return $current;
    }

    /**
     * Returns the scope or null if not in one. Use when calling code may
     * legitimately run outside a scope (background bootstrap paths, CLI
     * setup) and you want a fast-path check rather than catching.
     */
    public static function tryScope(): ?Scope
    {
        return CoroutineScopeRegistry::current();
    }

    private function __construct()
    {
    }
}
