<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Live\WebSocket;

use Phalanx\WebSocket\Client\WsClientConnectionHandle;
use Phalanx\WebSocket\WsMessage;

class Socket implements \Phalanx\SurrealDb\Live\Socket
{
    public function __construct(
        private readonly WsClientConnectionHandle $handle,
    ) {
    }

    /** @return iterable<WsMessage> */
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
