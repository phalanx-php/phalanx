<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\Scope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;
use Throwable;

/**
 * Coordinator for every dispatchable task in a Phalanx application.
 *
 * Every primitive that runs user-visible work — execute, concurrent,
 * race, any, map, series, waterfall, settle, defer, singleflight,
 * inWorker, timeout, retry — routes through Supervisor::start(). One
 * execution path, one lease ledger, one cancellation source, one trace
 * correlation per running thing.
 *
 * This is the load-bearing 0.2 invariant. Any primitive that bypasses
 * this path silently drops middleware, lease tracking, wait reasons,
 * worker placement inference, and the live task-tree diagnostic surface.
 *
 * State of the slice (current commit):
 *   - Skeleton only. Methods declare the API surface and contract; they
 *     are not yet wired into ExecutionLifecycleScope's primitives.
 *   - InProcessLedger is functional and tested.
 *   - Subsequent slices wire execute(), concurrent(), and the rest of
 *     the primitives through start()/join()/cancel()/reap().
 *
 * Sibling-isolation invariant (Decision 5):
 *   When start() is called with DispatchMode::Concurrent, the caller is
 *   responsible for handing the child its own scope object with its own
 *   scoped-instance map, its own dispose stack, and its own cancellation
 *   token linked to the parent's token as a source. The supervisor does
 *   NOT amplify pool depth — pools live in the singleton container,
 *   which is shared across siblings. See packages/phalanx-aegis/CLAUDE.md
 *   "Pool & Scope Discipline" for the full invariants.
 */
final class Supervisor
{
    private static int $idSeq = 0;

    public function __construct(
        public readonly LedgerStorage $ledger,
        public readonly Trace $trace,
    ) {
    }

    /**
     * Open a new TaskRun. Creates the run record, derives the child's
     * cancellation token from the parent's, opens a trace span, returns
     * the handle. The caller is responsible for invoking the task body
     * and calling join() on success or fail()/cancel() on error.
     *
     * Wiring (subsequent slice): the framework's primitives call this
     * before invoking the task body. The TaskMiddleware chain runs
     * around the body; on body return, complete() + reap() finalize.
     *
     * @param Scopeable|Executable $task    The dispatchable task.
     * @param Scope                $parent  Parent scope; cancellation source and attribute carrier.
     * @param DispatchMode         $mode    How the task is being dispatched.
     * @param string|null          $name    Optional explicit name (e.g. from Traceable). Falls
     *                                      back to class FQCN, then a generated id.
     */
    public function start(
        Scopeable|Executable $task,
        Scope $parent,
        DispatchMode $mode,
        ?string $name = null,
    ): TaskRun {
        $id = self::nextId();
        $resolvedName = $name ?? self::resolveName($task, $id);

        $parentToken = $parent instanceof \Phalanx\Scope\Cancellable
            ? $parent->cancellation()
            : CancellationToken::none();

        $run = new TaskRun(
            id: $id,
            name: $resolvedName,
            parentId: null,
            mode: $mode,
            cancellation: CancellationToken::composite($parentToken),
            startedAt: microtime(true),
        );

        $this->ledger->register($run);
        return $run;
    }

    /**
     * Mark the run as actively executing. Called immediately before the
     * task body runs.
     */
    public function markRunning(TaskRun $run): void
    {
        $this->ledger->update($run->id, static function (TaskRun $r): void {
            $r->state = RunState::Running;
        });
    }

    /**
     * Record that the run is currently parked on a wait point. Called by
     * the framework's suspend wrappers (Suspendable::call(), delay(),
     * worker submit, singleflight, lock acquire, etc.).
     *
     * Returns a closure that clears the wait reason when the suspend
     * completes — designed to be invoked in a finally block:
     *
     *   $clear = $supervisor->beginWait($run, WaitReason::http('GET', $url));
     *   try { return $client->execute($url); }
     *   finally { $clear(); }
     */
    public function beginWait(TaskRun $run, WaitReason $reason): \Closure
    {
        $this->ledger->update($run->id, static function (TaskRun $r) use ($reason): void {
            $r->state = RunState::Suspended;
            $r->currentWait = $reason;
        });

        $ledger = $this->ledger;
        $runId = $run->id;
        return static function () use ($ledger, $runId): void {
            $ledger->update($runId, static function (TaskRun $r): void {
                if ($r->state === RunState::Suspended) {
                    $r->state = RunState::Running;
                    $r->currentWait = null;
                }
            });
        };
    }

    /**
     * Mark the run completed with a return value. Reap is the caller's
     * responsibility once any post-complete cleanup is done.
     */
    public function complete(TaskRun $run, mixed $value): void
    {
        $this->ledger->complete($run->id, $value);
    }

    /**
     * Mark the run failed with a throwable.
     */
    public function fail(TaskRun $run, Throwable $error): void
    {
        $this->ledger->fail($run->id, $error);
    }

    /**
     * Cancel the run. Cancels the run's cancellation token (which
     * cascades to substrate handles via registered listeners) and marks
     * the ledger state. Idempotent.
     */
    public function cancel(TaskRun $run): void
    {
        $run->cancellation->cancel();
        $this->ledger->cancel($run->id);
    }

    /**
     * Final cleanup for a terminal run. Releases any still-held leases
     * (a violation — they should have been released by their owner —
     * but we don't leak), removes the run from the ledger.
     */
    public function reap(TaskRun $run): void
    {
        if (!$run->isTerminal()) {
            // Defensive: caller should not reap a running run. Treat as
            // an implicit cancel rather than silently leaving it dangling.
            $this->cancel($run);
        }

        if ($run->leases !== []) {
            // Wiring (subsequent slice): emit a PHX-LEASE-001 trace event
            // for each leftover lease — these indicate a missing
            // release somewhere in the lease's owner code.
        }

        $this->ledger->reap($run->id);
    }

    /**
     * Register a lease against an active run. Pool / transaction / lock
     * acquisitions call this. Detection of nested-acquire and other
     * violations happens here in subsequent slices via the appropriate
     * PHX error codes.
     */
    public function registerLease(TaskRun $run, Lease $lease): void
    {
        $this->ledger->update($run->id, static function (TaskRun $r) use ($lease): void {
            $r->leases[] = $lease;
        });
    }

    /**
     * Release a lease previously registered against the run. Called by
     * the lease's owner (pool client, lock manager, transaction handle)
     * when the underlying resource is returned.
     */
    public function releaseLease(TaskRun $run, Lease $lease): void
    {
        $this->ledger->update($run->id, static function (TaskRun $r) use ($lease): void {
            foreach ($r->leases as $i => $held) {
                if ($held === $lease) {
                    array_splice($r->leases, $i, 1);
                    return;
                }
            }
        });
    }

    /**
     * Detached snapshot of the live task tree. Powers diagnostic surfaces.
     *
     * @return list<TaskRunSnapshot>
     */
    public function tree(?string $rootRunId = null): array
    {
        return $this->ledger->tree($rootRunId);
    }

    public function liveCount(): int
    {
        return $this->ledger->liveCount();
    }

    private static function nextId(): string
    {
        return 'run-' . str_pad((string) ++self::$idSeq, 6, '0', STR_PAD_LEFT);
    }

    private static function resolveName(Scopeable|Executable $task, string $fallbackId): string
    {
        $class = $task::class;

        if (str_contains($class, '@anonymous')) {
            // Anonymous class — try to surface a useful name via the
            // owning task's traceName property if present.
            if (property_exists($task, 'traceName')) {
                /** @phpstan-ignore-next-line property hooks resolve at access */
                $hint = $task->traceName ?? null;
                if (is_string($hint) && $hint !== '') {
                    return $hint;
                }
            }
            return $fallbackId;
        }

        return $class;
    }
}
