<?php

declare(strict_types=1);

namespace Phalanx\Athena\Activity;

use Phalanx\Athena\Effect\Dispatcher;
use Phalanx\Athena\Effect\DispatchResult;
use Phalanx\Athena\Persistence\ExecutionStore;
use Phalanx\Athena\Stream\CompositeStream;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Scope\TaskScope;

final class Suspender
{
    public function __construct(
        private ExecutionStore $executionStore,
        private GrantMonitor $grantMonitor,
    ) {
    }

    public function __invoke(
        TaskScope $scope,
        string $activityId,
        Log $log,
        Requested $pendingEffect,
        Dispatcher $dispatcher,
        CompositeStream $stream,
    ): DispatchResult {
        $this->executionStore->suspendActivity($scope, $activityId, $log, $pendingEffect);

        ($this->grantMonitor)(
            $scope,
            $pendingEffect->agentId ?? '',
            $pendingEffect->kind,
            $pendingEffect->arguments,
        );

        return $dispatcher->dispatch($scope, $pendingEffect, $stream);
    }
}
