<?php

declare(strict_types=1);

namespace ThreePath;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Suspendable;
use React\Datagram\Factory as DatagramFactory;

final class ThreePathServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->config(StbConfig::class, static fn(array $ctx) => new StbConfig(
            port: (int) ($ctx['STB_PORT'] ?? 25671),
            apiKey: (string) ($ctx['STB_API_KEY'] ?? 'dca15ceb-39c9-49f8-a0a6-a85c7402af6e'),
            timeoutSeconds: (float) ($ctx['STB_TIMEOUT'] ?? 2.0),
            scanConcurrency: (int) ($ctx['STB_SCAN_CONCURRENCY'] ?? 50),
            defaultServiceId: (int) ($ctx['STB_DEFAULT_SERVICE_ID'] ?? 146),
            defaultSubnet: (string) ($ctx['STB_DEFAULT_SUBNET'] ?? '10.30.5.0/24'),
            defaultDeviceIp: (string) ($ctx['STB_DEFAULT_DEVICE_IP'] ?? '10.30.5.219'),
            defaultDeviceId: (string) ($ctx['STB_DEFAULT_DEVICE_ID'] ?? '750051296'),
        ));

        $services->singleton(DatagramFactory::class)
            ->factory(static fn() => new DatagramFactory());

        $services->scoped(StbTransport::class)
            ->factory(static fn(Suspendable $scope, DatagramFactory $factory, StbConfig $config) => new StbTransport(
                factory: $factory,
                scope: $scope,
                config: $config,
            ));
    }
}
