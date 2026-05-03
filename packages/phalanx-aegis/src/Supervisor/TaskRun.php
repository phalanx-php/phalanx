<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

use Phalanx\Cancellation\CancellationToken;

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
 * Mutable fields (state, currentWait, leases, childIds) are owned by the
 * supervisor and updated through LedgerStorage::update(). Direct mutation
 * outside the supervisor is forbidden — the ledger is the source of truth.
 */
final class TaskRun
{
    /** @var list<Lease> */
    public array $leases = [];

    /** @var list<string> */
    public array $childIds = [];

    public RunState $state;

    public ?WaitReason $currentWait = null;

    public ?float $endedAt = null;

    public mixed $value = null;

    public ?\Throwable $error = null;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $parentId,
        public readonly DispatchMode $mode,
        public readonly CancellationToken $cancellation,
        public readonly float $startedAt,
        public readonly ?string $scopeId = null,
        public readonly ?string $taskFqcn = null,
        public readonly ?string $sourcePath = null,
        public readonly ?int $sourceLine = null,
    ) {
        $this->state = RunState::Pending;
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
