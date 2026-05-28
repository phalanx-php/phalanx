<?php

declare(strict_types=1);

namespace Phalanx\Engine\Swoole;

use Phalanx\Engine\TimerDriver;
use Swoole\Timer;

final class SwooleTimerDriver implements TimerDriver
{
    public function after(int $ms, \Closure $callback): int|false
    {
        return Timer::after($ms, $callback);
    }

    public function tick(int $ms, \Closure $callback): int|false
    {
        return Timer::tick($ms, $callback);
    }

    public function clear(int $timerId): void
    {
        Timer::clear($timerId);
    }
}
