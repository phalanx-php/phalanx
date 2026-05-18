<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kernel\Cases;

use OpenSwoole\Core\Coroutine\WaitGroup;
use OpenSwoole\Coroutine;
use Phalanx\Benchmarks\Kernel\AbstractBenchmarkCase;
use Phalanx\Benchmarks\Kernel\BenchmarkContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

final class RawFiberContextSwitchCase extends AbstractBenchmarkCase
{
    private const int UNITS = 1_000;

    private const int SUSPENDS_PER_UNIT = 1_000;

    public function __construct()
    {
        parent::__construct('raw_fiber_context_switch_1k', 20, 2);
    }

    public function run(BenchmarkContext $context): void
    {
        $suspends = self::SUSPENDS_PER_UNIT;
        $fibers = [];

        for ($i = 0; $i < self::UNITS; $i++) {
            $fibers[] = new \Fiber(static function () use ($suspends): void {
                for ($j = 0; $j < $suspends; $j++) {
                    \Fiber::suspend();
                }
            });
        }

        foreach ($fibers as $fiber) {
            $fiber->start();
        }

        while (true) {
            $alive = false;

            foreach ($fibers as $fiber) {
                if (!$fiber->isTerminated()) {
                    $alive = true;
                    $fiber->resume();
                }
            }

            if (!$alive) {
                break;
            }
        }
    }
}

final class RawSwooleContextSwitchCase extends AbstractBenchmarkCase
{
    private const int UNITS = 1_000;

    private const int SUSPENDS_PER_UNIT = 1_000;

    public function __construct()
    {
        parent::__construct('raw_swoole_context_switch_1k', 20, 2);
    }

    public function run(BenchmarkContext $context): void
    {
        $wg = new WaitGroup();
        $suspends = self::SUSPENDS_PER_UNIT;

        for ($i = 0; $i < self::UNITS; $i++) {
            $wg->add(1);
            Coroutine::create(static function () use ($wg, $suspends): void {
                for ($j = 0; $j < $suspends; $j++) {
                    Coroutine::sleep(0);
                }
                $wg->done();
            });
        }

        $wg->wait();
    }
}

final class PhalanxManagedContextSwitchCase extends AbstractBenchmarkCase
{
    private const int UNITS = 1_000;

    private const int SUSPENDS_PER_UNIT = 1_000;

    public function __construct()
    {
        parent::__construct('phalanx_context_switch_1k', 20, 2);
    }

    public function run(BenchmarkContext $context): void
    {
        $scope = $context->scope();
        $suspends = self::SUSPENDS_PER_UNIT;
        $tasks = [];

        for ($i = 0; $i < self::UNITS; $i++) {
            $tasks[] = Task::named(
                "bench.ctx_switch.{$i}",
                static function (ExecutionScope $_scope) use ($suspends): int {
                    for ($j = 0; $j < $suspends; $j++) {
                        Coroutine::sleep(0);
                    }
                    return 1;
                },
            );
        }

        $scope->concurrent(...$tasks);
        $scope->dispose();
    }
}
