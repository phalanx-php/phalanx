<?php

declare(strict_types=1);

namespace Phalanx\Demos\Hydra\CancellationFailFast;

use Phalanx\Worker\WorkerScope;
use Phalanx\Worker\WorkerTask;

class SlowWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly int $microseconds,
    ) {
    }

    public function __invoke(WorkerScope $scope): string
    {
        usleep($this->microseconds);

        return 'slow-done';
    }
}
