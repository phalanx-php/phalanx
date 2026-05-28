<?php

declare(strict_types=1);

namespace ThreePath;

final readonly class StbConfig
{
    public function __construct(
        public int $port = 25671,
        public string $apiKey = 'dca15ceb-39c9-49f8-a0a6-a85c7402af6e',
        public float $timeoutSeconds = 2.0,
        public int $scanConcurrency = 50,
        public int $defaultServiceId = 146,
        public string $defaultSubnet = '10.30.5.0/24',
        public string $defaultDeviceIp = '10.30.5.219',
        public string $defaultDeviceId = '750051296',
    ) {}
}
