<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Closure;
use Phalanx\Application;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;
use Phalanx\Tests\Support\CoroutineTestCase;
use Phalanx\Worker\WorkerDispatch;
use RuntimeException;

final class InWorkerDispatchTest extends CoroutineTestCase
{
    public function testInWorkerRequiresConfiguredDispatch(): void
    {
        $this->runInCoroutine(static function (): void {
            $scope = Application::starting()
                ->compile()
                ->createScope();

            self::expectRuntimeException(
                static fn(): mixed => $scope->inWorker(new InWorkerProbeTask()),
                'no WorkerDispatch configured',
            );
        });
    }

    public function testInWorkerRejectsClosuresAtProcessBoundary(): void
    {
        $this->runInCoroutine(static function (): void {
            $scope = Application::starting()
                ->withWorkerDispatch(new RecordingWorkerDispatch())
                ->compile()
                ->createScope();

            self::expectRuntimeException(
                static fn(): mixed => $scope->inWorker(static fn(): string => 'unsupported'),
                'Closure cannot cross process boundary',
            );
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

            $value = $scope->execute(Task::of(
                static fn(ExecutionScope $s): mixed => $s->inWorker(new InWorkerProbeTask()),
            ));

            self::assertSame('worker-result', $value);
            self::assertSame($scope, $dispatch->scope);
            self::assertInstanceOf(CancellationToken::class, $dispatch->token);
            self::assertInstanceOf(InWorkerProbeTask::class, $dispatch->task);
        });
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

class InWorkerProbeTask implements Scopeable
{
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

    public function dispatch(Scopeable|Executable $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
    {
        $this->task = $task;
        $this->scope = $scope;
        $this->token = $token;

        return 'worker-result';
    }

    public function shutdown(): void
    {
    }
}
