<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Live;

use Phalanx\WebSocket\WsMessage;

interface Socket
{
    /** @return iterable<WsMessage> */
    public function messages(): iterable;

    /** @param array<string, mixed> $payload */
    public function sendJson(array $payload): void;

    public function close(): void;
}
