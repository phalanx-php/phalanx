<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Tests\Unit;

use Closure;
use Phalanx\Hydra\ParallelConfig;
use Phalanx\Hydra\ParallelWorkerDispatch;
use Phalanx\Scope\Scope;
use Phalanx\Worker\WorkerTask;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class ParallelWorkerDispatchSerializationTest extends TestCase
{
    #[Test]
    public function extractsSerializableConstructorState(): void
    {
        $args = $this->extract(new SerializableHydraTask(42, ['a' => 1], HydraTaskKind::Fast));

        self::assertSame(42, $args['id']);
        self::assertSame(['a' => 1], $args['payload']);
        self::assertSame(HydraTaskKind::Fast, $args['kind']);
    }

    #[Test]
    public function rejectsClosureConstructorState(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("property 'callback' is not serializable");

        $this->extract(new ClosureHydraTask(static fn(): null => null));
    }

    #[Test]
    public function rejectsObjectConstructorState(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("property 'payload' is not serializable");

        $this->extract(new ObjectHydraTask(new HydraPayload()));
    }

    /** @return array<string, mixed> */
    private function extract(WorkerTask $task): array
    {
        $method = new ReflectionMethod(ParallelWorkerDispatch::class, 'extractConstructorArgs');
        $method->setAccessible(true);

        return $method->invoke(new ParallelWorkerDispatch(ParallelConfig::singleWorker()), $task);
    }
}

enum HydraTaskKind: string
{
    case Fast = 'fast';
}

final class SerializableHydraTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    /** @param array<string, int> $payload */
    public function __construct(
        private readonly int $id,
        private readonly array $payload,
        private readonly HydraTaskKind $kind,
    ) {
    }

    public function __invoke(Scope $scope): int
    {
        return $this->id;
    }
}

final class ClosureHydraTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly Closure $callback,
    ) {
    }

    public function __invoke(Scope $scope): mixed
    {
        return ($this->callback)();
    }
}

final class ObjectHydraTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly HydraPayload $payload,
    ) {
    }

    public function __invoke(Scope $scope): HydraPayload
    {
        return $this->payload;
    }
}

final class HydraPayload
{
}
