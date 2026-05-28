<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class DelayScenarios
{
    public function register(Harness $h): void
    {
        $h->add('delay.sleeps.duration', function (ExecutionScope $scope): Result {
            $start = microtime(true);
            $scope->delay(0.05);
            $err = Assertions::elapsedBetween($start, 0.045, 0.120);
            return $err === null ? Result::pass() : Result::fail($err);
        });
    }
}
