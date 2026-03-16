<?php

declare(strict_types=1);

namespace Convoy\Parallel\Supervisor;

use Convoy\Parallel\Agent\AgentState;
use Convoy\Parallel\Agent\Worker;
use Convoy\Parallel\Dispatch\Dispatcher;
use Convoy\Parallel\Dispatch\DispatchStrategy;
use Convoy\Parallel\Dispatch\LeastMailboxDispatcher;
use Convoy\Parallel\Dispatch\RoundRobinDispatcher;
use Convoy\Parallel\Process\ProcessConfig;
use Convoy\Parallel\Protocol\ServiceCall;
use Convoy\Parallel\Runtime\ParentServiceProxy;
use Convoy\Service\LazySingleton;
use Convoy\Service\ServiceGraph;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;

use function React\Promise\all;

final class WorkerSupervisor
{
    private bool $started = false;
    
    /** @var list<Worker> */
    private array $agents = [];
    /** @var array<string, list<float>> */
    private array $restartHistory = [];

    private ?Dispatcher $dispatcher = null;
    private ?ParentServiceProxy $serviceProxy = null;
    private ?TimerInterface $crashMonitorTimer = null;

    public function __construct(
        private readonly SupervisorConfig $config,
        private readonly ProcessConfig $processConfig,
        private readonly LoopInterface $loop,
        private readonly ServiceGraph $graph,
        private readonly LazySingleton $singletons,
    ) {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->serviceProxy = new ParentServiceProxy($this->graph, $this->singletons);

        for ($i = 0; $i < $this->config->agents; $i++) {
            $agent = new Worker(
                config: $this->processConfig,
                loop: $this->loop,
                mailboxLimit: $this->config->mailboxLimit,
                id: sprintf('agent-%d', $i),
            );

            $agent->setServiceHandler(fn(ServiceCall $call) => $this->serviceProxy->handle($call));
            $this->agents[] = $agent;
        }

        $this->dispatcher = $this->createDispatcher();
        $this->startCrashMonitor();
    }

    /**
     * @return list<Worker>
     */
    public function agents(): array
    {
        return $this->agents;
    }

    public function dispatcher(): Dispatcher
    {
        if ($this->dispatcher === null) {
            throw new \RuntimeException('Supervisor not started');
        }

        return $this->dispatcher;
    }

    public function shutdown(): PromiseInterface
    {
        if (!$this->started) {
            return \React\Promise\resolve(null);
        }

        $this->stopCrashMonitor();

        $promises = [];

        foreach ($this->agents as $agent) {
            $promises[] = $agent->drain();
        }

        return all($promises)->finally(function (): void {
            $this->started = false;
            $this->agents = [];
            $this->dispatcher = null;
        });
    }

    public function kill(): void
    {
        $this->stopCrashMonitor();

        foreach ($this->agents as $agent) {
            $agent->kill();
        }

        $this->agents = [];
        $this->started = false;
    }

    private function createDispatcher(): Dispatcher
    {
        return match ($this->config->dispatchStrategy) {
            DispatchStrategy::RoundRobin => new RoundRobinDispatcher($this->agents),
            DispatchStrategy::LeastMailbox => new LeastMailboxDispatcher($this->agents),
        };
    }

    private function startCrashMonitor(): void
    {
        $this->crashMonitorTimer = $this->loop->addPeriodicTimer(0.5, function (): void {
            foreach ($this->agents as $agent) {
                if ($agent->state === AgentState::Crashed) {
                    $this->handleCrash($agent);
                }
            }
        });
    }

    private function stopCrashMonitor(): void
    {
        if ($this->crashMonitorTimer !== null) {
            $this->loop->cancelTimer($this->crashMonitorTimer);
            $this->crashMonitorTimer = null;
        }
    }

    private function handleCrash(Worker $agent): void
    {
        match ($this->config->supervision) {
            SupervisorStrategy::RestartOnCrash => $this->attemptRestart($agent),
            SupervisorStrategy::StopAll => $this->kill(),
            SupervisorStrategy::Ignore => null,
        };
    }

    private function attemptRestart(Worker $agent): void
    {
        $agentId = $agent->id();
        $now = hrtime(true) / 1e9;

        $this->restartHistory[$agentId] ??= [];
        $this->restartHistory[$agentId][] = $now;

        $windowStart = $now - $this->config->restartWindow;
        $this->restartHistory[$agentId] = array_filter(
            $this->restartHistory[$agentId],
            static fn(float $time) => $time >= $windowStart
        );

        if (count($this->restartHistory[$agentId]) > $this->config->maxRestarts) {
            error_log("[Supervisor] Agent {$agentId} exceeded max restarts ({$this->config->maxRestarts}), not restarting");
            return;
        }

        error_log("[Supervisor] Restarting agent {$agentId}");
        $agent->restart();
    }
}
