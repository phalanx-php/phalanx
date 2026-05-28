<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Showcase\Event;

use Phalanx\Theatron\Stream\SerializableStreamEvent;

final class AgentStatusEvent implements SerializableStreamEvent
{
    public function __construct(
        private(set) string $agentId,
        private(set) string $status,
        private(set) int $totalTokens = 0,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): static
    {
        return new static( // @phpstan-ignore new.static
            agentId: (string) ($payload['agentId'] ?? ''),
            status: (string) ($payload['status'] ?? ''),
            totalTokens: (int) ($payload['totalTokens'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'agentId' => $this->agentId,
            'status' => $this->status,
            'totalTokens' => $this->totalTokens,
        ];
    }
}
