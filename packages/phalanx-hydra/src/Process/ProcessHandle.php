<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Process;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Hydra\Protocol\Codec;
use Phalanx\Hydra\Protocol\MessageType;
use Phalanx\Hydra\Protocol\Response;
use Phalanx\Hydra\Protocol\ServiceCall;
use Phalanx\Hydra\Protocol\TaskRequest;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\System\StreamingProcess;
use Phalanx\System\StreamingProcessException;
use Phalanx\System\StreamingProcessHandle;
use RuntimeException;

class ProcessHandle
{
    private const float READ_TIMEOUT = 0.1;

    private ?StreamingProcessHandle $process = null;

    private ProcessState $state = ProcessState::Idle;

    public function __construct(
        private readonly ProcessConfig $config,
    ) {
    }

    public function state(): ProcessState
    {
        return $this->state;
    }

    public function isIdle(): bool
    {
        return $this->state === ProcessState::Idle;
    }

    public function isRunning(): bool
    {
        return $this->process?->isRunning() ?? false;
    }

    public function start(TaskScope&TaskExecutor $scope): void
    {
        if ($this->process !== null && $this->process->isRunning()) {
            return;
        }

        $this->cleanup();

        $this->process = StreamingProcess::command([
            PHP_BINARY,
            $this->config->workerScript,
            "--autoload={$this->config->autoloadPath}",
        ])->start($scope);

        $this->state = ProcessState::Idle;
        $process = $this->process;

        $scope->go(static function () use ($process): void {
            self::drainStderr($process);
        }, 'hydra.worker.stderr');
    }

    /** @param callable(ServiceCall): mixed $serviceHandler */
    public function execute(TaskRequest $task, TaskScope&TaskExecutor $scope, callable $serviceHandler): mixed
    {
        if ($this->state !== ProcessState::Idle) {
            throw new RuntimeException("Process not idle: {$this->state->name}");
        }

        if ($this->process === null || !$this->process->isRunning()) {
            throw new RuntimeException('Process not running');
        }

        $this->state = ProcessState::Busy;

        try {
            $this->process->write(Codec::encode($task), timeout: 1.0);
            return $this->readTaskResult($task, $scope, $serviceHandler);
        } catch (Cancelled $e) {
            $this->kill();
            throw $e;
        } catch (\Throwable $e) {
            if (!$this->isRunning()) {
                $this->state = ProcessState::Crashed;
            } elseif ($this->state === ProcessState::Busy) {
                $this->state = ProcessState::Idle;
            }

            throw $e;
        }
    }

    public function drain(): void
    {
        if ($this->process === null) {
            return;
        }

        if (!$this->process->isRunning()) {
            $this->cleanup();
            return;
        }

        $this->state = ProcessState::Draining;
        $this->process->stop(
            $this->config->gracefulTimeout,
            $this->config->forceTimeout,
        );
        $this->cleanup();
    }

    public function kill(): void
    {
        if ($this->process !== null) {
            $this->process->close('hydra.kill');
        }

        $this->state = ProcessState::Crashed;
        $this->cleanup();
    }

    private static function drainStderr(StreamingProcessHandle $process): void
    {
        while ($process->isRunning()) {
            try {
                $chunk = $process->readError(8192, self::READ_TIMEOUT);
            } catch (Cancelled $e) {
                throw $e;
            } catch (\Throwable) {
                return;
            }

            if ($chunk !== '') {
                error_log("[Worker STDERR] {$chunk}");
            }
        }
    }

    /** @param callable(ServiceCall): mixed $serviceHandler */
    private function readTaskResult(TaskRequest $task, TaskScope&TaskExecutor $scope, callable $serviceHandler): mixed
    {
        while (true) {
            $scope->throwIfCancelled();

            $line = $this->readLine();
            if ($line === '') {
                if (!$this->isRunning()) {
                    $this->state = ProcessState::Crashed;
                    throw new RuntimeException('Worker exited before returning a task response');
                }
                continue;
            }

            $message = Codec::decode($line);

            if ($message instanceof ServiceCall) {
                $this->handleServiceCall($message, $serviceHandler);
                continue;
            }

            if ($message instanceof Response && $message->type === MessageType::TaskResponse) {
                if ($message->id !== $task->id) {
                    continue;
                }

                $this->state = ProcessState::Idle;
                return $message->unwrap();
            }
        }
    }

    private function readLine(): string
    {
        if ($this->process === null) {
            throw new RuntimeException('Process not running');
        }

        try {
            return $this->process->readLine(self::READ_TIMEOUT);
        } catch (StreamingProcessException $e) {
            if (!$this->isRunning()) {
                return '';
            }

            throw $e;
        }
    }

    /** @param callable(ServiceCall): mixed $serviceHandler */
    private function handleServiceCall(ServiceCall $call, callable $serviceHandler): void
    {
        if ($this->process === null) {
            throw new RuntimeException('Process not running');
        }

        try {
            $response = Response::serviceOk($call->id, $serviceHandler($call));
        } catch (Cancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            $response = Response::serviceErr($call->id, $e);
        }

        $this->process->write(Codec::encode($response), timeout: 1.0);
    }

    private function cleanup(): void
    {
        $this->process = null;
        if ($this->state !== ProcessState::Crashed) {
            $this->state = ProcessState::Idle;
        }
    }
}
