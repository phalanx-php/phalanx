<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use Phalanx\Hermes\Client\WsClientConnectionHandle;
use Phalanx\Hermes\WsMessage;

class HermesSurrealLiveSocket implements SurrealLiveSocket
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
