<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Udp;

final readonly class UdpListenerConfig
{
    public function __construct(
        public string $host = '0.0.0.0',
        public int $port = 0,
        public bool $broadcast = false,
        public int $maxPacketSize = 65507,
        public int $workerNum = 1,
    ) {
    }
}
