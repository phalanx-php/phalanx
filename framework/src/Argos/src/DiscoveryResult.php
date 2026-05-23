<?php

declare(strict_types=1);

namespace Phalanx\Argos;

final readonly class DiscoveryResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $ip,
        public string $protocol,
        public array $metadata = [],
    ) {}
}
