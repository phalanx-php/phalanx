<?php

declare(strict_types=1);

namespace Phalanx\Styx\Tests\Support;

use Closure;
use Phalanx\Tests\Support\CoroutineTestCase;

abstract class AsyncTestCase extends CoroutineTestCase
{
    protected function runAsync(Closure $body): void
    {
        $this->runInCoroutine($body);
    }
}
