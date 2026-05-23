<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Worker\WorkerTask;

final class NoClosureBoundaryFixture
{
    public function __construct(private readonly WorkerTask $task)
    {
    }

    public function __invoke(ExecutionScope $scope): void
    {
        $typedClosure = static fn(): null => null;
        $scope->inWorker(static fn(): null => null);
        $scope->inWorker($typedClosure);
        $scope->inWorker($this->task);
        $scope->inWorker(Task::of(static fn(): null => null));
        $scope->parallel(static fn(): null => null, $this->task);
        $scope->settleParallel($this->task, Task::of(static fn(): null => null));
        $scope->parallel(...[static fn(): null => null]);
        $this->parallel(static fn(): null => null);
    }

    private function parallel(mixed $task): void
    {
    }
}
