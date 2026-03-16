<?php

declare(strict_types=1);

namespace Convoy\Tests\Support\Fixtures;

use Convoy\Scope;
use Convoy\Task\Scopeable;

final readonly class AddNumbers implements Scopeable
{
    public function __construct(
        public int $a,
        public int $b,
    ) {
    }

    public function __invoke(Scope $scope): int
    {
        return $this->a + $this->b;
    }
}

final readonly class CpuIntensiveTask implements Scopeable
{
    public function __construct(
        public int $iterations,
    ) {
    }

    public function __invoke(Scope $scope): int
    {
        $sum = 0;
        for ($i = 0; $i < $this->iterations; $i++) {
            $sum += $i;
        }
        return $sum;
    }
}

final readonly class TaskThatThrows implements Scopeable
{
    public function __construct(
        public string $message,
    ) {
    }

    public function __invoke(Scope $scope): never
    {
        throw new \RuntimeException($this->message);
    }
}
