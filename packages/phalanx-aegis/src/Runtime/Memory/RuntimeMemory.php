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

    private ManagedResourceTransitionLocks $transitionLocks;

    private bool $shutdown = false;

    public function __construct(public readonly RuntimeMemoryConfig $config)
    {
        $this->tables = new ManagedSwooleTables($config);
        $this->symbols = new RuntimeSymbols($this->tables);
        $this->counters = new RuntimeCounters($this->tables);
        $this->ids = new RuntimeIds($this->counters);
        $this->claims = new RuntimeClaims($this->tables);
        $this->events = new RuntimeLifecycleEvents($this->tables, $this->counters);

        $this->transitionLocks = new ManagedResourceTransitionLocks(
            stripes: $config->transitionLockStripes,
            timeout: $config->transitionLockTimeout,
        );

        $this->resources = new ManagedResourceRegistry(
            tables: $this->tables,
            symbols: $this->symbols,
            events: $this->events,
            ids: $this->ids,
            locks: $this->transitionLocks,
            gate: new ManagedResourceTransitionGate(
                tables: $this->tables,
                symbols: $this->symbols,
                counters: $this->counters,
                events: $this->events,
                locks: $this->transitionLocks,
            ),
        );
    }

    public static function fromContext(\Phalanx\Boot\AppContext $context): self
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
        $this->transitionLocks->destroy();
        $this->tables->destroy();
    }
}
