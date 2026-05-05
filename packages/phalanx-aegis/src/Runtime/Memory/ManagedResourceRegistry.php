<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

use OpenSwoole\Coroutine;
use OpenSwoole\Exception as OpenSwooleException;
use Phalanx\Runtime\Identity\AegisEventSid;
use Phalanx\Runtime\Identity\RuntimeAnnotationId;
use Phalanx\Runtime\Identity\RuntimeEventId;
use Phalanx\Runtime\Identity\RuntimeResourceId;
use RuntimeException;

final readonly class ManagedResourceRegistry
{
    public function __construct(
        private ManagedSwooleTables $tables,
        private RuntimeSymbols $symbols,
        private RuntimeLifecycleEvents $events,
        private RuntimeIds $ids,
        private ManagedResourceTransitionGate $gate,
        private ManagedResourceTransitionLocks $locks,
    ) {
    }

    public function open(
        RuntimeResourceId|string $type,
        ?string $id = null,
        ?string $parentResourceId = null,
        ?string $ownerScopeId = null,
        ?string $ownerRunId = null,
        ManagedResourceState $state = ManagedResourceState::Opening,
    ): ManagedResourceHandle {
        return $this->gate->open(
            id: $id ?? $this->ids->nextRuntime('resource'),
            type: self::assertResourceType($type),
            parentResourceId: $parentResourceId,
            ownerScopeId: $ownerScopeId,
            ownerRunId: $ownerRunId,
            state: $state,
            coroutineId: Coroutine::getCid(),
        );
    }

    public function activate(ManagedResourceHandle|string $resource): ManagedResourceHandle
    {
        return $this->gate->transition($resource, ManagedResourceState::Active);
    }

    public function close(ManagedResourceHandle|string $resource, string $reason = ''): ManagedResourceHandle
    {
        return $this->gate->transition(
            resource: $resource,
            to: ManagedResourceState::Closed,
            outcome: 'closed',
            reason: $reason,
        );
    }

    public function abort(ManagedResourceHandle|string $resource, string $reason = ''): ManagedResourceHandle
    {
        return $this->gate->transition(
            resource: $resource,
            to: ManagedResourceState::Aborted,
            outcome: 'aborted',
            reason: $reason,
        );
    }

    public function fail(ManagedResourceHandle|string $resource, string $reason = ''): ManagedResourceHandle
    {
        return $this->gate->transition(
            resource: $resource,
            to: ManagedResourceState::Failed,
            outcome: 'failed',
            reason: $reason,
        );
    }

    /**
     * Retag the resource's type without changing its lifecycle state. Used
     * when a connection's protocol identity changes mid-stream — e.g. an
     * incoming HTTP request upgrades to WebSocket on handshake, or an
     * HTTP response decides to keep the connection open as an SSE stream.
     *
     * Generation bumps so any held handle becomes stale; callers receive
     * the new typed handle. A `resource.upgraded` lifecycle event records
     * old type → new type so audits and diagnostics can trace the
     * transition. Terminal resources cannot be upgraded.
     */
    public function upgrade(
        ManagedResourceHandle|string $resource,
        RuntimeResourceId|string $toType,
    ): ManagedResourceHandle {
        $id = $resource instanceof ManagedResourceHandle ? $resource->id : $resource;
        $newType = self::assertResourceType($toType);

        $lock = $this->locks->acquire($id);
        $event = null;
        try {
            $row = $this->tables->resources->get($id);
            if (!is_array($row)) {
                throw new ManagedResourceException("managed resource '{$id}' does not exist");
            }

            $generation = (int) $row['generation'];
            if ($resource instanceof ManagedResourceHandle && $resource->generation !== $generation) {
                throw StaleManagedResourceHandle::forGeneration($id, $resource->generation, $generation);
            }

            $state = ManagedResourceState::from((string) $row['state']);
            if ($state->isTerminal()) {
                throw new RuntimeException(
                    "managed resource '{$id}' is terminal ({$state->value}); cannot upgrade type.",
                );
            }

            $fromType = $this->symbols->valueFor((int) $row['type_symbol'], 'unknown');
            if ($fromType === $newType) {
                return new ManagedResourceHandle($id, $newType, $generation);
            }

            $now = microtime(true);
            $row['type_symbol'] = $this->symbols->idFor('resource.type', $newType);
            $row['generation'] = $generation + 1;
            $row['updated_at'] = $now;

            try {
                $ok = $this->tables->resources->set($id, $row);
            } catch (OpenSwooleException) {
                throw RuntimeMemoryCapacityExceeded::forTable('resources', $id);
            }

            if (!$ok) {
                throw RuntimeMemoryCapacityExceeded::forTable('resources', $id);
            }

            $event = $this->events->record(
                AegisEventSid::ResourceUpgraded,
                resourceId: $id,
                resourceType: $newType,
                scopeId: (string) $row['owner_scope_id'],
                runId: (string) $row['owner_run_id'],
                state: $state->value,
                valueA: $fromType,
                valueB: $newType,
                dispatchListeners: false,
            );

            return new ManagedResourceHandle($id, $newType, $generation + 1);
        } finally {
            $lock->release();
            if ($event !== null) {
                $this->events->dispatch($event);
            }
        }
    }

    public function annotate(
        ManagedResourceHandle|string $resource,
        RuntimeAnnotationId|string $key,
        string|int|float|bool|null $value,
        float $ttl = 0.0,
    ): void {
        $resourceId = $resource instanceof ManagedResourceHandle ? $resource->id : $resource;
        $key = self::assertAnnotationKey($key);
        $value = self::annotationValue($key, $value);
        $rowId = self::annotationRowId($resourceId, $key);
        try {
            $ok = $this->tables->resourceAnnotations->set($rowId, [
                'resource_id' => $resourceId,
                'key_symbol' => $this->symbols->idFor('annotation.key', $key),
                'value' => $value,
                'updated_at' => microtime(true),
                'expires_at' => $ttl > 0.0 ? microtime(true) + $ttl : 0.0,
            ]);
        } catch (OpenSwooleException) {
            throw RuntimeMemoryCapacityExceeded::forTable('resource_annotations', $rowId);
        }

        if (!$ok) {
            throw RuntimeMemoryCapacityExceeded::forTable('resource_annotations', $rowId);
        }

        $this->tables->mark('resource_annotations');
    }

    public function annotation(string $resourceId, RuntimeAnnotationId|string $key, string $default = ''): string
    {
        $key = self::annotationKey($key);
        $row = $this->tables->resourceAnnotations->get(self::annotationRowId($resourceId, $key));

        return is_array($row) && !self::annotationExpired($row) ? (string) $row['value'] : $default;
    }

    /** @return array<string, string> */
    public function annotations(string $resourceId): array
    {
        $annotations = [];
        foreach ($this->tables->resourceAnnotations as $row) {
            if (!is_array($row) || (string) $row['resource_id'] !== $resourceId) {
                continue;
            }
            if (self::annotationExpired($row)) {
                continue;
            }

            $annotations[$this->symbols->valueFor((int) $row['key_symbol'])] = (string) $row['value'];
        }

        return $annotations;
    }

    public function recordEvent(
        ManagedResourceHandle|string $resource,
        RuntimeEventId|string $type,
        string $valueA = '',
        string $valueB = '',
    ): RuntimeLifecycleEvent {
        $resourceId = $resource instanceof ManagedResourceHandle ? $resource->id : $resource;
        $snapshot = $this->get($resourceId);
        $type = self::eventType($type);

        return $this->events->record(
            type: $type,
            resourceId: $resourceId,
            resourceType: $snapshot === null ? '' : $snapshot->type,
            scopeId: $snapshot === null ? '' : ($snapshot->ownerScopeId ?? ''),
            runId: $snapshot === null ? '' : ($snapshot->ownerRunId ?? ''),
            state: $snapshot === null ? '' : $snapshot->state->value,
            valueA: $valueA,
            valueB: $valueB,
        );
    }

    public function addEdge(string $parentResourceId, string $childResourceId, string $type = 'child'): void
    {
        $edgeId = $this->ids->nextRuntime('edge');
        try {
            $ok = $this->tables->resourceEdges->set($edgeId, [
                'parent_resource_id' => $parentResourceId,
                'child_resource_id' => $childResourceId,
                'edge_type' => self::fit($type, 32),
                'created_at' => microtime(true),
                'expires_at' => 0.0,
            ]);
        } catch (OpenSwooleException) {
            throw RuntimeMemoryCapacityExceeded::forTable('resource_edges', $edgeId);
        }

        if (!$ok) {
            throw RuntimeMemoryCapacityExceeded::forTable('resource_edges', $edgeId);
        }

        $this->tables->mark('resource_edges');
        $this->events->record(
            AegisEventSid::ResourceEdge,
            resourceId: $parentResourceId,
            valueA: $childResourceId,
            valueB: $type,
        );
    }

    /** @return list<string> */
    public function childIds(string $resourceId): array
    {
        $ids = [];
        foreach ($this->tables->resourceEdges as $row) {
            if (is_array($row) && (string) $row['parent_resource_id'] === $resourceId) {
                $ids[] = (string) $row['child_resource_id'];
            }
        }

        return $ids;
    }

    /** @param array<string, string|float> $lease */
    public function addLease(string $ownerResourceId, string $ownerRunId, array $lease): void
    {
        $leaseId = $this->ids->nextRuntime('lease');
        try {
            $ok = $this->tables->resourceLeases->set($leaseId, [
                'owner_resource_id' => $ownerResourceId,
                'owner_run_id' => $ownerRunId,
                'lease_type' => self::fit((string) $lease['lease_type'], 64),
                'domain' => self::fit((string) $lease['domain'], 128),
                'resource_key' => self::fit((string) $lease['resource_key'], 128),
                'mode' => self::fit((string) $lease['mode'], 16),
                'acquired_at' => (float) $lease['acquired_at'],
                'expires_at' => 0.0,
            ]);
        } catch (OpenSwooleException) {
            throw RuntimeMemoryCapacityExceeded::forTable('resource_leases', $leaseId);
        }

        if (!$ok) {
            throw RuntimeMemoryCapacityExceeded::forTable('resource_leases', $leaseId);
        }

        $this->tables->mark('resource_leases');
        $this->events->record(
            AegisEventSid::ResourceLeaseAcquired,
            resourceId: $ownerResourceId,
            runId: $ownerRunId,
            valueA: (string) $lease['domain'],
            valueB: (string) $lease['resource_key'],
        );
    }

    public function releaseLease(
        string $ownerResourceId,
        string $leaseType,
        string $domain,
        string $resourceKey,
    ): void {
        foreach ($this->tables->resourceLeases as $leaseId => $row) {
            if (!is_array($row) || (string) $row['owner_resource_id'] !== $ownerResourceId) {
                continue;
            }
            if ((string) $row['lease_type'] !== $leaseType) {
                continue;
            }
            if ((string) $row['domain'] !== $domain || (string) $row['resource_key'] !== $resourceKey) {
                continue;
            }

            $this->tables->resourceLeases->del((string) $leaseId);
            $this->events->record(
                AegisEventSid::ResourceLeaseReleased,
                resourceId: $ownerResourceId,
                valueA: $domain,
                valueB: $resourceKey,
            );
            return;
        }
    }

    /** @return list<array{lease_type: string, domain: string, resource_key: string, mode: string, acquired_at: float}> */
    public function leases(string $ownerResourceId): array
    {
        $leases = [];
        foreach ($this->tables->resourceLeases as $row) {
            if (!is_array($row) || (string) $row['owner_resource_id'] !== $ownerResourceId) {
                continue;
            }

            $leases[] = [
                'lease_type' => (string) $row['lease_type'],
                'domain' => (string) $row['domain'],
                'resource_key' => (string) $row['resource_key'],
                'mode' => (string) $row['mode'],
                'acquired_at' => (float) $row['acquired_at'],
            ];
        }

        return $leases;
    }

    public function get(string $id): ?ManagedResource
    {
        $row = $this->tables->resources->get($id);

        return is_array($row) ? $this->hydrate($id, $row) : null;
    }

    /** @return list<ManagedResource> */
    public function all(RuntimeResourceId|string|null $type = null): array
    {
        $type = $type === null ? null : self::resourceType($type);
        $resources = [];
        foreach ($this->tables->resources as $id => $row) {
            if (!is_array($row)) {
                continue;
            }

            $resource = $this->hydrate((string) $id, $row);
            if ($type === null || $resource->type === $type) {
                $resources[] = $resource;
            }
        }

        return $resources;
    }

    public function liveCount(RuntimeResourceId|string|null $type = null): int
    {
        $live = 0;
        foreach ($this->all($type) as $resource) {
            if (!$resource->state->isTerminal()) {
                $live++;
            }
        }

        return $live;
    }

    public function release(string $id): void
    {
        $lock = $this->locks->acquire($id);
        $event = null;
        try {
            $this->tables->resources->del($id);

            foreach ($this->tables->resourceEdges as $edgeId => $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ((string) $row['parent_resource_id'] === $id || (string) $row['child_resource_id'] === $id) {
                    $this->tables->resourceEdges->del((string) $edgeId);
                }
            }

            foreach ($this->tables->resourceLeases as $leaseId => $row) {
                if (is_array($row) && (string) $row['owner_resource_id'] === $id) {
                    $this->tables->resourceLeases->del((string) $leaseId);
                }
            }

            foreach ($this->tables->resourceAnnotations as $annotationId => $row) {
                if (is_array($row) && (string) $row['resource_id'] === $id) {
                    $this->tables->resourceAnnotations->del((string) $annotationId);
                }
            }

            $event = $this->events->record(
                AegisEventSid::ResourceReleased,
                resourceId: $id,
                dispatchListeners: false,
            );
        } finally {
            $lock->release();
            if ($event !== null) {
                $this->events->dispatch($event);
            }
        }
    }

    private static function resourceType(RuntimeResourceId|string $type): string
    {
        return $type instanceof RuntimeResourceId ? $type->value() : $type;
    }

    private static function annotationKey(RuntimeAnnotationId|string $key): string
    {
        return $key instanceof RuntimeAnnotationId ? $key->value() : $key;
    }

    private static function eventType(RuntimeEventId|string $type): string
    {
        return $type instanceof RuntimeEventId ? $type->value() : $type;
    }

    private static function assertResourceType(RuntimeResourceId|string $type): string
    {
        $type = self::resourceType($type);
        if (!str_contains($type, '.') || mb_strlen($type) > 96) {
            throw RuntimeAnnotationRejected::forKey($type);
        }

        return $type;
    }

    private static function assertAnnotationKey(RuntimeAnnotationId|string $key): string
    {
        $key = self::annotationKey($key);
        if (!str_contains($key, '.') || mb_strlen($key) > 128) {
            throw RuntimeAnnotationRejected::forKey($key);
        }

        return $key;
    }

    private static function annotationValue(string $key, string|int|float|bool|null $value): string
    {
        $encoded = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };

        if (mb_strlen($encoded) > 256) {
            throw RuntimeAnnotationRejected::forKey($key);
        }

        return $encoded;
    }

    private static function annotationRowId(string $resourceId, string $key): string
    {
        return substr(sha1($resourceId . "\0" . $key), 0, 32);
    }

    /** @param array<string, mixed> $row */
    private static function annotationExpired(array $row): bool
    {
        $expiresAt = (float) ($row['expires_at'] ?? 0.0);

        return $expiresAt > 0.0 && $expiresAt <= microtime(true);
    }

    private static function fit(string $value, int $length): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(string $id, array $row): ManagedResource
    {
        $terminalAt = (float) $row['terminal_at'];

        return new ManagedResource(
            id: $id,
            type: $this->symbols->valueFor((int) $row['type_symbol'], 'unknown'),
            state: ManagedResourceState::from((string) $row['state']),
            generation: (int) $row['generation'],
            parentResourceId: (string) $row['parent_resource_id'] === '' ? null : (string) $row['parent_resource_id'],
            ownerScopeId: (string) $row['owner_scope_id'] === '' ? null : (string) $row['owner_scope_id'],
            ownerRunId: (string) $row['owner_run_id'] === '' ? null : (string) $row['owner_run_id'],
            workerId: (int) $row['worker_id'],
            coroutineId: (int) $row['coroutine_id'],
            createdAt: (float) $row['created_at'],
            updatedAt: (float) $row['updated_at'],
            terminalAt: $terminalAt > 0.0 ? $terminalAt : null,
            outcome: (string) $row['outcome'],
            reason: $this->symbols->valueFor((int) $row['reason_symbol']),
            cancelRequested: (int) $row['cancel_requested'] === 1,
        );
    }
}
