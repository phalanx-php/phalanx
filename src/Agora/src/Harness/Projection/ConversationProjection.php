<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Projection;

use Phalanx\Agora\Harness\HarnessEvent;
use Phalanx\Agora\Harness\ProjectionKind;

final class ConversationProjection extends EventProjection
{
    /**
     * @param array<string, array<string, mixed>> $turns
     * @param list<string> $turnOrder
     */
    public function __construct(
        string $sessionId,
        int $eventSequence = 0,
        private(set) array $turns = [],
        private(set) array $turnOrder = [],
    ) {
        parent::__construct($sessionId, $eventSequence);
    }

    public function kind(): ProjectionKind
    {
        return ProjectionKind::Conversation;
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return [
            'session_id' => $this->sessionId,
            'event_sequence' => $this->eventSequence,
            'turn_order' => $this->turnOrder,
            'turns' => $this->turns,
        ];
    }

    protected function applyEvent(
        HarnessEvent $event,
    ): void {
        $turnId = $event->turnId;
        if ($turnId === null) {
            return;
        }

        $this->ensureTurn($turnId);
        $this->turns[$turnId]['events'][] = $event->cueType;

        if ($event->cueType !== 'cue.output.token_delta') {
            return;
        }

        $payload = self::cuePayload($event);
        $text = $payload['text'] ?? null;
        $channel = $payload['channel'] ?? null;

        if (!is_string($text) || !is_string($channel)) {
            return;
        }

        match ($channel) {
            'message' => $this->turns[$turnId]['message'] .= $text,
            'thinking' => $this->turns[$turnId]['thinking'][] = $text,
            'reasoning' => $this->turns[$turnId]['reasoning'][] = $text,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private static function cuePayload(
        HarnessEvent $event,
    ): array {
        $payload = $event->payload['payload'] ?? [];

        return is_array($payload) ? $payload : [];
    }

    private function ensureTurn(
        string $turnId,
    ): void {
        if (isset($this->turns[$turnId])) {
            return;
        }

        $this->turnOrder[] = $turnId;
        $this->turns[$turnId] = [
            'turn_id' => $turnId,
            'message' => '',
            'thinking' => [],
            'reasoning' => [],
            'events' => [],
        ];
    }
}
