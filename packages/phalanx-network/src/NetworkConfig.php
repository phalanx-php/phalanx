<?php

declare(strict_types=1);

namespace Phalanx\Network;

final readonly class NetworkConfig
{
    public function __construct(
        public float $defaultTimeout = 5.0,
        public int $defaultConcurrency = 50,
        public string $pingBinary = 'ping',
        public string $broadcastAddress = '255.255.255.255',
        public int $wolPort = 9,
    ) {}
}
