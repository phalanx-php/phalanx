<?php

declare(strict_types=1);

namespace Phalanx\Tests\Support;

use Closure;

abstract class AsyncTestCase extends CoroutineTestCase
{
    protected function runAsync(callable $test): void
    {
        $this->runInCoroutine(Closure::fromCallable($test));
    }
}
