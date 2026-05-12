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
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Worker\WorkerDispatch;
use Phalanx\Worker\WorkerTask;
use RuntimeException;

final class InWorkerDispatchTest extends PhalanxTestCase
{
    public function testInWorkerRequiresConfiguredDispatch(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $inner = Application::starting()
                ->compile()
                ->createScope();

            try {
                self::expectRuntimeException(
                    static fn(): mixed => $inner->inWorker(new InWorkerProbeTask()),
                    'no WorkerDispatch configured',
                );
            } finally {
                $inner->dispose();
            }
        });
    }

    public function testInWorkerPassesCurrentScopeAndCancellationTokenToDispatch(): void
    {
        $dispatch = new RecordingWorkerDispatch();

        $this->scope->run(static function (ExecutionScope $_scope) use ($dispatch): void {
            $inner = Application::starting()
                ->withWorkerDispatch($dispatch)
                ->compile()
                ->createScope();

            try {
                $value = $inner->execute(Task::of(
                    static fn(ExecutionScope $s): mixed => $s->inWorker(new InWorkerProbeTask()),
                ));
            } finally {
                $inner->dispose();
            }

            self::assertSame('worker-result', $value);
            self::assertSame($inner, $dispatch->scope);
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
