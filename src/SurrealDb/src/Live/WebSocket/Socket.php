<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Live\WebSocket;

class Socket implements \Phalanx\SurrealDb\Live\Socket
{
    public function __construct(
        private readonly \Phalanx\WebSocket\Client\ConnectionHandle $handle,
    ) {
    }

    /** @return iterable<\Phalanx\WebSocket\Message> */
    public function messages(): iterable
    {
        yield from $this->handle->messages();
    }

    public function sendJson(array $payload): void
    {
        $this->handle->sendJson($payload);
    }

    public function close(): void
    {
        $this->handle->close();
    }
}
