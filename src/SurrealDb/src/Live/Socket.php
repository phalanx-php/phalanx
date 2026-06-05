<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Live;

interface Socket
{
    /** @return iterable<\Phalanx\WebSocket\Message> */
    public function messages(): iterable;

    /** @param array<string, mixed> $payload */
    public function sendJson(array $payload): void;

    public function close(): void;
}
