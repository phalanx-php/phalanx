<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Udp;

use OpenSwoole\Server;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Stoa\Runtime\Identity\StoaResourceSid;

/**
 * One peer interaction inside a UDP listener.
 *
 * The session bounds a packet's processing window: a managed resource
 * is opened for traceability, the handler may call {@see reply()} to
 * send a response back to the originating address, and `close()`
 * retires the resource. Sessions do not survive across packets — UDP
 * is connectionless.
 */
final class UdpSession
{
    private bool $closed = false;
    private ManagedResourceHandle $handle;

    public function __construct(
        public readonly UdpPacket $packet,
        private readonly RuntimeContext $runtime,
        private readonly Server $server,
        private readonly int $serverSocket,
        ?ManagedResourceHandle $parentHandle = null,
    ) {
        $this->handle = $this->runtime->memory->resources->open(
            type: StoaResourceSid::UdpSession,
            id: $this->runtime->memory->ids->nextRuntime('udp-session'),
            parentResourceId: $parentHandle?->id,
        );
        $this->handle = $this->runtime->memory->resources->activate($this->handle);
    }

    public function reply(string $payload): bool
    {
        if ($this->closed) {
            return false;
        }

        return $this->server->sendto(
            $this->packet->remoteAddress,
            $this->packet->remotePort,
            $payload,
            $this->serverSocket,
        );
    }

    public function close(string $reason = 'completed'): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->runtime->memory->resources->close($this->handle->id, $reason);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
