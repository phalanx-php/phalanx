<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Udp;

/**
 * @phpstan-type ClientInfo array{address: string, port: int, server_socket?: int, server_port?: int, dispatch_time?: float}
 */
final readonly class UdpPacket
{
    /** @param ClientInfo $clientInfo */
    public function __construct(
        public string $payload,
        public string $remoteAddress,
        public int $remotePort,
        public array $clientInfo,
    ) {
    }
}
