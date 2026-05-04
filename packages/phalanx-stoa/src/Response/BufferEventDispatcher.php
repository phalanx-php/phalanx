<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Response;

use OpenSwoole\Http\Server;
use Phalanx\Stoa\Runtime\Identity\StoaEventSid;
use Phalanx\Stoa\StoaRequestResource;

final class BufferEventDispatcher
{
    /** @var array<int, StoaRequestResource> */
    private array $tracked = [];

    public function attach(Server $server): void
    {
        $server->on('BufferFull', $this->onBufferFull(...));
        $server->on('BufferEmpty', $this->onBufferEmpty(...));
    }

    public function track(int $fd, StoaRequestResource $request): void
    {
        $this->tracked[$fd] = $request;
    }

    public function untrack(int $fd): void
    {
        unset($this->tracked[$fd]);
    }

    public function tracksFd(int $fd): bool
    {
        return isset($this->tracked[$fd]);
    }

    private function onBufferFull(Server $server, int $fd): void
    {
        $request = $this->tracked[$fd] ?? null;

        if ($request === null) {
            return;
        }

        $request->event(StoaEventSid::BufferFull, (string) $fd);
    }

    private function onBufferEmpty(Server $server, int $fd): void
    {
        $request = $this->tracked[$fd] ?? null;

        if ($request === null) {
            return;
        }

        $request->event(StoaEventSid::BufferEmpty, (string) $fd);
        $request->releaseDeliveryLease('fulfilled');
    }
}
