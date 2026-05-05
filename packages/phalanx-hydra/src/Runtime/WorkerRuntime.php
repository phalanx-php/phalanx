<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Runtime;

use Phalanx\Hydra\Protocol\Codec;
use Phalanx\Hydra\Protocol\MessageType;
use Phalanx\Hydra\Protocol\Response;
use Phalanx\Hydra\Protocol\TaskRequest;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;
use ReflectionClass;
use ReflectionParameter;
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
            } catch (\Throwable $e) {
                fwrite($this->stderr, "[Worker] Failed to process message: {$e->getMessage()}\n");
            }
        }
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

            $this->writeResponse(Response::taskOk($request->id, $task($scope)));
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
