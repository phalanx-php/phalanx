<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Runtime;

use OpenSwoole\Coroutine\Channel;

final class WaitGroup
{
    private int $count = 0;

    private readonly Channel $done;

    public function __construct()
    {
        $this->done = new Channel(1);
    }

    public function add(int $delta = 1): void
    {
        $this->count += $delta;
    }

    public function done(): void
    {
        $this->count--;
        if ($this->count === 0) {
            $this->done->push(true, 0.001);
        }
    }

    public function wait(): void
    {
        if ($this->count === 0) {
            return;
        }
        $this->done->pop();
    }
}
