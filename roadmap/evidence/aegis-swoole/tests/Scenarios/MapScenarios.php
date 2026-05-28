<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Concurrency\Co;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class MapScenarios
{
    public function register(Harness $h): void
    {
        $h->add('map.bounded.concurrency.timing', function (ExecutionScope $scope): Result {
            $items = range(1, 10);
            $start = microtime(true);
            $results = $scope->map(
                $items,
                static fn(int $i) => static function () use ($i): int {
                    Co::sleep(0.1);
                    return $i * 2;
                },
                limit: 3,
            );
            $err = Assertions::elapsedBetween($start, 0.380, 0.600, '10 items @ 100ms / limit 3 ~= 400ms');
            if ($err !== null) {
                return Result::fail($err);
            }
            return Assertions::equals(20, $results[9] ?? null) === null
                ? Result::pass()
                : Result::fail((string) json_encode($results));
        });

        $h->add('map.onEach.fires.per.item', function (ExecutionScope $scope): Result {
            $observed = [];
            $scope->map(
                ['a' => 1, 'b' => 2, 'c' => 3],
                static fn(int $v) => static fn() => $v + 10,
                limit: 2,
                onEach: static function (string|int $k, mixed $v) use (&$observed): void {
                    $observed[$k] = $v;
                },
            );
            ksort($observed);
            return Assertions::arrayEquals(['a' => 11, 'b' => 12, 'c' => 13], $observed) === null
                ? Result::pass()
                : Result::fail((string) json_encode($observed));
        });

        $h->add('map.ordering.preserved', function (ExecutionScope $scope): Result {
            $results = $scope->map(
                ['x' => 'X', 'y' => 'Y', 'z' => 'Z'],
                static fn(string $v) => static function () use ($v): string {
                    Co::sleep((ord($v) - ord('X')) * 0.02);
                    return strtolower($v);
                },
                limit: 3,
            );
            return Assertions::arrayEquals(['x' => 'x', 'y' => 'y', 'z' => 'z'], $results) === null
                ? Result::pass()
                : Result::fail((string) json_encode($results));
        });

        $h->add('map.error.fail.fast', function (ExecutionScope $scope): Result {
            $err = Assertions::throws(
                \RuntimeException::class,
                static fn() => $scope->map(
                    [1, 2, 3],
                    static fn(int $i) => static function () use ($i): never {
                    throw new \RuntimeException("item {$i}");
                    },
                    limit: 3,
                ),
            );
            return $err === null ? Result::pass() : Result::fail($err);
        });
    }
}
