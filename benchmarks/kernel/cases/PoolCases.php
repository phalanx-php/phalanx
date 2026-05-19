<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kernel\Cases;

use Closure;
use Phalanx\Benchmarks\Kernel\AbstractBenchmarkCase;
use Phalanx\Benchmarks\Kernel\BenchmarkContext;
use Phalanx\Pool\BorrowedValue;
use Phalanx\Pool\ObjectPool;
use Phalanx\Pool\PoolRing;
use ReflectionClass;

final class PoolBenchValue implements BorrowedValue
{
    public function __construct(
        private(set) int $kind = 0,
        private(set) string $name = '',
        private(set) float $timestamp = 0.0,
        /** @var array<string, mixed> */
        private(set) array $attrs = [],
    ) {
    }
}

// -- ObjectPool acquire/release cycle vs new+unset --

final class ObjectPoolCycleCase extends AbstractBenchmarkCase
{
    /** @var ObjectPool<PoolBenchValue>|null */
    private ?ObjectPool $pool = null;

    private ?Closure $initializer = null;

    public function __construct()
    {
        parent::__construct('pool_object_acquire_release', 100_000, 1_000);
    }

    public function run(BenchmarkContext $context): void
    {
        $this->pool ??= new ObjectPool(PoolBenchValue::class, 64);
        $this->initializer ??= Closure::bind(
            static function (PoolBenchValue $v): void {
                $v->kind = 1;
                $v->name = 'bench';
                $v->timestamp = 1716100000.0;
                $v->attrs = ['op' => 'cycle'];
            },
            null,
            PoolBenchValue::class,
        );

        $instance = $this->pool->acquire($this->initializer);
        $this->pool->release($instance);
    }

    public function cleanup(): void
    {
        $this->pool = null;
        $this->initializer = null;
    }
}

final class FreshAllocationBaselineCase extends AbstractBenchmarkCase
{
    public function __construct()
    {
        parent::__construct('pool_fresh_allocation_baseline', 100_000, 1_000);
    }

    public function run(BenchmarkContext $context): void
    {
        $v = new PoolBenchValue(
            kind: 1,
            name: 'bench',
            timestamp: 1716100000.0,
            attrs: ['op' => 'cycle'],
        );
        unset($v);
    }

    public function cleanup(): void
    {
    }
}

// -- PoolRing withBorrowed cycle vs fresh allocation --

final class PoolRingWithBorrowedCase extends AbstractBenchmarkCase
{
    /** @var PoolRing<PoolBenchValue>|null */
    private ?PoolRing $ring = null;

    private ?Closure $initializer = null;

    public function __construct()
    {
        parent::__construct('pool_ring_with_borrowed', 100_000, 1_000);
    }

    public function run(BenchmarkContext $context): void
    {
        $this->ring ??= new PoolRing(PoolBenchValue::class, 64);
        $this->initializer ??= Closure::bind(
            static function (PoolBenchValue $v): void {
                $v->kind = 1;
                $v->name = 'bench';
                $v->timestamp = 1716100000.0;
                $v->attrs = ['op' => 'ring'];
            },
            null,
            PoolBenchValue::class,
        );

        $this->ring->withBorrowed(
            $this->initializer,
            static fn(PoolBenchValue $v): string => $v->name,
        );
    }

    public function cleanup(): void
    {
        $this->ring = null;
        $this->initializer = null;
    }
}

// -- Cue-like allocation: 500 fresh new vs 500 resetAsLazyGhost per iteration --

final class CueLikeValue implements BorrowedValue
{
    public function __construct(
        private(set) string $id = '',
        private(set) int $sequence = 0,
        private(set) string $activityId = '',
        private(set) ?string $invocationId = null,
        private(set) ?string $agentId = null,
        private(set) string $text = '',
        private(set) string $channel = 'message',
    ) {
    }
}

final class CueAllocationNewCase extends AbstractBenchmarkCase
{
    private const int BATCH = 500;

    public function __construct()
    {
        parent::__construct('cue_allocation_new_500', 1_000, 100);
    }

    public function run(BenchmarkContext $context): void
    {
        $batch = [];

        for ($i = 0; $i < self::BATCH; $i++) {
            $batch[] = new CueLikeValue(
                id: "cue_{$i}",
                sequence: $i,
                activityId: 'act_bench',
                invocationId: 'inv_bench',
                agentId: 'agent_bench',
                text: 'token',
                channel: 'message',
            );
        }

        unset($batch);
    }

    public function cleanup(): void
    {
    }
}

final class CueAllocationResetCase extends AbstractBenchmarkCase
{
    private const int BATCH = 500;

    /** @var list<CueLikeValue> */
    private array $slots = [];

    /** @var ReflectionClass<CueLikeValue>|null */
    private ?ReflectionClass $reflector = null;

    public function __construct()
    {
        parent::__construct('cue_allocation_reset_500', 1_000, 100);
    }

    public function run(BenchmarkContext $context): void
    {
        if ($this->reflector === null) {
            $this->reflector = new ReflectionClass(CueLikeValue::class);

            for ($i = 0; $i < self::BATCH; $i++) {
                $ghost = $this->reflector->newLazyGhost(static function (): void {
                });
                $this->reflector->markLazyObjectAsInitialized($ghost);
                $this->slots[] = $ghost;
            }
        }

        $rc = $this->reflector;
        $slots = &$this->slots;

        for ($i = 0; $i < self::BATCH; $i++) {
            $seq = $i;
            $rc->resetAsLazyGhost(
                $slots[$i],
                Closure::bind(
                    static function (CueLikeValue $v) use ($seq): void {
                        $v->id = "cue_{$seq}";
                        $v->sequence = $seq;
                        $v->activityId = 'act_bench';
                        $v->invocationId = 'inv_bench';
                        $v->agentId = 'agent_bench';
                        $v->text = 'token';
                        $v->channel = 'message';
                    },
                    null,
                    CueLikeValue::class,
                ),
            );
            $rc->initializeLazyObject($slots[$i]);
        }
    }

    public function cleanup(): void
    {
        $this->slots = [];
        $this->reflector = null;
    }
}

// -- Memory growth: 10K iterations, pooled vs unpooled --

final class MemoryGrowthPooledCase extends AbstractBenchmarkCase
{
    /** @var ObjectPool<PoolBenchValue>|null */
    private ?ObjectPool $pool = null;

    private ?Closure $initializer = null;

    public function __construct()
    {
        parent::__construct('memory_growth_pooled_10k', 10_000, 100);
    }

    public function run(BenchmarkContext $context): void
    {
        $this->pool ??= new ObjectPool(PoolBenchValue::class, 256);
        $this->initializer ??= Closure::bind(
            static function (PoolBenchValue $v): void {
                $v->kind = 42;
                $v->name = 'memory-bench';
                $v->timestamp = microtime(true);
                $v->attrs = ['iteration' => true, 'payload' => str_repeat('x', 64)];
            },
            null,
            PoolBenchValue::class,
        );

        $instance = $this->pool->acquire($this->initializer);
        $this->pool->release($instance);
    }

    public function cleanup(): void
    {
        $this->pool = null;
        $this->initializer = null;
    }
}

final class MemoryGrowthUnpooledCase extends AbstractBenchmarkCase
{
    public function __construct()
    {
        parent::__construct('memory_growth_unpooled_10k', 10_000, 100);
    }

    public function run(BenchmarkContext $context): void
    {
        $v = new PoolBenchValue(
            kind: 42,
            name: 'memory-bench',
            timestamp: microtime(true),
            attrs: ['iteration' => true, 'payload' => str_repeat('x', 64)],
        );
        unset($v);
    }

    public function cleanup(): void
    {
    }
}
