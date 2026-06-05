<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Unit;

use Closure;
use Phalanx\Worker\ParallelConfig;
use Phalanx\Worker\ParallelWorkerDispatch;
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
        $args = $this->extract(new SerializableWorkerTask(42, ['a' => 1], WorkerTaskKind::Fast));

        self::assertSame(42, $args['id']);
        self::assertSame(['a' => 1], $args['payload']);
        self::assertSame(WorkerTaskKind::Fast, $args['kind']);
    }

    #[Test]
    public function rejectsClosureConstructorState(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("property 'callback' is not serializable");

        $this->extract(new ClosureWorkerTask(static fn(): null => null));
    }

    #[Test]
    public function rejectsObjectConstructorState(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("property 'payload' is not serializable");

        $this->extract(new ObjectWorkerTask(new WorkerPayload()));
    }

    /** @return array<string, mixed> */
    private function extract(WorkerTask $task): array
    {
        $method = new ReflectionMethod(ParallelWorkerDispatch::class, 'extractConstructorArgs');

        return $method->invoke(new ParallelWorkerDispatch(ParallelConfig::singleWorker()), $task);
    }
}

enum WorkerTaskKind: string
{
    case Fast = 'fast';
}

final class SerializableWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    /** @param array<string, int> $payload */
    public function __construct(
        private readonly int $id,
        private readonly array $payload,
        private readonly WorkerTaskKind $kind,
    ) {
    }

    public function __invoke(Scope $scope): int
    {
        return $this->id;
    }
}

final class ClosureWorkerTask implements WorkerTask
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

final class ObjectWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly WorkerPayload $payload,
    ) {
    }

    public function __invoke(Scope $scope): WorkerPayload
    {
        return $this->payload;
    }
}

final class WorkerPayload
{
}
