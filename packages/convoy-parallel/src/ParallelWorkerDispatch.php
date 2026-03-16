<?php

declare(strict_types=1);

namespace Convoy\Parallel;

use Convoy\ExecutionScope;
use Convoy\Parallel\Dispatch\Dispatcher;
use Convoy\Parallel\Process\ProcessConfig;
use Convoy\Parallel\Protocol\TaskRequest;
use Convoy\Parallel\Supervisor\WorkerSupervisor;
use Convoy\Service\LazySingleton;
use Convoy\Service\ServiceGraph;
use Convoy\Support\ClassNames;
use Convoy\Task\Executable;
use Convoy\Task\Scopeable;
use Convoy\Task\Traceable;
use Convoy\Trace\TraceType;
use Convoy\WorkerDispatch;
use React\EventLoop\Loop;
use ReflectionClass;

use function React\Async\await;

final class ParallelWorkerDispatch implements WorkerDispatch
{
    private ?WorkerSupervisor $supervisor = null;

    public function __construct(
        private readonly ParallelConfig $config,
        private readonly ServiceGraph $graph,
        private readonly LazySingleton $singletons,
    ) {
    }

    public function inWorker(Scopeable|Executable $task, ExecutionScope $scope): mixed
    {
        $scope->throwIfCancelled();

        $name = $task instanceof Traceable ? $task->traceName : ClassNames::short($task::class);
        $start = hrtime(true);

        $scope->trace()->log(TraceType::Executing, "worker:$name", task: $task);

        $dispatcher = $this->getDispatcher($scope);
        $request = $this->serializeTask($task, $scope);
        $promise = $dispatcher->dispatch($request);

        try {
            $result = await($promise);
            $elapsed = (hrtime(true) - $start) / 1e6;
            $scope->trace()->log(TraceType::Done, "worker:$name", ['elapsed' => $elapsed]);
            return $result;
        } catch (\Throwable $e) {
            $elapsed = (hrtime(true) - $start) / 1e6;
            $scope->trace()->log(TraceType::Failed, "worker:$name", ['elapsed' => $elapsed, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function getDispatcher(ExecutionScope $scope): Dispatcher
    {
        if ($this->supervisor !== null) {
            return $this->supervisor->dispatcher();
        }

        $processConfig = ProcessConfig::detect($this->config->workerScript, $this->config->autoloadPath);

        $supervisor = new WorkerSupervisor(
            config: $this->config->toSupervisorConfig(),
            processConfig: $processConfig,
            loop: Loop::get(),
            graph: $this->graph,
            singletons: $this->singletons,
        );

        $this->supervisor = $supervisor;
        $supervisor->start();

        $scope->onDispose(static function () use ($supervisor): void {
            $supervisor->shutdown();
        });

        return $supervisor->dispatcher();
    }

    private function serializeTask(Scopeable|Executable $task, ExecutionScope $scope): TaskRequest
    {
        $class = $task::class;
        $args = $this->extractConstructorArgs($task);

        return new TaskRequest(
            id: bin2hex(random_bytes(8)),
            taskClass: $class,
            constructorArgs: $args,
            contextAttrs: [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function extractConstructorArgs(object $task): array
    {
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
                $taskClass = $task::class;
                throw new \RuntimeException(
                    "Cannot serialize task $taskClass: property '$name' is not serializable"
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
            return array_all($value, fn($item) => $this->isSerializable($item));
        }

        if (is_object($value)) {
            if ($value instanceof \Closure) {
                return false;
            }

            if ($value instanceof \UnitEnum) {
                return true;
            }

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
