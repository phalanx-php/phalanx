<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb;

use Phalanx\Stream\Channel;

interface SurrealDbLiveConnection
{
    public bool $isOpen { get; }

    /** @param list<mixed> $params */
    public function request(string $method, array $params = []): mixed;

    public function subscribe(string $queryId, Channel $channel): void;

    public function unsubscribe(string $queryId): void;

    public function close(): void;
}
