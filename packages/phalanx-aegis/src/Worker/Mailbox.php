<?php

declare(strict_types=1);

namespace Phalanx\Worker;

use Phalanx\Worker\Protocol\TaskRequest;
use OpenSwoole\Coroutine\Channel;

/**
 * Bounded coroutine channel for queued task requests. Push throws
 * OverflowException when full; pop suspends the calling coroutine until a
 * request is available.
 */
class Mailbox
{
    private readonly Channel $channel;

    public function __construct(public readonly int $limit)
    {
        $this->channel = new Channel($this->limit);
    }

    public function push(TaskRequest $req): void
    {
        if ($this->channel->isFull()) {
            throw new OverflowException("Mailbox: limit {$this->limit} reached");
        }
        $this->channel->push($req);
    }

    public function pop(): TaskRequest|false
    {
        $value = $this->channel->pop();
        if ($value === false) {
            return false;
        }
        return $value;
    }

    public function depth(): int
    {
        return $this->channel->length();
    }

    public function close(): void
    {
        $this->channel->close();
    }
}
