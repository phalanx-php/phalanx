<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Fixtures;

use Phalanx\Scope\Scope;
use Phalanx\Worker\WorkerTask;

final class StderrTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

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
