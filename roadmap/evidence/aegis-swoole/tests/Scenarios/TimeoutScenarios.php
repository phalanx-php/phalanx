<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Cancellation\Cancelled;
use AegisSwoole\Concurrency\Co;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class TimeoutScenarios
{
    public function register(Harness $h): void
    {
        $h->add('timeout.fast.completes', function (ExecutionScope $scope): Result {
            $value = $scope->timeout(0.5, static function (ExecutionScope $s): int {
                Co::sleep(0.02);
                return 42;
            });
            return Assertions::equals(42, $value) === null
                ? Result::pass()
                : Result::fail("got {$value}");
        });

        $h->add('timeout.slow.throws.Cancelled', function (ExecutionScope $scope): Result {
            $start = microtime(true);
            $err = Assertions::throws(
                Cancelled::class,
                static fn(): mixed => $scope->timeout(0.05, static function (ExecutionScope $s): int {
                    $s->delay(0.5);
                    return 1;
                }),
            );
            if ($err !== null) {
                return Result::fail($err);
            }
            return Assertions::elapsedBetween($start, 0.040, 0.200, 'fired near 50ms') === null
                ? Result::pass()
                : Result::fail('elapsed out of range');
        });

        $h->add('timeout.nested.inner.fires.first', function (ExecutionScope $scope): Result {
            $start = microtime(true);
            try {
                $scope->timeout(
                    0.3,
                    static fn(ExecutionScope $outer): mixed => $outer->timeout(
                        0.05,
                        static function (ExecutionScope $inner): mixed {
                            $inner->delay(0.5);
                            return 'never';
                        },
                    ),
                );
                return Result::fail('expected Cancelled');
            } catch (Cancelled) {
                $err = Assertions::elapsedBetween($start, 0.040, 0.200, 'inner fires near 50ms');
                return $err === null ? Result::pass() : Result::fail($err);
            }
        });

        $h->add('timeout.parent.cancellation.beats.timeout', function (ExecutionScope $scope): Result {
            $token = $scope->cancellation();
            \OpenSwoole\Coroutine::create(static function () use ($token): void {
                Co::sleep(0.03);
                $token->cancel();
            });
            $start = microtime(true);
            try {
                $scope->timeout(0.5, static function (ExecutionScope $inner): int {
                    $inner->delay(0.5);
                    return 1;
                });
                return Result::fail('expected Cancelled');
            } catch (Cancelled) {
                $err = Assertions::elapsedBetween($start, 0.020, 0.200, 'parent fires near 30ms');
                return $err === null ? Result::pass() : Result::fail($err);
            }
        });
    }
}
