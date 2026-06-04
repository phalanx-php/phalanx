<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Mark\Mark;
use Phalanx\Scope\ExecutionScope;

final class RawSleepFixture
{
    public function __invoke(ExecutionScope $scope): void
    {
        \Swoole\Coroutine::usleep(10);
        \Swoole\Coroutine::sleep(1);
        $scope->delay(Mark::ms(10));
    }
}
