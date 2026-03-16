<?php

declare(strict_types=1);

namespace Convoy\Http;

final readonly class UdpConfig
{
    public function __construct(
        public int $maxPayloadSize = 65536,
    ) {}
}
