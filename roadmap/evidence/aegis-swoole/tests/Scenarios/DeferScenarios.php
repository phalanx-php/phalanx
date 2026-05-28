<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\AppHost;
use AegisSwoole\Concurrency\Co;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;
use AegisSwoole\Trace\TraceType;

class DeferScenarios
{
    public function __construct(private readonly AppHost $app)
    {
    }

    public function register(Harness $h): void
    {
        $h->add('defer.fire.and.forget.no.await', function (ExecutionScope $scope): Result {
            $ran = false;
            $start = microtime(true);
            $scope->defer(static function () use (&$ran): void {
                Co::sleep(0.05);
                $ran = true;
            });
            $elapsedSync = microtime(true) - $start;
            // defer should return immediately (well before the 50ms sleep finishes)
            if ($elapsedSync > 0.030) {
                return Result::fail("defer blocked for {$elapsedSync}s");
            }
            // but the deferred work eventually runs
            Co::sleep(0.10);
            return Assertions::equals(true, $ran) === null
                ? Result::pass()
                : Result::fail('deferred work did not run');
        });

        $h->add('defer.dispose.cancels.pending', function (): Result {
            $other = $this->app->createScope();
            $finished = false;
            $other->defer(static function () use (&$finished): void {
                Co::sleep(0.5);
                $finished = true;
            });
            Co::sleep(0.02);
            $other->dispose();
            Co::sleep(0.10);
            return Assertions::equals(false, $finished, 'deferred body interrupted by dispose') === null
                ? Result::pass()
                : Result::fail('deferred work completed despite dispose');
        });

        $h->add('defer.errors.logged.not.propagated', function (): Result {
            $other = $this->app->createScope();
            $other->defer(static function (): never {
                throw new \RuntimeException('boom');
            });
            Co::sleep(0.05);
            $events = $other->trace()->events();
            $found = array_any(
                $events,
                static fn($event) => $event->type === TraceType::Defer
                    && str_contains((string) $event->name, 'defer.error'),
            );
            $other->dispose();
            return $found ? Result::pass() : Result::fail('no defer.error trace event');
        });
    }
}
