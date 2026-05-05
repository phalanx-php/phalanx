<?php

declare(strict_types=1);

namespace Phalanx\Athena\Swarm;

/**
 * Configuration for a swarm session.
 */
final readonly class SwarmConfig
{
    public function __construct(
        public string $workspace,
        public string $session,
        public string $daemon8Url = 'http://localhost:8888',
        public string $app = 'phalanx-swarm',
    ) {
    }
}
