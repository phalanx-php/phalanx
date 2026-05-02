<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

final class StaticClosureOnlyFixture
{
    public function __invoke(ExecutionScope $scope): void
    {
        $scope->concurrent([
            static fn(): int => 1,
            fn(): int => 2,
        ]);

        $scope->map(
            [1, 2],
            fn(int $value): int => $value,
        );

        $scope->go(static fn(): null => null);
        $scope->go(fn(): null => null);

        Task::of(fn(): int => 1);
        Task::named('bad-task', fn(): int => 1);
        Task::of(static fn(): int => 1);
    }
}
