<?php

declare(strict_types=1);

namespace Phalanx\Argos\Discovery;

use Clue\React\Ssdp\Client as SsdpClient;
use Phalanx\Argos\DiscoveryResult;
use Phalanx\Scope\TaskScope;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Scopeable;
use RuntimeException;

use function React\Async\await;

final class DiscoverSsdp implements Scopeable, HasTimeout
{
    public float $timeout {
        get => $this->listenSeconds + 1.0;
    }

    public function __construct(
        private readonly string $searchTarget = 'ssdp:all',
        private readonly float $listenSeconds = 5.0,
    ) {
    }

    /** @return list<DiscoveryResult> */
    public function __invoke(TaskScope $scope): array
    {
        if (!class_exists(SsdpClient::class)) {
            throw new RuntimeException(
                'SSDP discovery requires clue/ssdp-react. Install it: composer require clue/ssdp-react',
            );
        }

        $client = new SsdpClient();
        $searchTarget = $this->searchTarget;
        $listenSeconds = $this->listenSeconds;

        /** @var array<int, array<string, string>> $devices */
        $devices = $scope->call(
            // @phpstan-ignore argument.templateType (Clue SSDP Client carves out: untyped PromiseInterface; replacement tracked in OSR-42)
            static fn(): mixed => await($client->search($searchTarget, $listenSeconds)),
            WaitReason::custom("ssdp.search {$searchTarget}"),
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
