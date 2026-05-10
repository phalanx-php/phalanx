<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kit;

use Phalanx\Benchmarks\Http\HttpBenchmarkCase;
use Phalanx\Benchmarks\Kernel\KernelBenchmarkCase;
use RuntimeException;

/**
 * Aggregates and displays results for a set of benchmark cases.
 */
final class BenchmarkReport
{
    /** @var list<BenchmarkResult> */
    private array $results = [];

    private bool $headerPrinted = false;

    private ?BenchmarkRunner $runner = null;

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $metadata = [];

    public function __construct(private(set) string $title)
    {
    }

    public function setRunner(BenchmarkRunner $runner): void
    {
        $this->runner = $runner;
    }

    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * Registers and measures multiple cases at once.
     *
     * @param list<BenchmarkCase> $cases
     */
    public function group(array $cases): void
    {
        $filter = $this->options['case'] ?? null;

        foreach ($cases as $case) {
            if ($filter !== null && $case->name() !== $filter) {
                continue;
            }

            if ($case instanceof HttpBenchmarkCase) {
                $this->measure(
                    $case->name(),
                    $case->iterations(),
                    static fn(BenchmarkApp $app) => $case->run($app),
                    $case->warmups()
                );
            } elseif ($case instanceof KernelBenchmarkCase) {
                $this->measure(
                    $case->name(),
                    $case->iterations(),
                    static fn(BenchmarkHarness $harness) => $case->run($harness),
                    $case->warmups()
                );
            } else {
                throw new RuntimeException(sprintf('Unknown benchmark case type: %s', $case::class));
            }
        }
    }

    /**
     * Measures a specific operation and records the result.
     */
    public function measure(string $name, int $iterations, \Closure $work, int $warmups = 10): void
    {
        if ($this->runner === null) {
            throw new RuntimeException('BenchmarkReport: runner not set.');
        }

        $result = $this->runner->measure($name, $iterations, $work, $warmups);
        $this->record($result);
    }

    public function record(BenchmarkResult $result): void
    {
        $this->results[] = $result;
        
        if (($this->options['format'] ?? 'table') === 'table') {
            $this->printHeaderOnce();
            printf(
                "  %-38s | %10.2f ops/s | %10.2f us/op | %8.2f KB\n",
                $result->case,
                $result->opsPerSec,
                $result->meanUs,
                $result->memoryDeltaKb
            );
        }
    }

    public function render(): int
    {
        if ($this->results === []) {
            return 0;
        }

        if (isset($this->options['baseline'])) {
            $this->compareBaseline($this->options['baseline']);
        }

        if (($this->options['format'] ?? 'table') === 'json') {
            $this->renderJson();
            return 0;
        }

        $this->renderTable();
        return 0;
    }

    private function renderJson(): void
    {
        echo json_encode(
            [
                'metadata' => $this->metadata,
                'results' => array_map(static fn(BenchmarkResult $result): array => $result->toArray(), $this->results),
            ],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
    }

    private function renderTable(): void
    {
        printf("\nSummary:\n");
        printf(
            "%-38s %8s %10s %10s %10s %12s %13s %8s %8s\n",
            'case',
            'iter',
            'mean_us',
            'p95_us',
            'p99_us',
            'ops_sec',
            'mem_delta_kb',
            'errors',
            'cleanup',
        );
        printf("%s\n", str_repeat('-', 125));

        foreach ($this->results as $result) {
            printf(
                "%-38s %8d %10.2f %10.2f %10.2f %12.2f %13.2f %8d %8s\n",
                $result->case,
                $result->iterations,
                $result->meanUs,
                $result->p95Us(),
                $result->p99Us(),
                $result->opsPerSec,
                $result->memoryDeltaKb,
                $result->errors,
                $result->cleanup,
            );
        }
    }

    private function compareBaseline(string $path): void
    {
        if (!is_file($path)) {
            fwrite(STDERR, "Baseline file not found: {$path}\n");
            return;
        }

        $raw = file_get_contents($path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['results'])) {
            fwrite(STDERR, "Invalid baseline format: {$path}\n");
            return;
        }

        $baselineByCase = [];
        foreach ($decoded['results'] as $result) {
            $baselineByCase[$result['case']] = $result;
        }

        foreach ($this->results as $result) {
            $baseline = $baselineByCase[$result->case] ?? null;
            if ($baseline === null) {
                continue;
            }

            $prevMean = (float) ($baseline['mean_us'] ?? 0);
            if ($prevMean <= 0) continue;

            $threshold = $this->thresholdForCase($result->case);
            $change = ($result->meanUs - $prevMean) / $prevMean;

            if ($change > $threshold) {
                printf(
                    "REGRESSION: %s mean_us %.2fus -> %.2fus (+%.1f%%, limit %.0f%%)\n",
                    $result->case,
                    $prevMean,
                    $result->meanUs,
                    $change * 100,
                    $threshold * 100
                );
            }
        }
    }

    private function thresholdForCase(string $case): float
    {
        if ($case === 'stoa_drain_cleanup') {
            return (float) ($this->options['drain-threshold'] ?? 0.20);
        }

        return (float) ($this->options['stable-threshold'] ?? 0.10);
    }

    private function printHeaderOnce(): void
    {
        if ($this->headerPrinted) {
            return;
        }

        $this->headerPrinted = true;
        printf("%s\n%s\n", $this->title, str_repeat('=', strlen($this->title)));
        
        printf("commit=%s dirty=%s php=%s openswoole=%s\n",
            $this->metadata['commit'] ?? 'unknown',
            $this->metadata['dirty'] ?? 'unknown',
            $this->metadata['php'] ?? PHP_VERSION,
            $this->metadata['openswoole'] ?? 'unknown'
        );
        printf("php_binary=%s xdebug=%s\n\n",
            $this->metadata['php_binary'] ?? PHP_BINARY,
            $this->metadata['xdebug'] ?? 'no'
        );
    }
}
