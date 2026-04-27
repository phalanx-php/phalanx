<?php

declare(strict_types=1);

namespace Phalanx\Argos\Task;

use Phalanx\ExecutionScope;
use Phalanx\Argos\ProbeResult;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;
use React\Datagram\Factory as DatagramFactory;
use React\Promise\Deferred;

final class ProbeUdp implements Executable, HasTimeout
{
    public float $timeout { get => $this->timeoutSeconds + 0.5; }

    public function __construct(
        private readonly string $ip,
        private readonly int $port,
        private readonly string $payload,
        private readonly float $timeoutSeconds = 2.0,
    ) {}

    public function __invoke(ExecutionScope $scope): ProbeResult
    {
        $factory = $scope->service(DatagramFactory::class);

        $client = $scope->await($factory->createClient("{$this->ip}:{$this->port}"));
        $start = hrtime(true);

        $deferred = new Deferred();

        $client->on('message', static function (string $data) use ($deferred): void {
            $deferred->resolve($data);
        });

        $client->send($this->payload);

        try {
            $response = $scope->await($deferred->promise());
        } catch (\Phalanx\Exception\CancelledException) {
            $response = null;
        } finally {
            $client->close();
        }

        $elapsed = (hrtime(true) - $start) / 1e6;

        return new ProbeResult(
            ip: $this->ip,
            reachable: $response !== null,
            latencyMs: $response !== null ? $elapsed : null,
            method: 'udp',
            port: $this->port,
            responseData: $response,
        );
    }
}
