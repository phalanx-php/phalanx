<?php

declare(strict_types=1);

namespace Phalanx\Substrate;

use Swoole\Coroutine\Channel;

final class ChannelWaitGroup implements WaitGroupHandle
{
    private int $count = 0;

    private Channel $channel;

    public function __construct()
    {
        $this->channel = new Channel(1);
    }

    public function add(int $delta = 1): void
    {
        $this->count += $delta;
    }

    public function done(): void
    {
        if ($this->count <= 0) {
            return;
        }

        $this->count--;

        if ($this->count === 0) {
            $this->channel->push(true);
        }
    }

    public function wait(float $timeout = -1): bool
    {
        if ($this->count <= 0) {
            return true;
        }

        return $this->channel->pop($timeout) !== false;
    }

    public function count(): int
    {
        return $this->count;
    }
}
