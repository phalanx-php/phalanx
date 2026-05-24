<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use Generator;
use Phalanx\Styx\Channel;
use Throwable;

class SurrealLiveSubscription
{
    private(set) bool $isOpen = true;

    public function __construct(
        private readonly string $id,
        private readonly SurrealLiveConnection $connection,
        private readonly Channel $channel,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    /** @return Generator<SurrealLiveNotification> */
    public function messages(): Generator
    {
        foreach ($this->channel->consume() as $message) {
            if ($message instanceof SurrealLiveNotification) {
                yield $message;
            }
        }
    }

    public function next(?float $timeout = null): ?SurrealLiveNotification
    {
        $message = $this->channel->next($timeout);

        return $message instanceof SurrealLiveNotification ? $message : null;
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
