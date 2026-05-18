<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kernel;

use Phalanx\Application;
use Phalanx\Benchmarks\Kit\BenchmarkCase;
use Phalanx\Runtime\Memory\RuntimeTableStats;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\LedgerStorage;
use Phalanx\Supervisor\SwooleTableLedger;
use Phalanx\Supervisor\TaskTreeFormatter;
use RuntimeException;

interface KernelBenchmarkCase extends BenchmarkCase
{
    public function run(BenchmarkContext $context): void;

    public function cleanup(): void;
}

final class BenchmarkContext
{
    private ?Application $defaultApp = null;

    /** @var array<int, Application> */
    private array $apps = [];

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

    public function appWithPoolCapacities(int $taskRun, int $scopeFrame, int $token): Application
    {
        return $this->track(Application::starting([])
            ->withLedger(new InProcessLedger())
            ->withPoolCapacities($taskRun, $scopeFrame, $token)
            ->compile());
    }

    public function scope(?LedgerStorage $ledger = null): ExecutionScope
    {
        return $this->app($ledger)->createScope();
    }

    public function assertClean(string $case): void
    {
        foreach ($this->apps as $app) {
            $supervisor = $app->supervisor();
            $tree = $supervisor->tree();

            if ($supervisor->liveCount() !== 0 || $tree !== []) {
                $formatted = (new TaskTreeFormatter())->format($tree);

                throw new RuntimeException(
                    "Benchmark case '{$case}' left live or unreaped task runs:\n{$formatted}",
                );
            }

            $liveScopes = $supervisor->liveScopeCount();
            if ($liveScopes !== 0) {
                throw new RuntimeException(
                    "Benchmark case '{$case}' left {$liveScopes} live scopes.",
                );
            }

            $borrowed = $supervisor->poolStats()->taskRun->borrowed;
            if ($borrowed !== 0) {
                throw new RuntimeException(
                    "Benchmark case '{$case}' left {$borrowed} borrowed task runs.",
                );
            }
        }
    }

    /** @return array{apps: list<array{pool_stats: array<string, mixed>, runtime_memory: list<array{name: string, configured_rows: int, current_rows: int, memory_size: int, high_water_rows: int}>}>} */
    public function diagnostics(): array
    {
        $apps = [];

        foreach ($this->apps as $app) {
            $ledger = $app->supervisor()->ledger;
            $memory = $ledger instanceof SwooleTableLedger
                ? $ledger->memory
                : $app->runtime()->memory;

            $apps[] = [
                'pool_stats' => $app->supervisor()->poolStats()->toArray(),
                'runtime_memory' => array_map(
                    static fn(RuntimeTableStats $stats): array => [
                        'name' => $stats->name,
                        'configured_rows' => $stats->configuredRows,
                        'current_rows' => $stats->currentRows,
                        'memory_size' => $stats->memorySize,
                        'high_water_rows' => $stats->highWaterRows,
                    ],
                    $memory->stats(),
                ),
            ];
        }

        return ['apps' => $apps];
    }

    public function shutdown(): void
    {
        foreach ($this->apps as $app) {
            $ledger = $app->supervisor()->ledger;
            if ($ledger instanceof SwooleTableLedger) {
                $ledger->memory->shutdown();
            }

            $app->shutdown();
        }

        $this->apps = [];
        $this->defaultApp = null;
    }

    private function track(Application $app): Application
    {
        $this->apps[spl_object_id($app)] = $app;

        return $app;
    }
}

abstract class AbstractBenchmarkCase implements KernelBenchmarkCase
{
    public function __construct(
        private(set) string $caseName,
        private(set) int $caseIterations,
        private(set) int $caseWarmups = 5,
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

    public function cleanup(): void
    {
    }
}
