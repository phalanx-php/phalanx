<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb;

use Generator;
use Phalanx\Stream\Channel;
use Throwable;

class SurrealDbLiveSubscription
{
    private(set) bool $isOpen = true;

    public function __construct(
        private readonly string $id,
        private readonly SurrealDbLiveConnection $connection,
        private readonly Channel $channel,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    /** @return Generator<SurrealDbLiveNotification> */
    public function messages(): Generator
    {
        foreach ($this->channel->consume() as $message) {
            if ($message instanceof SurrealDbLiveNotification) {
                yield $message;
            }
        }
    }

    public function next(?float $timeout = null): ?SurrealDbLiveNotification
    {
        $message = $this->channel->next($timeout);

        return $message instanceof SurrealDbLiveNotification ? $message : null;
    }

    public function kill(): void
    {
        if (!$this->isOpen) {
            return;
        }

        try {
            $this->connection->request('kill', [$this->id]);
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        if (!$this->isOpen) {
            return;
        }

        $this->isOpen = false;
        $this->connection->unsubscribe($this->id);
        $this->channel->complete();
    }

    public function fail(Throwable $error): void
    {
        if (!$this->isOpen) {
            return;
        }

        $this->isOpen = false;
        $this->connection->unsubscribe($this->id);
        $this->channel->error($error);
    }
}
