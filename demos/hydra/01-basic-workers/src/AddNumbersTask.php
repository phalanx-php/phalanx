<?php

declare(strict_types=1);

namespace Phalanx\Demos\Hydra\BasicWorkers;

use Phalanx\Worker\WorkerScope;
use Phalanx\Worker\WorkerTask;

class AddNumbersTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly int $left,
        private readonly int $right,
    ) {
    }

    public function __invoke(WorkerScope $scope): int
    {
        return $this->left + $this->right;
    }
}
