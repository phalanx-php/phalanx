<?php

declare(strict_types=1);

namespace Phalanx\Tests\Support\Fixtures;

use Phalanx\Scope\Scope;
use Phalanx\Worker\WorkerTask;

final class AddNumbers implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

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

final class CpuIntensiveTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

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

final class TaskThatThrows implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        public string $message,
    ) {
    }

    public function __invoke(Scope $scope): never
    {
        throw new \RuntimeException($this->message);
    }
}
