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
                    \OpenSwoole\Coroutine::run(static function () use ($report, $appContext, $body, $runner): void {
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
            'openswoole' => phpversion('openswoole') ?: 'unknown',
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
     */
    public function measure(string $name, int $iterations, Closure $work, int $warmups = 10): BenchmarkResult
    {
        $errors = 0;
        $cleanup = 'ok';
        $samples = [];
        $memoryBefore = 0;
        $memoryAfter = 0;
        $memoryPeak = 0;
        $totalNs = 0;

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

            $memoryBefore = memory_get_usage(false);
            $started = hrtime(true);

            // 4. Iterate
            for ($i = 0; $i < $iterations; $i++) {
                $iterationStarted = hrtime(true);
                $work($this->harness);
                $samples[] = hrtime(true) - $iterationStarted;
            }

            $totalNs = hrtime(true) - $started;

            // 5. Post-flight Check
            $this->harness->assertClean($name);
            
            $memoryPeak = memory_get_peak_usage(true);
        } catch (Throwable $e) {
            $errors++;
            $cleanup = 'failed';
            throw $e;
        } finally {
            // Always shutdown and clear harness tracked apps for this case
            $this->harness->shutdown();
            
            gc_collect_cycles();
            $memoryAfter = memory_get_usage(false);
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
        );
    }
}
