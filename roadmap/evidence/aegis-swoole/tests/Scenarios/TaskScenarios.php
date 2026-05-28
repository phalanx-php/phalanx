<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Task\Task;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Fixtures\PlainScopeable;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;
use RuntimeException;

class TaskScenarios
{
    public function register(Harness $h): void
    {
        $h->add('task.of.accepts.static.closure', function (ExecutionScope $scope): Result {
            $task = Task::of(static fn(): int => 42);
            $value = $scope->execute($task);
            $err = Assertions::equals(42, $value);
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('task.of.rejects.non.static.closure', function (ExecutionScope $scope): Result {
            $nonStatic = (fn(): int => 1);
            $err = Assertions::throws(RuntimeException::class, static fn() => Task::of($nonStatic));
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('task.execute.dispatches.scopeable.and.executable', function (ExecutionScope $scope): Result {
            $sc = new PlainScopeable();
            $value = $scope->execute($sc);
            $errs = [
                Assertions::equals('plain', $value, 'scopeable returned value'),
                Assertions::equals(1, $sc->invocations, 'scopeable invoked once'),
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
