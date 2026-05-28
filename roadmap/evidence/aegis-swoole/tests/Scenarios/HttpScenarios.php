<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Cancellation\Cancelled;
use AegisSwoole\Concurrency\RetryPolicy;
use AegisSwoole\Http\HttpClient;
use AegisSwoole\Http\HttpResponse;
use AegisSwoole\Middleware\RetryMiddleware;
use AegisSwoole\Middleware\TimeoutMiddleware;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Fixtures\HttpRetryableTask;
use AegisSwoole\Tests\Fixtures\HttpTestServerHandle;
use AegisSwoole\Tests\Fixtures\HttpTimeoutBoundTask;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;
use OpenSwoole\Coroutine;

class HttpScenarios
{
    public function __construct(private readonly HttpTestServerHandle $server)
    {
    }

    public function register(Harness $h): void
    {
        $port = $this->server->port();

        $h->add('http.basic.get.returns.200', function (ExecutionScope $scope) use ($port): Result {
            $client = $scope->service(HttpClient::class);
            $resp = $client->get('127.0.0.1', $port, '/ok');
            $errs = [
                Assertions::equals(200, $resp->statusCode, 'status 200'),
                Assertions::equals('ok', $resp->body, 'body ok'),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('http.concurrent.requests.parallelize', function (ExecutionScope $scope) use ($port): Result {
            $tasks = [
                static fn(ExecutionScope $s): HttpResponse
                    => $s->service(HttpClient::class)->get('127.0.0.1', $port, '/slow?ms=100'),
                static fn(ExecutionScope $s): HttpResponse
                    => $s->service(HttpClient::class)->get('127.0.0.1', $port, '/slow?ms=100'),
                static fn(ExecutionScope $s): HttpResponse
                    => $s->service(HttpClient::class)->get('127.0.0.1', $port, '/slow?ms=100'),
            ];
            $start = microtime(true);
            $results = $scope->concurrent($tasks);
            $err = Assertions::elapsedBetween($start, 0.090, 0.250, '3 × 100ms requests in parallel');
            if ($err !== null) {
                return Result::fail($err);
            }
            $allOk = array_all($results, static fn($r): bool => $r->statusCode === 200 && $r->body === 'slow');
            return $allOk ? Result::pass() : Result::fail('not all responses ok');
        });

        $h->add('http.scope.cancellation.aborts.in.flight', function (ExecutionScope $scope) use ($port): Result {
            $token = $scope->cancellation();
            Coroutine::create(static function () use ($token): void {
                Coroutine::usleep(50_000);
                $token->cancel();
            });
            $start = microtime(true);
            $err = Assertions::throws(
                Cancelled::class,
                static fn() => $scope->service(HttpClient::class)->get('127.0.0.1', $port, '/slow?ms=2000'),
            );
            if ($err !== null) {
                return Result::fail($err);
            }
            return Assertions::elapsedBetween($start, 0.040, 0.500, 'cancelled near 50ms') === null
                ? Result::pass()
                : Result::fail('cancel did not interrupt in-flight HTTP');
        });

        $h->add('http.timeout.middleware.composes.with.HasTimeout', function (ExecutionScope $scope) use ($port): Result {
            $tm = new TimeoutMiddleware();
            $task = new HttpTimeoutBoundTask(timeout: 0.05, host: '127.0.0.1', port: $port, path: '/slow?ms=2000');
            $next = static fn(ExecutionScope $s): HttpResponse => ($task)($s);
            $start = microtime(true);
            $err = Assertions::throws(
                Cancelled::class,
                static fn() => $tm->handle($task, $scope, $next),
            );
            if ($err !== null) {
                return Result::fail($err);
            }
            return Assertions::elapsedBetween($start, 0.040, 0.500, 'timeout fired near 50ms') === null
                ? Result::pass()
                : Result::fail('timeout did not fire in time');
        });

        $h->add('http.retry.middleware.handles.flaky.5xx', function (ExecutionScope $scope) use ($port): Result {
            $rm = new RetryMiddleware();
            $id = bin2hex(random_bytes(4));
            $task = new HttpRetryableTask(
                host: '127.0.0.1',
                port: $port,
                path: "/flaky?id={$id}&after=2",
                policy: RetryPolicy::fixed(5, 20),
            );
            $next = static fn(ExecutionScope $s): HttpResponse => ($task)($s);
            $resp = $rm->handle($task, $scope, $next);
            $errs = [
                Assertions::equals(200, $resp->statusCode, 'eventually got 200'),
                Assertions::equals(3, $task->attempts, 'succeeded on 3rd attempt'),
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
