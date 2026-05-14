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
            $handle = $inner->go(static function (ExecutionScope $_s) use (&$observed): int {
                $observed = 'ran';
                return 7;
            }, name: 'demo-spawn');

            Coroutine::usleep(5_000);

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

            $handle = $inner->go(static function (ExecutionScope $_s): never {
                throw new RuntimeException('background boom');
            });

            Coroutine::usleep(5_000);

            $events = array_values(array_filter(
                $inner->trace()->events(),
                static fn($e) => $e->name === 'PHX-SPAWN-001',
            ));
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

            $handle = $inner->go(static function (ExecutionScope $s): void {
                $s->delay(5.0);
            }, name: 'long-spawn');

            Coroutine::usleep(5_000);

            $inner->dispose();

            $events = array_values(array_filter(
                $inner->trace()->events(),
                static fn($e) => $e->name === 'PHX-SPAWN-002',
            ));
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
            $handle = $inner->go(static function (ExecutionScope $s) use (&$reachedAfterDelay): void {
                $s->delay(5.0);
                $reachedAfterDelay = true;
            });

            Coroutine::usleep(5_000);
            $handle->cancel();

            Coroutine::usleep(20_000);

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

            $handle = $inner->go(static function (ExecutionScope $s) use ($started): void {
                $started->push(true);
                $s->delay(5.0);
            }, name: 'snapshot-spawn');

            self::assertTrue($started->pop(1.0));

            $snapshot = $handle->snapshot();

            self::assertNotNull($snapshot);
            self::assertSame($handle->id, $snapshot->id);
            self::assertSame('snapshot-spawn', $snapshot->name);

            $handle->cancel();
            Coroutine::usleep(20_000);

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

            $completed = $inner->go(static fn(): string => 'done', name: 'completed-spawn');
            Coroutine::usleep(5_000);

            $parked = $inner->go(static function (ExecutionScope $s) use ($started): void {
                $started->push(true);
                $s->delay(5.0);
            }, name: 'parked-spawn');

            self::assertTrue($started->pop(1.0));

            $completed->cancel();
            Coroutine::usleep(20_000);

            $snapshot = $parked->snapshot();
            self::assertNotNull($snapshot);
            self::assertSame($parked->id, $snapshot->id);
            self::assertSame('parked-spawn', $snapshot->name);

            $parked->cancel();
            Coroutine::usleep(20_000);

            $inner->dispose();
            self::assertSame(0, $ledger->liveCount());
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
}
