<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\AppHost;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class ScopeScenarios
{
    public function __construct(private readonly AppHost $app)
    {
    }

    public function register(Harness $h): void
    {
        $h->add('scope.onDispose.LIFO', function (ExecutionScope $scope): Result {
            $other = $this->app->createScope();
            $log = [];
            $other->onDispose(static function () use (&$log): void {
                $log[] = 'a';
            });
            $other->onDispose(static function () use (&$log): void {
                $log[] = 'b';
            });
            $other->onDispose(static function () use (&$log): void {
                $log[] = 'c';
            });
            $other->dispose();
            return Assertions::arrayEquals(['c', 'b', 'a'], $log, 'LIFO order') === null
                ? Result::pass()
                : Result::fail('order: ' . (string) json_encode($log));
        });

        $h->add('scope.dispose.idempotent', function (ExecutionScope $scope): Result {
            $other = $this->app->createScope();
            $count = 0;
            $other->onDispose(static function () use (&$count): void {
                $count++;
            });
            $other->dispose();
            $other->dispose();
            return Assertions::equals(1, $count, 'callback ran once') === null
                ? Result::pass()
                : Result::fail("count={$count}");
        });

        $h->add('scope.withAttribute.immutable', function (ExecutionScope $scope): Result {
            $a = $scope->withAttribute('k', 'v1');
            $b = $a->withAttribute('k', 'v2');
            $errs = [
                Assertions::equals('v1', $a->attribute('k'), 'a unchanged'),
                Assertions::equals('v2', $b->attribute('k'), 'b new value'),
                Assertions::equals(null, $scope->attribute('k'), 'original untouched'),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('scope.attribute.default', function (ExecutionScope $scope): Result {
            $err = Assertions::equals('fallback', $scope->attribute('missing', 'fallback'));
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('scope.dispose.swallows.exceptions', function (ExecutionScope $scope): Result {
            $other = $this->app->createScope();
            $ran = 0;
            $other->onDispose(static function () use (&$ran): void {
                $ran++;
            });
            $other->onDispose(static function (): void {
                throw new \RuntimeException('boom');
            });
            $other->onDispose(static function () use (&$ran): void {
                $ran++;
            });
            $other->dispose();
            return Assertions::equals(2, $ran, 'both safe callbacks ran') === null
                ? Result::pass()
                : Result::fail("ran={$ran}");
        });
    }
}
