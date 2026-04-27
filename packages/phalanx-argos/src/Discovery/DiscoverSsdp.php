<?php

declare(strict_types=1);

namespace Phalanx\Argos\Discovery;

use Phalanx\ExecutionScope;
use Phalanx\Argos\DiscoveryResult;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;

final class DiscoverSsdp implements Executable, HasTimeout
{
    public float $timeout { get => $this->listenSeconds + 1.0; }

    public function __construct(
        private readonly string $searchTarget = 'ssdp:all',
        private readonly float $listenSeconds = 5.0,
    ) {}

    /** @return list<DiscoveryResult> */
    public function __invoke(ExecutionScope $scope): array
    {
        if (!class_exists(\Clue\React\Ssdp\Client::class)) {
            throw new \RuntimeException(
                'SSDP discovery requires clue/ssdp-react. Install it: composer require clue/ssdp-react',
            );
        }

        $client = new \Clue\React\Ssdp\Client();

        $devices = $scope->await(
            $client->search($this->searchTarget, $this->listenSeconds),
        );

        return array_values(array_map(
            static fn(array $device): DiscoveryResult => new DiscoveryResult(
                ip: (string) (parse_url($device['LOCATION'] ?? '', PHP_URL_HOST) ?? 'unknown'),
                protocol: 'ssdp',
                metadata: $device,
            ),
            $devices,
        ));
    }
}
