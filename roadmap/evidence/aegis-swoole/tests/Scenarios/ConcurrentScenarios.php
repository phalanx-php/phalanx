<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Cancellation\Cancelled;
use AegisSwoole\Concurrency\Co;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;
use OpenSwoole\Coroutine;

class ConcurrentScenarios
{
    public function register(Harness $h): void
    {
        $h->add('concurrent.fan.out.keys.preserved', function (ExecutionScope $scope): Result {
            $tasks = [
                'a' => static fn() => 1,
                'b' => static fn() => 2,
                'c' => static fn() => 3,
            ];
            $results = $scope->concurrent($tasks);
            return Assertions::arrayEquals(['a' => 1, 'b' => 2, 'c' => 3], $results) === null
                ? Result::pass()
                : Result::fail((string) json_encode($results));
        });

        $h->add('concurrent.HOOK_ALL.parallelism', function (ExecutionScope $scope): Result {
            $tasks = [
                static function (): int {
                    Co::sleep(0.1);
                    return 1;
                },
                static function (): int {
                    Co::sleep(0.1);
                    return 2;
                },
                static function (): int {
                    Co::sleep(0.1);
                    return 3;
                },
            ];
            $start = microtime(true);
            $scope->concurrent($tasks);
            $err = Assertions::elapsedBetween($start, 0.090, 0.180, 'three 100ms sleeps in parallel');
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('concurrent.fail.fast.throws.first.error', function (ExecutionScope $scope): Result {
            $tasks = [
                'ok'  => static fn() => 'ok',
                'bad' => static function (): never {
                    throw new \RuntimeException('boom');
                },
            ];
            $err = Assertions::throws(\RuntimeException::class, static fn() => $scope->concurrent($tasks));
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('concurrent.ordering.by.input.key', function (ExecutionScope $scope): Result {
            $tasks = [
                'slow' => static function (): string {
                    Co::sleep(0.05);
                    return 'slow';
                },
                'fast' => static fn(): string => 'fast',
            ];
            $results = $scope->concurrent($tasks);
            return Assertions::arrayEquals(['slow' => 'slow', 'fast' => 'fast'], $results) === null
                ? Result::pass()
                : Result::fail((string) json_encode($results));
        });

        $h->add('concurrent.parent.cancellation.propagates', function (ExecutionScope $scope): Result {
            $token = $scope->cancellation();
            $started = 0;
            $finished = 0;
            $tasks = [
                static function () use (&$started, &$finished): void {
                    $started++;
                    Co::sleep(0.5);
                    $finished++;
                },
                static function () use (&$started, &$finished): void {
                    $started++;
                    Co::sleep(0.5);
                    $finished++;
                },
            ];

            // schedule cancellation 50ms in
            Coroutine::create(static function () use ($token): void {
                Co::sleep(0.05);
                $token->cancel();
            });

            $start = microtime(true);
            $caught = null;
            try {
                $scope->concurrent($tasks);
            } catch (Cancelled $e) {
                $caught = $e;
            }

            $errs = [
                Assertions::equals(2, $started, 'both started'),
                Assertions::equals(0, $finished, 'neither completed past sleep'),
                Assertions::elapsedBetween($start, 0.040, 0.200, 'returned within ~50ms cancel'),
            ];
            if ($caught === null) {
                return Result::fail('expected Cancelled');
            }
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });
    }
}
