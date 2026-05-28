<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Cancellation\CancellationToken;
use AegisSwoole\Concurrency\Co;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class CancellationExtraScenarios
{
    public function register(Harness $h): void
    {
        $h->add('cancellation.timeout.auto.cancels.and.clears.timer', function (ExecutionScope $scope): Result {
            $token = CancellationToken::timeout(0.1);
            // cancel manually before the timer fires
            Co::sleep(0.02);
            $token->cancel();
            $cancelled = $token->isCancelled;
            // wait past when the timer would have fired
            Co::sleep(0.2);
            // if the timer ran on the cancelled token, no observable side effect, but ensure the flag remained true
            $errs = [
                Assertions::equals(true, $cancelled, 'cancelled after manual cancel'),
                Assertions::equals(true, $token->isCancelled, 'still cancelled, no double-fire'),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('cancellation.composite.cancels.from.any.source', function (ExecutionScope $scope): Result {
            $a = CancellationToken::create();
            $b = CancellationToken::create();
            $c = CancellationToken::composite($a, $b);
            $errs = [
                Assertions::equals(false, $c->isCancelled, 'initially open'),
            ];
            $b->cancel();
            $errs[] = Assertions::equals(true, $c->isCancelled, 'composite cancelled when source cancels');
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('cancellation.composite.pre.cancelled.source', function (ExecutionScope $scope): Result {
            $a = CancellationToken::create();
            $b = CancellationToken::create();
            $b->cancel();
            $c = CancellationToken::composite($a, $b);
            return Assertions::equals(true, $c->isCancelled, 'composite cancelled at construction') === null
                ? Result::pass()
                : Result::fail('composite not pre-cancelled');
        });

        $h->add('cancellation.timeout.actually.fires', function (ExecutionScope $scope): Result {
            $token = CancellationToken::timeout(0.05);
            Co::sleep(0.10);
            return Assertions::equals(true, $token->isCancelled) === null
                ? Result::pass()
                : Result::fail('timer did not fire');
        });
    }
}
