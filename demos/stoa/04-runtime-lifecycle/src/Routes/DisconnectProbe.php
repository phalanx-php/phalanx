<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Routes;

use Acme\StoaDemo\Runtime\Support\RuntimeEvents;
use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Scopeable;

final readonly class DisconnectProbe implements Scopeable
{
    public function __construct(private RuntimeEvents $events)
    {
    }

    /** @return array{status: string} */
    public function __invoke(RequestScope $scope): array
    {
        $this->events->record($scope, 'disconnect.started', ['path' => $scope->path()]);
        $scope->cancellation()->onCancel(function () use ($scope): void {
            $this->events->record($scope, 'disconnect.cancelled', ['path' => $scope->path()]);
        });

        try {
            for ($tick = 0; $tick < 100; $tick++) {
                $scope->delay(0.05);
            }

            $this->events->record($scope, 'disconnect.completed');

            return ['status' => 'completed'];
        } finally {
            $this->events->record($scope, 'disconnect.finalized', ['path' => $scope->path()]);
        }
    }
}
