<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use Phalanx\Application;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Tests\Support\CoroutineTestCase;
use RuntimeException;

final class SpawnHelperTest extends CoroutineTestCase
{
    public function testGoReturnsTaskRunAndExecutesBody(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $scope = $app->createScope();

            $observed = null;
            $run = $scope->go(static function (ExecutionScope $s) use (&$observed): int {
                $observed = 'ran';
                return 7;
            }, name: 'demo-spawn');

            // Yield once so spawned coroutine schedules.
            Coroutine::usleep(5_000);

            self::assertSame('demo-spawn', $run->name);
            self::assertSame('ran', $observed);

            $scope->dispose();
            self::assertSame(0, $ledger->liveCount());
        });
    }

    public function testGoCatchesErrorsAndEmitsPhxSpawn001(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $scope = $app->createScope();

            $run = $scope->go(static function (ExecutionScope $s): never {
                throw new RuntimeException('background boom');
            });

            // Allow spawned task to run + crash without taking down anything.
            Coroutine::usleep(5_000);

            $events = array_values(array_filter(
                $scope->trace()->events(),
                static fn($e) => $e->name === 'PHX-SPAWN-001',
            ));
            self::assertCount(1, $events);
            self::assertSame($run->id, $events[0]->attrs['run']);
            self::assertStringContainsString('background boom', $events[0]->attrs['message']);

            $scope->dispose();
            self::assertSame(0, $ledger->liveCount());
        });
    }

    public function testDisposeForceCancelsLiveSpawnAndEmitsPhxSpawn002(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $scope = $app->createScope();

            $run = $scope->go(static function (ExecutionScope $s): void {
                $s->delay(5.0); // long, will be cancelled
            }, name: 'long-spawn');

            // Yield so the coroutine starts and parks on the delay.
            Coroutine::usleep(5_000);

            $scope->dispose();

            $events = array_values(array_filter(
                $scope->trace()->events(),
                static fn($e) => $e->name === 'PHX-SPAWN-002',
            ));
            self::assertCount(1, $events);
            self::assertSame($run->id, $events[0]->attrs['run']);

            self::assertSame(0, $ledger->liveCount());
        });
    }

    public function testDisposeWaitsForCancelledSpawnToUnwind(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $scope = $app->createScope();
            $started = new Channel(1);
            $unwound = false;

            $scope->go(static function (ExecutionScope $s) use ($started, &$unwound): void {
                try {
                    $started->push(true);
                    $s->delay(5.0);
                } finally {
                    $unwound = true;
                }
            }, name: 'unwind-spawn');

            self::assertTrue($started->pop(1.0));

            $scope->dispose();

            self::assertTrue($unwound);
            self::assertSame(0, $ledger->liveCount());
        });
    }

    public function testCancelReturnedRunStopsSpawnedBody(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $scope = $app->createScope();

            $reachedAfterDelay = false;
            $run = $scope->go(static function (ExecutionScope $s) use (&$reachedAfterDelay): void {
                $s->delay(5.0);
                $reachedAfterDelay = true;
            });

            Coroutine::usleep(5_000);
            $run->cancellation->cancel();

            // Wait briefly for spawned coroutine to observe cancel.
            Coroutine::usleep(20_000);

            self::assertFalse($reachedAfterDelay, 'spawn body must not pass cancelled delay');

            $scope->dispose();
            self::assertSame(0, $ledger->liveCount());
        });
    }

    public function testGoOnDisposedScopeThrows(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $scope = $app->createScope();
            $scope->dispose();

            $caught = null;
            try {
                $scope->go(static fn() => 1);
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
            self::assertNotNull($caught);
            self::assertStringContainsString('disposed', $caught->getMessage());
        });
    }

    private static function buildApp(InProcessLedger $ledger): Application
    {
        $bundle = new class extends ServiceBundle {
            public function services(Services $services, array $context): void
            {
            }
        };
        return Application::starting([])
            ->providers($bundle)
            ->withLedger($ledger)
            ->compile();
    }
}
