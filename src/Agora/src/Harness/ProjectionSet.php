<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness;

use Phalanx\Agora\Harness\Projection\ActivityProjection;
use Phalanx\Agora\Harness\Projection\ConversationProjection;
use Phalanx\Agora\Harness\Projection\RuntimeProjection;
use Phalanx\Agora\Harness\Projection\WorkspaceProjection;

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
}
