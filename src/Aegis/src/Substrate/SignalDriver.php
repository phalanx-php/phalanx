<?php

declare(strict_types=1);

namespace Phalanx\Substrate;

interface SignalDriver
{
    public function signal(int $signo, ?\Closure $handler): void;
}
