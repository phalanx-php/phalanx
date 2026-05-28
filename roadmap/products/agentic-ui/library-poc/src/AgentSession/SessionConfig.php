<?php

declare(strict_types=1);

namespace Phalanx\Agentic\AgentSession;

final readonly class SessionConfig
{
    public function __construct(
        public string $sessionId,
        public string $agentClass,
        public string $workspace = 'global',
        public ?string $parentSessionId = null,
        public int $maxTokens = 128000,
        public bool $autoPersist = true,
    ) {}
}
