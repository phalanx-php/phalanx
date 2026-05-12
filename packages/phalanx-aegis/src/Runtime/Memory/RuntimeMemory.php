<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

use Phalanx\Boot\AppContext;

final class RuntimeMemory
{
    private(set) RuntimeIds $ids;

    private(set) RuntimeClaims $claims;

    private(set) RuntimeSymbols $symbols;

    private(set) RuntimeCounters $counters;

    private(set) ManagedSwooleTables $tables;

    private(set) RuntimeLifecycleEvents $events;

    private(set) ManagedResourceRegistry $resources;

    private ManagedResourceTransitionLocks $transitionLocks;

    private bool $shutdown = false;

    public function __construct(
        public readonly RuntimeMemoryConfig $config
    ) {
        $this->tables = new ManagedSwooleTables($config);
        $this->claims = new RuntimeClaims($this->tables);
        $this->symbols = new RuntimeSymbols($this->tables);
        $this->counters = new RuntimeCounters($this->tables);
        $this->ids = new RuntimeIds($this->counters);

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

    public static function fromContext(AppContext $context): self
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
