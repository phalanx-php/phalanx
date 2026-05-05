<?php

declare(strict_types=1);

namespace Phalanx\Hydra;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Hydra\Process\ProcessConfig;
use Phalanx\Hydra\Protocol\TaskRequest;
use Phalanx\Hydra\Supervisor\WorkerSupervisor;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\Support\ClassNames;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Traceable;
use Phalanx\Trace\TraceType;
use Phalanx\Worker\WorkerDispatch;
use ReflectionClass;
use RuntimeException;

class ParallelWorkerDispatch implements WorkerDispatch
{
    private ?WorkerSupervisor $supervisor = null;

    public function __construct(
        private readonly ParallelConfig $config,
    ) {
    }

    public function dispatch(Scopeable|Executable $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
    {
        $token->throwIfCancelled();
        $scope->throwIfCancelled();

        if (!$task instanceof Scopeable) {
            throw new RuntimeException(
                'Hydra worker dispatch supports Scopeable tasks only; Executable tasks require ExecutionScope.',
            );
        }

        $name = $task instanceof Traceable ? $task->traceName : ClassNames::short($task::class);
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

    private function serializeTask(Scopeable|Executable $task): TaskRequest
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
                continue;
            }

            $prop = $reflection->getProperty($name);
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

        if ($value instanceof \UnitEnum) {
            return true;
        }

        if (is_object($value)) {
            try {
                json_encode($value, JSON_THROW_ON_ERROR);
                return true;
            } catch (\JsonException) {
                return false;
            }
        }

        return false;
    }
}
