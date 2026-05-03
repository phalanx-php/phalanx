<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Http;

use Throwable;

use function Phalanx\Benchmarks\Http\Cases\stoaHttpCases;

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

    /** @return array<string, string> */
    private static function metadata(): array
    {
        return [
            'php' => PHP_VERSION,
            'php_binary' => PHP_BINARY,
            'commit' => self::command('git rev-parse --short HEAD'),
            'dirty' => self::command('git status --short') === '' ? 'no' : 'yes',
            'openswoole' => phpversion('openswoole') ?: 'unknown',
            'os' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
            'xdebug' => extension_loaded('xdebug') ? 'yes' : 'no',
        ];
    }

    private static function command(string $command): string
    {
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 ? trim(implode("\n", $output)) : 'unknown';
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
            $results[] = $this->measure($case, $metadata);
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
        echo "Usage: php benchmarks/http/run.php [--case=name] [--format=table|json]\n";
        echo "\nAvailable cases:\n";

        foreach (stoaHttpCases()->cases as $case) {
            echo "  - {$case->name()}\n";
        }
    }

    /** @return list<HttpBenchmarkCase> */
    private function selectedCases(): array
    {
        $filter = $this->options['case'] ?? null;

        return array_values(array_filter(
            stoaHttpCases()->cases,
            static fn(HttpBenchmarkCase $case): bool => $filter === null || $case->name() === $filter,
        ));
    }

    /** @param array<string, string> $metadata */
    private function measure(HttpBenchmarkCase $case, array $metadata): HttpBenchmarkResult
    {
        $context = new HttpBenchmarkContext();
        $errors = 0;
        $cleanup = 'ok';

        try {
            for ($i = 0; $i < $case->warmups(); $i++) {
                $case->run($context);
            }

            $context->assertClean($case->name());

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

            $context->assertClean($case->name());
        } catch (Throwable $e) {
            $errors++;
            $cleanup = 'failed';
            throw $e;
        } finally {
            $context->shutdown();
        }

        $totalNs = hrtime(true) - $started;
        $memoryAfter = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);

        gc_collect_cycles();

        return new HttpBenchmarkResult(
            case: $case->name(),
            iterations: $case->iterations(),
            totalNs: $totalNs,
            memoryBefore: $memoryBefore,
            memoryAfter: $memoryAfter,
            memoryPeak: $memoryPeak,
            errors: $errors,
            cleanup: $cleanup,
            samplesNs: $samples,
            metadata: $metadata,
        );
    }

    /**
     * @param list<HttpBenchmarkResult> $results
     * @param array<string, string> $metadata
     */
    private function printJson(array $results, array $metadata): void
    {
        echo json_encode(
            [
                'metadata' => $metadata,
                'results' => array_map(static fn(HttpBenchmarkResult $result): array => $result->toArray(), $results),
            ],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
    }

    /**
     * @param list<HttpBenchmarkResult> $results
     * @param array<string, string> $metadata
     */
    private function printTable(array $results, array $metadata): void
    {
        echo 'Phalanx Stoa HTTP benchmarks' . PHP_EOL;
        echo "commit={$metadata['commit']} dirty={$metadata['dirty']} php={$metadata['php']} "
            . "openswoole={$metadata['openswoole']}"
            . PHP_EOL;
        echo "php_binary={$metadata['php_binary']} xdebug={$metadata['xdebug']}" . PHP_EOL . PHP_EOL;

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

        foreach ($results as $result) {
            printf(
                "%-38s %8d %10.2f %10.2f %10.2f %12.2f %13.2f %8d %8s\n",
                $result->case,
                $result->iterations,
                $result->meanUs(),
                $result->p95Us(),
                $result->p99Us(),
                $result->opsPerSec(),
                $result->memoryDeltaKb(),
                $result->errors,
                $result->cleanup,
            );
        }
    }
}
