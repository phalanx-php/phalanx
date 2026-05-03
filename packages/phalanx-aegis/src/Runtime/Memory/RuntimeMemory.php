<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

final class RuntimeMemory
{
    public readonly ManagedSwooleTables $tables;

    public readonly RuntimeSymbols $symbols;

    public readonly RuntimeCounters $counters;

    public readonly RuntimeIds $ids;

    public readonly RuntimeClaims $claims;

    public readonly RuntimeLifecycleEvents $events;

    public readonly ManagedResourceRegistry $resources;

    private bool $shutdown = false;

    public function __construct(public readonly RuntimeMemoryConfig $config)
    {
        $this->tables = new ManagedSwooleTables($config);
        $this->symbols = new RuntimeSymbols($this->tables);
        $this->counters = new RuntimeCounters($this->tables);
        $this->ids = new RuntimeIds($this->counters);
        $this->claims = new RuntimeClaims($this->tables);
        $this->events = new RuntimeLifecycleEvents($this->tables, $this->counters);
        $this->resources = new ManagedResourceRegistry(
            tables: $this->tables,
            symbols: $this->symbols,
            events: $this->events,
            ids: $this->ids,
            gate: new ManagedResourceTransitionGate(
                tables: $this->tables,
                symbols: $this->symbols,
                counters: $this->counters,
                events: $this->events,
            ),
        );
    }

    /** @param array<string, mixed> $context */
    public static function fromContext(array $context): self
    {
        return new self(RuntimeMemoryConfig::fromContext($context));
    }

    public static function forLedgerSize(int $rows): self
    {
        return new self(RuntimeMemoryConfig::forLedgerSize($rows));
    }

    public function sweepExpired(): int
    {
        return $this->claims->sweepExpired(microtime(true));
    }

    /** @return list<RuntimeTableStats> */
    public function stats(): array
    {
        return $this->tables->stats();
    }

    public function shutdown(): void
    {
        if ($this->shutdown) {
            return;
        }
        $this->shutdown = true;

        $this->claims->destroy();
        $this->tables->destroy();
    }
}
