<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class SettleScenarios
{
    public function register(Harness $h): void
    {
        $h->add('settle.mixed.ok.err', function (ExecutionScope $scope): Result {
            $bag = $scope->settle([
                'a' => static fn(): int => 1,
                'b' => static function (): never {
                    throw new \RuntimeException('b-fail');
                },
                'c' => static fn(): int => 3,
            ]);
            $errs = [
                Assertions::equals(true, $bag->isOk('a'), 'a ok'),
                Assertions::equals(true, $bag->isErr('b'), 'b err'),
                Assertions::equals(true, $bag->isOk('c'), 'c ok'),
                Assertions::equals(1, $bag->get('a'), 'a value'),
                Assertions::equals(3, $bag->get('c'), 'c value'),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('settle.partition', function (ExecutionScope $scope): Result {
            $bag = $scope->settle([
                'x' => static fn(): int => 10,
                'y' => static function (): never {
                    throw new \RuntimeException('y');
                },
            ]);
            [$values, $errors] = $bag->partition();
            $errs = [
                Assertions::arrayEquals(['x' => 10], $values),
                Assertions::equals(1, count($errors), 'one error'),
                Assertions::equals(true, isset($errors['y']), 'y in errors'),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('settle.unwrapAll.throws.on.any.error', function (ExecutionScope $scope): Result {
            $bag = $scope->settle([
                'a' => static fn(): int => 1,
                'b' => static function (): never {
                    throw new \RuntimeException('b');
                },
            ]);
            $err = Assertions::throws(\RuntimeException::class, static fn() => $bag->unwrapAll());
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('settle.allOk.anyOk.semantics', function (ExecutionScope $scope): Result {
            $bag = $scope->settle([
                'a' => static fn(): int => 1,
                'b' => static fn(): int => 2,
            ]);
            $errs = [
                Assertions::equals(true, $bag->allOk, 'allOk'),
                Assertions::equals(true, $bag->anyOk, 'anyOk'),
                Assertions::equals(false, $bag->anyErr, 'no errors'),
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
