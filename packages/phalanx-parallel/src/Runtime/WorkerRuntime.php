<?php

declare(strict_types=1);

namespace Phalanx\Parallel\Runtime;

use Phalanx\Parallel\Protocol\Codec;
use Phalanx\Parallel\Protocol\MessageType;
use Phalanx\Parallel\Protocol\Response;
use Phalanx\Parallel\Protocol\TaskRequest;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;
use ReflectionClass;
use ReflectionParameter;

final class WorkerRuntime
{
    private string $buffer = '';

    /**
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(private $stdin = STDIN, private $stdout = STDOUT, private $stderr = STDERR)
    {
    }

    public function run(): void
    {
        stream_set_blocking($this->stdin, false);

        while (!feof($this->stdin)) {
            $chunk = fread($this->stdin, 8192);

            if ($chunk === false) {
                break;
            }

            if ($chunk === '') {
                usleep(1000);
                continue;
            }

            $this->buffer .= $chunk;
            $this->processBuffer();
        }
    }

    private function processBuffer(): void
    {
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);

            if (trim($line) === '') {
                continue;
            }

            try {
                $message = Codec::decode($line);

                if ($message instanceof TaskRequest) {
                    $this->handleTask($message);
                } elseif ($message instanceof Response && $message->type === MessageType::ServiceResponse) {
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
                trace: new Trace(enabled: false),
                stdin: $this->stdin,
                stdout: $this->stdout,
            );

            $result = $task($scope);

            $response = Response::taskOk($request->id, $result);
            fwrite($this->stdout, Codec::encode($response));
            fflush($this->stdout);
        } catch (\Throwable $e) {
            $response = Response::taskErr($request->id, $e);
            fwrite($this->stdout, Codec::encode($response));
            fflush($this->stdout);
        }
    }

    private function instantiateTask(TaskRequest $request): Scopeable|Executable
    {
        $class = $request->taskClass;

        if (!class_exists($class)) {
            throw new \RuntimeException("Task class not found: $class");
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->implementsInterface(Scopeable::class) && !$reflection->implementsInterface(Executable::class)) {
            throw new \RuntimeException("Task must implement Scopeable or Executable: $class");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            $instance = $reflection->newInstance();
            assert($instance instanceof Scopeable || $instance instanceof Executable);
            return $instance;
        }

        $args = $this->resolveConstructorArgs($constructor->getParameters(), $request->constructorArgs);

        $instance = $reflection->newInstanceArgs($args);
        assert($instance instanceof Scopeable || $instance instanceof Executable);
        return $instance;
    }

    /**
     * @param ReflectionParameter[] $params
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
            } elseif ($param->isDefaultValueAvailable()) {
                $resolved[] = $param->getDefaultValue();
            } else {
                throw new \RuntimeException("Missing required constructor argument: $name");
            }
        }

        return $resolved;
    }
}
