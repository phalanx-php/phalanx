<?php

declare(strict_types=1);

namespace Phalanx\Substrate;

interface ChannelHandle
{
    public const int CLOSED = -2;

    public function push(mixed $data, float $timeout = -1): bool;

    public function pop(float $timeout = -1): mixed;

    public function close(): void;

    public function length(): int;

    public function isFull(): bool;

    public function isEmpty(): bool;

    public function errCode(): int;
}
