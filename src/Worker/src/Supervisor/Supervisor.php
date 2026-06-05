<?php

declare(strict_types=1);

namespace Phalanx\Worker\Supervisor;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\Worker\Dispatch\Dispatcher;
use Phalanx\Worker\Dispatch\DispatchStrategy;
use Phalanx\Worker\Dispatch\LeastMailboxDispatcher;
use Phalanx\Worker\Dispatch\RoundRobinDispatcher;
use Phalanx\Worker\Process\ProcessConfig;
use Phalanx\Worker\Process\Worker;
use Phalanx\Worker\Process\WorkerState;
use Phalanx\Worker\Protocol\TaskRequest;
use Swoole\Atomic;

class Supervisor
{
    private Atomic $started;

    /** @var list<Worker> */
    private array $workers = [];

    /** @var array<string, list<float>> */
    private array $restartHistory = [];

    private ?Dispatcher $dispatcher = null;

    public function __construct(
        private readonly SupervisorConfig $config,
        private readonly ProcessConfig $processConfig,
    ) {
        $this->started = new Atomic(0);
    }

    public function start(): void
    {
        if (!$this->started->cmpset(0, 1)) {
            return;
        }

        for ($i = 0; $i < $this->config->agents; $i++) {
            $this->workers[] = new Worker(
                config: $this->processConfig,
                id: sprintf('worker-%d', $i),
            );
        }

        $this->dispatcher = $this->createDispatcher();
    }

    /** @return list<Worker> */
    public function workers(): array
    {
        return $this->workers;
    }

    public function dispatch(TaskRequest $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
    {
        $this->start();

        try {
            return $this->dispatcher()->dispatch($task, $scope, $token);
        } catch (\Throwable $e) {
            $this->handleCrashedWorkers();

            throw $e;
        }
    }

    public function shutdown(): void
    {
        if (!$this->started->cmpset(1, 0)) {
            return;
        }

        foreach ($this->workers as $worker) {
            $worker->drain();
        }

        $this->workers = [];
        $this->dispatcher = null;
    }

    public function kill(): void
    {
        foreach ($this->workers as $worker) {
            $worker->kill();
        }

        $this->workers = [];
        $this->started->set(0);
        $this->dispatcher = null;
    }

    private function dispatcher(): Dispatcher
    {
        if ($this->dispatcher === null) {
            throw new \RuntimeException('Supervisor not started');
        }

        return $this->dispatcher;
    }

    private function createDispatcher(): Dispatcher
    {
        return match ($this->config->dispatchStrategy) {
            DispatchStrategy::RoundRobin => new RoundRobinDispatcher($this->workers),
            DispatchStrategy::LeastMailbox => new LeastMailboxDispatcher($this->workers),
        };
    }

    private function handleCrashedWorkers(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->state !== WorkerState::Crashed) {
                continue;
            }

            match ($this->config->supervision) {
                SupervisorStrategy::RestartOnCrash => $this->attemptRestart($worker),
                SupervisorStrategy::StopAll => $this->kill(),
                SupervisorStrategy::Ignore => null,
            };
        }
    }

    private function attemptRestart(Worker $worker): void
    {
        $workerId = $worker->id();
        $now = hrtime(true) / 1e9;

        $this->restartHistory[$workerId] ??= [];
        $this->restartHistory[$workerId][] = $now;

        $windowStart = $now - $this->config->restartWindow;
        $this->restartHistory[$workerId] = array_values(array_filter(
            $this->restartHistory[$workerId],
            static fn(float $time): bool => $time >= $windowStart,
        ));

        if (count($this->restartHistory[$workerId]) > $this->config->maxRestarts) {
            return;
        }

        $worker->restart();
    }
}
