<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

/**
 * How the supervisor dispatched a task.
 *
 * Inline      Runs in the calling coroutine. Preserves call-stack identity.
 *             Used by `execute()` and the inline path of `series()` / `waterfall()`.
 *
 * Concurrent  Runs in a fresh sibling coroutine. Each child gets its own
 *             scope object with its own scoped-instance map and its own
 *             cancellation token (linked to parent). Used by `concurrent()`,
 *             `race()`, `any()`, `map()`, `settle()`, `defer()`.
 *
 * Worker      Crosses a process boundary via serialize(). Used by
 *             `inWorker()`, `parallel()`, `settleParallel()`, and
 *             `mapParallel()`. The dispatched task must be a WorkerTask
 *             instance — bare closures cannot cross.
 */
enum DispatchMode: string
{
    case Inline = 'inline';
    case Concurrent = 'concurrent';
    case Worker = 'worker';
}
