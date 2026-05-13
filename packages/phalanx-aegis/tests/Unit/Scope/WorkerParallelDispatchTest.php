<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use OpenSwoole\Coroutine\Channel;
use Phalanx\Application;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Scope\Scope;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\Supervisor\DispatchMode;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Worker\WorkerDispatch;
use Phalanx\Worker\WorkerTask;

final class WorkerParallelDispatchTest extends PhalanxTestCase
{
    public function testParallelDispatchesWorkerTasksAsWorkerRuns(): void
    {
        $dispatch = new RecordingParallelDispatch();

        $this->scope->run(static function () use ($dispatch): void {
            $inner = Application::starting()
                ->withWorkerDispatch($dispatch)
                ->compile()
                ->createScope();

            try {
                self::assertSame(
                    [0 => 10, 1 => 20],
                    $inner->parallel(new ValueWorkerTask(10), new ValueWorkerTask(20)),
                );
            } finally {
                $inner->dispose();
            }
        });

        self::assertSame([DispatchMode::Worker, DispatchMode::Worker], $dispatch->modes);
    }

    public function testSettleParallelCollectsWorkerFailures(): void
    {
        $dispatch = new RecordingParallelDispatch();

        $settled = $this->scope->run(static function () use ($dispatch): mixed {
            $inner = Application::starting()
                ->withWorkerDispatch($dispatch)
                ->compile()
                ->createScope();

            try {
                return $inner->settleParallel(
                    ok: new ValueWorkerTask(10),
                    err: new FailingWorkerTask('boom'),
                );
            } finally {
                $inner->dispose();
            }
        });

        self::assertSame(10, $settled->get('ok'));
        self::assertArrayHasKey('err', $settled->errors);
        self::assertSame('boom', $settled->errors['err']->getMessage());
    }

    public function testMapParallelDispatchesFactoryTasksWithLimit(): void
    {
        $dispatch = new LimitRecordingDispatch();
        $seen = [];

        $results = $this->scope->run(static function () use ($dispatch, &$seen): array {
            $app = Application::starting()
                ->withWorkerDispatch($dispatch)
                ->compile();
            $inner = $app->createScope();

            try {
                $out = $inner->mapParallel(
                    ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5],
                    static fn(int $value): WorkerTask => new ValueWorkerTask($value * 10),
                    limit: 2,
                    onEach: static function (string|int $key, mixed $value) use (&$seen): void {
                        $seen[$key] = $value;
                    },
                );
            } finally {
                $inner->dispose();
            }

            return $out;
        });

        ksort($seen);
        self::assertSame(['a' => 10, 'b' => 20, 'c' => 30, 'd' => 40, 'e' => 50], $results);
        self::assertSame(['a' => 10, 'b' => 20, 'c' => 30, 'd' => 40, 'e' => 50], $seen);
        self::assertSame(2, $dispatch->maxActive);
    }

    public function testParallelCancelsSiblingsOnFirstFailure(): void
    {
        $dispatch = new CoordinatedFailFastDispatch();

        $this->scope->run(static function () use ($dispatch): void {
            $inner = Application::starting()
                ->withWorkerDispatch($dispatch)
                ->compile()
                ->createScope();

            try {
                try {
                    $inner->parallel(
                        new BlockingWorkerTask(),
                        new FailingWorkerTask('fail-fast'),
                    );
                    self::fail('Expected fail-fast worker error was not thrown.');
                } catch (\RuntimeException $e) {
                    self::assertSame('fail-fast', $e->getMessage());
                }
            } finally {
                $inner->dispose();
            }
        });

        self::assertTrue($dispatch->blockedTaskWasCancelled);
    }
}

final class ValueWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly int $value,
    ) {
    }

    public function __invoke(Scope $scope): int
    {
        return $this->value;
    }
}

final class FailingWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly string $message,
    ) {
    }

    public function __invoke(Scope $scope): never
    {
        throw new \RuntimeException($this->message);
    }
}

final class BlockingWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __invoke(Scope $scope): int
    {
        return 1;
    }
}

final class RecordingParallelDispatch implements WorkerDispatch
{
    /** @var list<DispatchMode|null> */
    public array $modes = [];

    public function dispatch(WorkerTask $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
    {
        if (!$scope instanceof ExecutionLifecycleScope) {
            throw new \RuntimeException('Expected ExecutionLifecycleScope.');
        }

        $run = $scope->currentTaskRun();
        $this->modes[] = $run?->mode;

        if ($task instanceof ValueWorkerTask || $task instanceof FailingWorkerTask) {
            return $task($scope);
        }

        throw new \RuntimeException('Unexpected worker task in recording dispatch.');
    }

    public function shutdown(): void
    {
    }
}

final class CoordinatedFailFastDispatch implements WorkerDispatch
{
    private Channel $blockedStarted;

    private Channel $blockedCancelled;

    private(set) bool $blockedTaskWasCancelled = false;

    public function __construct()
    {
        $this->blockedStarted = new Channel(1);
        $this->blockedCancelled = new Channel(1);
    }

    public function dispatch(WorkerTask $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
    {
        if ($task instanceof BlockingWorkerTask) {
            $this->blockedStarted->push(true);
            $blockedCancelled = $this->blockedCancelled;
            $token->onCancel(static function () use ($blockedCancelled): void {
                $blockedCancelled->push(true);
            });
            $this->blockedCancelled->pop(1.0);
            $this->blockedTaskWasCancelled = $token->isCancelled;
            throw new Cancelled();
        }

        if ($task instanceof FailingWorkerTask) {
            if ($this->blockedStarted->pop(1.0) !== true) {
                throw new \RuntimeException('blocking worker did not start');
            }
            return $task($scope);
        }

        throw new \RuntimeException('Unexpected worker task in fail-fast dispatch.');
    }

    public function shutdown(): void
    {
        $this->blockedStarted->close();
        $this->blockedCancelled->close();
    }
}

final class LimitRecordingDispatch implements WorkerDispatch
{
    private int $active = 0;

    private(set) int $maxActive = 0;

    public function dispatch(WorkerTask $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
    {
        $this->active++;
        $this->maxActive = max($this->maxActive, $this->active);

        try {
            \OpenSwoole\Coroutine::sleep(0);

            if ($task instanceof ValueWorkerTask) {
                return $task($scope);
            }

            throw new \RuntimeException('Unexpected worker task in limit dispatch.');
        } finally {
            $this->active--;
        }
    }

    public function shutdown(): void
    {
    }
}
