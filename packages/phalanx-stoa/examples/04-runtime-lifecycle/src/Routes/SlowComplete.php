<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Routes;

use Acme\StoaDemo\Runtime\Support\RuntimeEvents;
use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Scopeable;

final readonly class SlowComplete implements Scopeable
{
    public function __construct(private RuntimeEvents $events)
    {
    }

    /** @return array{status: string} */
    public function __invoke(RequestScope $scope): array
    {
        $this->events->record('slow.started', ['path' => $scope->path()]);
        $scope->delay(0.15);
        $this->events->record('slow.completed', ['path' => $scope->path()]);

        return ['status' => 'completed'];
    }
}
