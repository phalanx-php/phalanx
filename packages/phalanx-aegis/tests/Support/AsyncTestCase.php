<?php

declare(strict_types=1);

namespace Phalanx\Tests\Support;

use PHPUnit\Framework\TestCase;

use function React\Async\await;
use function React\Promise\resolve;

abstract class AsyncTestCase extends TestCase
{
    protected function runAsync(callable $test): void
    {
        await(resolve(null)->then($test));
    }
}
