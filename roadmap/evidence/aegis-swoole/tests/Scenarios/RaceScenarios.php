<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Concurrency\Co;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class RaceScenarios
{
    public function register(Harness $h): void
    {
        $h->add('race.first.wins.others.cancelled', function (ExecutionScope $scope): Result {
            $started = 0;
            $finishedPastSleep = 0;
            $tasks = [
                'fast' => static function () use (&$started, &$finishedPastSleep): string {
                    $started++;
                    Co::sleep(0.02);
                    $finishedPastSleep++;
                    return 'fast';
                },
                'slow' => static function () use (&$started, &$finishedPastSleep): string {
                    $started++;
                    Co::sleep(0.5);
                    $finishedPastSleep++;
                    return 'slow';
                },
            ];
            $value = $scope->race($tasks);
            // give the cancelled coroutine a moment to fully settle before asserting
            Co::sleep(0.05);
            $errs = [
                Assertions::equals('fast', $value, 'fast wins'),
                Assertions::equals(2, $started, 'both started'),
                Assertions::equals(1, $finishedPastSleep, 'only winner past sleep'),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('race.error.first.throws', function (ExecutionScope $scope): Result {
            $tasks = [
                'bad' => static function (): never {
                    throw new \RuntimeException('boom');
                },
                'slow' => static function (): string {
                    Co::sleep(0.5);
                    return 'slow';
                },
            ];
            $err = Assertions::throws(\RuntimeException::class, static fn() => $scope->race($tasks));
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('race.parent.cancellation.aborts', function (ExecutionScope $scope): Result {
            $token = $scope->cancellation();
            \OpenSwoole\Coroutine::create(static function () use ($token): void {
                Co::sleep(0.05);
                $token->cancel();
            });
            $tasks = [
                static function (): string {
                    Co::sleep(0.5);
                    return 'a';
                },
                static function (): string {
                    Co::sleep(0.5);
                    return 'b';
                },
            ];
            $start = microtime(true);
            $caught = null;
            try {
                $scope->race($tasks);
            } catch (\AegisSwoole\Cancellation\Cancelled $e) {
                $caught = $e;
            }
            if ($caught === null) {
                return Result::fail('expected Cancelled');
            }
            $err = Assertions::elapsedBetween($start, 0.040, 0.200);
            return $err === null ? Result::pass() : Result::fail($err);
        });
    }
}
