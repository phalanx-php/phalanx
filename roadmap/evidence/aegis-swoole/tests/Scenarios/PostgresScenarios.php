<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Cancellation\Cancelled;
use AegisSwoole\Middleware\TimeoutMiddleware;
use AegisSwoole\Postgres\PostgresPool;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Fixtures\SlowQueryTimeoutTask;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;
use OpenSwoole\Coroutine;

/**
 * Phase 9 (rebuilt OpenSwoole 26 with --with-postgres + --enable-cares):
 * `OpenSwoole\Coroutine\PostgreSQL` is the native async client. Queries yield
 * to the scheduler, the connection pool truly parallelizes, scope cancellation
 * interrupts in-flight queries cleanly via Coroutine::cancel.
 *
 * The pdo_pgsql limitations from Phase 7 (serial queries, no in-coroutine cancel)
 * are gone. These scenarios re-instate the parallelism + cancellation tests that
 * couldn't pass on the default homebrew PECL build.
 *
 * Postgres parameter style: `$1, $2, ...` (libpq native), NOT `?` (PDO style).
 */
class PostgresScenarios
{
    public function register(Harness $h): void
    {
        $h->add('postgres.basic.query.returns.rows', function (ExecutionScope $scope): Result {
            $pg = $scope->service(PostgresPool::class);
            $rows = $pg->query('SELECT 1 AS n, 2 AS m');
            $errs = [
                Assertions::equals(1, count($rows), 'one row'),
                Assertions::equals(1, (int) $rows[0]['n'], 'n=1'),
                Assertions::equals(2, (int) $rows[0]['m'], 'm=2'),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('postgres.parameterized.query.binds.values', function (ExecutionScope $scope): Result {
            $pg = $scope->service(PostgresPool::class);
            $rows = $pg->query('SELECT $1::int + $2::int AS sum', [40, 2]);
            $err = Assertions::equals(42, (int) $rows[0]['sum']);
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('postgres.pool.parallelizes.10.queries.over.5.connections', function (ExecutionScope $scope): Result {
            // Native async client + ClientPool. Each query sleeps 100ms server-side.
            // 10 queries / pool size 5 = 2 batches × 100ms ≈ 200ms wall time.
            // Serialized would be ~1000ms.
            $tasks = [];
            for ($i = 0; $i < 10; $i++) {
                $tasks["q{$i}"] = static function (ExecutionScope $s) use ($i): int {
                    $pg = $s->service(PostgresPool::class);
                    $rows = $pg->query('SELECT pg_sleep(0.1), $1::int AS n', [$i]);
                    return (int) $rows[0]['n'];
                };
            }
            $start = microtime(true);
            $results = $scope->concurrent($tasks);
            $err = Assertions::elapsedBetween($start, 0.180, 0.500, '10 queries over pool of 5 in parallel');
            if ($err !== null) {
                return Result::fail($err);
            }
            $expected = [];
            for ($i = 0; $i < 10; $i++) {
                $expected["q{$i}"] = $i;
            }
            return Assertions::arrayEquals($expected, $results) === null
                ? Result::pass()
                : Result::fail('result map mismatch');
        });

        $h->add('postgres.scope.cancellation.aborts.in.flight.query', function (ExecutionScope $scope): Result {
            // Native async client honors Coroutine::cancel — pg_sleep(2) gets
            // interrupted partway through, surfaced as Cancelled.
            $token = $scope->cancellation();
            Coroutine::create(static function () use ($token): void {
                Coroutine::usleep(100_000);
                $token->cancel();
            });
            $start = microtime(true);
            $err = Assertions::throws(
                Cancelled::class,
                static fn() => $scope->service(PostgresPool::class)->query('SELECT pg_sleep(2)'),
            );
            if ($err !== null) {
                return Result::fail($err);
            }
            return Assertions::elapsedBetween($start, 0.090, 0.500, 'cancelled near 100ms') === null
                ? Result::pass()
                : Result::fail('cancel did not interrupt query');
        });

        $h->add('postgres.timeout.middleware.kills.slow.query', function (ExecutionScope $scope): Result {
            $tm = new TimeoutMiddleware();
            $task = new SlowQueryTimeoutTask(timeout: 0.1, sleepSeconds: 2.0);
            $next = static fn(ExecutionScope $s): array => ($task)($s);
            $start = microtime(true);
            $err = Assertions::throws(
                Cancelled::class,
                static fn() => $tm->handle($task, $scope, $next),
            );
            if ($err !== null) {
                return Result::fail($err);
            }
            return Assertions::elapsedBetween($start, 0.080, 0.500, 'timeout fired near 100ms') === null
                ? Result::pass()
                : Result::fail('timeout did not fire in time');
        });
    }
}
