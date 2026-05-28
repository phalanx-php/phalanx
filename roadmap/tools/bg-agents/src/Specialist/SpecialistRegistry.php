<?php

declare(strict_types=1);

namespace BgAgents\Specialist;

final readonly class SpecialistRegistry
{
    /**
     * @param array<string, Specialist> $byName
     */
    public function __construct(
        private array $byName,
    ) {}

    /** @return array<string, Specialist> */
    public function all(): array
    {
        return $this->byName;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->byName);
    }

    public function get(string $name): ?Specialist
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * Resolve a name OR an addressing token (e.g. "@runtime") to a Specialist.
     * Direct name lookup wins; addressing is checked second.
     */
    public function resolve(string $needle): ?Specialist
    {
        if (isset($this->byName[$needle])) {
            return $this->byName[$needle];
        }

        foreach ($this->byName as $spec) {
            if (in_array($needle, $spec->addressing, true)) {
                return $spec;
            }
        }

        return null;
    }

    /** @return list<Specialist> */
    public function findBySubscriptionMatch(\BgAgents\Daemon8\ObservationRecord $record): array
    {
        $hits = [];
        foreach ($this->byName as $spec) {
            if (!$spec->subscription->isEmpty() && $spec->subscription->matches($record)) {
                $hits[] = $spec;
            }
        }
        return $hits;
    }
}
