<?php

declare(strict_types=1);

namespace Phalanx\Demos\Hydra\BasicWorkers;

use Phalanx\Worker\WorkerScope;
use Phalanx\Worker\WorkerTask;

class GreetThroughServiceTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly string $name,
    ) {
    }

    public function __invoke(WorkerScope $scope): string
    {
        return $scope->service(HydraGreetingService::class)->greet($this->name);
    }
}
