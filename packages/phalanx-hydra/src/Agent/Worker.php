<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Agent;

use OpenSwoole\Coroutine\Channel;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Hydra\Process\ProcessConfig;
use Phalanx\Hydra\Process\ProcessHandle;
use Phalanx\Hydra\Process\ProcessState;
use Phalanx\Hydra\Protocol\ServiceCall;
use Phalanx\Hydra\Protocol\TaskRequest;
use Phalanx\Hydra\Runtime\ParentServiceProxy;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use RuntimeException;

class Worker
{
    private(set) AgentState $state = AgentState::Idle;

    private readonly ProcessHandle $process;

    private readonly string $id;

    private readonly Channel $lock;

    public function __construct(
        ProcessConfig $config,
        ?string $id = null,
    ) {
        $this->id = $id ?? uniqid('agent-', true);
        $this->process = new ProcessHandle($config);
        $this->lock = new Channel(1);
        $this->lock->push(true);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function mailboxSize(): int
    {
        return $this->state === AgentState::Processing ? 1 : 0;
    }

    public function send(TaskRequest $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
    {
        $token->throwIfCancelled();
        $scope->throwIfCancelled();

        $this->acquire();

        try {
            if ($this->state === AgentState::Crashed) {
                throw new RuntimeException("Agent {$this->id} crashed");
            }

            if ($this->state === AgentState::Draining) {
                throw new RuntimeException("Agent {$this->id} is draining");
            }

            if (!$this->process->isRunning()) {
                $this->process->start($scope);
            }

            $this->state = AgentState::Processing;
            $proxy = new ParentServiceProxy($scope);

            $result = $this->process->execute(
                $task,
                $scope,
                static fn(ServiceCall $call): mixed => $proxy->handle($call),
            );

            $this->state = AgentState::Idle;
            return $result;
        } catch (\Throwable $e) {
            $this->state = $this->process->state() === ProcessState::Crashed
                ? AgentState::Crashed
                : AgentState::Idle;

            throw $e;
        } finally {
            $this->release();
        }
    }

    public function drain(): void
    {
        if ($this->state === AgentState::Crashed) {
            return;
        }

        $this->state = AgentState::Draining;
        $this->process->drain();
        $this->state = AgentState::Idle;
    }

    public function kill(): void
    {
        $this->state = AgentState::Crashed;
        $this->process->kill();
    }

    public function restart(): void
    {
        if ($this->state !== AgentState::Crashed) {
            return;
        }

        $this->state = AgentState::Idle;
    }

    private function acquire(): void
    {
        $acquired = $this->lock->pop();
        if ($acquired === false) {
            throw new RuntimeException("Agent {$this->id} lock closed");
        }
    }

    private function release(): void
    {
        $this->lock->push(true);
    }
}
