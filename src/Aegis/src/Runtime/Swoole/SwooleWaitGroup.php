<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Swoole;

use Swoole\Coroutine\Channel;

final class SwooleWaitGroup
{
    private int $count = 0;

    private ?Channel $waiter = null;

    private bool $waiting = false;

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

        if ($this->count === 0 && $this->waiting && $this->waiter !== null) {
            $this->waiter->push(true, 0.001);
        }
    }

    public function wait(float $timeout = -1): bool
    {
        if ($this->count <= 0) {
            return true;
        }

        $this->waiter ??= new Channel(1);
        $this->waiting = true;

        try {
            return $this->waiter->pop($timeout) !== false;
        } finally {
            $this->waiting = false;
        }
    }

    public function count(): int
    {
        return $this->count;
    }
}
