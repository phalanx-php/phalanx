<?php

declare(strict_types=1);

namespace Phalanx\Agents\Activity;

use Phalanx\Agents\Effect\Dispatcher;
use Phalanx\Agents\Effect\DispatchResult;
use Phalanx\Agents\Persistence\ExecutionStore;
use Phalanx\Agents\Stream\CueEmitter;
use Phalanx\AiProviders\Conversation\Log;
use Phalanx\AiProviders\Cue\Effect\Requested;
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
        CueEmitter $emitter,
    ): DispatchResult {
        $this->executionStore->suspendActivity($scope, $activityId, $log, $pendingEffect);

        ($this->grantMonitor)(
            $scope,
            $pendingEffect->agentId ?? '',
            $pendingEffect->kind,
            $pendingEffect->arguments,
        );

        return $dispatcher->dispatch($scope, $pendingEffect, $emitter);
    }
}
