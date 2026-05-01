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
 * Sibling-isolation invariant:
 *   When start() is called with DispatchMode::Concurrent, the caller is
 *   responsible for handing the child its own scope object with its own
 *   scoped-instance map, its own dispose stack, and its own cancellation
 *   token linked to the parent's token as a source. The supervisor does
 *   NOT amplify pool depth — pools live in the singleton container,
 *   which is shared across siblings. See the "Pool & Scope Discipline"
 *   section of the package README for the full invariants.
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
        Scopeable|Executable|\Closure $task,
        Scope $parent,
        DispatchMode $mode,
        ?string $name = null,
        ?string $parentRunId = null,
    ): TaskRun {
        $id = self::nextId();
        $resolvedName = $name ?? self::resolveName($task, $id);

        $parentToken = $parent instanceof \Phalanx\Scope\Cancellable
            ? $parent->cancellation()
            : CancellationToken::none();

        $run = new TaskRun(
            id: $id,
            name: $resolvedName,
            parentId: $parentRunId,
            mode: $mode,
            cancellation: CancellationToken::composite($parentToken),
            startedAt: microtime(true),
        );

        $this->ledger->register($run);

        if ($parentRunId !== null) {
            $this->ledger->update($parentRunId, static function (TaskRun $parent) use ($id): void {
                $parent->childIds[] = $id;
            });
        }

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

        // Emit PHX-LEASE-001 for any lease still held at reap time —
        // indicates the lease owner forgot to release in a finally
        // block. The supervisor doesn't free the underlying resource
        // (it doesn't own pool connections / locks), but the trace
        // event makes the leak visible and attributable to a specific
        // run by name.
        if ($run->leases !== []) {
            foreach ($run->leases as $orphaned) {
                $this->trace->log(
                    \Phalanx\Trace\TraceType::Defer,
                    'PHX-LEASE-001',
                    [
                        'run' => $run->id,
                        'task' => $run->name,
                        'domain' => $orphaned->domain,
                        'key' => $orphaned->key,
                        'mode' => $orphaned->mode,
                        'detail' => 'lease still held at reap; release missing in owner code',
                    ],
                );
            }
        }

        $this->ledger->reap($run->id);
    }

    /**
     * Register a lease against an active run. Pool / transaction / lock
     * acquisitions call this; the supervisor checks the run's existing
     * leases for violations before recording.
     *
     * @throws LeaseViolation
     *   PHX-POOL-001  Nested acquire of the same pool by the same run.
     *   PHX-LOCK-001  Lock acquire whose key sorts before an already-held
     *                 lock in the same domain — the unsorted acquire would
     *                 allow the canonical deadlock pattern.
     */
    public function registerLease(TaskRun $run, Lease $lease): void
    {
        $existing = $this->ledger->find($run->id);
        if ($existing === null) {
            return;
        }

        if ($lease instanceof PoolLease) {
            foreach ($existing->leases as $held) {
                if ($held instanceof PoolLease && $held->domain === $lease->domain) {
                    throw new LeaseViolation(
                        phxCode: 'PHX-POOL-001',
                        detail: "nested acquire from pool '{$lease->domain}' (already holds connection #{$held->key})",
                        run: $run,
                        offending: $lease,
                    );
                }
            }
        }

        if ($lease instanceof LockLease) {
            foreach ($existing->leases as $held) {
                if (!$held instanceof LockLease) {
                    continue;
                }
                if ($held->domain !== $lease->domain) {
                    continue;
                }
                // Re-entry on the same key is allowed (read after read,
                // upgrade-style write after read on same key is held by
                // the lock manager, not us).
                if ($held->key === $lease->key) {
                    continue;
                }
                if (strcmp($lease->key, $held->key) < 0) {
                    throw new LeaseViolation(
                        phxCode: 'PHX-LOCK-001',
                        detail: "out-of-order lock acquire in domain '{$lease->domain}': "
                              . "would acquire '{$lease->key}' while holding '{$held->key}' "
                              . "— canonical-sort multi-key acquires to prevent deadlock",
                        run: $run,
                        offending: $lease,
                    );
                }
            }
        }

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
     * Bracketed lease helper: register, run body, release in finally.
     * Pool clients should prefer this form so leases can never leak past
     * a thrown body. Returns whatever the body returns.
     *
     * @template T
     * @param Closure(): T $body
     * @return T
     */
    public function withLease(TaskRun $run, Lease $lease, \Closure $body): mixed
    {
        $this->registerLease($run, $lease);
        try {
            return $body();
        } finally {
            $this->releaseLease($run, $lease);
        }
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

    private static function resolveName(Scopeable|Executable|\Closure $task, string $fallbackId): string
    {
        if ($task instanceof \Closure) {
            try {
                $reflection = new \ReflectionFunction($task);
                $file = $reflection->getFileName();
                if ($file !== false) {
                    return basename($file) . ':' . $reflection->getStartLine();
                }
            } catch (\Throwable) {
                // fall through
            }
            return $fallbackId;
        }

        $class = $task::class;

        if (str_contains($class, '@anonymous')) {
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
