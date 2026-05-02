#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kernel;

use OpenSwoole\Coroutine;
use Throwable;

use function Phalanx\Benchmarks\Kernel\Cases\aegisKernelCases;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/BenchmarkCase.php';
require __DIR__ . '/cases/CoreCases.php';

Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);

$caught = null;
$exitCode = 0;

Coroutine::run(static function () use (&$caught, &$exitCode): void {
    try {
        $exitCode = (new Runner($_SERVER['argv'] ?? []))->run();
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

        $context = new BenchmarkContext();
        $metadata = self::metadata();
        $results = [];

        foreach ($cases as $case) {
            $results[] = $this->measure($case, $context, $metadata);
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
        echo "Usage: php benchmarks/kernel/run.php [--case=name] [--format=table|json]\n";
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
        $memoryBefore = memory_get_usage(true);
        $started = hrtime(true);

        for ($i = 0; $i < $case->iterations(); $i++) {
            $iterationStarted = hrtime(true);
            $case->run($context);
            $samples[] = hrtime(true) - $iterationStarted;
        }

        $totalNs = hrtime(true) - $started;
        $memoryAfter = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);

        gc_collect_cycles();

        return new BenchmarkResult(
            case: $case->name(),
            iterations: $case->iterations(),
            totalNs: $totalNs,
            memoryBefore: $memoryBefore,
            memoryAfter: $memoryAfter,
            memoryPeak: $memoryPeak,
            samplesNs: $samples,
            metadata: $metadata,
        );
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
            "%-34s %8s %10s %10s %10s %12s %13s\n",
            'case',
            'iter',
            'mean_us',
            'p95_us',
            'p99_us',
            'ops_sec',
            'mem_delta_kb',
        );

        foreach ($results as $result) {
            printf(
                "%-34s %8d %10.2f %10.2f %10.2f %12.2f %13.2f\n",
                $result->case,
                $result->iterations,
                $result->meanUs(),
                $result->p95Us(),
                $result->p99Us(),
                $result->opsPerSec(),
                $result->memoryDeltaKb(),
            );
        }
    }
}
