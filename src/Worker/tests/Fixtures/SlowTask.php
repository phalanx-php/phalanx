<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Fixtures;

use Phalanx\Scope\Scope;
use Phalanx\Worker\WorkerTask;

final class SlowTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

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
