<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

use Phalanx\Cancellation\CancellationToken;
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
    private const string SCOPE_TYPE = 'aegis.scope';

    private const string TASK_RUN_TYPE = 'aegis.task_run';

    public readonly RuntimeMemory $memory;

    /** @var array<string, CancellationToken> */
    private array $tokens = [];

    public function __construct(
        int $size = 1024,
        ?RuntimeMemory $memory = null,
    ) {
        $this->memory = $memory ?? RuntimeMemory::forLedgerSize($size);
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
            childIds: $run->childIds,
            leases: $leases,
            startedAt: $run->startedAt,
            endedAt: $run->endedAt,
        );
    }

    /** @param array<string, string> $annotations */
    private static function runState(ManagedResource $resource, array $annotations): RunState
    {
        $state = $annotations['aegis.run_state'] ?? '';
        if ($state !== '') {
            return RunState::from($state);
        }

        return match ($resource->state) {
            ManagedResourceState::Opening => RunState::Pending,
            ManagedResourceState::Active,
            ManagedResourceState::Closing => RunState::Running,
            ManagedResourceState::Closed => RunState::Completed,
            ManagedResourceState::Aborted,
            ManagedResourceState::Aborting => RunState::Cancelled,
            ManagedResourceState::Failed,
            ManagedResourceState::Failing => RunState::Failed,
        };
    }

    private static function fit(string $value, int $length): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length);
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
            type: self::SCOPE_TYPE,
            id: $scopeId,
            parentResourceId: $parentScopeId,
            ownerScopeId: $scopeId,
            state: ManagedResourceState::Active,
        );
        $this->memory->resources->annotate($scopeId, 'aegis.scope_fqcn', self::fit($fqcn, 256));
        $this->memory->resources->annotate(
            $scopeId,
            'aegis.project_path',
            self::fit($this->memory->config->projectPath, 256),
        );
        $this->memory->resources->annotate($scopeId, 'aegis.coroutine_id', $coroutineId);
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
        return $this->memory->resources->liveCount(self::SCOPE_TYPE);
    }

    public function register(TaskRun $run): void
    {
        $this->tokens[$run->id] = $run->cancellation;
        $this->memory->resources->open(
            type: self::TASK_RUN_TYPE,
            id: $run->id,
            parentResourceId: $run->parentId,
            ownerScopeId: $run->scopeId,
            ownerRunId: $run->id,
        );
        $this->annotateRun($run->id, [
            'aegis.run_name' => $run->name,
            'aegis.run_mode' => $run->mode->value,
            'aegis.run_state' => RunState::Pending->value,
            'aegis.parent_run_id' => $run->parentId ?? '',
            'aegis.scope_id' => $run->scopeId ?? '',
            'aegis.task_fqcn' => $run->taskFqcn ?? '',
            'aegis.source_path' => $run->sourcePath ?? '',
            'aegis.source_line' => (string) ($run->sourceLine ?? 0),
            'aegis.started_at' => (string) $run->startedAt,
            'aegis.ended_at' => '',
            'aegis.wait_kind' => '',
            'aegis.wait_detail' => '',
        ]);
    }

    public function addChild(string $parentRunId, string $childRunId): void
    {
        $this->memory->resources->addEdge($parentRunId, $childRunId, 'task_child');
    }

    public function markRunning(string $runId): void
    {
        $this->activateIfOpening($runId);
        $this->memory->resources->annotate($runId, 'aegis.run_state', RunState::Running->value);
        $this->memory->resources->recordEvent($runId, 'run.running');
    }

    public function beginWait(string $runId, WaitReason $reason): void
    {
        $this->activateIfOpening($runId);
        $this->annotateRun($runId, [
            'aegis.run_state' => RunState::Suspended->value,
            'aegis.wait_kind' => $reason->kind->value,
            'aegis.wait_detail' => $reason->detail,
            'aegis.wait_since' => (string) $reason->startedAt,
        ]);
        $this->memory->resources->recordEvent($runId, 'run.suspended', $reason->kind->value, $reason->detail);
    }

    public function clearWait(string $runId): void
    {
        $resource = $this->memory->resources->get($runId);
        if ($resource === null || $resource->state->isTerminal()) {
            return;
        }

        $this->annotateRun($runId, [
            'aegis.run_state' => RunState::Running->value,
            'aegis.wait_kind' => '',
            'aegis.wait_detail' => '',
            'aegis.wait_since' => '',
        ]);
        $this->memory->resources->recordEvent($runId, 'run.resumed');
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
        if ($resource === null || $resource->type !== self::TASK_RUN_TYPE) {
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
            foreach ($this->memory->resources->all(self::TASK_RUN_TYPE) as $resource) {
                $out[] = self::project($this->hydrate($resource));
            }

            return $out;
        }

        $root = $this->find($rootRunId);
        if ($root === null) {
            return [];
        }

        $out = [self::project($root)];
        foreach ($root->childIds as $childId) {
            foreach ($this->tree($childId) as $descendant) {
                $out[] = $descendant;
            }
        }

        return $out;
    }

    public function liveCount(): int
    {
        return $this->memory->resources->liveCount(self::TASK_RUN_TYPE);
    }

    public function reap(string $runId): void
    {
        unset($this->tokens[$runId]);
        $this->memory->resources->release($runId);
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

        $this->memory->resources->annotate($runId, 'aegis.run_state', $runState->value);
        $this->memory->resources->annotate($runId, 'aegis.ended_at', (string) microtime(true));
        $this->memory->resources->annotate($runId, 'aegis.wait_kind', '');
        $this->memory->resources->annotate($runId, 'aegis.wait_detail', '');

        match ($resourceState) {
            ManagedResourceState::Closed => $this->memory->resources->close($runId, $reason),
            ManagedResourceState::Aborted => $this->memory->resources->abort($runId, $reason),
            ManagedResourceState::Failed => $this->memory->resources->fail($runId, $reason),
            default => throw new RuntimeException("unsupported terminal resource state '{$resourceState->value}'"),
        };
    }

    private function hydrate(ManagedResource $resource): TaskRun
    {
        $annotations = $this->memory->resources->annotations($resource->id);
        $waitKind = $annotations['aegis.wait_kind'] ?? '';
        $wait = $waitKind === ''
            ? null
            : new WaitReason(
                WaitKind::from($waitKind),
                $annotations['aegis.wait_detail'] ?? '',
                (float) ($annotations['aegis.wait_since'] ?? $resource->updatedAt),
            );

        $run = new TaskRun(
            id: $resource->id,
            name: $annotations['aegis.run_name'] ?? $resource->id,
            parentId: ($annotations['aegis.parent_run_id'] ?? '') === '' ? null : $annotations['aegis.parent_run_id'],
            mode: DispatchMode::from($annotations['aegis.run_mode'] ?? DispatchMode::Inline->value),
            cancellation: $this->tokens[$resource->id] ?? CancellationToken::none(),
            startedAt: (float) ($annotations['aegis.started_at'] ?? $resource->createdAt),
            scopeId: ($annotations['aegis.scope_id'] ?? '') === '' ? null : $annotations['aegis.scope_id'],
            taskFqcn: ($annotations['aegis.task_fqcn'] ?? '') === '' ? null : $annotations['aegis.task_fqcn'],
            sourcePath: ($annotations['aegis.source_path'] ?? '') === '' ? null : $annotations['aegis.source_path'],
            sourceLine: (int) ($annotations['aegis.source_line'] ?? 0) ?: null,
        );
        $run->state = self::runState($resource, $annotations);
        $run->currentWait = $wait;
        $run->childIds = $this->memory->resources->childIds($resource->id);
        $run->leases = $this->leases($resource->id);
        $endedAt = $annotations['aegis.ended_at'] ?? '';
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
