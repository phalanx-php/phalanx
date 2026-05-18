<?php

declare(strict_types=1);

namespace Phalanx\Athena\Persistence;

use Phalanx\Athena\Activity\State;
use Phalanx\Scope\TaskScope;
use Phalanx\Surreal\Surreal;

final class SurrealExecutionStore implements ExecutionStore
{
    public function __construct(
        private Surreal $surreal,
    ) {
    }

    public function saveActivity(TaskScope $scope, ActivityRecord $record): void
    {
        $scope->throwIfCancelled();

        $this->surreal->upsert('athena_activity:' . $record->id, [
            'agent_id'         => $record->agentId,
            'state'            => $record->state->value,
            'started_at'       => $record->startedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'completed_at'     => $record->completedAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
            'invocation_count' => $record->invocationCount,
        ]);
    }

    public function findActivity(TaskScope $scope, string $activityId): ?ActivityRecord
    {
        $scope->throwIfCancelled();

        $results = $this->surreal->query(
            'SELECT * FROM athena_activity WHERE id = $id LIMIT 1',
            ['id' => 'athena_activity:' . $activityId],
        );

        $rows = SurrealResult::firstRows($results);
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return new ActivityRecord(
            id: $activityId,
            agentId: $row['agent_id'],
            state: State::from($row['state']),
            startedAt: new \DateTimeImmutable($row['started_at']),
            completedAt: isset($row['completed_at']) ? new \DateTimeImmutable($row['completed_at']) : null,
            invocationCount: (int) $row['invocation_count'],
        );
    }

    public function saveInvocation(TaskScope $scope, InvocationRecord $record): void
    {
        $scope->throwIfCancelled();

        $this->surreal->upsert('athena_invocation:' . $record->id, [
            'activity_id'  => $record->activityId,
            'prompt_hash'  => $record->promptHash,
            'provider'     => $record->provider,
            'model'        => $record->model,
            'at'           => $record->at->format(\DateTimeInterface::RFC3339_EXTENDED),
            'completed_at' => $record->completedAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
        ]);
    }

    public function logEffect(TaskScope $scope, EffectLogRecord $record): void
    {
        $scope->throwIfCancelled();

        $this->surreal->create('athena_effect_log:' . $record->id, [
            'invocation_id' => $record->invocationId,
            'kind'          => $record->kind,
            'tool_name'     => $record->toolName,
            'args_hash'     => $record->argsHash,
            'resolution'    => $record->resolution->value,
            'outcome'       => $record->outcome,
            'at'            => $record->at->format(\DateTimeInterface::RFC3339_EXTENDED),
        ]);
    }

    public function savePromptHash(TaskScope $scope, PromptHashRecord $record): void
    {
        $scope->throwIfCancelled();

        $this->surreal->upsert('athena_prompt_hash:' . $record->hash, [
            'activity_id'   => $record->activityId,
            'invocation_id' => $record->invocationId,
            'at'            => $record->at->format(\DateTimeInterface::RFC3339_EXTENDED),
        ]);
    }

    public function findPromptHash(TaskScope $scope, string $hash): ?PromptHashRecord
    {
        $scope->throwIfCancelled();

        $results = $this->surreal->query(
            'SELECT * FROM athena_prompt_hash WHERE id = $id LIMIT 1',
            ['id' => 'athena_prompt_hash:' . $hash],
        );

        $rows = SurrealResult::firstRows($results);
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return new PromptHashRecord(
            hash: $hash,
            activityId: $row['activity_id'],
            invocationId: $row['invocation_id'],
            at: new \DateTimeImmutable($row['at']),
        );
    }
}
