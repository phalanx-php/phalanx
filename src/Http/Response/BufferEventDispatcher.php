<?php

declare(strict_types=1);

namespace Phalanx\Http\Response;

use Phalanx\Http\Runtime\Identity\HttpEventSid;
use Phalanx\Http\HttpRequestResource;
use Swoole\Http\Server;

final class BufferEventDispatcher
{
    /** @var array<int, HttpRequestResource> */
    private array $tracked = [];

    public function attach(Server $server): void
    {
        $server->on('BufferFull', $this->onBufferFull(...));
        $server->on('BufferEmpty', $this->onBufferEmpty(...));
    }

    public function track(int $fd, HttpRequestResource $request): void
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

        $request->event(HttpEventSid::BufferFull, (string) $fd);
    }

    private function onBufferEmpty(Server $server, int $fd): void
    {
        $request = $this->tracked[$fd] ?? null;

        if ($request === null) {
            return;
        }

        $request->event(HttpEventSid::BufferEmpty, (string) $fd);
        $request->releaseDeliveryLease('fulfilled');
    }
}
