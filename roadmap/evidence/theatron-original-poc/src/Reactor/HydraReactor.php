<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactor;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Hydra\Process\ProcessConfig;
use Phalanx\Hydra\Protocol\Codec;
use Phalanx\Hydra\Protocol\TaskRequest;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskHandle;
use Phalanx\System\StreamingProcess;
use Phalanx\Theatron\Stream\ProcessStreamBridge;
use Phalanx\Theatron\Stream\TheatronStream;

final class HydraReactor implements BackgroundReactor
{
    public ReactorState $state { get => $this->currentState; }
    public ReactorExclusivity $exclusivity { get => $this->exclusivityMode; }

    private ReactorState $currentState = ReactorState::Idle;
    private ?TaskHandle $taskHandle = null;
    private ?ProcessStreamBridge $bridge = null;

    /** @var list<float> */
    private array $restartTimestamps = [];

    /**
     * @param class-string $taskClass
     * @param array<string, mixed> $constructorArgs
     */
    public function __construct(
        private(set) string $id,
        private string $taskClass,
        private(set) ?string $group = null,
        private array $constructorArgs = [],
        private RestartPolicy $restartPolicy = new RestartPolicy(),
        private ReactorExclusivity $exclusivityMode = ReactorExclusivity::Exclusive,
        private ?ProcessConfig $processConfig = null,
    ) {
    }

    public function start(ExecutionScope $scope, TheatronStream $stream): void
    {
        if ($this->currentState === ReactorState::Running) {
            return;
        }

        $this->currentState = ReactorState::Running;

        $reactor = $this;

        $this->taskHandle = $scope->go(static function () use ($reactor, $scope, $stream): void {
            $reactor->runLoop($scope, $stream);
        }, "reactor.{$this->id}");
    }

    public function cancel(): void
    {
        $this->currentState = ReactorState::Cancelled;
        $this->bridge?->stop();
        $this->taskHandle?->cancel();
    }

    private function runLoop(ExecutionScope $scope, TheatronStream $stream): void
    {
        $attempt = 0;

        while ($this->currentState === ReactorState::Running || $this->currentState === ReactorState::Restarting) {
            try {
                $this->runWorker($scope, $stream);

                if ($this->currentState === ReactorState::Cancelled) {
                    return;
                }

                $this->currentState = ReactorState::Crashed;
            } catch (Cancelled) {
                $this->currentState = ReactorState::Cancelled;

                return;
            } catch (\Throwable) {
                $this->currentState = ReactorState::Crashed;
            }

            if (!$this->canRestart()) {
                $this->currentState = ReactorState::Exhausted;

                return;
            }

            $attempt++;
            $this->recordRestart();
            $this->currentState = ReactorState::Restarting;

            $delay = $this->restartPolicy->backoff->delay($attempt);

            if ($delay > 0.0) {
                $scope->delay($delay);
            }

            $this->currentState = ReactorState::Running;
        }
    }

    private function runWorker(ExecutionScope $scope, TheatronStream $stream): void
    {
        $config = $this->processConfig ?? ProcessConfig::detect();

        $process = StreamingProcess::command([
            PHP_BINARY,
            $config->workerScript,
            "--autoload={$config->autoloadPath}",
        ])->start($scope);

        $taskRequest = new TaskRequest(
            id: uniqid("reactor.{$this->id}.", true),
            taskClass: $this->taskClass,
            constructorArgs: $this->constructorArgs,
        );

        $process->write(Codec::encode($taskRequest));

        $this->bridge = new ProcessStreamBridge($process, $stream, $scope);
        $this->bridge->start();

        $exitCode = $process->wait();

        $this->bridge = null;

        if ($exitCode !== 0 && $exitCode !== null) {
            throw new \RuntimeException("Worker exited with code {$exitCode}");
        }
    }

    private function canRestart(): bool
    {
        if ($this->restartPolicy->maxRestarts === 0) {
            return false;
        }

        $this->pruneOldRestarts();

        return count($this->restartTimestamps) < $this->restartPolicy->maxRestarts;
    }

    private function recordRestart(): void
    {
        $this->restartTimestamps[] = microtime(true);
    }

    private function pruneOldRestarts(): void
    {
        $cutoff = microtime(true) - $this->restartPolicy->window;
        $this->restartTimestamps = array_values(
            array_filter($this->restartTimestamps, static fn(float $t): bool => $t > $cutoff),
        );
    }
}
