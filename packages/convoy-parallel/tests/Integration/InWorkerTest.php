<?php

declare(strict_types=1);

namespace Convoy\Parallel\Tests\Integration;

use Convoy\Application;
use Convoy\ExecutionScope;
use Convoy\Parallel\ParallelConfig;
use Convoy\Task\Task;
use Convoy\Tests\Support\AsyncTestCase;
use Convoy\Tests\Support\Fixtures\AddNumbers;
use Convoy\Tests\Support\Fixtures\CpuIntensiveTask;
use Convoy\Tests\Support\Fixtures\TaskThatThrows;
use Convoy\Tests\Support\TestServiceBundle;
use PHPUnit\Framework\Attributes\Test;

final class InWorkerTest extends AsyncTestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $config = new ParallelConfig(agents: 2);
        $bundle = TestServiceBundle::create();

        $this->app = Application::starting()
            ->providers($bundle)
            ->withWorkerDispatch($config->workerDispatchFactory())
            ->compile();

        $this->app->startup();
    }

    protected function tearDown(): void
    {
        $this->app->shutdown();
    }

    #[Test]
    public function executes_simple_task_in_worker(): void
    {
        $this->runAsync(function (): void {
            $scope = $this->app->createScope();

            $result = $scope->inWorker(new AddNumbers(2, 3));

            $this->assertSame(5, $result);

            $scope->dispose();
        });
    }

    #[Test]
    public function executes_cpu_intensive_task(): void
    {
        $this->runAsync(function (): void {
            $scope = $this->app->createScope();

            $result = $scope->inWorker(new CpuIntensiveTask(100));

            $this->assertSame(4950, $result);

            $scope->dispose();
        });
    }

    #[Test]
    public function propagates_exceptions_from_worker(): void
    {
        $this->runAsync(function (): void {
            $scope = $this->app->createScope();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Intentional failure');

            try {
                $scope->inWorker(new TaskThatThrows('Intentional failure'));
            } finally {
                $scope->dispose();
            }
        });
    }

    #[Test]
    public function multiple_tasks_execute_sequentially(): void
    {
        $this->runAsync(function (): void {
            $scope = $this->app->createScope();

            $result1 = $scope->inWorker(new AddNumbers(1, 2));
            $result2 = $scope->inWorker(new AddNumbers(3, 4));
            $result3 = $scope->inWorker(new AddNumbers(5, 6));

            $this->assertSame(3, $result1);
            $this->assertSame(7, $result2);
            $this->assertSame(11, $result3);

            $scope->dispose();
        });
    }

    #[Test]
    public function parallel_tasks_execute_concurrently(): void
    {
        $this->runAsync(function (): void {
            $scope = $this->app->createScope();

            $results = $scope->execute(Task::of(
                static fn(ExecutionScope $es): array => $es->concurrent([
                    'a' => Task::of(static fn(ExecutionScope $s) => $s->inWorker(new AddNumbers(1, 2))),
                    'b' => Task::of(static fn(ExecutionScope $s) => $s->inWorker(new AddNumbers(3, 4))),
                ])
            ));

            $this->assertSame(3, $results['a']);
            $this->assertSame(7, $results['b']);

            $scope->dispose();
        });
    }
}
