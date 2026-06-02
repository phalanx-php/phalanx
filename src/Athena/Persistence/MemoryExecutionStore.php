<?php

declare(strict_types=1);

namespace Phalanx\Athena\Persistence;

use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Scope\TaskScope;

final class MemoryExecutionStore implements ExecutionStore
{
    /** @var array<string, ActivityRecord> */
    private array $activities = [];

    /** @var array<string, PromptHashRecord> */
    private array $promptHashes = [];

    /** @var list<InvocationRecord> */
    private array $invocations = [];

    /** @var list<EffectLogRecord> */
    private array $effectLogs = [];

    /** @var array<string, SuspendedState> */
    private array $suspended = [];

    public function saveActivity(TaskScope $scope, ActivityRecord $record): void
    {
        $this->activities[$record->id] = $record;
    }

    public function findActivity(TaskScope $scope, string $activityId): ?ActivityRecord
    {
        return $this->activities[$activityId] ?? null;
    }

    public function saveInvocation(TaskScope $scope, InvocationRecord $record): void
    {
        $this->invocations[] = $record;
    }

    public function logEffect(TaskScope $scope, EffectLogRecord $record): void
    {
        $this->effectLogs[] = $record;
    }

    /** @return list<InvocationRecord> */
    public function invocations(): array
    {
        return $this->invocations;
    }

    /** @return list<EffectLogRecord> */
    public function effectLogs(): array
    {
        return $this->effectLogs;
    }

    public function savePromptHash(TaskScope $scope, PromptHashRecord $record): void
    {
        $this->promptHashes[$record->hash] = $record;
    }

    public function findPromptHash(TaskScope $scope, string $hash): ?PromptHashRecord
    {
        return $this->promptHashes[$hash] ?? null;
    }

    public function suspendActivity(TaskScope $scope, string $activityId, Log $log, Requested $pendingEffect): void
    {
        $record = $this->activities[$activityId] ?? throw new \RuntimeException(
            "Cannot suspend unknown activity: {$activityId}",
        );

        $suspended = new ActivityRecord(
            id: $record->id,
            agentId: $record->agentId,
            state: \Phalanx\Athena\Activity\State::Suspended,
            startedAt: $record->startedAt,
            completedAt: $record->completedAt,
            invocationCount: $record->invocationCount,
        );

        $this->activities[$activityId] = $suspended;
        $this->suspended[$activityId] = new SuspendedState($suspended, $log, $pendingEffect);
    }

    public function loadSuspended(TaskScope $scope, string $activityId): ?SuspendedState
    {
        return $this->suspended[$activityId] ?? null;
    }
}
