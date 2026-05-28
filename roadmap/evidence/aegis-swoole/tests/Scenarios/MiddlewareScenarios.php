<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Application;
use AegisSwoole\Cancellation\Cancelled;
use AegisSwoole\Concurrency\RetryPolicy;
use AegisSwoole\Middleware\RetryMiddleware;
use AegisSwoole\Middleware\TaskMiddleware;
use AegisSwoole\Middleware\TimeoutMiddleware;
use AegisSwoole\Middleware\TraceMiddleware;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Service\ServiceBundle;
use AegisSwoole\Service\Services;
use AegisSwoole\Task\Executable;
use AegisSwoole\Task\Scopeable;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Fixtures\PlainScopeable;
use AegisSwoole\Tests\Fixtures\RetryableTask;
use AegisSwoole\Tests\Fixtures\TimeoutBoundTask;
use AegisSwoole\Tests\Fixtures\TraceableTask;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;
use AegisSwoole\Trace\TraceType;
use Closure;

class MiddlewareScenarios
{
    /** @param list<string> $order */
    private static function recording(string $tag, array &$order): TaskMiddleware
    {
        return new class ($tag, $order) implements TaskMiddleware {
            /** @var list<string> */
            public array $order;

            /** @param list<string> $order */
            public function __construct(public readonly string $tag, array &$order)
            {
                $this->order = &$order;
            }

            public function handle(Scopeable|Executable|Closure $task, ExecutionScope $scope, Closure $next): mixed
            {
                $this->order[] = "{$this->tag}:before";
                $result = $next($scope);
                $this->order[] = "{$this->tag}:after";
                return $result;
            }
        };
    }

    /** @param list<TaskMiddleware> $middlewares */
    private static function buildApp(array $middlewares): Application
    {
        $bundle = new class implements ServiceBundle {
            public function services(Services $services, array $context): void
            {
            }
        };
        return Application::starting([])
            ->providers($bundle)
            ->taskMiddleware(...$middlewares)
            ->compile()
            ->startup();
    }

    public function register(Harness $h): void
    {
        $h->add('middleware.chain.order.preserved', function (ExecutionScope $_): Result {
            $order = [];
            $a = self::recording('a', $order);
            $b = self::recording('b', $order);
            $c = self::recording('c', $order);
            $app = self::buildApp([$a, $b, $c]);
            try {
                $scope = $app->createScope();
                $scope->execute(new PlainScopeable());
                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            $err = Assertions::arrayEquals(
                ['a:before', 'b:before', 'c:before', 'c:after', 'b:after', 'a:after'],
                $order,
            );
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('middleware.retry.fires.on.Retryable', function (ExecutionScope $_): Result {
            $task = new RetryableTask(failUntilAttempt: 3, policy: RetryPolicy::fixed(3, 10));
            $app = self::buildApp([new RetryMiddleware()]);
            try {
                $scope = $app->createScope();
                $value = $scope->execute($task);
                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            $errs = [
                Assertions::equals(3, $task->attempts, 'task ran 3 times'),
                Assertions::equals(3, $value, 'returned attempt count'),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('middleware.retry.skipped.on.non.Retryable', function (ExecutionScope $_): Result {
            $sc = new PlainScopeable();
            $app = self::buildApp([new RetryMiddleware()]);
            try {
                $scope = $app->createScope();
                $scope->execute($sc);
                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            $err = Assertions::equals(1, $sc->invocations, 'plain scopeable invoked once (no retry)');
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('middleware.timeout.fires.on.HasTimeout', function (ExecutionScope $_): Result {
            $task = new TimeoutBoundTask(timeout: 0.05, sleep: 0.5);
            $app = self::buildApp([new TimeoutMiddleware()]);
            try {
                $scope = $app->createScope();
                $start = microtime(true);
                $err = Assertions::throws(Cancelled::class, static fn() => $scope->execute($task));
                $elapsed = microtime(true) - $start;
                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            if ($err !== null) {
                return Result::fail($err);
            }
            $rangeErr = Assertions::elapsedBetween(microtime(true) - $elapsed, 0.040, 0.250, 'fired near 50ms');
            return $rangeErr === null ? Result::pass() : Result::fail($rangeErr);
        });

        $h->add('middleware.trace.emits.execute.events', function (ExecutionScope $_): Result {
            $task = new TraceableTask('test.task');
            $app = self::buildApp([new TraceMiddleware()]);
            try {
                $scope = $app->createScope();
                $scope->execute($task);
                $events = $scope->trace()->events();
                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            $matched = array_values(array_filter(
                $events,
                static fn($e): bool => $e->type === TraceType::Execute && $e->name === 'test.task',
            ));
            $errs = [
                Assertions::equals(2, count($matched), 'two execute events emitted'),
                Assertions::equals('start', $matched[0]->attrs['phase'] ?? null, 'first event = start'),
                Assertions::equals('end', $matched[1]->attrs['phase'] ?? null, 'second event = end'),
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
