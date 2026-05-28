<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Cancellation\Cancelled;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class SeriesScenarios
{
    public function register(Harness $h): void
    {
        $h->add('series.sequential.ordering', function (ExecutionScope $scope): Result {
            $log = [];
            $scope->series([
                static function () use (&$log): int {
                    $log[] = 'a';
                    return 1;
                },
                static function () use (&$log): int {
                    $log[] = 'b';
                    return 2;
                },
                static function () use (&$log): int {
                    $log[] = 'c';
                    return 3;
                },
            ]);
            return Assertions::arrayEquals(['a', 'b', 'c'], $log) === null
                ? Result::pass()
                : Result::fail((string) json_encode($log));
        });

        $h->add('series.error.stops.chain', function (ExecutionScope $scope): Result {
            $log = [];
            try {
                $scope->series([
                    static function () use (&$log): int {
                        $log[] = 'a';
                        return 1;
                    },
                    static function (): never {
                        throw new \RuntimeException('mid');
                    },
                    static function () use (&$log): int {
                        $log[] = 'c';
                        return 3;
                    },
                ]);
            } catch (\RuntimeException) {
            }
            return Assertions::arrayEquals(['a'], $log) === null
                ? Result::pass()
                : Result::fail((string) json_encode($log));
        });

        $h->add('series.cancellation.between.iterations', function (ExecutionScope $scope): Result {
            $token = $scope->cancellation();
            $log = [];
            $tasks = [
                static function () use (&$log, $token): int {
                    $log[] = 'a';
                    $token->cancel();
                    return 1;
                },
                static function () use (&$log): int {
                    $log[] = 'b';
                    return 2;
                },
            ];
            $err = Assertions::throws(Cancelled::class, static fn() => $scope->series($tasks));
            if ($err !== null) {
                return Result::fail($err);
            }
            return Assertions::arrayEquals(['a'], $log) === null
                ? Result::pass()
                : Result::fail((string) json_encode($log));
        });
    }
}
