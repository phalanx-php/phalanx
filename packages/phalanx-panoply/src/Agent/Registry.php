<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Agent;

use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Capability;

/**
 * Immutable registry of agents available to the host. Each
 * {@see self::with()} call returns a new instance with the additional
 * Agent keyed by its id. Bulk loading strategies live in dedicated
 * loader classes that compose with this registry.
 *
 * Final — extension would change immutability semantics that hosts
 * depend on.
 */
final class Registry
{
    /**
     * @param array<string, Agent> $agents keyed by agent id
     */
    public function __construct(
        private(set) array $agents = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function with(Agent $agent): self
    {
        $agents = $this->agents;
        $agents[$agent->id] = $agent;

        return new self($agents);
    }

    public function get(string $id): ?Agent
    {
        return $this->agents[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->agents[$id]);
    }

    public function all(): Collection
    {
        return Collection::from($this->agents);
    }

    public function byCapability(Capability $capability): Collection
    {
        return Collection::from(array_filter(
            $this->agents,
            static fn (Agent $agent): bool => $agent->capabilities->has($capability),
        ));
    }
}
