<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Showcase\Event;

use Phalanx\Theatron\Stream\SerializableStreamEvent;

final class AgentTokenEvent implements SerializableStreamEvent
{
    public function __construct(
        private(set) string $agentId,
        private(set) string $delta,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): static
    {
        return new static( // @phpstan-ignore new.static
            agentId: (string) ($payload['agentId'] ?? ''),
            delta: (string) ($payload['delta'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'agentId' => $this->agentId,
            'delta' => $this->delta,
        ];
    }
}
