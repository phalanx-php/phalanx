<?php

declare(strict_types=1);

namespace Phalanx\Network;

final readonly class ProbeResult
{
    public function __construct(
        public string $ip,
        public bool $reachable,
        public ?float $latencyMs = null,
        public ?string $method = null,
        public ?int $port = null,
        public ?string $responseData = null,
    ) {}
}
