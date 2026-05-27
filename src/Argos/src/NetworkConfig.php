<?php

declare(strict_types=1);

namespace Phalanx\Argos;

use Phalanx\Themis\Config;
use Phalanx\Themis\Env;
use Phalanx\Themis\Issue;
use Phalanx\Themis\ValidationContext;

final class NetworkConfig implements Config
{
    public bool $configured {
        get => $this->pingBinary !== '';
    }

    public function __construct(
        #[Env(key: 'NETWORK_DEFAULT_TIMEOUT', description: 'Default network operation timeout in seconds')]
        private(set) float $defaultTimeout = 5.0,

        #[Env(key: 'NETWORK_DEFAULT_CONCURRENCY', description: 'Maximum concurrent network operations')]
        private(set) int $defaultConcurrency = 50,

        #[Env(key: 'NETWORK_PING_BINARY', description: 'Path to the ping binary')]
        private(set) string $pingBinary = 'ping',

        #[Env(key: 'NETWORK_BROADCAST_ADDRESS', description: 'Default broadcast address for Wake-on-LAN')]
        private(set) string $broadcastAddress = '255.255.255.255',

        #[Env(key: 'NETWORK_WOL_PORT', description: 'UDP port for Wake-on-LAN magic packets')]
        private(set) int $wolPort = 9,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [];
    }
}
