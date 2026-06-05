<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Unit;

use Closure;
use Phalanx\Worker\ParallelConfig;
use Phalanx\Worker\ParallelDispatch;
use Phalanx\Scope\Scope;
use Phalanx\Worker\WorkerTask;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class ParallelDispatchSerializationTest extends TestCase
{
    #[Test]
    public function extractsSerializableConstructorState(): void
    {
        $args = $this->extract(new SerializableTask(42, ['a' => 1], TaskKind::Fast));

        self::assertSame(42, $args['id']);
        self::assertSame(['a' => 1], $args['payload']);
        self::assertSame(TaskKind::Fast, $args['kind']);
    }

    #[Test]
    public function rejectsClosureConstructorState(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("property 'callback' is not serializable");

        $this->extract(new ClosureTask(static fn(): null => null));
    }

    #[Test]
    public function rejectsObjectConstructorState(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("property 'payload' is not serializable");

        $this->extract(new ObjectTask(new Payload()));
    }

    /** @return array<string, mixed> */
    private function extract(WorkerTask $task): array
    {
        $method = new ReflectionMethod(ParallelDispatch::class, 'extractConstructorArgs');

        return $method->invoke(new ParallelDispatch(ParallelConfig::singleWorker()), $task);
    }
}

enum TaskKind: string
{
    case Fast = 'fast';
}

final class SerializableTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    /** @param array<string, int> $payload */
    public function __construct(
        private readonly int $id,
        private readonly array $payload,
        private readonly TaskKind $kind,
    ) {
    }

    public function __invoke(Scope $scope): int
    {
        return $this->id;
    }
}

final class ClosureTask implements WorkerTask
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

final class ObjectTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly Payload $payload,
    ) {
    }

    public function __invoke(Scope $scope): Payload
    {
        return $this->payload;
    }
}

final class Payload
{
}
