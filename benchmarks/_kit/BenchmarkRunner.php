<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kit;

use Closure;
use Phalanx\Boot\AppContext;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
use Throwable;

/**
 * Owns the sampling lifecycle and measurement orchestration.
 */
final class BenchmarkRunner
{
    private readonly BenchmarkApp $harness;

    public function __construct(private readonly AppContext $context)
    {
        $this->harness = new BenchmarkApp($this->context);
    }

    /**
     * Boots a benchmark suite. Returns a closure for symfony/runtime.
     *
     * @param Closure(BenchmarkReport, AppContext): void $body
     */
    public static function boot(string $title, Closure $body): Closure
    {
        return static fn (array $context): Closure =>
            static function () use ($context, $title, $body): int {
                $appContext = new AppContext($context);
                RuntimeHooks::ensure(RuntimePolicy::fromContext($appContext));

                $options = self::parseOptions($context['argv'] ?? []);
                $metadata = self::metadata();

                $report = new BenchmarkReport($title);
                $report->setOptions($options);
                $report->setMetadata($metadata);

                $runner = new self($appContext);
                $report->setRunner($runner);

                try {
                    \Swoole\Coroutine\run(static function () use ($report, $appContext, $body): void {
                        $body($report, $appContext);
                    });
                } catch (Throwable $e) {
                    fwrite(STDERR, "Benchmark execution failed: " . $e->getMessage() . PHP_EOL);
                    fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
                    return 1;
                }

                return $report->render();
            };
    }

    /**
     * @param list<string> $argv
     * @return array<string, string>
     */
    private static function parseOptions(array $argv): array
    {
        $options = [];

        foreach ($argv as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }

            $parts = explode('=', substr($arg, 2), 2);
            $options[$parts[0]] = $parts[1] ?? '1';
        }

        return $options;
    }

    /** @return array<string, mixed> */
    private static function metadata(): array
    {
        return [
            'php' => PHP_VERSION,
            'php_binary' => PHP_BINARY,
            'commit' => self::command('git rev-parse --short HEAD'),
            'dirty' => self::command('git status --short') === '' ? 'no' : 'yes',
            'swoole' => phpversion('swoole') ?: 'unknown',
            'os' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
            'xdebug' => extension_loaded('xdebug') ? 'yes' : 'no',
        ];
    }

    private static function command(string $command): string
    {
        $output = [];
        $exitCode = 0;
        @exec($command . ' 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 ? trim(implode("\n", $output)) : 'unknown';
    }

    /**
     * Measures a specific operation and returns the result.
     *
     * @param \Closure(): array<string, mixed> $captureExtras
     */
    public function measure(
        string $name,
        int $iterations,
        Closure $work,
        int $warmups = 10,
        ?Closure $captureExtras = null,
    ): BenchmarkResult {
        $errors = 0;
        $cleanup = 'ok';
        $samples = [];
        $memoryBefore = 0;
        $memoryAfter = 0;
        $memoryPeak = 0;
        $zendMemoryBefore = 0;
        $zendMemoryAfter = 0;
        $zendMemoryPeak = 0;
        $realMemoryBefore = 0;
        $realMemoryAfter = 0;
        $realMemoryPeak = 0;
        $gcRootsBefore = 0;
        $gcRootsAfter = 0;
        $totalNs = 0;
        $diagnostics = [];

        try {
            // 1. Warmup
            for ($i = 0; $i < $warmups; $i++) {
                $work($this->harness);
            }

            // 2. Pre-flight Check
            $this->harness->assertClean($name);

            // 3. Reset
            gc_collect_cycles();
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }

            $gcBefore = gc_status();
            $gcRootsBefore = $gcBefore['roots'];
            $memoryBefore = memory_get_usage(false);
            $zendMemoryBefore = memory_get_usage(false);
            $realMemoryBefore = memory_get_usage(true);
            $started = hrtime(true);

            // 4. Iterate
            for ($i = 0; $i < $iterations; $i++) {
                $iterationStarted = hrtime(true);
                $work($this->harness);
                $samples[] = hrtime(true) - $iterationStarted;
            }

            $totalNs = hrtime(true) - $started;

            $gcAfter = gc_status();
            $gcRootsAfter = $gcAfter['roots'];
            $zendMemoryAfter = memory_get_usage(false);
            $realMemoryAfter = memory_get_usage(true);
            $memoryAfter = $realMemoryAfter;
            $zendMemoryPeak = memory_get_peak_usage(false);
            $realMemoryPeak = memory_get_peak_usage(true);
            $memoryPeak = $realMemoryPeak;

            // 5. Post-flight Check
            $this->harness->assertClean($name);

            $diagnostics = $captureExtras !== null ? $captureExtras() : [];
        } catch (Throwable $e) {
            $errors++;
            $cleanup = 'failed';
            throw $e;
        } finally {
            $this->harness->shutdown();
        }

        gc_collect_cycles();

        return new BenchmarkResult(
            case: $name,
            iterations: $iterations,
            totalNs: $totalNs,
            memoryBefore: $memoryBefore,
            memoryAfter: $memoryAfter,
            memoryPeak: $memoryPeak,
            errors: $errors,
            cleanup: $cleanup,
            samplesNs: $samples,
            zendMemoryBefore: $zendMemoryBefore,
            zendMemoryAfter: $zendMemoryAfter,
            zendMemoryPeak: $zendMemoryPeak,
            realMemoryBefore: $realMemoryBefore,
            realMemoryAfter: $realMemoryAfter,
            realMemoryPeak: $realMemoryPeak,
            gcRootsBefore: $gcRootsBefore,
            gcRootsAfter: $gcRootsAfter,
            diagnostics: $diagnostics,
        );
    }
}
