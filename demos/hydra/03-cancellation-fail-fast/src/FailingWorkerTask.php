<?php

declare(strict_types=1);

namespace Phalanx\Demos\Hydra\CancellationFailFast;

use Phalanx\Worker\WorkerScope;
use Phalanx\Worker\WorkerTask;

class FailingWorkerTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly string $message,
    ) {
    }

    public function __invoke(WorkerScope $scope): never
    {
        throw new \RuntimeException($this->message);
    }
}
