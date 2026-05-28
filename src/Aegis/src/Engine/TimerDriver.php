<?php

declare(strict_types=1);

namespace Phalanx\Engine;

interface TimerDriver
{
    public function after(int $ms, \Closure $callback): int|false;

    public function tick(int $ms, \Closure $callback): int|false;

    public function clear(int $timerId): void;
}
