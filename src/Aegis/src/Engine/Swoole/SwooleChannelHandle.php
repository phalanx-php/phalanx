<?php

declare(strict_types=1);

namespace Phalanx\Engine\Swoole;

use Phalanx\Engine\ChannelHandle;
use Swoole\Coroutine\Channel;

final class SwooleChannelHandle implements ChannelHandle
{
    private Channel $inner;

    public function __construct(int $capacity = 0)
    {
        $this->inner = new Channel($capacity);
    }

    public function push(mixed $data, float $timeout = -1): bool
    {
        return $this->inner->push($data, $timeout);
    }

    public function pop(float $timeout = -1): mixed
    {
        return $this->inner->pop($timeout);
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function length(): int
    {
        return $this->inner->length();
    }

    public function isFull(): bool
    {
        return $this->inner->isFull();
    }

    public function isEmpty(): bool
    {
        return $this->inner->isEmpty();
    }

    public function errCode(): int
    {
        return $this->inner->errCode;
    }
}
