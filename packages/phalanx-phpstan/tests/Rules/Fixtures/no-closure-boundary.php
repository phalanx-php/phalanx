<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;

final class NoClosureBoundaryFixture
{
    public function __construct(private readonly Scopeable $task)
    {
    }

    public function __invoke(ExecutionScope $scope): void
    {
        $typedClosure = static fn(): null => null;
        $scope->inWorker(static fn(): null => null);
        $scope->inWorker($typedClosure);
        $scope->inWorker($this->task);
        $scope->inWorker(Task::of(static fn(): null => null));
    }
}
