<?php

declare(strict_types=1);

// Corrects swoole/ide-helper's Swoole\Lock::lock() stub, which omits the
// $operation and $timeout parameters present in ext-swoole 6.
// Verified via reflection against ext-swoole 6.2.1:
//   lock(int $operation = 2, float $timeout = -1): bool

namespace Swoole;

class Lock
{
    public function lock(int $operation = 2, float $timeout = -1.0): bool
    {
    }
}
