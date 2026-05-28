<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Cancellation\CancellationToken;
use AegisSwoole\Cancellation\Cancelled;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class CancellationScenarios
{
    public function register(Harness $h): void
    {
        $h->add('cancellation.create.cancel.flag', function (ExecutionScope $scope): Result {
            $token = CancellationToken::create();
            $errs = [
                Assertions::equals(false, $token->isCancelled, 'initially not cancelled'),
            ];
            $token->cancel();
            $errs[] = Assertions::equals(true, $token->isCancelled, 'cancelled after cancel');
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('cancellation.throwIfCancelled', function (ExecutionScope $scope): Result {
            $token = CancellationToken::create();
            $token->cancel();
            $err = Assertions::throws(Cancelled::class, static fn() => $token->throwIfCancelled());
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('cancellation.onCancel.listener.fires', function (ExecutionScope $scope): Result {
            $token = CancellationToken::create();
            $fired = false;
            $token->onCancel(static function () use (&$fired): void {
                $fired = true;
            });
            $token->cancel();
            return Assertions::equals(true, $fired) === null ? Result::pass() : Result::fail('listener did not fire');
        });

        $h->add('cancellation.onCancel.after.cancel.fires.immediately', function (ExecutionScope $scope): Result {
            $token = CancellationToken::create();
            $token->cancel();
            $fired = false;
            $token->onCancel(static function () use (&$fired): void {
                $fired = true;
            });
            return Assertions::equals(true, $fired) === null ? Result::pass() : Result::fail('listener did not fire immediately');
        });

        $h->add('cancellation.onCancel.unregister', function (ExecutionScope $scope): Result {
            $token = CancellationToken::create();
            $fired = false;
            $unregister = $token->onCancel(static function () use (&$fired): void {
                $fired = true;
            });
            $unregister();
            $token->cancel();
            return Assertions::equals(false, $fired) === null ? Result::pass() : Result::fail('listener fired after unregister');
        });

        $h->add('cancellation.cancel.idempotent', function (ExecutionScope $scope): Result {
            $token = CancellationToken::create();
            $count = 0;
            $token->onCancel(static function () use (&$count): void {
                $count++;
            });
            $token->cancel();
            $token->cancel();
            return Assertions::equals(1, $count) === null ? Result::pass() : Result::fail("count={$count}");
        });
    }
}
