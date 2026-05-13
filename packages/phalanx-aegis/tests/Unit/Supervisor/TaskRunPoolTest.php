<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Pool\ObjectPool;
use Phalanx\Supervisor\DispatchMode;
use Phalanx\Supervisor\RunState;
use Phalanx\Supervisor\TaskRun;
use PHPUnit\Framework\TestCase;

final class TaskRunPoolTest extends TestCase
{
    public function testPoolAcquireSetsAllIdentityFields(): void
    {
        $pool = new ObjectPool(TaskRun::class, 8);
        $token = CancellationToken::create();

        $run = $pool->acquire(static function (TaskRun $r) use ($token): void {
            $r->id = 'run-001';
            $r->name = 'Leonidas';
            $r->parentId = null;
            $r->mode = DispatchMode::Inline;
            $r->cancellation = $token;
            $r->startedAt = 1000.0;
            $r->scopeId = 'scope-001';
            $r->taskFqcn = 'App\\Task\\Defend';
            $r->sourcePath = '/phalanx/src/Task.php';
            $r->sourceLine = 42;
            $r->state = RunState::Pending;
            $r->leases = [];
            $r->currentWait = null;
            $r->endedAt = null;
            $r->value = null;
            $r->error = null;
            $r->failureTree = null;
        });

        self::assertSame('run-001', $run->id);
        self::assertSame('Leonidas', $run->name);
        self::assertNull($run->parentId);
        self::assertSame(DispatchMode::Inline, $run->mode);
        self::assertSame($token, $run->cancellation);
        self::assertSame(1000.0, $run->startedAt);
        self::assertSame('scope-001', $run->scopeId);
        self::assertSame(RunState::Pending, $run->state);
    }

    public function testPoolRecyclesZmmSlot(): void
    {
        $pool = new ObjectPool(TaskRun::class, 4);

        $first = self::acquireTaskRun($pool, 'run-001', 'Achilles');
        $firstId = spl_object_id($first);
        $pool->release($first);

        $second = self::acquireTaskRun($pool, 'run-002', 'Odysseus');

        self::assertSame($firstId, spl_object_id($second), 'Same ZMM slot reused');
        self::assertSame('run-002', $second->id);
        self::assertSame('Odysseus', $second->name);
    }

    public function testPoolInitializerFactoryProducesValidRun(): void
    {
        $pool = new ObjectPool(TaskRun::class, 4);
        $token = CancellationToken::create();

        $run = $pool->acquire(TaskRun::poolInitializer(
            'run-001',
            'Leonidas',
            null,
            DispatchMode::Inline,
            $token,
            'scope-001',
            ['fqcn' => 'App\\Task\\Defend', 'sourcePath' => '/src/Task.php', 'sourceLine' => 42],
        ));

        self::assertSame('run-001', $run->id);
        self::assertSame('Leonidas', $run->name);
        self::assertSame(DispatchMode::Inline, $run->mode);
        self::assertSame($token, $run->cancellation);
        self::assertSame(RunState::Pending, $run->state);
        self::assertFalse($run->tokenOwnedBySupervisor);
    }

    public function testMutableFieldIsolationAfterRecycle(): void
    {
        $pool = new ObjectPool(TaskRun::class, 4);

        $run = self::acquireTaskRun($pool, 'run-001', 'Hoplite');
        $run->state = RunState::Failed;
        $run->error = new \RuntimeException('spear broke');
        $run->value = 'old-value';
        $run->failureTree = [['id' => 'run-001']];
        $run->endedAt = 9999.0;
        $pool->release($run);

        $recycled = self::acquireTaskRun($pool, 'run-002', 'Agamemnon');

        self::assertSame(RunState::Pending, $recycled->state);
        self::assertNull($recycled->error);
        self::assertNull($recycled->value);
        self::assertNull($recycled->failureTree);
        self::assertNull($recycled->endedAt);
        self::assertNull($recycled->currentWait);
        self::assertSame([], $recycled->leases);
    }

    public function testErrorReferencesClearedOnRecycle(): void
    {
        $pool = new ObjectPool(TaskRun::class, 4);
        $exception = new \RuntimeException('battle lost');

        $run = self::acquireTaskRun($pool, 'run-001', 'Sparta');
        $run->error = $exception;
        $run->value = new \stdClass();

        $pool->release($run);

        $recycled = self::acquireTaskRun($pool, 'run-002', 'Athens');
        self::assertNull($recycled->error);
        self::assertNull($recycled->value);
    }

    public function testOverflowBehavior(): void
    {
        $pool = new ObjectPool(TaskRun::class, 2);

        $runs = [];
        for ($i = 0; $i < 4; $i++) {
            $runs[] = self::acquireTaskRun($pool, "run-{$i}", "hoplite-{$i}");
        }

        foreach ($runs as $run) {
            $pool->release($run);
        }

        $stats = $pool->stats();
        self::assertSame(2, $stats['overflows']);
        self::assertSame(2, $stats['free']);
    }

    public function testPoolStatsTrackHitRate(): void
    {
        $pool = new ObjectPool(TaskRun::class, 4);

        $run = self::acquireTaskRun($pool, 'run-001', 'Thermopylae');
        $pool->release($run);

        self::acquireTaskRun($pool, 'run-002', 'Marathon');

        $stats = $pool->stats();
        self::assertSame(1, $stats['hits']);
        self::assertSame(1, $stats['misses']);
    }

    public function testMultipleRecycleCyclesStable(): void
    {
        $pool = new ObjectPool(TaskRun::class, 2);
        $ids = [];

        for ($i = 0; $i < 10; $i++) {
            $run = self::acquireTaskRun($pool, "run-{$i}", "sarissa-{$i}");
            $ids[] = spl_object_id($run);
            self::assertSame("run-{$i}", $run->id);
            self::assertSame(RunState::Pending, $run->state);
            $pool->release($run);
        }

        self::assertSame(9, $pool->stats()['hits']);
        self::assertSame(1, $pool->stats()['misses']);
    }

    private static function acquireTaskRun(ObjectPool $pool, string $id, string $name): TaskRun
    {
        $token = CancellationToken::none();

        return $pool->acquire(static function (TaskRun $r) use ($id, $name, $token): void {
            $r->id = $id;
            $r->name = $name;
            $r->parentId = null;
            $r->mode = DispatchMode::Inline;
            $r->cancellation = $token;
            $r->startedAt = microtime(true);
            $r->scopeId = null;
            $r->taskFqcn = null;
            $r->sourcePath = null;
            $r->sourceLine = null;
            $r->state = RunState::Pending;
            $r->leases = [];
            $r->currentWait = null;
            $r->endedAt = null;
            $r->value = null;
            $r->error = null;
            $r->failureTree = null;
        });
    }
}
