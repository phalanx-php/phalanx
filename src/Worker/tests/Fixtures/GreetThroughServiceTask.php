<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Fixtures;

use Phalanx\Scope\Scope;
use Phalanx\Worker\WorkerTask;

final class GreetThroughServiceTask implements WorkerTask
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
        return $scope->service(GreetingService::class)->greet($this->name);
    }
}
