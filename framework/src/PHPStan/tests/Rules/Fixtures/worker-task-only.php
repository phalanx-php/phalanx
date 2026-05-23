<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Worker\WorkerTask;

final class WorkerTaskOnlyFixture
{
    public function __invoke(ExecutionScope $scope): void
    {
        $scope->inWorker(new NonWorkerPayload());
        $scope->parallel(new ValidWorkerPayload(1), new NonWorkerPayload());
        $scope->settleParallel(new NonWorkerPayload());
        $scope->mapParallel([1], static fn(int $value): NonWorkerPayload => new NonWorkerPayload());
        $scope->parallel(...[new NonWorkerPayload()]);
        $scope->mapParallel([1], static function (int $value): NonWorkerPayload {
            return new NonWorkerPayload();
        });
    }
}

final class ValidWorkerPayload implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly int $value,
    ) {
    }

    public function __invoke(Scope $scope): int
    {
        return $this->value;
    }
}

final class NonWorkerPayload
{
}
