<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Live;

use Generator;
use Phalanx\Stream\Channel;
use Throwable;

class Subscription
{
    private(set) bool $isOpen = true;

    public function __construct(
        private readonly string $id,
        private readonly \Phalanx\SurrealDb\Live\Connection $connection,
        private readonly Channel $channel,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    /** @return Generator<\Phalanx\SurrealDb\Live\Notification> */
    public function messages(): Generator
    {
        foreach ($this->channel->consume() as $message) {
            if ($message instanceof \Phalanx\SurrealDb\Live\Notification) {
                yield $message;
            }
        }
    }

    public function next(?float $timeout = null): ?\Phalanx\SurrealDb\Live\Notification
    {
        $message = $this->channel->next($timeout);

        return $message instanceof \Phalanx\SurrealDb\Live\Notification ? $message : null;
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
