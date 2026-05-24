<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Tests\Fixtures;

use Phalanx\Scope\Scope;
use Phalanx\Worker\WorkerTask;

final class GreetThroughWorkerService implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        public string $name,
    ) {
    }

    public function __invoke(Scope $scope): string
    {
        return $scope->service(WorkerGreetingService::class)->greet($this->name);
    }
}
