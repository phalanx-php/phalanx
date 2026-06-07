<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\TestingPaths\Tests\Acceptance;

use Phalanx\Concurrency\Co;
use Swoole\Coroutine;

final class NoRawTestSleepViolation
{
    public function sleeps(): void
    {
        Coroutine::sleep(0.001);
        Coroutine::usleep(1000);
        Co::sleep(0.001);
        sleep(1);
        usleep(1000);
    }
}
