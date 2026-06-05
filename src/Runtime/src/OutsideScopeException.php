<?php

declare(strict_types=1);

namespace Phalanx;

use RuntimeException;

/**
 * Thrown by `Phalanx::scope()` when called from a coroutine that has no
 * installed scope.
 *
 * Almost always indicates one of:
 *
 *   1. Code is running outside any `$app->createScope()` / `$scope->execute()` /
 *      `$scope->concurrent()` body — for example during application bootstrap,
 *      from a top-level CLI script, or from a raw `Coroutine::create()` that
 *      forgot to install the parent scope.
 *
 *   2. A library-level call assumed scope context that the caller never
 *      arranged. Make scope passing explicit at the call site instead.
 *
 * The message is actionable: it tells the reader exactly which two paths
 * lead here and what to do.
 */
final class OutsideScopeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Phalanx::scope() called outside any installed scope. '
            . 'Either pass a Scope explicitly through your call chain, or '
            . 'wrap the work in $app->createScope() / $scope->execute(). '
            . 'If you spawned a raw Coroutine::create(), the parent scope '
            . 'must be re-installed via CoroutineScopeRegistry::install() '
            . '— prefer $scope->go(...) to avoid this entirely.',
        );
    }
}
