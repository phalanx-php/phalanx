<?php

declare(strict_types=1);

namespace Phalanx\Substrate;

interface WaitGroupHandle
{
    public function add(int $delta = 1): void;

    public function done(): void;

    public function wait(float $timeout = -1): bool;

    public function count(): int;
}
