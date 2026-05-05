<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Tests\Fixtures;

use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;

final readonly class SlowWorkerTask implements Scopeable
{
    public function __construct(
        public int $microseconds,
    ) {
    }

    public function __invoke(Scope $scope): string
    {
        usleep($this->microseconds);
        return 'slow-done';
    }
}
