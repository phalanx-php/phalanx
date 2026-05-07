<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Tests\Fixtures;

use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;

final readonly class WorkerStderrTask implements Scopeable
{
    public function __construct(
        public string $message,
    ) {
    }

    public function __invoke(Scope $scope): string
    {
        fwrite(STDERR, $this->message);
        return 'stderr-drained';
    }
}
