<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Projection;

use Phalanx\Agora\Harness\HarnessEvent;
use Phalanx\Agora\Harness\ProjectionKind;

final class WorkspaceProjection extends EventProjection
{
    /**
     * @param array<string, mixed> $restore
     */
    public function __construct(
        string $sessionId,
        int $eventSequence = 0,
        private(set) array $restore = [
            'active_surface' => 'conversation',
            'selected_turn_id' => null,
            'scroll_offset' => 0,
            'expanded_block' => null,
            'input_mode' => 'insert',
        ],
    ) {
        parent::__construct($sessionId, $eventSequence);
    }

    public function kind(): ProjectionKind
    {
        return ProjectionKind::Workspace;
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return [
            'session_id' => $this->sessionId,
            'event_sequence' => $this->eventSequence,
            'restore' => $this->restore,
        ];
    }

    protected function applyEvent(
        HarnessEvent $event,
    ): void {
        if ($event->source->value !== 'agora' || $event->cueType !== 'agora.workspace.restore') {
            return;
        }

        foreach (['active_surface', 'selected_turn_id', 'scroll_offset', 'expanded_block', 'input_mode'] as $field) {
            if (array_key_exists($field, $event->payload)) {
                $this->restore[$field] = $event->payload[$field];
            }
        }
    }
}
