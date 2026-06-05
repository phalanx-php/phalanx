<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Reactive;

use Closure;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Concurrency\SettlementBag;
use Phalanx\Mark\Mark;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Scheduling\ScheduleBuilder;
use Phalanx\Scope\Subscription;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Supervisor\TaskHandle;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Tui\Reactive\Resource;
use Phalanx\Worker\WorkerTask;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ResourceTest extends TestCase
{
    #[Test]
    public function refreshSetsLoadingAndResolvesValue(): void
    {
        $dirty = 0;

        $resource = new Resource(
            fetcher: static fn(mixed $key): string => "result-{$key}",
            onDirty: static function () use (&$dirty): void {
                $dirty++;
            },
        );

        $resource->refresh('abc');

        self::assertTrue($resource->ok);
        self::assertFalse($resource->loading);
        self::assertSame('result-abc', $resource->value);
        self::assertNull($resource->error);
        self::assertSame(2, $dirty);
    }

    #[Test]
    public function refreshCapturesErrorOnFailure(): void
    {
        $dirty = 0;

        $resource = new Resource(
            fetcher: static function (): never {
                throw new RuntimeException('fetch failed');
            },
            onDirty: static function () use (&$dirty): void {
                $dirty++;
            },
        );

        $resource->refresh();

        self::assertFalse($resource->ok);
        self::assertFalse($resource->loading);
        self::assertNull($resource->value);
        self::assertInstanceOf(RuntimeException::class, $resource->error);
        self::assertSame('fetch failed', $resource->error->getMessage());
    }

    #[Test]
    public function repeatedRefreshesUseLatestResult(): void
    {
        $callCount = 0;

        $resource = new Resource(
            fetcher: static function (mixed $_key) use (&$callCount): string {
                $callCount++;
                return "v{$callCount}";
            },
        );

        $resource->refresh('a');
        self::assertSame('v1', $resource->value);

        $resource->refresh('b');
        self::assertSame('v2', $resource->value);
        self::assertTrue($resource->ok);
    }

    #[Test]
    public function repeatedRefreshesKeepTheSameResourceInstance(): void
    {
        $resource = new Resource(
            fetcher: static fn(mixed $key): string => "value-{$key}",
        );

        $observed = $resource;

        $resource->refresh('one');
        $resource->refresh('two');

        self::assertSame($observed, $resource);
        self::assertTrue($resource->ok);
        self::assertFalse($resource->loading);
        self::assertSame('value-two', $resource->value);
    }

    #[Test]
    public function failedRefreshPreservesLastSuccessfulValue(): void
    {
        $calls = 0;

        $resource = new Resource(
            fetcher: static function () use (&$calls): string {
                $calls++;

                if ($calls === 2) {
                    throw new RuntimeException('later failure');
                }

                return 'last-good';
            },
        );

        $resource->refresh();
        $resource->refresh();

        self::assertTrue($resource->ok);
        self::assertFalse($resource->loading);
        self::assertSame('last-good', $resource->value);
        self::assertInstanceOf(RuntimeException::class, $resource->error);
        self::assertSame('later failure', $resource->error->getMessage());
    }

    #[Test]
    public function asyncSupersessionIgnoresStaleResultAndDirtyNotification(): void
    {
        $dirty = 0;
        $executor = new QueuedResourceTaskExecutor();

        $resource = new Resource(
            fetcher: static fn(string $key): string => "result-{$key}",
            executor: $executor,
            onDirty: static function () use (&$dirty): void {
                $dirty++;
            },
        );

        $resource->refresh('slow');
        $resource->refresh('fast');

        self::assertSame(2, $executor->queuedCount());
        self::assertSame(1, $executor->cancelCount());
        self::assertSame(2, $dirty);
        self::assertTrue($resource->loading);

        $executor->runQueued(1);

        self::assertTrue($resource->ok);
        self::assertFalse($resource->loading);
        self::assertSame('result-fast', $resource->value);
        self::assertSame(3, $dirty);

        $executor->runQueued(0);

        self::assertSame('result-fast', $resource->value);
        self::assertSame(3, $dirty);
    }

    #[Test]
    public function disposeBeforeAsyncCompletionPreventsWritesAndDirtyNotification(): void
    {
        $dirty = 0;
        $executor = new QueuedResourceTaskExecutor();

        $resource = new Resource(
            fetcher: static fn(): string => 'late-result',
            executor: $executor,
            onDirty: static function () use (&$dirty): void {
                $dirty++;
            },
        );

        $resource->refresh();
        $resource->dispose();

        self::assertFalse($resource->loading);
        self::assertSame(1, $dirty);
        self::assertSame(1, $executor->cancelCount());

        $executor->runQueued(0);

        self::assertFalse($resource->ok);
        self::assertFalse($resource->loading);
        self::assertNull($resource->value);
        self::assertNull($resource->error);
        self::assertSame(1, $dirty);
    }

    #[Test]
    public function streamAppendsChunksIntoBufferAndPromotesCompletedValue(): void
    {
        $dirty = 0;

        $resource = new Resource(
            fetcher: static fn(): iterable => ['Phalanx ', 'streaming ', 'works'],
            onDirty: static function () use (&$dirty): void {
                $dirty++;
            },
        );

        $resource->stream();

        self::assertTrue($resource->ok);
        self::assertFalse($resource->loading);
        self::assertSame('Phalanx streaming works', $resource->buffer);
        self::assertSame('Phalanx streaming works', $resource->value);
        self::assertNull($resource->error);
        self::assertSame(5, $dirty);
    }

    #[Test]
    public function failedStreamPreservesLastCompletedValueAndExposesPartialBuffer(): void
    {
        $calls = 0;

        $resource = new Resource(
            fetcher: static function () use (&$calls): iterable {
                $calls++;

                if ($calls === 1) {
                    yield 'last ';
                    yield 'good';
                    return;
                }

                yield 'partial ';
                yield 'attempt';

                throw new RuntimeException('stream failed');
            },
        );

        $resource->stream();
        $resource->stream();

        self::assertTrue($resource->ok);
        self::assertFalse($resource->loading);
        self::assertSame('last good', $resource->value);
        self::assertSame('partial attempt', $resource->buffer);
        self::assertInstanceOf(RuntimeException::class, $resource->error);
        self::assertSame('stream failed', $resource->error->getMessage());
    }

    #[Test]
    public function asyncStreamSupersessionIgnoresStaleChunksCompletionAndDirtyNotifications(): void
    {
        $dirty = 0;
        $executor = new QueuedResourceTaskExecutor();

        $resource = new Resource(
            fetcher: static fn(string $key): iterable => match ($key) {
                'slow' => ['stale ', 'stream'],
                'fast' => ['fresh ', 'stream'],
                default => throw new RuntimeException("Unexpected stream key {$key}."),
            },
            executor: $executor,
            onDirty: static function () use (&$dirty): void {
                $dirty++;
            },
        );

        $resource->stream('slow');
        $resource->stream('fast');

        self::assertSame(2, $executor->queuedCount());
        self::assertSame(1, $executor->cancelCount());
        self::assertSame(2, $dirty);
        self::assertSame('', $resource->buffer);
        self::assertTrue($resource->loading);

        $executor->runQueued(1);

        self::assertTrue($resource->ok);
        self::assertFalse($resource->loading);
        self::assertSame('fresh stream', $resource->buffer);
        self::assertSame('fresh stream', $resource->value);
        self::assertSame(5, $dirty);

        $executor->runQueued(0);

        self::assertSame('fresh stream', $resource->buffer);
        self::assertSame('fresh stream', $resource->value);
        self::assertSame(5, $dirty);
    }

    #[Test]
    public function disposeBeforeAsyncStreamCompletionPreventsWritesAndDirtyNotification(): void
    {
        $dirty = 0;
        $executor = new QueuedResourceTaskExecutor();

        $resource = new Resource(
            fetcher: static fn(): iterable => ['late ', 'stream'],
            executor: $executor,
            onDirty: static function () use (&$dirty): void {
                $dirty++;
            },
        );

        $resource->stream();
        $resource->dispose();

        self::assertFalse($resource->loading);
        self::assertSame('', $resource->buffer);
        self::assertSame(1, $dirty);
        self::assertSame(1, $executor->cancelCount());

        $executor->runQueued(0);

        self::assertFalse($resource->ok);
        self::assertFalse($resource->loading);
        self::assertSame('', $resource->buffer);
        self::assertNull($resource->value);
        self::assertNull($resource->error);
        self::assertSame(1, $dirty);
    }

    #[Test]
    public function streamCapturesNonIterableFetcherResultAsError(): void
    {
        $resource = new Resource(
            fetcher: static fn(): string => 'not iterable',
        );

        $resource->stream();

        self::assertFalse($resource->ok);
        self::assertFalse($resource->loading);
        self::assertSame('', $resource->buffer);
        self::assertNull($resource->value);
        self::assertInstanceOf(RuntimeException::class, $resource->error);
        self::assertSame('Resource stream fetcher must return an iterable.', $resource->error->getMessage());
    }

    #[Test]
    public function streamCapturesNonStringChunkAsError(): void
    {
        $resource = new Resource(
            fetcher: static fn(): iterable => ['valid', 42],
        );

        $resource->stream();

        self::assertFalse($resource->ok);
        self::assertFalse($resource->loading);
        self::assertSame('valid', $resource->buffer);
        self::assertNull($resource->value);
        self::assertInstanceOf(RuntimeException::class, $resource->error);
        self::assertSame('Resource stream chunks must be strings.', $resource->error->getMessage());
    }

    #[Test]
    public function streamNotifiesSubscribers(): void
    {
        $calls = 0;

        $resource = new Resource(
            fetcher: static fn(): iterable => ['a', 'b'],
        );
        $subscription = $resource->subscribe(static function () use (&$calls): void {
            $calls++;
        });

        $resource->stream();

        self::assertFalse($subscription->isDisposed);
        self::assertSame(4, $calls);

        $subscription->dispose();
        $resource->stream();

        self::assertTrue($subscription->isDisposed);
        self::assertSame(4, $calls);
    }

    #[Test]
    public function nonStaticSubscriberThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Resource subscribers must be static closures.');

        $resource = new Resource(
            fetcher: static fn(): string => 'data',
        );

        $resource->subscribe(function (): void {
        });
    }

    #[Test]
    public function cancelledRefreshIsNotCapturedAsResourceError(): void
    {
        $dirty = 0;
        $resource = new Resource(
            fetcher: static function (): never {
                throw new Cancelled('test cancel');
            },
            onDirty: static function () use (&$dirty): void {
                $dirty++;
            },
        );

        try {
            $resource->refresh();
            self::fail('Expected resource refresh cancellation to be rethrown.');
        } catch (Cancelled $e) {
            self::assertSame('test cancel', $e->getMessage());
        }

        self::assertFalse($resource->loading);
        self::assertNull($resource->error);
        self::assertSame(2, $dirty);
    }

    #[Test]
    public function refreshClearsPreviousStreamBuffer(): void
    {
        $calls = 0;

        $resource = new Resource(
            fetcher: static function () use (&$calls): mixed {
                $calls++;

                if ($calls === 1) {
                    return ['stream ', 'buffer'];
                }

                return 'fresh scalar';
            },
        );

        $resource->stream();
        $resource->refresh();

        self::assertTrue($resource->ok);
        self::assertFalse($resource->loading);
        self::assertSame('', $resource->buffer);
        self::assertSame('fresh scalar', $resource->value);
    }

    #[Test]
    public function startedStreamSupersessionCancelsPreviousTaskAndRejectsFurtherChunks(): void
    {
        $dirty = 0;
        $executor = new QueuedResourceTaskExecutor();
        $resource = null;

        $resource = new Resource(
            fetcher: static function (string $key) use (&$resource): iterable {
                if ($key === 'slow') {
                    yield 'stale ';
                    $resource?->stream('fast');
                    yield 'ignored';
                    return;
                }

                yield 'fresh';
            },
            executor: $executor,
            onDirty: static function () use (&$dirty): void {
                $dirty++;
            },
        );

        $resource->stream('slow');
        $executor->runQueued(0);

        self::assertSame(1, $executor->queuedCount());
        self::assertSame(1, $executor->cancelCount());
        self::assertTrue($resource->loading);
        self::assertSame('', $resource->buffer);

        $executor->runQueued(0);

        self::assertTrue($resource->ok);
        self::assertFalse($resource->loading);
        self::assertSame('fresh', $resource->buffer);
        self::assertSame('fresh', $resource->value);
        self::assertSame(5, $dirty);
    }

    #[Test]
    public function startedStreamDisposeCancelsTaskAndRejectsFurtherChunks(): void
    {
        $dirty = 0;
        $executor = new QueuedResourceTaskExecutor();
        $resource = null;

        $resource = new Resource(
            fetcher: static function () use (&$resource): iterable {
                yield 'partial';
                $resource?->dispose();
                yield 'ignored';
            },
            executor: $executor,
            onDirty: static function () use (&$dirty): void {
                $dirty++;
            },
        );

        $resource->stream();
        $executor->runQueued(0);

        self::assertSame(1, $executor->cancelCount());
        self::assertFalse($resource->ok);
        self::assertFalse($resource->loading);
        self::assertSame('partial', $resource->buffer);
        self::assertNull($resource->value);
        self::assertSame(2, $dirty);
    }

    #[Test]
    public function disposalPreventsRefresh(): void
    {
        $fetched = false;

        $resource = new Resource(
            fetcher: static function () use (&$fetched): string {
                $fetched = true;
                return 'data';
            },
        );

        $resource->dispose();
        $resource->refresh();

        self::assertFalse($fetched);
        self::assertFalse($resource->loading);
        self::assertNull($resource->value);
    }

    #[Test]
    public function doubleDisposeIsSafe(): void
    {
        $resource = new Resource(
            fetcher: static fn(): string => 'data',
        );

        $resource->dispose();
        $resource->dispose();

        self::assertFalse($resource->loading);
    }

    #[Test]
    public function nonStaticFetcherThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Resource fetcher must be a static closure.');

        new Resource(fetcher: fn(): string => 'data');
    }

    #[Test]
    public function nonStaticOnDirtyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Resource onDirty callback must be a static closure.');

        new Resource(
            fetcher: static fn(): string => 'data',
            onDirty: fn(): null => null,
        );
    }
}

final class QueuedResourceTaskExecutor implements TaskExecutor
{
    /** @var list<array{task: Closure, cancelled: bool}> */
    private array $tasks = [];

    private int $cancelCount = 0;

    public function queuedCount(): int
    {
        return count($this->tasks);
    }

    public function cancelCount(): int
    {
        return $this->cancelCount;
    }

    public function runQueued(int $index): mixed
    {
        $entry = $this->tasks[$index] ?? throw new RuntimeException("No queued task at index {$index}.");

        if ($entry['cancelled']) {
            array_splice($this->tasks, $index, 1);

            return null;
        }

        try {
            return $entry['task']();
        } finally {
            array_splice($this->tasks, $index, 1);
        }
    }

    public function go(Closure $fn, ?string $name = null): TaskHandle
    {
        $index = count($this->tasks);
        $this->tasks[] = [
            'task' => $fn,
            'cancelled' => false,
        ];
        $tasks = &$this->tasks;
        $cancelCount = &$this->cancelCount;

        return new TaskHandle(
            id: "queued-resource-task-{$index}",
            name: $name ?? 'queued-resource-task',
            cancel: static function () use (&$tasks, &$cancelCount, $index): void {
                if (!isset($tasks[$index]) || $tasks[$index]['cancelled']) {
                    return;
                }

                $tasks[$index]['cancelled'] = true;
                $cancelCount++;
            },
            snapshot: static fn(): null => null,
        );
    }

    /** @return array<string|int, mixed> */
    public function concurrent(Scopeable|Executable|Closure ...$tasks): array
    {
        throw new RuntimeException('concurrent is not implemented by the queued resource test executor.');
    }

    public function race(Scopeable|Executable|Closure ...$tasks): mixed
    {
        throw new RuntimeException('race is not implemented by the queued resource test executor.');
    }

    public function any(Scopeable|Executable|Closure ...$tasks): mixed
    {
        throw new RuntimeException('any is not implemented by the queued resource test executor.');
    }

    public function map(iterable $items, Closure $fn, int $limit = 10, ?Closure $onEach = null): array
    {
        throw new RuntimeException('map is not implemented by the queued resource test executor.');
    }

    /** @return array<string|int, mixed> */
    public function series(Scopeable|Executable|Closure ...$tasks): array
    {
        throw new RuntimeException('series is not implemented by the queued resource test executor.');
    }

    public function waterfall(Scopeable|Executable|Closure ...$tasks): mixed
    {
        throw new RuntimeException('waterfall is not implemented by the queued resource test executor.');
    }

    public function settle(Scopeable|Executable|Closure ...$tasks): SettlementBag
    {
        throw new RuntimeException('settle is not implemented by the queued resource test executor.');
    }

    public function timeout(Mark $duration, Scopeable|Executable|Closure $task): mixed
    {
        throw new RuntimeException('timeout is not implemented by the queued resource test executor.');
    }

    public function retry(Scopeable|Executable|Closure $task, RecoveryPlan $plan): mixed
    {
        throw new RuntimeException('retry is not implemented by the queued resource test executor.');
    }

    public function delay(Mark $duration): void
    {
        throw new RuntimeException('delay is not implemented by the queued resource test executor.');
    }

    public function periodic(Mark $interval, Closure $tick): Subscription
    {
        throw new RuntimeException('periodic is not implemented by the queued resource test executor.');
    }

    public function schedule(): ScheduleBuilder
    {
        throw new RuntimeException('schedule is not implemented by the queued resource test executor.');
    }

    public function defer(Scopeable|Executable|Closure $task): void
    {
        throw new RuntimeException('defer is not implemented by the queued resource test executor.');
    }

    public function singleflight(string $key, Scopeable|Executable|Closure $task): mixed
    {
        throw new RuntimeException('singleflight is not implemented by the queued resource test executor.');
    }

    public function inWorker(WorkerTask $task): mixed
    {
        throw new RuntimeException('inWorker is not implemented by the queued resource test executor.');
    }

    /** @return array<string|int, mixed> */
    public function parallel(WorkerTask ...$tasks): array
    {
        throw new RuntimeException('parallel is not implemented by the queued resource test executor.');
    }

    public function settleParallel(WorkerTask ...$tasks): SettlementBag
    {
        throw new RuntimeException('settleParallel is not implemented by the queued resource test executor.');
    }

    /** @return array<string|int, mixed> */
    public function mapParallel(iterable $items, Closure $fn, int $limit = 10, ?Closure $onEach = null): array
    {
        throw new RuntimeException('mapParallel is not implemented by the queued resource test executor.');
    }
}
