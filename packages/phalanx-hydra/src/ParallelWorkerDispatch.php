<?php

declare(strict_types=1);

namespace Phalanx\Hydra;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Hydra\Process\ProcessConfig;
use Phalanx\Hydra\Protocol\TaskRequest;
use Phalanx\Hydra\Supervisor\WorkerSupervisor;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\Trace\TraceType;
use Phalanx\Worker\WorkerDispatch;
use Phalanx\Worker\WorkerTask;
use ReflectionClass;
use RuntimeException;
use UnitEnum;

class ParallelWorkerDispatch implements WorkerDispatch
{
    private ?WorkerSupervisor $supervisor = null;

    public function __construct(
        private readonly ParallelConfig $config,
    ) {
    }

    public function dispatch(WorkerTask $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
    {
        $token->throwIfCancelled();
        $scope->throwIfCancelled();

        $name = $task->traceName;
        $start = hrtime(true);

        $scope->trace()->log(TraceType::Worker, "worker:{$name}", ['state' => 'dispatching']);

        try {
            $result = $this->supervisor()->dispatch($this->serializeTask($task), $scope, $token);
            $elapsed = (hrtime(true) - $start) / 1e6;
            $scope->trace()->log(TraceType::Worker, "worker:{$name}", ['elapsed' => $elapsed, 'state' => 'done']);
            return $result;
        } catch (\Throwable $e) {
            $elapsed = (hrtime(true) - $start) / 1e6;
            $scope->trace()->log(
                TraceType::Failed,
                "worker:{$name}",
                ['elapsed' => $elapsed, 'error' => $e->getMessage()],
            );
            throw $e;
        }
    }

    public function shutdown(): void
    {
        $this->supervisor?->shutdown();
        $this->supervisor = null;
    }

    private function supervisor(): WorkerSupervisor
    {
        if ($this->supervisor !== null) {
            return $this->supervisor;
        }

        $supervisor = new WorkerSupervisor(
            config: $this->config->toSupervisorConfig(),
            processConfig: ProcessConfig::detect($this->config->workerScript, $this->config->autoloadPath),
        );
        $supervisor->start();

        $this->supervisor = $supervisor;
        return $supervisor;
    }

    private function serializeTask(WorkerTask $task): TaskRequest
    {
        return new TaskRequest(
            id: uniqid('task-', true),
            taskClass: $task::class,
            constructorArgs: $this->extractConstructorArgs($task),
            contextAttrs: [],
        );
    }

    /** @return array<string, mixed> */
    private function extractConstructorArgs(object $task): array
    {
        $taskClass = $task::class;
        $reflection = new ReflectionClass($task);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $args = [];

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (!$reflection->hasProperty($name)) {
                if ($param->isDefaultValueAvailable()) {
                    continue;
                }

                throw new RuntimeException(
                    "Cannot serialize task {$taskClass}: constructor parameter '{$name}' has no matching property",
                );
            }

            $prop = $reflection->getProperty($name);
            if (!$prop->isInitialized($task)) {
                if ($param->isDefaultValueAvailable()) {
                    continue;
                }

                throw new RuntimeException(
                    "Cannot serialize task {$taskClass}: property '{$name}' is uninitialized",
                );
            }

            if ($prop->isStatic()) {
                continue;
            }

            $value = $prop->getValue($task);

            if (!$this->isSerializable($value)) {
                throw new RuntimeException(
                    "Cannot serialize task {$taskClass}: property '{$name}' is not serializable",
                );
            }

            $args[$name] = $value;
        }

        return $args;
    }

    private function isSerializable(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!$this->isSerializable($item)) {
                    return false;
                }
            }

            return true;
        }

        if ($value instanceof \Closure) {
            return false;
        }

        if ($value instanceof UnitEnum) {
            return true;
        }

        return !is_object($value) && !is_resource($value);
    }
}
