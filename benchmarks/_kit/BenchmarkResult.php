<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kit;

/**
 * Data bucket for a single benchmark case result.
 */
final class BenchmarkResult
{
    /**
     * @param list<int> $samplesNs
     * @param array<string, mixed> $diagnostics
     */
    public function __construct(
        private(set) string $case,
        private(set) int $iterations,
        private(set) int $totalNs,
        private(set) int $memoryBefore,
        private(set) int $memoryAfter,
        private(set) int $memoryPeak,
        private(set) int $errors,
        private(set) string $cleanup,
        private(set) array $samplesNs,
        private(set) int $zendMemoryBefore = 0,
        private(set) int $zendMemoryAfter = 0,
        private(set) int $zendMemoryPeak = 0,
        private(set) int $realMemoryBefore = 0,
        private(set) int $realMemoryAfter = 0,
        private(set) int $realMemoryPeak = 0,
        private(set) int $gcRootsBefore = 0,
        private(set) int $gcRootsAfter = 0,
        private(set) array $diagnostics = [],
    ) {
    }

    public float $meanUs {
        get => (array_sum($this->samplesNs) / max(1, $this->iterations)) / 1_000;
    }

    public float $opsPerSec {
        get {
            $totalWorkNs = array_sum($this->samplesNs);
            return $totalWorkNs === 0 ? 0.0 : $this->iterations / ($totalWorkNs / 1_000_000_000);
        }
    }

    public float $memoryDeltaKb {
        get => ($this->memoryAfter - $this->memoryBefore) / 1024;
    }

    public float $zendMemoryDeltaKb {
        get => ($this->zendMemoryAfter - $this->zendMemoryBefore) / 1024;
    }

    public float $realMemoryDeltaKb {
        get => ($this->realMemoryAfter - $this->realMemoryBefore) / 1024;
    }

    public int $gcRootsDelta {
        get => $this->gcRootsAfter - $this->gcRootsBefore;
    }

    public bool $hasKernelMetrics {
        get => $this->zendMemoryBefore !== 0
            || $this->zendMemoryAfter !== 0
            || $this->gcRootsBefore !== 0
            || $this->gcRootsAfter !== 0;
    }

    public function p95Us(): float
    {
        return $this->percentileUs(0.95);
    }

    public function p99Us(): float
    {
        return $this->percentileUs(0.99);
    }

    public function percentileUs(float $percentile): float
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'case' => $this->case,
            'iterations' => $this->iterations,
            'total_ns' => $this->totalNs,
            'mean_us' => $this->meanUs,
            'p95_us' => $this->p95Us(),
            'p99_us' => $this->p99Us(),
            'ops_sec' => $this->opsPerSec,
            'mem_before' => $this->memoryBefore,
            'mem_after' => $this->memoryAfter,
            'mem_peak' => $this->memoryPeak,
            'mem_delta_kb' => $this->memoryDeltaKb,
            'zend_memory_before' => $this->zendMemoryBefore,
            'zend_memory_after' => $this->zendMemoryAfter,
            'zend_memory_peak' => $this->zendMemoryPeak,
            'zend_memory_delta_kb' => $this->zendMemoryDeltaKb,
            'real_memory_before' => $this->realMemoryBefore,
            'real_memory_after' => $this->realMemoryAfter,
            'real_memory_peak' => $this->realMemoryPeak,
            'real_memory_delta_kb' => $this->realMemoryDeltaKb,
            'gc_roots_before' => $this->gcRootsBefore,
            'gc_roots_after' => $this->gcRootsAfter,
            'gc_roots_delta' => $this->gcRootsDelta,
            'errors' => $this->errors,
            'cleanup' => $this->cleanup,
            'diagnostics' => $this->diagnostics,
        ];
    }
}
