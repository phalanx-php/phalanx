<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Runtime;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Hydra\Protocol\Codec;
use Phalanx\Hydra\Protocol\MessageType;
use Phalanx\Hydra\Protocol\Response;
use Phalanx\Hydra\Protocol\TaskRequest;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;

class WorkerRuntime
{
    /**
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private $stdin = STDIN,
        private $stdout = STDOUT,
        private $stderr = STDERR,
    ) {
    }

    public function run(): void
    {
        while (($line = fgets($this->stdin)) !== false) {
            try {
                $message = Codec::decode($line);

                if ($message instanceof TaskRequest) {
                    $this->handleTask($message);
                    continue;
                }

                if ($message instanceof Response && $message->type === MessageType::ServiceResponse) {
                    fwrite($this->stderr, "[Worker] Unexpected ServiceResponse - should be handled by WorkerScope\n");
                }
            } catch (Cancelled $e) {
                throw $e;
            } catch (\Throwable $e) {
                fwrite($this->stderr, "[Worker] Failed to process message: {$e->getMessage()}\n");
            }
        }
    }

    private static function assertAcceptsWorkerScope(Scopeable|Executable $task): void
    {
        $taskClass = $task::class;
        $reflection = new ReflectionClass($task);
        if (!$reflection->hasMethod('__invoke')) {
            throw new RuntimeException("Task must be invokable: {$taskClass}");
        }

        $parameter = $reflection->getMethod('__invoke')->getParameters()[0] ?? null;
        if ($parameter === null || self::typeAcceptsWorkerScope($parameter->getType())) {
            return;
        }

        $type = $parameter->getType();
        $expected = $type instanceof ReflectionType ? (string) $type : 'unknown';
        throw new RuntimeException(
            "Hydra worker task {$taskClass} requires {$expected}; "
            . 'the current worker runtime exposes ' . WorkerScope::class . ' only.',
        );
    }

    private static function typeAcceptsWorkerScope(?ReflectionType $type): bool
    {
        if ($type === null) {
            return true;
        }

        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return $type->getName() === 'mixed';
            }

            return is_a(WorkerScope::class, $type->getName(), true);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                if (self::typeAcceptsWorkerScope($unionType)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function handleTask(TaskRequest $request): void
    {
        try {
            $task = $this->instantiateTask($request);
            $scope = new WorkerScope(
                attributes: $request->contextAttrs,
                trace: new Trace(),
                stdin: $this->stdin,
                stdout: $this->stdout,
            );

            self::assertAcceptsWorkerScope($task);
            $this->writeResponse(Response::taskOk($request->id, $task->__invoke($scope)));
        } catch (Cancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->writeResponse(Response::taskErr($request->id, $e));
        }
    }

    private function instantiateTask(TaskRequest $request): Scopeable|Executable
    {
        if (!class_exists($request->taskClass)) {
            throw new RuntimeException("Task class not found: {$request->taskClass}");
        }

        $reflection = new ReflectionClass($request->taskClass);

        if (
            !$reflection->implementsInterface(Scopeable::class)
            && !$reflection->implementsInterface(Executable::class)
        ) {
            throw new RuntimeException("Task must implement Scopeable or Executable: {$request->taskClass}");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            $instance = $reflection->newInstance();
            assert($instance instanceof Scopeable || $instance instanceof Executable);
            return $instance;
        }

        $instance = $reflection->newInstanceArgs(
            $this->resolveConstructorArgs($constructor->getParameters(), $request->constructorArgs),
        );
        assert($instance instanceof Scopeable || $instance instanceof Executable);
        return $instance;
    }

    /**
     * @param list<ReflectionParameter> $params
     * @param array<string, mixed> $args
     * @return list<mixed>
     */
    private function resolveConstructorArgs(array $params, array $args): array
    {
        $resolved = [];

        foreach ($params as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $args)) {
                $resolved[] = $args[$name];
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $resolved[] = $param->getDefaultValue();
                continue;
            }

            throw new RuntimeException("Missing required constructor argument: {$name}");
        }

        return $resolved;
    }

    private function writeResponse(Response $response): void
    {
        fwrite($this->stdout, Codec::encode($response));
        fflush($this->stdout);
    }
}
