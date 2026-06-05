<?php

declare(strict_types=1);

namespace Phalanx\Agent\Persistence;

use Phalanx\AiProviders\Conversation\Log;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\Scope\TaskScope;

interface ExecutionStore
{
    public function saveActivity(TaskScope $scope, ActivityRecord $record): void;

    public function findActivity(TaskScope $scope, string $activityId): ?ActivityRecord;

    public function saveInvocation(TaskScope $scope, InvocationRecord $record): void;

    public function logEffect(TaskScope $scope, EffectLogRecord $record): void;

    public function savePromptHash(TaskScope $scope, PromptHashRecord $record): void;

    public function findPromptHash(TaskScope $scope, string $hash): ?PromptHashRecord;

    public function suspendActivity(TaskScope $scope, string $activityId, Log $log, Requested $pendingEffect): void;

    public function loadSuspended(TaskScope $scope, string $activityId): ?SuspendedState;
}
