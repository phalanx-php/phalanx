<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Cancellation\AggregateException;
use AegisSwoole\Concurrency\Co;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class AnyScenarios
{
    public function register(Harness $h): void
    {
        $h->add('any.first.success.after.failures', function (ExecutionScope $scope): Result {
            $tasks = [
                'fast.fail' => static function (): never {
                    throw new \RuntimeException('a');
                },
                'slow.ok'   => static function (): string {
                    Co::sleep(0.05);
                    return 'won';
                },
                'mid.fail'  => static function (): never {
                    Co::sleep(0.02);
                    throw new \RuntimeException('b');
                },
            ];
            $value = $scope->any($tasks);
            return Assertions::equals('won', $value) === null ? Result::pass() : Result::fail((string) $value);
        });

        $h->add('any.all.fail.aggregate', function (ExecutionScope $scope): Result {
            $tasks = [
                'a' => static function (): never {
                    throw new \RuntimeException('a');
                },
                'b' => static function (): never {
                    throw new \RuntimeException('b');
                },
            ];
            $err = Assertions::throws(AggregateException::class, static fn() => $scope->any($tasks));
            return $err === null ? Result::pass() : Result::fail($err);
        });
    }
}
