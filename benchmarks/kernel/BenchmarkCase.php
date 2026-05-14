<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kernel;

use Phalanx\Application;
use Phalanx\Runtime\Memory\RuntimeTableStats;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\LedgerStorage;
use Phalanx\Supervisor\TaskTreeFormatter;
use RuntimeException;

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

    /** @var array<int, Application> */
    private array $apps = [];

    private function track(Application $app): Application
    {
        $this->apps[spl_object_id($app)] = $app;

        return $app;
    }

    public function app(?LedgerStorage $ledger = null): Application
    {
        if ($ledger === null) {
            return $this->track($this->defaultApp ??= Application::starting([])
                ->withLedger(new InProcessLedger())
                ->compile());
        }

        return $this->track(Application::starting([])
            ->withLedger($ledger)
            ->compile());
    }

    public function scope(?LedgerStorage $ledger = null): ExecutionScope
    {
        return $this->app($ledger)->createScope();
    }

    public function assertNoLiveTasks(string $case): void
    {
        foreach ($this->apps as $app) {
            $supervisor = $app->supervisor();
            $tree = $supervisor->tree();

            if ($supervisor->liveCount() === 0 && $tree === []) {
                continue;
            }

            $formatted = (new TaskTreeFormatter())->format($tree);

            throw new RuntimeException(
                "Benchmark case '{$case}' left live or unreaped task runs:\n{$formatted}",
            );
        }
    }

    /** @return array{apps: list<array{pool_stats: array<string, mixed>, runtime_memory: list<array{name: string, configured_rows: int, current_rows: int, memory_size: int, high_water_rows: int}>}>} */
    public function diagnostics(): array
    {
        $apps = [];

        foreach ($this->apps as $app) {
            $apps[] = [
                'pool_stats' => $app->supervisor()->poolStats(),
                'runtime_memory' => array_map(
                    static fn(RuntimeTableStats $stats): array => [
                        'name' => $stats->name,
                        'configured_rows' => $stats->configuredRows,
                        'current_rows' => $stats->currentRows,
                        'memory_size' => $stats->memorySize,
                        'high_water_rows' => $stats->highWaterRows,
                    ],
                    $app->runtime()->memory->stats(),
                ),
            ];
        }

        return ['apps' => $apps];
    }
}

final class BenchmarkResult
{
    /**
     * @param list<int> $samplesNs
     * @param array<string, string> $metadata
     * @param array<string, mixed> $diagnostics
     */
    public function __construct(
        public readonly string $case,
        public readonly int $iterations,
        public readonly int $totalNs,
        public readonly int $zendMemoryBefore,
        public readonly int $zendMemoryAfter,
        public readonly int $realMemoryBefore,
        public readonly int $realMemoryAfter,
        public readonly int $memoryPeak,
        public readonly int $zendMemoryPeak,
        public readonly int $gcRootsBefore,
        public readonly int $gcRootsAfter,
        public readonly array $samplesNs,
        public readonly array $metadata,
        public readonly array $diagnostics,
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
        return $this->realMemoryDeltaKb();
    }

    public function zendMemoryDeltaKb(): float
    {
        return ($this->zendMemoryAfter - $this->zendMemoryBefore) / 1024;
    }

    public function realMemoryDeltaKb(): float
    {
        return ($this->realMemoryAfter - $this->realMemoryBefore) / 1024;
    }

    public function gcRootsDelta(): int
    {
        return $this->gcRootsAfter - $this->gcRootsBefore;
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
            'memory_before' => $this->realMemoryBefore,
            'memory_after' => $this->realMemoryAfter,
            'memory_peak' => $this->memoryPeak,
            'memory_delta_kb' => $this->memoryDeltaKb(),
            'zend_memory_before' => $this->zendMemoryBefore,
            'zend_memory_after' => $this->zendMemoryAfter,
            'zend_memory_peak' => $this->zendMemoryPeak,
            'zend_memory_delta_kb' => $this->zendMemoryDeltaKb(),
            'real_memory_before' => $this->realMemoryBefore,
            'real_memory_after' => $this->realMemoryAfter,
            'real_memory_peak' => $this->memoryPeak,
            'real_memory_delta_kb' => $this->realMemoryDeltaKb(),
            'gc_roots_before' => $this->gcRootsBefore,
            'gc_roots_after' => $this->gcRootsAfter,
            'gc_roots_delta' => $this->gcRootsDelta(),
            'diagnostics' => $this->diagnostics,
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
