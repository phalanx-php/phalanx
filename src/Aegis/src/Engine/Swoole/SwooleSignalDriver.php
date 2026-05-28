<?php

declare(strict_types=1);

namespace Phalanx\Engine\Swoole;

use Phalanx\Engine\SignalDriver;
use Swoole\Process;

final class SwooleSignalDriver implements SignalDriver
{
    public function signal(int $signo, ?\Closure $handler): void
    {
        Process::signal($signo, $handler);
    }
}
