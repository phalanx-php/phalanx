<?php

declare(strict_types=1);

namespace Phalanx\Substrate\Swoole;

use Phalanx\Substrate\SignalDriver;
use Swoole\Process;

final class SwooleSignalDriver implements SignalDriver
{
    public function signal(int $signo, ?\Closure $handler): void
    {
        Process::signal($signo, $handler);
    }
}
