<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Supervisor;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Hydra\Agent\AgentState;
use Phalanx\Hydra\Agent\Worker;
use Phalanx\Hydra\Dispatch\Dispatcher;
use Phalanx\Hydra\Dispatch\DispatchStrategy;
use Phalanx\Hydra\Dispatch\LeastMailboxDispatcher;
use Phalanx\Hydra\Dispatch\RoundRobinDispatcher;
use Phalanx\Hydra\Process\ProcessConfig;
use Phalanx\Hydra\Protocol\TaskRequest;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

class WorkerSupervisor
{
    private bool $started = false;

    /** @var list<Worker> */
    private array $agents = [];

    /** @var array<string, list<float>> */
    private array $restartHistory = [];

    private ?Dispatcher $dispatcher = null;

    public function __construct(
        private readonly SupervisorConfig $config,
        private readonly ProcessConfig $processConfig,
    ) {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        for ($i = 0; $i < $this->config->agents; $i++) {
            $this->agents[] = new Worker(
                config: $this->processConfig,
                id: sprintf('agent-%d', $i),
            );
        }

        $this->dispatcher = $this->createDispatcher();
    }

    /** @return list<Worker> */
    public function agents(): array
    {
        return $this->agents;
    }

    public function dispatch(TaskRequest $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
    {
        $this->start();

        try {
            return $this->dispatcher()->dispatch($task, $scope, $token);
        } catch (\Throwable $e) {
            $this->handleCrashedAgents();
            throw $e;
        }
    }

    public function shutdown(): void
    {
        if (!$this->started) {
            return;
        }

        foreach ($this->agents as $agent) {
            $agent->drain();
        }

        $this->started = false;
        $this->agents = [];
        $this->dispatcher = null;
    }

    public function kill(): void
    {
        foreach ($this->agents as $agent) {
            $agent->kill();
        }

        $this->agents = [];
        $this->started = false;
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
            DispatchStrategy::RoundRobin => new RoundRobinDispatcher($this->agents),
            DispatchStrategy::LeastMailbox => new LeastMailboxDispatcher($this->agents),
        };
    }

    private function handleCrashedAgents(): void
    {
        foreach ($this->agents as $agent) {
            if ($agent->state !== AgentState::Crashed) {
                continue;
            }

            match ($this->config->supervision) {
                SupervisorStrategy::RestartOnCrash => $this->attemptRestart($agent),
                SupervisorStrategy::StopAll => $this->kill(),
                SupervisorStrategy::Ignore => null,
            };
        }
    }

    private function attemptRestart(Worker $agent): void
    {
        $agentId = $agent->id();
        $now = hrtime(true) / 1e9;

        $this->restartHistory[$agentId] ??= [];
        $this->restartHistory[$agentId][] = $now;

        $windowStart = $now - $this->config->restartWindow;
        $this->restartHistory[$agentId] = array_values(array_filter(
            $this->restartHistory[$agentId],
            static fn(float $time): bool => $time >= $windowStart,
        ));

        if (count($this->restartHistory[$agentId]) > $this->config->maxRestarts) {
            error_log("[Supervisor] Agent {$agentId} exceeded max restarts ({$this->config->maxRestarts})");
            return;
        }

        error_log("[Supervisor] Restarting agent {$agentId}");
        $agent->restart();
    }
}
