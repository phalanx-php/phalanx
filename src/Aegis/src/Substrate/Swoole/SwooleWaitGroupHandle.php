<?php

declare(strict_types=1);

namespace Phalanx\Substrate\Swoole;

use Phalanx\Substrate\WaitGroupHandle;
use Swoole\Coroutine\WaitGroup;

final class SwooleWaitGroupHandle implements WaitGroupHandle
{
    private(set) WaitGroup $inner;

    public function __construct()
    {
        $this->inner = new WaitGroup();
    }

    public function add(int $delta = 1): void
    {
        $this->inner->add($delta);
    }

    public function done(): void
    {
        $this->inner->done();
    }

    public function wait(float $timeout = -1): bool
    {
        return $this->inner->wait($timeout);
    }

    public function count(): int
    {
        return $this->inner->count();
    }
}
