<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Http;

use Phalanx\Application;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Runtime\Identity\StoaResourceSid;
use Phalanx\Stoa\StoaRunner;
use Phalanx\Supervisor\TaskTreeFormatter;
use RuntimeException;

interface HttpBenchmarkCase
{
    public function name(): string;

    public function iterations(): int;

    public function warmups(): int;

    public function run(HttpBenchmarkContext $context): void;
}

final class HttpBenchmarkSuite
{
    /** @var list<HttpBenchmarkCase> */
    public readonly array $cases;

    private function __construct(HttpBenchmarkCase ...$cases)
    {
        $this->cases = array_values($cases);
    }

    public static function of(HttpBenchmarkCase ...$cases): self
    {
        return new self(...$cases);
    }
}

final class HttpBenchmarkContext
{
    /**
     * @var array<string, array{app: Application, runner: StoaRunner}>
     */
    private array $stoaRunners = [];

    public function runner(string $key, RouteGroup $routes): StoaRunner
    {
        if (isset($this->stoaRunners[$key])) {
            return $this->stoaRunners[$key]['runner'];
        }

        $app = Application::starting()->compile()->startup();
        $runner = StoaRunner::from($app)->withRoutes($routes);
        $this->stoaRunners[$key] = ['app' => $app, 'runner' => $runner];

        return $runner;
    }

    public function assertClean(string $case): void
    {
        foreach ($this->stoaRunners as $key => $entry) {
            $runner = $entry['runner'];
            $app = $entry['app'];

            if ($runner->activeRequests() !== 0) {
                throw new RuntimeException(
                    "Benchmark case '{$case}' left active Stoa requests in runner '{$key}'.",
                );
            }

            $liveRequests = $app->runtime()->memory->resources->liveCount(StoaResourceSid::HttpRequest);
            if ($liveRequests !== 0) {
                throw new RuntimeException(
                    "Benchmark case '{$case}' left {$liveRequests} live Stoa request resources in runner '{$key}'.",
                );
            }

            $supervisor = $app->supervisor();
            if ($supervisor->liveCount() === 0 && $supervisor->tree() === []) {
                continue;
            }

            $formatted = (new TaskTreeFormatter())->format($supervisor->tree());

            throw new RuntimeException(
                "Benchmark case '{$case}' left live or unreaped task runs:\n{$formatted}",
            );
        }
    }

    public function shutdown(): void
    {
        foreach ($this->stoaRunners as $entry) {
            $entry['app']->shutdown();
        }

        $this->stoaRunners = [];
    }
}

final class HttpBenchmarkResult
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
        public readonly int $errors,
        public readonly string $cleanup,
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
            'errors' => $this->errors,
            'cleanup' => $this->cleanup,
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

abstract class AbstractHttpBenchmarkCase implements HttpBenchmarkCase
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
