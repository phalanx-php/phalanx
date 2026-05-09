<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Closure;
use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\DispatchMode;
use Phalanx\Supervisor\TaskRun;
use Phalanx\Task\Task;
use Phalanx\Tests\Support\CoroutineTestCase;
use Phalanx\Worker\WorkerDispatch;
use Phalanx\Worker\WorkerTask;
use RuntimeException;

final class InWorkerDispatchTest extends CoroutineTestCase
{
    public function testInWorkerRequiresConfiguredDispatch(): void
    {
        $this->runInCoroutine(static function (): void {
            $scope = Application::starting()
                ->compile()
                ->createScope();

            try {
                self::expectRuntimeException(
                    static fn(): mixed => $scope->inWorker(new InWorkerProbeTask()),
                    'no WorkerDispatch configured',
                );
            } finally {
                $scope->dispose();
            }
        });
    }

    public function testInWorkerPassesCurrentScopeAndCancellationTokenToDispatch(): void
    {
        $dispatch = new RecordingWorkerDispatch();

        $this->runInCoroutine(static function () use ($dispatch): void {
            $scope = Application::starting()
                ->withWorkerDispatch($dispatch)
                ->compile()
                ->createScope();

            try {
                $value = $scope->execute(Task::of(
                    static fn(ExecutionScope $s): mixed => $s->inWorker(new InWorkerProbeTask()),
                ));
            } finally {
                $scope->dispose();
            }

            self::assertSame('worker-result', $value);
            self::assertSame($scope, $dispatch->scope);
            self::assertInstanceOf(CancellationToken::class, $dispatch->token);
            self::assertInstanceOf(InWorkerProbeTask::class, $dispatch->task);
            self::assertInstanceOf(TaskRun::class, $dispatch->run);
            self::assertSame(DispatchMode::Worker, $dispatch->run->mode);
        });
    }

    public function testProviderWorkerDispatchMustBeSingleton(): void
    {
        self::expectRuntimeException(
            static fn(): mixed => Application::starting()
                ->providers(new ScopedWorkerDispatchBundle())
                ->compile(),
            'WorkerDispatch services must be registered as singletons',
        );
    }

    /** @param Closure(): mixed $callback */
    private static function expectRuntimeException(Closure $callback, string $message): void
    {
        try {
            $callback();
            self::fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}

class InWorkerProbeTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __invoke(Scope $scope): string
    {
        return 'unused';
    }
}

class RecordingWorkerDispatch implements WorkerDispatch
{
    public mixed $task = null;

    public mixed $scope = null;

    public ?CancellationToken $token = null;

    public ?TaskRun $run = null;

    public function dispatch(WorkerTask $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
    {
        if (!$scope instanceof ExecutionLifecycleScope) {
            throw new RuntimeException('Expected ExecutionLifecycleScope.');
        }

        $this->task = $task;
        $this->scope = $scope;
        $this->token = $token;
        $this->run = $scope->currentTaskRun();

        return 'worker-result';
    }

    public function shutdown(): void
    {
    }
}

class ScopedWorkerDispatchBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        $services->scoped(WorkerDispatch::class)
            ->factory(static fn(): WorkerDispatch => new RecordingWorkerDispatch());
    }
}
