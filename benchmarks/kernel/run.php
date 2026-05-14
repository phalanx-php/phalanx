#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kernel;

use OpenSwoole\Coroutine;
use Phalanx\Boot\AppContext;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
use Throwable;

use function Phalanx\Benchmarks\Kernel\Cases\aegisKernelCases;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/BenchmarkCase.php';
require __DIR__ . '/cases/CoreCases.php';

$arguments = $argv ?? [];
$context = new AppContext(['argv' => $arguments]);

RuntimeHooks::ensure(RuntimePolicy::fromContext($context));

$caught = null;
$exitCode = 0;

Coroutine::run(static function () use ($arguments, &$caught, &$exitCode): void {
    try {
        $exitCode = (new Runner($arguments))->run();
    } catch (Throwable $e) {
        $caught = $e;
        $exitCode = 1;
    }
});

if ($caught !== null) {
    fwrite(STDERR, $caught::class . ': ' . $caught->getMessage() . PHP_EOL);
    fwrite(STDERR, $caught->getTraceAsString() . PHP_EOL);
}

exit($exitCode);

final class Runner
{
    /** @var array<string, string> */
    private array $options;

    /** @param list<string> $argv */
    public function __construct(array $argv)
    {
        $this->options = self::parseOptions($argv);
    }

    /**
     * @param list<string> $argv
     * @return array<string, string>
     */
    private static function parseOptions(array $argv): array
    {
        $options = [];

        foreach (array_slice($argv, 1) as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }

            $parts = explode('=', substr($arg, 2), 2);
            $options[$parts[0]] = $parts[1] ?? '1';
        }

        return $options;
    }

    public function run(): int
    {
        if (isset($this->options['help'])) {
            $this->printHelp();
            return 0;
        }

        $cases = $this->selectedCases();
        if ($cases === []) {
            fwrite(STDERR, 'No benchmark cases matched.' . PHP_EOL);
            return 1;
        }

        $metadata = self::metadata();
        $results = [];

        foreach ($cases as $case) {
            $context = new BenchmarkContext();
            $results[] = $this->measure($case, $context, $metadata);
        }

        if (isset($this->options['baseline'])) {
            foreach ($this->compareBaseline($results, $this->options['baseline']) as $warning) {
                fwrite(STDERR, $warning . PHP_EOL);
            }
        }

        if (($this->options['format'] ?? 'table') === 'json') {
            $this->printJson($results, $metadata);
            return 0;
        }

        $this->printTable($results, $metadata);
        return 0;
    }

    private function printHelp(): void
    {
        echo "Usage: php benchmarks/kernel/run.php [--case=name] [--format=table|json] [--baseline=path]\n";
        echo "       [--stable-threshold=0.10] [--fanout-threshold=0.20]\n";
        echo "\nAvailable cases:\n";

        foreach (aegisKernelCases() as $case) {
            echo "  - {$case->name()}\n";
        }
    }

    /** @return list<BenchmarkCase> */
    private function selectedCases(): array
    {
        $filter = $this->options['case'] ?? null;

        return array_values(array_filter(
            aegisKernelCases(),
            static fn(BenchmarkCase $case): bool => $filter === null || $case->name() === $filter,
        ));
    }

    /** @param array<string, string> $metadata */
    private function measure(BenchmarkCase $case, BenchmarkContext $context, array $metadata): BenchmarkResult
    {
        for ($i = 0; $i < $case->warmups(); $i++) {
            $case->run($context);
        }

        gc_collect_cycles();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $samples = [];
        $gcBefore = gc_status();
        $zendMemoryBefore = memory_get_usage(false);
        $realMemoryBefore = memory_get_usage(true);
        $started = hrtime(true);

        for ($i = 0; $i < $case->iterations(); $i++) {
            $iterationStarted = hrtime(true);
            $case->run($context);
            $samples[] = hrtime(true) - $iterationStarted;
        }

        $context->assertNoLiveTasks($case->name());

        $totalNs = hrtime(true) - $started;
        $diagnostics = $context->diagnostics();
        $gcAfter = gc_status();
        $zendMemoryAfter = memory_get_usage(false);
        $realMemoryAfter = memory_get_usage(true);
        $zendMemoryPeak = memory_get_peak_usage(false);
        $memoryPeak = memory_get_peak_usage(true);

        gc_collect_cycles();

        return new BenchmarkResult(
            case: $case->name(),
            iterations: $case->iterations(),
            totalNs: $totalNs,
            zendMemoryBefore: $zendMemoryBefore,
            zendMemoryAfter: $zendMemoryAfter,
            realMemoryBefore: $realMemoryBefore,
            realMemoryAfter: $realMemoryAfter,
            memoryPeak: $memoryPeak,
            zendMemoryPeak: $zendMemoryPeak,
            gcRootsBefore: self::gcRoots($gcBefore),
            gcRootsAfter: self::gcRoots($gcAfter),
            samplesNs: $samples,
            metadata: $metadata,
            diagnostics: $diagnostics,
        );
    }

    /**
     * @param list<BenchmarkResult> $results
     * @return list<string>
     */
    private function compareBaseline(array $results, string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Baseline file not found: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Unable to read baseline file: {$path}");
        }

        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $baselineResults = is_array($decoded) && isset($decoded['results']) && is_array($decoded['results'])
            ? $decoded['results']
            : [];

        $baselineByCase = [];
        foreach ($baselineResults as $result) {
            if (!is_array($result) || !isset($result['case']) || !is_string($result['case'])) {
                continue;
            }

            $baselineByCase[$result['case']] = $result;
        }

        $warnings = [];
        foreach ($results as $result) {
            $baseline = $baselineByCase[$result->case] ?? null;
            if (!is_array($baseline)) {
                continue;
            }

            foreach (['mean_us' => $result->meanUs(), 'p95_us' => $result->p95Us()] as $metric => $current) {
                $previous = $baseline[$metric] ?? null;
                if (!is_int($previous) && !is_float($previous)) {
                    continue;
                }
                if ($previous <= 0.0) {
                    continue;
                }

                $threshold = $this->thresholdForCase($result->case);
                $change = ($current - (float) $previous) / (float) $previous;
                if ($change <= $threshold) {
                    continue;
                }

                $warnings[] = sprintf(
                    'Benchmark regression warning: %s %s %.2fus -> %.2fus (%+.1f%%, threshold %.0f%%)',
                    $result->case,
                    $metric,
                    (float) $previous,
                    $current,
                    $change * 100,
                    $threshold * 100,
                );
            }
        }

        return $warnings;
    }

    private function thresholdForCase(string $case): float
    {
        $fanout = str_starts_with($case, 'concurrent_')
            || str_starts_with($case, 'singleflight_')
            || str_starts_with($case, 'cancel_');

        if ($fanout) {
            return $this->floatOption('fanout-threshold', 0.20);
        }

        return $this->floatOption('stable-threshold', 0.10);
    }

    private function floatOption(string $name, float $default): float
    {
        $raw = $this->options[$name] ?? null;
        if ($raw === null || !is_numeric($raw)) {
            return $default;
        }

        return max(0.0, (float) $raw);
    }

    /** @param array<string, mixed> $status */
    private static function gcRoots(array $status): int
    {
        $roots = $status['roots'] ?? 0;

        return is_int($roots) ? $roots : 0;
    }

    /** @return array<string, string> */
    private static function metadata(): array
    {
        return [
            'php' => PHP_VERSION,
            'php_binary' => PHP_BINARY,
            'commit' => self::command('git rev-parse --short HEAD'),
            'openswoole' => phpversion('openswoole') ?: 'unknown',
            'os' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
        ];
    }

    private static function command(string $command): string
    {
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 ? trim(implode("\n", $output)) : 'unknown';
    }

    /**
     * @param list<BenchmarkResult> $results
     * @param array<string, string> $metadata
     */
    private function printJson(array $results, array $metadata): void
    {
        echo json_encode(
            [
                'metadata' => $metadata,
                'results' => array_map(static fn(BenchmarkResult $result): array => $result->toArray(), $results),
            ],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
    }

    /**
     * @param list<BenchmarkResult> $results
     * @param array<string, string> $metadata
     */
    private function printTable(array $results, array $metadata): void
    {
        echo 'Phalanx Aegis kernel benchmarks' . PHP_EOL;
        echo "commit={$metadata['commit']} php={$metadata['php']} openswoole={$metadata['openswoole']}" . PHP_EOL;
        echo "php_binary={$metadata['php_binary']}" . PHP_EOL . PHP_EOL;

        printf(
            "%-34s %8s %10s %10s %10s %12s %13s %13s %8s\n",
            'case',
            'iter',
            'mean_us',
            'p95_us',
            'p99_us',
            'ops_sec',
            'real_delta_kb',
            'zend_delta_kb',
            'gc_roots',
        );

        foreach ($results as $result) {
            printf(
                "%-34s %8d %10.2f %10.2f %10.2f %12.2f %13.2f %13.2f %8d\n",
                $result->case,
                $result->iterations,
                $result->meanUs(),
                $result->p95Us(),
                $result->p99Us(),
                $result->opsPerSec(),
                $result->realMemoryDeltaKb(),
                $result->zendMemoryDeltaKb(),
                $result->gcRootsDelta(),
            );
        }
    }
}
