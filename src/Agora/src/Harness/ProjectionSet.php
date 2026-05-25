<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness;

use Phalanx\Agora\Harness\Projection\ActivityProjection;
use Phalanx\Agora\Harness\Projection\ConversationProjection;
use Phalanx\Agora\Harness\Projection\RuntimeProjection;
use Phalanx\Agora\Harness\Projection\WorkspaceProjection;
use Phalanx\Panoply\Hash\Canonical;

final class ProjectionSet
{
    public function __construct(
        private(set) ConversationProjection $conversation,
        private(set) RuntimeProjection $runtime,
        private(set) ActivityProjection $activity,
        private(set) WorkspaceProjection $workspace,
    ) {
    }

    public static function empty(
        string $sessionId,
    ): self {
        return new self(
            conversation: new ConversationProjection($sessionId),
            runtime: new RuntimeProjection($sessionId),
            activity: new ActivityProjection($sessionId),
            workspace: new WorkspaceProjection($sessionId),
        );
    }

    /**
     * @param list<ProjectionCheckpoint> $checkpoints
     */
    public static function fromCheckpoints(
        array $checkpoints,
    ): self {
        $byKind = [];
        foreach ($checkpoints as $checkpoint) {
            self::assertCheckpointHash($checkpoint);
            if (isset($byKind[$checkpoint->stateKind->value])) {
                throw new \InvalidArgumentException("Duplicate {$checkpoint->stateKind->value} projection checkpoint.");
            }

            $byKind[$checkpoint->stateKind->value] = $checkpoint;
        }

        foreach (ProjectionKind::cases() as $kind) {
            if (!isset($byKind[$kind->value])) {
                throw new \InvalidArgumentException("Missing {$kind->value} projection checkpoint.");
            }
        }

        $conversation = $byKind[ProjectionKind::Conversation->value];
        $runtime = $byKind[ProjectionKind::Runtime->value];
        $activity = $byKind[ProjectionKind::Activity->value];
        $workspace = $byKind[ProjectionKind::Workspace->value];

        self::assertSameCheckpointBoundary($conversation, $runtime, $activity, $workspace);

        return new self(
            conversation: new ConversationProjection(
                sessionId: $conversation->sessionId,
                eventSequence: $conversation->eventSequence,
                turns: self::mapField($conversation->state, 'turns'),
                turnOrder: self::listField($conversation->state, 'turn_order'),
            ),
            runtime: new RuntimeProjection(
                sessionId: $runtime->sessionId,
                eventSequence: $runtime->eventSequence,
                effects: self::mapField($runtime->state, 'effects'),
                effectLogs: self::mapField($runtime->state, 'effect_logs'),
                runtimeEvents: self::listField($runtime->state, 'runtime_events'),
            ),
            activity: new ActivityProjection(
                sessionId: $activity->sessionId,
                eventSequence: $activity->eventSequence,
                status: ResumeStatus::from(self::stringField($activity->state, 'status')),
                turnId: self::nullableStringField($activity->state, 'turn_id'),
                pendingEffectRecordId: self::nullableStringField($activity->state, 'pending_effect_record_id'),
                usage: self::mapField($activity->state, 'usage'),
                usageByInvocation: self::mapField($activity->state, 'usage_by_invocation'),
                context: self::mapField($activity->state, 'context'),
            ),
            workspace: new WorkspaceProjection(
                sessionId: $workspace->sessionId,
                eventSequence: $workspace->eventSequence,
                restore: self::mapField($workspace->state, 'restore'),
            ),
        );
    }

    public function apply(
        HarnessEvent $event,
    ): self {
        return new self(
            conversation: $this->conversation->apply($event),
            runtime: $this->runtime->apply($event),
            activity: $this->activity->apply($event),
            workspace: $this->workspace->apply($event),
        );
    }

    public function eventSequence(): int
    {
        return $this->conversation->eventSequence();
    }

    /** @return list<ProjectionCheckpoint> */
    public function checkpoints(
        ?\DateTimeImmutable $createdAt = null,
    ): array {
        return [
            $this->conversation->checkpoint($createdAt),
            $this->runtime->checkpoint($createdAt),
            $this->activity->checkpoint($createdAt),
            $this->workspace->checkpoint($createdAt),
        ];
    }

    private static function assertSameCheckpointBoundary(
        ProjectionCheckpoint ...$checkpoints,
    ): void {
        $sessionId = $checkpoints[0]->sessionId;
        $sequence = $checkpoints[0]->eventSequence;

        foreach ($checkpoints as $checkpoint) {
            if ($checkpoint->sessionId !== $sessionId) {
                throw new \InvalidArgumentException('Projection checkpoints must share a session.');
            }

            if ($checkpoint->eventSequence !== $sequence) {
                throw new \InvalidArgumentException('Projection checkpoints must share an event sequence.');
            }
        }
    }

    private static function assertCheckpointHash(
        ProjectionCheckpoint $checkpoint,
    ): void {
        if (Canonical::of($checkpoint->state) !== $checkpoint->projectionHash) {
            throw new \InvalidArgumentException("Projection checkpoint {$checkpoint->stateKind->value} hash mismatch.");
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function mapField(
        array $state,
        string $field,
    ): array {
        $value = $state[$field] ?? null;
        if ($value === []) {
            return [];
        }

        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException("Projection checkpoint field {$field} must be an object map.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $state
     * @return list<mixed>
     */
    private static function listField(
        array $state,
        string $field,
    ): array {
        $value = $state[$field] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("Projection checkpoint field {$field} must be a list.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function nullableStringField(
        array $state,
        string $field,
    ): ?string {
        $value = $state[$field] ?? null;
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new \InvalidArgumentException("Projection checkpoint field {$field} must be null or string.");
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function stringField(
        array $state,
        string $field,
    ): string {
        $value = $state[$field] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Projection checkpoint field {$field} must be string.");
        }

        return $value;
    }
}
