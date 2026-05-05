<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

use Phalanx\Runtime\Identity\RuntimeResourceId;
use Phalanx\Runtime\Memory\ManagedResource;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Runtime\Memory\RuntimeMemory;

class QueryScope
{
    public function __construct(
        private RuntimeMemory $memory,
    ) {
    }

    public function get(string $id): ?ManagedResource
    {
        return $this->memory->resources->get($id);
    }

    /** @return list<ManagedResource> */
    public function all(RuntimeResourceId|string|null $type = null): array
    {
        return $this->memory->resources->all($type);
    }

    /** @return list<ManagedResource> */
    public function live(RuntimeResourceId|string|null $type = null): array
    {
        return array_values(array_filter(
            $this->all($type),
            static fn(ManagedResource $resource): bool => !$resource->state->isTerminal(),
        ));
    }

    public function liveCount(RuntimeResourceId|string|null $type = null): int
    {
        return $this->memory->resources->liveCount($type);
    }

    /** @return list<ManagedResource> */
    public function byOwnerScope(string $scopeId, RuntimeResourceId|string|null $type = null): array
    {
        return array_values(array_filter(
            $this->all($type),
            static fn(ManagedResource $resource): bool => $resource->ownerScopeId === $scopeId,
        ));
    }

    /** @return list<ManagedResource> */
    public function byOwnerRun(string $runId, RuntimeResourceId|string|null $type = null): array
    {
        return array_values(array_filter(
            $this->all($type),
            static fn(ManagedResource $resource): bool => $resource->ownerRunId === $runId,
        ));
    }

    /**
     * Return resources related through either canonical parent resource id
     * or an explicit resource edge, deduplicated by child resource id.
     *
     * @return list<ManagedResource>
     */
    public function childrenOf(string $parentResourceId, RuntimeResourceId|string|null $type = null): array
    {
        $children = [];
        foreach ($this->all($type) as $child) {
            if ($child->parentResourceId !== $parentResourceId) {
                continue;
            }

            $children[$child->id] = $child;
        }

        $type = $type === null ? null : self::typeValue($type);
        foreach ($this->memory->resources->childIds($parentResourceId) as $childId) {
            if (isset($children[$childId])) {
                continue;
            }

            $child = $this->memory->resources->get($childId);
            if ($child === null) {
                continue;
            }
            if ($type !== null && $child->type !== $type) {
                continue;
            }

            $children[$child->id] = $child;
        }

        return array_values($children);
    }

    /** @return array<string, string> */
    public function annotations(string $resourceId): array
    {
        return $this->memory->resources->annotations($resourceId);
    }

    /** @return list<array{lease_type: string, domain: string, resource_key: string, mode: string, acquired_at: float}> */
    public function leases(string $ownerResourceId): array
    {
        return $this->memory->resources->leases($ownerResourceId);
    }

    /** @return array<string, int> */
    public function stateCounts(RuntimeResourceId|string|null $type = null): array
    {
        $counts = [];
        foreach (ManagedResourceState::cases() as $state) {
            $counts[$state->value] = 0;
        }

        foreach ($this->all($type) as $resource) {
            $counts[$resource->state->value]++;
        }

        return $counts;
    }

    private static function typeValue(RuntimeResourceId|string $type): string
    {
        return $type instanceof RuntimeResourceId ? $type->value() : $type;
    }
}
