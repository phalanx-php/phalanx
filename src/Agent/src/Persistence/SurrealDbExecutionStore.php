<?php

declare(strict_types=1);

namespace Phalanx\Agent\Persistence;

use Phalanx\Agent\Activity\State;
use Phalanx\AiProviders\Conversation\Log;
use Phalanx\AiProviders\Conversation\Record\Message;
use Phalanx\AiProviders\Conversation\Record\ToolCall;
use Phalanx\AiProviders\Conversation\Record\ToolResult;
use Phalanx\AiProviders\Conversation\RecordType;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Effect\Kind;
use Phalanx\Scope\TaskScope;
use Phalanx\SurrealDb\SurrealDb;

final class SurrealDbExecutionStore implements ExecutionStore
{
    public function __construct(
        private SurrealDb $surrealdb,
    ) {
    }

    public function saveActivity(TaskScope $scope, ActivityRecord $record): void
    {
        $scope->throwIfCancelled();

        $this->surrealdb->upsert('agent_activity:' . $record->id, [
            'agent_id' => $record->agentId,
            'state' => $record->state->value,
            'started_at' => $record->startedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'completed_at' => $record->completedAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
            'invocation_count' => $record->invocationCount,
        ]);
    }

    public function findActivity(TaskScope $scope, string $activityId): ?ActivityRecord
    {
        $scope->throwIfCancelled();

        $results = $this->surrealdb->query(
            'SELECT * FROM agent_activity WHERE id = $id LIMIT 1',
            ['id' => 'agent_activity:' . $activityId],
        );

        $rows = SurrealDbResult::firstRows($results);
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

        $this->surrealdb->upsert('agent_invocation:' . $record->id, [
            'activity_id' => $record->activityId,
            'prompt_hash' => $record->promptHash,
            'provider' => $record->provider,
            'model' => $record->model,
            'at' => $record->at->format(\DateTimeInterface::RFC3339_EXTENDED),
            'completed_at' => $record->completedAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
        ]);
    }

    public function logEffect(TaskScope $scope, EffectLogRecord $record): void
    {
        $scope->throwIfCancelled();

        $this->surrealdb->create('agent_effect_log:' . $record->id, [
            'invocation_id' => $record->invocationId,
            'kind' => $record->kind,
            'tool_name' => $record->toolName,
            'args_hash' => $record->argsHash,
            'resolution' => $record->resolution->value,
            'outcome' => $record->outcome,
            'at' => $record->at->format(\DateTimeInterface::RFC3339_EXTENDED),
        ]);
    }

    public function savePromptHash(TaskScope $scope, PromptHashRecord $record): void
    {
        $scope->throwIfCancelled();

        $this->surrealdb->upsert('agent_prompt_hash:' . $record->hash, [
            'activity_id' => $record->activityId,
            'invocation_id' => $record->invocationId,
            'at' => $record->at->format(\DateTimeInterface::RFC3339_EXTENDED),
        ]);
    }

    public function findPromptHash(TaskScope $scope, string $hash): ?PromptHashRecord
    {
        $scope->throwIfCancelled();

        $results = $this->surrealdb->query(
            'SELECT * FROM agent_prompt_hash WHERE id = $id LIMIT 1',
            ['id' => 'agent_prompt_hash:' . $hash],
        );

        $rows = SurrealDbResult::firstRows($results);
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

    public function suspendActivity(TaskScope $scope, string $activityId, Log $log, Requested $pendingEffect): void
    {
        $scope->throwIfCancelled();

        $logJson = json_encode(array_map(static fn($r) => $r->toCanonical(), $log->toArray()), JSON_THROW_ON_ERROR);
        $effectJson = json_encode($pendingEffect->toCanonical(), JSON_THROW_ON_ERROR);

        $this->surrealdb->upsert('agent_activity:' . $activityId, [
            'state' => State::Suspended->value,
            'serialized_log' => $logJson,
            'pending_effect_id' => $pendingEffect->effectId,
            'pending_effect_payload' => $effectJson,
        ]);
    }

    public function loadSuspended(TaskScope $scope, string $activityId): ?SuspendedState
    {
        $scope->throwIfCancelled();

        $results = $this->surrealdb->query(
            'SELECT * FROM agent_activity WHERE id = $id LIMIT 1',
            ['id' => 'agent_activity:' . $activityId],
        );

        $rows = SurrealDbResult::firstRows($results);
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        if (($row['state'] ?? '') !== State::Suspended->value) {
            return null;
        }

        $record = new ActivityRecord(
            id: $activityId,
            agentId: $row['agent_id'],
            state: State::from($row['state']),
            startedAt: new \DateTimeImmutable($row['started_at']),
            completedAt: isset($row['completed_at']) ? new \DateTimeImmutable($row['completed_at']) : null,
            invocationCount: (int) $row['invocation_count'],
            serializedLog: $row['serialized_log'] ?? null,
            pendingEffectId: $row['pending_effect_id'] ?? null,
            pendingEffectPayload: $row['pending_effect_payload'] ?? null,
        );

        /** @var list<array<string, mixed>> $logData */
        $logData = json_decode((string) $row['serialized_log'], true, 512, JSON_THROW_ON_ERROR);
        $log = Log::from(array_map(self::hydrateRecord(...), $logData));

        /** @var array<string, mixed> $effectData */
        $effectData = json_decode((string) $row['pending_effect_payload'], true, 512, JSON_THROW_ON_ERROR);
        $payload = $effectData['payload'] ?? [];
        $requested = new Requested(
            id: (string) $effectData['id'],
            sequence: (int) $effectData['sequence'],
            activityId: (string) $effectData['activity_id'],
            invocationId: isset($effectData['invocation_id']) ? (string) $effectData['invocation_id'] : null,
            agentId: isset($effectData['agent_id']) ? (string) $effectData['agent_id'] : null,
            at: new \DateTimeImmutable($effectData['at']),
            effectId: (string) $payload['effect_id'],
            kind: Kind::from((string) $payload['kind']),
            summary: (string) $payload['summary'],
            arguments: (array) ($payload['arguments'] ?? []),
            requiresApproval: (bool) ($payload['requires_approval'] ?? false),
        );

        return new SuspendedState($record, $log, $requested);
    }

    /** @param array<string, mixed> $entry */
    private static function hydrateRecord(array $entry): \Phalanx\AiProviders\Conversation\Record
    {
        $type = RecordType::from((string) $entry['type']);
        $id = (string) $entry['id'];
        $seq = isset($entry['sequence']) ? (int) $entry['sequence'] : null;
        $at = new \DateTimeImmutable((string) $entry['at']);
        $payload = (array) ($entry['payload'] ?? []);

        return match ($type) {
            RecordType::Message => new Message(
                id: $id,
                sequence: $seq,
                at: $at,
                role: (string) $payload['role'],
                text: (string) $payload['text'],
                attachments: array_values(array_map(strval(...), (array) ($payload['attachments'] ?? []))),
            ),
            RecordType::ToolCall => new ToolCall(
                id: $id,
                sequence: $seq,
                at: $at,
                callId: (string) $payload['call_id'],
                toolName: (string) $payload['tool_name'],
                arguments: (array) ($payload['arguments'] ?? []),
            ),
            RecordType::ToolResult => new ToolResult(
                id: $id,
                sequence: $seq,
                at: $at,
                callId: (string) $payload['call_id'],
                output: (string) $payload['output'],
                isError: (bool) ($payload['is_error'] ?? false),
            ),
            default => throw new \UnexpectedValueException(
                'Cannot hydrate record type "' . $type->value . '" from suspended state.',
            ),
        };
    }
}
