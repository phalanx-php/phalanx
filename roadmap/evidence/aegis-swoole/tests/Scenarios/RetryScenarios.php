<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Cancellation\Cancelled;
use AegisSwoole\Concurrency\RetryPolicy;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class RetryScenarios
{
    public function register(Harness $h): void
    {
        $h->add('retry.succeed.on.nth', function (ExecutionScope $scope): Result {
            $attempts = 0;
            $value = $scope->retry(
                static function () use (&$attempts): int {
                    $attempts++;
                    if ($attempts < 3) {
                        throw new \RuntimeException("attempt {$attempts}");
                    }
                    return 99;
                },
                RetryPolicy::fixed(5, 5.0),
            );
            $errs = [
                Assertions::equals(99, $value, 'final value'),
                Assertions::equals(3, $attempts, 'three tries'),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('retry.exhaust.throws.last', function (ExecutionScope $scope): Result {
            $attempts = 0;
            $err = Assertions::throws(\RuntimeException::class, function () use ($scope, &$attempts): mixed {
                return $scope->retry(
                    static function () use (&$attempts): never {
                        $attempts++;
                        throw new \RuntimeException("attempt {$attempts}");
                    },
                    RetryPolicy::fixed(3, 5.0),
                );
            });
            if ($err !== null) {
                return Result::fail($err);
            }
            return Assertions::equals(3, $attempts) === null
                ? Result::pass()
                : Result::fail("attempts={$attempts}");
        });

        $h->add('retry.Cancelled.never.retried', function (ExecutionScope $scope): Result {
            $attempts = 0;
            $err = Assertions::throws(Cancelled::class, function () use ($scope, &$attempts): mixed {
                return $scope->retry(
                    static function () use (&$attempts): never {
                        $attempts++;
                        throw new Cancelled('mid-task');
                    },
                    RetryPolicy::fixed(5, 5.0),
                );
            });
            if ($err !== null) {
                return Result::fail($err);
            }
            return Assertions::equals(1, $attempts, 'attempted exactly once') === null
                ? Result::pass()
                : Result::fail("attempts={$attempts}");
        });

        $h->add('retry.exponential.backoff.timing', function (ExecutionScope $scope): Result {
            $attempts = 0;
            $start = microtime(true);
            try {
                $scope->retry(
                    static function () use (&$attempts): never {
                        $attempts++;
                        throw new \RuntimeException("a{$attempts}");
                    },
                    RetryPolicy::exponential(3, baseDelayMs: 50.0, maxDelayMs: 1000.0),
                );
            } catch (\RuntimeException) {
            }
            // 3 attempts: sleep 50ms then 100ms (no sleep after last) ≈ 150ms + jitter
            return Assertions::elapsedBetween($start, 0.140, 0.400, '~150ms total backoff') === null
                ? Result::pass()
                : Result::fail('elapsed out of range');
        });

        $h->add('retry.retryingOn.filters.exceptions', function (ExecutionScope $scope): Result {
            $attempts = 0;
            $policy = RetryPolicy::fixed(5, 5.0)->retryingOn(\LogicException::class);
            $err = Assertions::throws(\RuntimeException::class, function () use ($scope, &$attempts, $policy): mixed {
                return $scope->retry(
                    static function () use (&$attempts): never {
                        $attempts++;
                        throw new \RuntimeException("not retryable");
                    },
                    $policy,
                );
            });
            if ($err !== null) {
                return Result::fail($err);
            }
            return Assertions::equals(1, $attempts) === null
                ? Result::pass()
                : Result::fail("attempts={$attempts}");
        });
    }
}
