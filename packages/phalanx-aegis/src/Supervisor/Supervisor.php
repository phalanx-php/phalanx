<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\Scope;
use Phalanx\Scope\ScopeIdentity;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;
use Phalanx\Trace\Trace;
use ReflectionFunction;
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
    public function __construct(
        public readonly LedgerStorage $ledger,
        public readonly Trace $trace,
    ) {
    }

    private static function isExternalWait(WaitReason $reason): bool
    {
        return match ($reason->kind) {
            WaitKind::Http,
            WaitKind::Redis,
            WaitKind::Worker,
            WaitKind::Custom => true,
            WaitKind::Delay,
            WaitKind::Postgres,
            WaitKind::Singleflight,
            WaitKind::Lock,
            WaitKind::Channel => false,
        };
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
                $hint = $task->traceName ?? null;
                if (is_string($hint) && $hint !== '') {
                    return $hint;
                }
            }
            return $fallbackId;
        }

        return $class;
    }

    /**
     * @return array{fqcn: string, sourcePath: string, sourceLine: int}
     */
    private static function resolveMetadata(Scopeable|Executable|\Closure $task): array
    {
        if ($task instanceof \Closure) {
            try {
                $reflection = new ReflectionFunction($task);
                $file = $reflection->getFileName();

                return [
                    'fqcn' => \Closure::class,
                    'sourcePath' => $file === false ? '' : $file,
                    'sourceLine' => (int) $reflection->getStartLine(),
                ];
            } catch (Throwable) {
                return ['fqcn' => \Closure::class, 'sourcePath' => '', 'sourceLine' => 0];
            }
        }

        if ($task instanceof Task) {
            return [
                'fqcn' => Task::class,
                'sourcePath' => $task->sourceLocation,
                'sourceLine' => 0,
            ];
        }

        return ['fqcn' => $task::class, 'sourcePath' => '', 'sourceLine' => 0];
    }

    public function registerScope(
        string $scopeId,
        ?string $parentScopeId,
        string $fqcn,
        int $coroutineId,
    ): void {
        $this->ledger->registerScope($scopeId, $parentScopeId, $fqcn, $coroutineId);
    }

    public function disposeScope(string $scopeId): void
    {
        $this->ledger->disposeScope($scopeId);
    }

    public function nextScopeId(): string
    {
        return $this->ledger->nextScopeId();
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
        $id = $this->ledger->nextRunId();
        $resolvedName = $name ?? self::resolveName($task, $id);
        $metadata = self::resolveMetadata($task);
        $scopeId = $parent instanceof ScopeIdentity ? $parent->scopeId : null;

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
            scopeId: $scopeId,
            taskFqcn: $metadata['fqcn'],
            sourcePath: $metadata['sourcePath'],
            sourceLine: $metadata['sourceLine'],
        );

        $this->ledger->register($run);

        if ($parentRunId !== null) {
            $this->ledger->addChild($parentRunId, $id);
        }

        return $run;
    }

    /**
     * Mark the run as actively executing. Called immediately before the
     * task body runs.
     */
    public function markRunning(TaskRun $run): void
    {
        $this->ledger->markRunning($run->id);
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
        $this->assertCanWait($run, $reason);

        $this->ledger->beginWait($run->id, $reason);

        $ledger = $this->ledger;
        $runId = $run->id;
        return static function () use ($ledger, $runId): void {
            $ledger->clearWait($runId);
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

        $this->ledger->addLease($run->id, $lease);
    }

    /**
     * Worker dispatch crosses a process boundary. Any lease that points at
     * process-local state must be released before the task leaves the parent.
     *
     * @throws LeaseViolation PHX-POOL-002
     */
    public function assertCanEnterWorker(TaskRun $run): void
    {
        $existing = $this->ledger->find($run->id);
        if ($existing === null) {
            return;
        }

        foreach ($existing->leases as $held) {
            if (!$held instanceof PoolLease && !$held instanceof TransactionLease) {
                continue;
            }

            throw new LeaseViolation(
                phxCode: 'PHX-POOL-002',
                detail: "process-local lease '{$held->domain}'/'{$held->key}' held across worker dispatch boundary",
                run: $run,
                offending: $held,
            );
        }
    }

    /**
     * Transactions may wait on local coordination primitives, but must not
     * perform unrelated external IO while the transaction lease is held.
     *
     * @throws LeaseViolation PHX-TXN-001
     */
    public function assertCanWait(TaskRun $run, WaitReason $reason): void
    {
        if (!self::isExternalWait($reason)) {
            return;
        }

        $existing = $this->ledger->find($run->id);
        if ($existing === null) {
            return;
        }

        foreach ($existing->leases as $held) {
            if (!$held instanceof TransactionLease) {
                continue;
            }

            throw new LeaseViolation(
                phxCode: 'PHX-TXN-001',
                detail: "external {$reason->kind->value} wait attempted while transaction "
                    . "'{$held->domain}'/'{$held->key}' is open",
                run: $run,
                offending: $held,
            );
        }
    }

    /**
     * Release a lease previously registered against the run. Called by
     * the lease's owner (pool client, lock manager, transaction handle)
     * when the underlying resource is returned.
     */
    public function releaseLease(TaskRun $run, Lease $lease): void
    {
        $this->ledger->releaseLease($run->id, $lease);
    }

    /**
     * Bracketed lease helper: register, run body, release in finally.
     * Pool clients should prefer this form so leases can never leak past
     * a thrown body. Returns whatever the body returns.
     *
     * @template T
     * @param \Closure(): T $body
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

    public function liveScopeCount(): int
    {
        return $this->ledger->liveScopeCount();
    }
}
