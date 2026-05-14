<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Pool\BorrowedValue;

/**
 * One entry in the supervisor's ledger. Represents a unit of work that
 * exists, is owned, has a state, may hold leases, and will be reaped.
 *
 * TaskRun is the supervisor's currency. Every primitive — execute,
 * concurrent, race, any, map, settle, defer, singleflight, inWorker,
 * timeout, retry — produces one TaskRun per dispatched task. The ledger
 * holds the canonical state; this object is the handle.
 *
 * Identity (in priority order):
 *   1. Explicit name passed at start (e.g. from a Traceable's traceName)
 *   2. Class FQCN for invokable task objects
 *   3. file:line for Task::of(static fn ...) closures
 *   4. Generated run id as fallback
 *
 * Mutable fields (state, currentWait, leases) are owned by the
 * supervisor and updated through LedgerStorage::update(). Direct mutation
 * outside the supervisor is forbidden — the ledger is the source of truth.
 */
final class TaskRun implements BorrowedValue
{
    /** @var list<Lease> */
    public array $leases = [];

    public RunState $state;

    public ?WaitReason $currentWait = null;

    public ?float $endedAt = null;

    public mixed $value = null;

    public ?\Throwable $error = null;

    /** @var list<TaskRunSnapshot>|null */
    public ?array $failureTree = null;

    public bool $tokenOwnedBySupervisor = false;

    public function __construct(
        private(set) string $id,
        private(set) string $name,
        private(set) ?string $parentId,
        private(set) DispatchMode $mode,
        private(set) CancellationToken $cancellation,
        private(set) float $startedAt,
        private(set) ?string $scopeId = null,
        private(set) ?string $taskFqcn = null,
        private(set) ?string $sourcePath = null,
        private(set) ?int $sourceLine = null,
    ) {
        $this->state = RunState::Pending;
    }

    /**
     * @internal Pool initializer — closure runs in TaskRun scope for private(set) access.
     * @param array{fqcn: string, sourcePath: string, sourceLine: int} $metadata
     * @return \Closure(self): void
     */
    public static function poolInitializer(
        string $id,
        string $name,
        ?string $parentId,
        DispatchMode $mode,
        CancellationToken $cancellation,
        ?string $scopeId,
        array $metadata,
    ): \Closure {
        return static function (self $run) use (
            $id,
            $name,
            $parentId,
            $mode,
            $cancellation,
            $scopeId,
            $metadata,
        ): void {
            $run->id = $id;
            $run->name = $name;
            $run->parentId = $parentId;
            $run->mode = $mode;
            $run->cancellation = $cancellation;
            $run->startedAt = microtime(true);
            $run->scopeId = $scopeId;
            $run->taskFqcn = $metadata['fqcn'];
            $run->sourcePath = $metadata['sourcePath'];
            $run->sourceLine = $metadata['sourceLine'];
            $run->state = RunState::Pending;
            $run->leases = [];
            $run->currentWait = null;
            $run->endedAt = null;
            $run->value = null;
            $run->error = null;
            $run->failureTree = null;
            $run->tokenOwnedBySupervisor = false;
        };
    }

    public function elapsed(): float
    {
        $end = $this->endedAt ?? microtime(true);
        return $end - $this->startedAt;
    }

    public function isTerminal(): bool
    {
        return $this->state->isTerminal();
    }
}
