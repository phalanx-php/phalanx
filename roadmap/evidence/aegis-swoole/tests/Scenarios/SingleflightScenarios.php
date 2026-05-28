<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Concurrency\Co;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class SingleflightScenarios
{
    public function register(Harness $h): void
    {
        $h->add('singleflight.same.key.deduped', function (ExecutionScope $scope): Result {
            $invocations = 0;
            $task = static function () use (&$invocations): int {
                $invocations++;
                Co::sleep(0.05);
                return 42;
            };

            $tasks = array_fill(0, 5, static fn() => $scope->singleflight('k', $task));
            $results = $scope->concurrent($tasks);

            $errs = [
                Assertions::equals(1, $invocations, 'task invoked exactly once'),
                Assertions::arrayEquals([42, 42, 42, 42, 42], array_values($results)),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('singleflight.different.keys.independent', function (ExecutionScope $scope): Result {
            $invocations = 0;
            $task = static function () use (&$invocations): int {
                return ++$invocations;
            };

            $a = $scope->singleflight('a', $task);
            $b = $scope->singleflight('b', $task);
            $c = $scope->singleflight('a', $task);

            // singleflight is in-flight-only: completed key removed, re-entry runs task again
            $errs = [
                Assertions::equals(3, $invocations, 'each call runs task because none overlap in flight'),
                Assertions::equals(1, $a),
                Assertions::equals(2, $b),
                Assertions::equals(3, $c, 're-entry of completed key reruns task'),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('singleflight.error.propagated.to.all.waiters', function (ExecutionScope $scope): Result {
            $invocations = 0;
            $task = static function () use (&$invocations): never {
                $invocations++;
                Co::sleep(0.05);
                throw new \RuntimeException('shared failure');
            };

            $tasks = array_fill(0, 3, static fn() => $scope->singleflight('err.key', $task));
            $bag = $scope->settle($tasks);

            $errs = [
                Assertions::equals(1, $invocations, 'task invoked once'),
                Assertions::equals(true, $bag->allErr, 'all callers see error'),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });
    }
}
