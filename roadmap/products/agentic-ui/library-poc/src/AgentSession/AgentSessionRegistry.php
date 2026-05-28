<?php

declare(strict_types=1);

namespace Phalanx\Agentic\AgentSession;

use Phalanx\Agentic\AgenticServiceBundle;
use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\Memory\PgConversationMemory;
use Phalanx\Scope\Scope;

final class AgentSessionRegistry
{
    /** @var array<string, AgentSession> */
    private array $sessions = [];

    public function __construct(
        private ?ConversationMemory $memory = null,
    ) {
        $this->memory ??= new \Phalanx\Athena\Memory\PgConversationMemory();
    }

    public function resumeOrCreate(
        string $sessionId,
        string $agentClass,
        string $workspace = 'global',
    ): AgentSession {
        if (isset($this->sessions[$sessionId])) {
            return $this->sessions[$sessionId];
        }

        $config = new SessionConfig($sessionId, $agentClass, $workspace);
        $memory = $this->memory;

        $agentDef = AgentDefinition::from($agentClass);

        $session = new AgentSession($config, $memory, $agentDef);
        $this->sessions[$sessionId] = $session;

        return $session;
    }

    public function get(string $sessionId): ?AgentSession
    {
        return $this->sessions[$sessionId] ?? null;
    }

    public function all(): array
    {
        return array_values($this->sessions);
    }
}
