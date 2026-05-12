<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Runtime\Identity\AegisAnnotationSid;
use Phalanx\Runtime\Identity\AegisEventSid;
use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Runtime\Memory\ManagedResource;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Runtime\Memory\RuntimeMemory;
use RuntimeException;
use Throwable;

/**
 * OpenSwoole-table supervisor ledger backed by the managed-resource kernel.
 *
 * Task runs and scopes are resource rows. Edges, leases, wait reasons, and
 * task metadata remain primitive rows/annotations owned by Aegis.
 */
final class SwooleTableLedger implements LedgerStorage
{
    private(set) RuntimeMemory $memory;

    /** @var array<string, CancellationToken> */
    private array $tokens = [];

    public function __construct(
        int $size = 1024,
        ?RuntimeMemory $memory = null,
    ) {
        $this->memory = $memory ?? RuntimeMemory::forLedgerSize($size);
    }

    public function nextRunId(): string
    {
        return $this->memory->ids->nextRuntime('run');
    }

    public function nextScopeId(): string
    {
        return $this->memory->ids->nextRuntime('scope');
    }

    public function registerScope(
        string $scopeId,
        ?string $parentScopeId,
        string $fqcn,
        int $coroutineId,
    ): void {
        $this->memory->resources->open(
            type: AegisResourceSid::Scope,
            id: $scopeId,
            parentResourceId: $parentScopeId,
            ownerScopeId: $scopeId,
            state: ManagedResourceState::Active,
        );
        $this->memory->resources->annotate($scopeId, AegisAnnotationSid::ScopeFqcn, self::fit($fqcn, 256));
        $this->memory->resources->annotate(
            $scopeId,
            AegisAnnotationSid::ProjectPath,
            self::fit($this->memory->config->projectPath, 256),
        );
        $this->memory->resources->annotate($scopeId, AegisAnnotationSid::CoroutineId, $coroutineId);
    }

    public function disposeScope(string $scopeId): void
    {
        if ($this->memory->resources->get($scopeId) === null) {
            return;
        }

        $this->memory->resources->close($scopeId, 'scope_disposed');
        $this->memory->resources->release($scopeId);
    }

    public function liveScopeCount(): int
    {
        return $this->memory->resources->liveCount(AegisResourceSid::Scope);
    }

    public function register(TaskRun $run): void
    {
        $this->tokens[$run->id] = $run->cancellation;
        $this->memory->resources->open(
            type: AegisResourceSid::TaskRun,
            id: $run->id,
            parentResourceId: $run->parentId,
            ownerScopeId: $run->scopeId,
            ownerRunId: $run->id,
        );
        if ($run->parentId !== null) {
            $this->memory->resources->addEdge($run->parentId, $run->id, 'task_child');
        }

        $this->annotateRun($run->id, [
            AegisAnnotationSid::RunName->value() => $run->name,
            AegisAnnotationSid::RunMode->value() => $run->mode->value,
            AegisAnnotationSid::RunState->value() => RunState::Pending->value,
            AegisAnnotationSid::ParentRunId->value() => $run->parentId ?? '',
            AegisAnnotationSid::ScopeId->value() => $run->scopeId ?? '',
            AegisAnnotationSid::TaskFqcn->value() => $run->taskFqcn ?? '',
            AegisAnnotationSid::SourcePath->value() => $run->sourcePath ?? '',
            AegisAnnotationSid::SourceLine->value() => (string) ($run->sourceLine ?? 0),
            AegisAnnotationSid::StartedAt->value() => (string) $run->startedAt,
            AegisAnnotationSid::EndedAt->value() => '',
            AegisAnnotationSid::WaitKind->value() => '',
            AegisAnnotationSid::WaitDetail->value() => '',
        ]);
    }

    public function markRunning(string $runId): void
    {
        $this->activateIfOpening($runId);
        $this->memory->resources->annotate($runId, AegisAnnotationSid::RunState, RunState::Running->value);
        $this->memory->resources->recordEvent($runId, AegisEventSid::RunRunning);
    }

    public function beginWait(string $runId, WaitReason $reason): void
    {
        $this->activateIfOpening($runId);
        $this->annotateRun($runId, [
            AegisAnnotationSid::RunState->value() => RunState::Suspended->value,
            AegisAnnotationSid::WaitKind->value() => $reason->kind->value,
            AegisAnnotationSid::WaitDetail->value() => $reason->detail,
            AegisAnnotationSid::WaitSince->value() => (string) $reason->startedAt,
        ]);
        if ($reason->kind !== WaitKind::Delay) {
            $this->memory->resources->recordEvent(
                $runId,
                AegisEventSid::RunSuspended,
                $reason->kind->value,
                $reason->detail,
            );
        }
    }

    public function clearWait(string $runId): void
    {
        $resource = $this->memory->resources->get($runId);
        if ($resource === null || $resource->state->isTerminal()) {
            return;
        }

        $wasDelay = $this->memory->resources->annotation(
            $runId,
            AegisAnnotationSid::WaitKind,
        ) === WaitKind::Delay->value;

        $this->annotateRun($runId, [
            AegisAnnotationSid::RunState->value() => RunState::Running->value,
            AegisAnnotationSid::WaitKind->value() => '',
            AegisAnnotationSid::WaitDetail->value() => '',
            AegisAnnotationSid::WaitSince->value() => '',
        ]);
        if (!$wasDelay) {
            $this->memory->resources->recordEvent($runId, AegisEventSid::RunResumed);
        }
    }

    public function addLease(string $runId, Lease $lease): void
    {
        $this->memory->resources->addLease($runId, $runId, [
            'lease_type' => $lease::class,
            'domain' => $lease->domain,
            'resource_key' => $lease->key,
            'mode' => $lease->mode,
            'acquired_at' => $lease->acquiredAt,
        ]);
    }

    public function releaseLease(string $runId, Lease $lease): void
    {
        $this->memory->resources->releaseLease(
            ownerResourceId: $runId,
            leaseType: $lease::class,
            domain: $lease->domain,
            resourceKey: $lease->key,
        );
    }

    public function complete(string $runId, mixed $value): void
    {
        $this->setTerminal($runId, RunState::Completed, ManagedResourceState::Closed, 'completed');
    }

    public function fail(string $runId, Throwable $error): void
    {
        $this->setTerminal($runId, RunState::Failed, ManagedResourceState::Failed, $error::class);
    }

    public function cancel(string $runId): void
    {
        $this->setTerminal($runId, RunState::Cancelled, ManagedResourceState::Aborted, 'cancelled');
    }

    public function find(string $runId): ?TaskRun
    {
        $resource = $this->memory->resources->get($runId);
        if ($resource === null || $resource->type !== AegisResourceSid::TaskRun->value()) {
            return null;
        }

        return $this->hydrate($resource);
    }

    public function snapshot(string $runId): ?TaskRunSnapshot
    {
        $run = $this->find($runId);
        return $run === null ? null : self::project($run);
    }

    public function tree(?string $rootRunId = null): array
    {
        if ($rootRunId === null) {
            $out = [];
            foreach ($this->memory->resources->all(AegisResourceSid::TaskRun) as $resource) {
                $out[] = self::project($this->hydrate($resource));
            }

            return $out;
        }

        $visited = [];

        return $this->collectSubtree($rootRunId, $visited);
    }

    public function liveCount(): int
    {
        return $this->memory->resources->liveCount(AegisResourceSid::TaskRun);
    }

    public function reap(string $runId): void
    {
        unset($this->tokens[$runId]);
        $this->memory->resources->release($runId);
    }

    private static function project(TaskRun $run): TaskRunSnapshot
    {
        $leases = [];
        foreach ($run->leases as $lease) {
            $leases[] = [
                'domain' => $lease->domain,
                'key' => $lease->key,
                'mode' => $lease->mode,
                'acquiredAt' => $lease->acquiredAt,
            ];
        }

        return new TaskRunSnapshot(
            id: $run->id,
            name: $run->name,
            parentId: $run->parentId,
            mode: $run->mode,
            state: $run->state,
            currentWait: $run->currentWait,
            leases: $leases,
            startedAt: $run->startedAt,
            endedAt: $run->endedAt,
        );
    }

    /** @param array<string, string> $annotations */
    private static function runState(ManagedResource $resource, array $annotations): RunState
    {
        if ($resource->state->isTerminal()) {
            return match ($resource->state) {
                ManagedResourceState::Closed => RunState::Completed,
                ManagedResourceState::Aborted => RunState::Cancelled,
                ManagedResourceState::Failed => RunState::Failed,
                default => throw new RuntimeException(
                    "unsupported terminal resource state '{$resource->state->value}'",
                ),
            };
        }

        $state = $annotations[AegisAnnotationSid::RunState->value()] ?? '';
        if ($state !== '') {
            return RunState::from($state);
        }

        return match ($resource->state) {
            ManagedResourceState::Opening => RunState::Pending,
            ManagedResourceState::Active,
            ManagedResourceState::Closing => RunState::Running,
            ManagedResourceState::Aborting => RunState::Cancelled,
            ManagedResourceState::Failing => RunState::Failed,
            ManagedResourceState::Closed,
            ManagedResourceState::Aborted,
            ManagedResourceState::Failed => throw new RuntimeException(
                "unreachable terminal resource state '{$resource->state->value}'",
            ),
        };
    }

    private static function fit(string $value, int $length): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length);
    }

    /**
     * @param array<string, true> $visited
     * @return list<TaskRunSnapshot>
     */
    private function collectSubtree(string $runId, array &$visited): array
    {
        if (isset($visited[$runId])) {
            return [];
        }
        $visited[$runId] = true;

        $run = $this->find($runId);
        if ($run === null) {
            return [];
        }

        $out = [self::project($run)];
        foreach ($this->memory->resources->childIds($runId) as $childId) {
            foreach ($this->collectSubtree($childId, $visited) as $descendant) {
                $out[] = $descendant;
            }
        }

        return $out;
    }

    /** @param array<string, string> $values */
    private function annotateRun(string $runId, array $values): void
    {
        foreach ($values as $key => $value) {
            $this->memory->resources->annotate($runId, $key, self::fit($value, 256));
        }
    }

    private function activateIfOpening(string $runId): void
    {
        $resource = $this->memory->resources->get($runId);
        if ($resource !== null && $resource->state === ManagedResourceState::Opening) {
            $this->memory->resources->activate($runId);
        }
    }

    private function setTerminal(
        string $runId,
        RunState $runState,
        ManagedResourceState $resourceState,
        string $reason,
    ): void {
        if ($this->memory->resources->get($runId) === null) {
            return;
        }

        match ($resourceState) {
            ManagedResourceState::Closed => $this->memory->resources->close($runId, $reason),
            ManagedResourceState::Aborted => $this->memory->resources->abort($runId, $reason),
            ManagedResourceState::Failed => $this->memory->resources->fail($runId, $reason),
            default => throw new RuntimeException("unsupported terminal resource state '{$resourceState->value}'"),
        };

        $resource = $this->memory->resources->get($runId);
        if ($resource->state !== $resourceState) {
            return;
        }

        $this->memory->resources->annotate($runId, AegisAnnotationSid::RunState, $runState->value);
        $this->memory->resources->annotate($runId, AegisAnnotationSid::EndedAt, (string) microtime(true));
        $this->memory->resources->annotate($runId, AegisAnnotationSid::WaitKind, '');
        $this->memory->resources->annotate($runId, AegisAnnotationSid::WaitDetail, '');
        unset($this->tokens[$runId]);
    }

    private function hydrate(ManagedResource $resource): TaskRun
    {
        $annotations = $this->memory->resources->annotations($resource->id);
        $waitKind = $annotations[AegisAnnotationSid::WaitKind->value()] ?? '';
        $wait = $waitKind === ''
            ? null
            : new WaitReason(
                WaitKind::from($waitKind),
                $annotations[AegisAnnotationSid::WaitDetail->value()] ?? '',
                (float) ($annotations[AegisAnnotationSid::WaitSince->value()] ?? $resource->updatedAt),
            );

        $run = new TaskRun(
            id: $resource->id,
            name: $annotations[AegisAnnotationSid::RunName->value()] ?? $resource->id,
            parentId: ($annotations[AegisAnnotationSid::ParentRunId->value()] ?? '') === ''
                ? null
                : $annotations[AegisAnnotationSid::ParentRunId->value()],
            mode: DispatchMode::from($annotations[AegisAnnotationSid::RunMode->value()] ?? DispatchMode::Inline->value),
            cancellation: $this->tokens[$resource->id] ?? CancellationToken::none(),
            startedAt: (float) ($annotations[AegisAnnotationSid::StartedAt->value()] ?? $resource->createdAt),
            scopeId: ($annotations[AegisAnnotationSid::ScopeId->value()] ?? '') === ''
                ? null
                : $annotations[AegisAnnotationSid::ScopeId->value()],
            taskFqcn: ($annotations[AegisAnnotationSid::TaskFqcn->value()] ?? '') === ''
                ? null
                : $annotations[AegisAnnotationSid::TaskFqcn->value()],
            sourcePath: ($annotations[AegisAnnotationSid::SourcePath->value()] ?? '') === ''
                ? null
                : $annotations[AegisAnnotationSid::SourcePath->value()],
            sourceLine: (int) ($annotations[AegisAnnotationSid::SourceLine->value()] ?? 0) ?: null,
        );
        $run->state = self::runState($resource, $annotations);
        $run->currentWait = $wait;
        $run->leases = $this->leases($resource->id);
        $endedAt = $annotations[AegisAnnotationSid::EndedAt->value()] ?? '';
        $run->endedAt = $endedAt === '' ? $resource->terminalAt : (float) $endedAt;

        return $run;
    }

    /** @return list<Lease> */
    private function leases(string $runId): array
    {
        $leases = [];
        foreach ($this->memory->resources->leases($runId) as $row) {
            $leases[] = match ($row['lease_type']) {
                PoolLease::class => new PoolLease(
                    $row['domain'],
                    $row['resource_key'],
                    $row['acquired_at'],
                ),
                TransactionLease::class => new TransactionLease(
                    $row['domain'],
                    $row['resource_key'],
                    $row['acquired_at'],
                ),
                LockLease::class => new LockLease(
                    $row['domain'],
                    $row['resource_key'],
                    $row['mode'],
                    $row['acquired_at'],
                ),
                default => throw new RuntimeException("unknown lease type '{$row['lease_type']}'"),
            };
        }

        return $leases;
    }
}
