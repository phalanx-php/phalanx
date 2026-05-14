<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\TaskHandle;
use Phalanx\Testing\PhalanxTestCase;
use RuntimeException;

final class SpawnHelperTest extends PhalanxTestCase
{
    public function testGoReturnsOwnedHandleAndExecutesBody(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $inner = $app->createScope();

            $observed = null;
            $ran = new Channel(1);
            $handle = $inner->go(static function (ExecutionScope $_s) use ($ran, &$observed): int {
                $observed = 'ran';
                $ran->push(true);
                return 7;
            }, name: 'demo-spawn');

            self::assertTrue($ran->pop(1.0));

            self::assertSame('demo-spawn', $handle->name);
            self::assertSame('ran', $observed);

            $inner->dispose();
            self::assertSame(0, $ledger->liveCount());
        });
    }

    public function testGoCatchesErrorsAndEmitsPhxSpawn001(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $inner = $app->createScope();
            $started = new Channel(1);

            $handle = $inner->go(static function (ExecutionScope $_s) use ($started): never {
                $started->push(true);
                throw new RuntimeException('background boom');
            });

            self::assertTrue($started->pop(1.0));
            self::waitUntil(
                static fn(): bool => self::traceEvents($inner, 'PHX-SPAWN-001') !== [],
                'spawn error trace was not emitted',
            );

            $events = self::traceEvents($inner, 'PHX-SPAWN-001');
            self::assertCount(1, $events);
            self::assertSame($handle->id, $events[0]->attrs['run']);
            self::assertStringContainsString('background boom', $events[0]->attrs['message']);

            $inner->dispose();
            self::assertSame(0, $ledger->liveCount());
        });
    }

    public function testDisposeForceCancelsLiveSpawnAndEmitsPhxSpawn002(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $inner = $app->createScope();
            $started = new Channel(1);

            $handle = $inner->go(static function (ExecutionScope $s) use ($started): void {
                $started->push(true);
                $s->delay(5.0);
            }, name: 'long-spawn');

            self::assertTrue($started->pop(1.0));

            $inner->dispose();

            $events = self::traceEvents($inner, 'PHX-SPAWN-002');
            self::assertCount(1, $events);
            self::assertSame($handle->id, $events[0]->attrs['run']);

            self::assertSame(0, $ledger->liveCount());
        });
    }

    public function testDisposeWaitsForCancelledSpawnToUnwind(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $inner = $app->createScope();
            $started = new Channel(1);
            $unwound = false;

            $inner->go(static function (ExecutionScope $s) use ($started, &$unwound): void {
                try {
                    $started->push(true);
                    $s->delay(5.0);
                } finally {
                    $unwound = true;
                }
            }, name: 'unwind-spawn');

            self::assertTrue($started->pop(1.0));

            $inner->dispose();

            self::assertTrue($unwound);
            self::assertSame(0, $ledger->liveCount());
        });
    }

    public function testCancelReturnedHandleStopsSpawnedBody(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $inner = $app->createScope();

            $reachedAfterDelay = false;
            $started = new Channel(1);
            $unwound = new Channel(1);
            $handle = $inner->go(static function (ExecutionScope $s) use ($started, $unwound, &$reachedAfterDelay): void {
                try {
                    $started->push(true);
                    $s->delay(5.0);
                    $reachedAfterDelay = true;
                } finally {
                    $unwound->push(true);
                }
            });

            self::assertTrue($started->pop(1.0));
            $handle->cancel();

            self::assertTrue($unwound->pop(1.0));

            self::assertFalse($reachedAfterDelay, 'spawn body must not pass cancelled delay');

            $inner->dispose();
            self::assertSame(0, $ledger->liveCount());
        });
    }

    public function testReturnedHandleSnapshotsLiveRunById(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $inner = $app->createScope();
            $started = new Channel(1);
            $unwound = new Channel(1);

            $handle = $inner->go(static function (ExecutionScope $s) use ($started, $unwound): void {
                try {
                    $started->push(true);
                    $s->delay(5.0);
                } finally {
                    $unwound->push(true);
                }
            }, name: 'snapshot-spawn');

            self::assertTrue($started->pop(1.0));

            $snapshot = $handle->snapshot();

            self::assertNotNull($snapshot);
            self::assertSame($handle->id, $snapshot->id);
            self::assertSame('snapshot-spawn', $snapshot->name);

            $handle->cancel();
            self::assertTrue($unwound->pop(1.0));

            $inner->dispose();

            self::assertNull($handle->snapshot());
            self::assertSame(0, $ledger->liveCount());
        });
    }

    public function testCompletedHandleCannotCancelReusedRunSlot(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $inner = $app->createScope();
            $started = new Channel(1);
            $completedDone = new Channel(1);
            $parkedUnwound = new Channel(1);

            $completed = $inner->go(static function () use ($completedDone): string {
                $completedDone->push(true);
                return 'done';
            }, name: 'completed-spawn');
            self::assertTrue($completedDone->pop(1.0));
            self::waitForNullSnapshot($completed);
            $statsBeforeParked = $inner->supervisor()->poolStats()['taskRun'];

            $parked = $inner->go(static function (ExecutionScope $s) use ($started, $parkedUnwound): void {
                try {
                    $started->push(true);
                    $s->delay(5.0);
                } finally {
                    $parkedUnwound->push(true);
                }
            }, name: 'parked-spawn');
            $statsAfterParked = $inner->supervisor()->poolStats()['taskRun'];

            self::assertTrue($started->pop(1.0));
            self::assertSame($statsBeforeParked['hits'] + 1, $statsAfterParked['hits']);

            $completed->cancel();

            $snapshot = $parked->snapshot();
            self::assertNotNull($snapshot);
            self::assertSame($parked->id, $snapshot->id);
            self::assertSame('parked-spawn', $snapshot->name);

            $parked->cancel();
            self::assertTrue($parkedUnwound->pop(1.0));

            $inner->dispose();
            self::assertSame(0, $ledger->liveCount());
            self::assertSame(0, $inner->supervisor()->poolStats()['taskRun']['borrowed']);
        });
    }

    public function testGoOnDisposedScopeThrows(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $inner = $app->createScope();
            $inner->dispose();

            $caught = null;
            try {
                $inner->go(static fn() => 1);
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
            public function services(Services $services, AppContext $context): void
            {
            }
        };
        return Application::starting()
            ->providers($bundle)
            ->withLedger($ledger)
            ->compile();
    }

    /** @return list<object> */
    private static function traceEvents(ExecutionScope $scope, string $name): array
    {
        return array_values(array_filter(
            $scope->trace()->events(),
            static fn($event): bool => $event->name === $name,
        ));
    }

    /** @param \Closure(): bool $condition */
    private static function waitUntil(\Closure $condition, string $message): void
    {
        $deadline = microtime(true) + 1.0;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            Coroutine::usleep(1_000);
        }

        self::fail($message);
    }

    private static function waitForNullSnapshot(TaskHandle $handle): void
    {
        self::waitUntil(
            static fn(): bool => $handle->snapshot() === null,
            'task handle still had a live snapshot after completion',
        );
    }
}
