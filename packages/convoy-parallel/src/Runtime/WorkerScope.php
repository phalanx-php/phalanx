<?php

declare(strict_types=1);

namespace Convoy\Parallel\Runtime;

use Convoy\Parallel\Protocol\Codec;
use Convoy\Parallel\Protocol\Response;
use Convoy\Parallel\Protocol\ServiceCall;
use Convoy\Scope;
use Convoy\Trace\Trace;

final class WorkerScope implements Scope
{
    private string $buffer = '';

    /**
     * @param resource $stdin
     * @param resource $stdout
     */
    public function __construct(
        /** @var array<string, mixed> */
        private array $attributes,
        private readonly Trace $trace,
        private $stdin = STDIN,
        private $stdout = STDOUT
    )
    {
    }

    public function service(string $type): object
    {
        $id = bin2hex(random_bytes(8));

        $call = new ServiceCall(
            id: $id,
            serviceClass: $type,
            method: '__resolve__',
            args: [],
        );

        fwrite($this->stdout, Codec::encode($call));
        fflush($this->stdout);

        $response = $this->waitForResponse($id);

        if (!$response->ok) {
            throw new \RuntimeException($response->errorMessage ?? "Failed to resolve service: $type");
        }

        return new ServiceProxy($type, $this->stdin, $this->stdout, $this);
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): Scope
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self($attributes, $this->trace, $this->stdin, $this->stdout);
    }

    public function trace(): Trace
    {
        return $this->trace;
    }

    public function callService(string $serviceClass, string $method, array $args): mixed
    {
        $id = bin2hex(random_bytes(8));

        $call = new ServiceCall(
            id: $id,
            serviceClass: $serviceClass,
            method: $method,
            args: $args,
        );

        fwrite($this->stdout, Codec::encode($call));
        fflush($this->stdout);

        return $this->waitForResponse($id)->unwrap();
    }

    private function waitForResponse(string $expectedId): Response
    {
        while (true) {
            $line = $this->readLine();

            if ($line === null) {
                throw new \RuntimeException('Worker stdin closed unexpectedly');
            }

            $response = Codec::decodeResponse($line);

            if ($response->id === $expectedId) {
                return $response;
            }
        }
    }

    private function readLine(): ?string
    {
        while (($pos = strpos($this->buffer, "\n")) === false) {
            $chunk = fread($this->stdin, 8192);

            if ($chunk === false || $chunk === '') {
                if (feof($this->stdin)) {
                    return null;
                }
                usleep(1000);
                continue;
            }

            $this->buffer .= $chunk;
        }

        $line = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + 1);

        return $line;
    }
}
