<?php

declare(strict_types=1);

namespace Phalanx\Middleware;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Throwable;

/**
 * Snapshots the active task ledger when an exception is thrown,
 * preserving the fiber state for high-fidelity diagnostics.
 */
final readonly class ErrorLedgerSnapshotMiddleware implements TaskMiddleware
{
    public function handle(
        Scopeable|Executable|\Closure $task,
        ExecutionScope $scope,
        \Closure $next,
    ): mixed {
        try {
            return $next($scope);
        } catch (Throwable $e) {
            $snapshots = $scope->service(Supervisor::class)->tree();
            throw $e;
        }
    }
}
