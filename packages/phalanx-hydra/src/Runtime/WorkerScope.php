<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Runtime;

use Phalanx\Hydra\Protocol\Codec;
use Phalanx\Hydra\Protocol\Response;
use Phalanx\Hydra\Protocol\ServiceCall;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Trace\Trace;
use Phalanx\Worker\WorkerScope as AegisWorkerScope;
use RuntimeException;

class WorkerScope implements AegisWorkerScope
{
    public RuntimeContext $runtime {
        get => throw new RuntimeException('WorkerScope does not expose parent runtime context.');
    }

    /**
     * @param resource $stdin
     * @param resource $stdout
     */
    public function __construct(
        /** @var array<string, mixed> */
        private array $attributes,
        private readonly Trace $trace,
        private $stdin = STDIN,
        private $stdout = STDOUT,
    ) {
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public function service(string $type): object
    {
        $id = uniqid('service-', true);
        $call = new ServiceCall(
            id: $id,
            serviceClass: $type,
            method: '__resolve__',
            args: [],
        );

        $this->write($call);

        $response = $this->waitForResponse($id);
        if (!$response->ok) {
            throw new RuntimeException($response->errorMessage ?? "Failed to resolve service: {$type}");
        }

        return new ServiceProxy($type, $this); // @phpstan-ignore return.type
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): AegisWorkerScope
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self($attributes, $this->trace, $this->stdin, $this->stdout);
    }

    public function trace(): Trace
    {
        return $this->trace;
    }

    /** @param list<mixed> $args */
    public function callService(string $serviceClass, string $method, array $args): mixed
    {
        $id = uniqid('service-', true);
        $call = new ServiceCall(
            id: $id,
            serviceClass: $serviceClass,
            method: $method,
            args: array_values($args),
        );

        $this->write($call);

        return $this->waitForResponse($id)->unwrap();
    }

    private function waitForResponse(string $expectedId): Response
    {
        while (true) {
            $line = fgets($this->stdin);

            if ($line === false) {
                throw new RuntimeException('Worker stdin closed unexpectedly');
            }

            $response = Codec::decodeResponse($line);

            if ($response->id === $expectedId) {
                return $response;
            }
        }
    }

    private function write(ServiceCall $call): void
    {
        fwrite($this->stdout, Codec::encode($call));
        fflush($this->stdout);
    }
}
