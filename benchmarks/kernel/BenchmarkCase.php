<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kernel;

use Phalanx\Application;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\LedgerStorage;

interface BenchmarkCase
{
    public function name(): string;

    public function iterations(): int;

    public function warmups(): int;

    public function run(BenchmarkContext $context): void;
}

final class BenchmarkContext
{
    private ?Application $defaultApp = null;

    public function app(?LedgerStorage $ledger = null): Application
    {
        if ($ledger === null) {
            return $this->defaultApp ??= Application::starting([])
                ->withLedger(new InProcessLedger())
                ->compile();
        }

        return Application::starting([])
            ->withLedger($ledger)
            ->compile();
    }

    public function scope(?LedgerStorage $ledger = null): ExecutionScope
    {
        return $this->app($ledger)->createScope();
    }
}

final class BenchmarkResult
{
    /**
     * @param list<int> $samplesNs
     * @param array<string, string> $metadata
     */
    public function __construct(
        public readonly string $case,
        public readonly int $iterations,
        public readonly int $totalNs,
        public readonly int $memoryBefore,
        public readonly int $memoryAfter,
        public readonly int $memoryPeak,
        public readonly array $samplesNs,
        public readonly array $metadata,
    ) {
    }

    public function meanUs(): float
    {
        return ($this->totalNs / max(1, $this->iterations)) / 1_000;
    }

    public function p50Us(): float
    {
        return $this->percentileUs(0.50);
    }

    public function p95Us(): float
    {
        return $this->percentileUs(0.95);
    }

    public function p99Us(): float
    {
        return $this->percentileUs(0.99);
    }

    public function opsPerSec(): float
    {
        if ($this->totalNs === 0) {
            return 0.0;
        }

        return $this->iterations / ($this->totalNs / 1_000_000_000);
    }

    public function memoryDeltaKb(): float
    {
        return ($this->memoryAfter - $this->memoryBefore) / 1024;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'case' => $this->case,
            'iterations' => $this->iterations,
            'total_ns' => $this->totalNs,
            'mean_us' => $this->meanUs(),
            'p50_us' => $this->p50Us(),
            'p95_us' => $this->p95Us(),
            'p99_us' => $this->p99Us(),
            'ops_sec' => $this->opsPerSec(),
            'memory_before' => $this->memoryBefore,
            'memory_after' => $this->memoryAfter,
            'memory_peak' => $this->memoryPeak,
            'memory_delta_kb' => $this->memoryDeltaKb(),
            'metadata' => $this->metadata,
        ];
    }

    private function percentileUs(float $percentile): float
    {
        if ($this->samplesNs === []) {
            return 0.0;
        }

        $samples = $this->samplesNs;
        sort($samples);

        $index = (int) ceil($percentile * count($samples)) - 1;
        $index = max(0, min($index, count($samples) - 1));

        return $samples[$index] / 1_000;
    }
}

abstract class AbstractBenchmarkCase implements BenchmarkCase
{
    public function __construct(
        private readonly string $caseName,
        private readonly int $caseIterations,
        private readonly int $caseWarmups = 5,
    ) {
    }

    public function name(): string
    {
        return $this->caseName;
    }

    public function iterations(): int
    {
        return $this->caseIterations;
    }

    public function warmups(): int
    {
        return $this->caseWarmups;
    }
}
