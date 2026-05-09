<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Closure;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\Scope;
use Phalanx\Worker\WorkerTask;

enum WorkerStateKind: string
{
    case One = 'one';
}

final class ClosureStateWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly Closure $callback,
    ) {
    }

    public function __invoke(Scope $scope): int
    {
        return 1;
    }
}

final class RuntimeStateWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly RuntimeContext $runtime,
    ) {
    }

    public function __invoke(Scope $scope): int
    {
        return 1;
    }
}

final class ObjectStateWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly NonWorkerPayload $payload,
    ) {
    }

    public function __invoke(Scope $scope): int
    {
        return 1;
    }
}

final class ArrayClosureStateWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    /** @var list<Closure> */
    private array $callbacks = [];

    public function __invoke(Scope $scope): int
    {
        return count($this->callbacks);
    }
}

final class ValidSerializableStateWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly int $id,
        private readonly array $items,
        private readonly WorkerStateKind $kind,
    ) {
    }

    public function __invoke(Scope $scope): int
    {
        return $this->id + count($this->items);
    }
}
