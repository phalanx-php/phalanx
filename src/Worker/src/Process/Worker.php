<?php

declare(strict_types=1);

namespace Phalanx\Worker\Process;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Runtime\CoroutineRuntime;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Worker\Protocol\ServiceCall;
use Phalanx\Worker\Protocol\TaskRequest;
use Phalanx\Worker\Runtime\ParentServiceProxy;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel as SwooleChannel;

class Worker
{
    private(set) WorkerState $state = WorkerState::Idle;

    private readonly ProcessHandle $process;

    private readonly string $id;

    /** @var SwooleChannel<true> */
    private SwooleChannel $lock;

    public function __construct(
        ProcessConfig $config,
        ?string $id = null,
    ) {
        $this->id = $id ?? uniqid('worker-', true);
        $this->process = new ProcessHandle($config);
        $this->lock = new SwooleChannel(1);
        $this->lock->push(true);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function mailboxSize(): int
    {
        return $this->state === WorkerState::Processing ? 1 : 0;
    }

    public function send(TaskRequest $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
    {
        $token->throwIfCancelled();
        $scope->throwIfCancelled();

        $this->acquire($scope, $token);

        try {
            if ($this->state === WorkerState::Crashed) {
                throw new RuntimeException("Worker {$this->id} crashed");
            }

            if ($this->state === WorkerState::Draining) {
                throw new RuntimeException("Worker {$this->id} is draining");
            }

            if (!$this->process->isRunning()) {
                $this->process->start($scope);
            }

            $this->state = WorkerState::Processing;
            $proxy = new ParentServiceProxy($scope);

            $result = $this->process->execute(
                $task,
                $scope,
                static fn(ServiceCall $call): mixed => $proxy->handle($call),
            );

            $this->state = WorkerState::Idle;

            return $result;
        } catch (\Throwable $e) {
            $this->state = $this->process->state() === ProcessState::Crashed
                ? WorkerState::Crashed
                : WorkerState::Idle;

            throw $e;
        } finally {
            $this->release();
        }
    }

    public function drain(): void
    {
        if ($this->state === WorkerState::Crashed) {
            return;
        }

        if (Coroutine::getCid() < 0) {
            $self = $this;
            CoroutineRuntime::run(
                RuntimePolicy::phalanxManaged(),
                static function () use ($self): void {
                    $self->drain();
                },
            );

            return;
        }

        if (!$this->acquireForDrain()) {
            return;
        }

        try {
            $this->state = WorkerState::Draining;
            $this->process->drain();
            $this->state = WorkerState::Idle;
        } finally {
            $this->release();
        }
    }

    public function kill(): void
    {
        $this->state = WorkerState::Crashed;
        $this->process->kill();
    }

    public function restart(): void
    {
        if ($this->state !== WorkerState::Crashed) {
            return;
        }

        $this->state = WorkerState::Idle;
    }

    private function acquire(TaskScope&TaskExecutor $scope, CancellationToken $token): void
    {
        while (true) {
            $token->throwIfCancelled();
            $scope->throwIfCancelled();

            $lock = $this->lock;
            $id = $this->id;
            $acquired = $scope->call(
                static fn(): mixed => $lock->pop(0.05),
                WaitReason::worker('worker', "{$id}.lock"),
            );

            if ($acquired !== false) {
                return;
            }

            if ($this->lock->errCode === SWOOLE_CHANNEL_CLOSED) {
                throw new RuntimeException("Worker {$this->id} lock closed");
            }
        }
    }

    private function acquireForDrain(): bool
    {
        while (true) {
            $acquired = $this->lock->pop(0.05);

            if ($acquired !== false) {
                return true;
            }

            if ($this->lock->errCode === SWOOLE_CHANNEL_CLOSED) {
                return false;
            }
        }
    }

    private function release(): void
    {
        $this->lock->push(true);
    }
}
