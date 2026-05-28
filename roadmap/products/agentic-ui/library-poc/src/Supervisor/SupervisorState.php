<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Supervisor;

final readonly class SupervisorState
{
    public function __construct(
        private string $workspace,
        private array $activeSessions = [],
        private string $synthesis = 'No active agent runs',
        private array $feed = [],
    ) {}

    public function workspace(): string { return $this->workspace; }
    public function activeSessions(): array { return $this->activeSessions; }
    public function synthesis(): string { return $this->synthesis; }
    public function feed(): array { return $this->feed; }

    public static function initial(string $workspace = 'global'): self
    {
        return new self($workspace);
    }

    public function withActiveSessions(array $sessions): self
    {
        return new self($this->workspace, $sessions, $this->synthesis, $this->feed);
    }
}
