<?php

declare(strict_types=1);

namespace Phalanx\Agentic\AgentSession;

final readonly class AgentSessionState
{
    public function __construct(
        private string $status,
        private int $tokens = 0,
        private ?array $lastSignal = null,
        private ?array $pendingTool = null,
    ) {}

    public function status(): string { return $this->status; }
    public function tokens(): int { return $this->tokens; }
    public function lastSignal(): ?array { return $this->lastSignal; }
    public function pendingTool(): ?array { return $this->pendingTool; }

    public static function idle(): self
    {
        return new self('idle');
    }

    public function withStatus(string $status): self
    {
        return new self($status, $this->tokens, $this->lastSignal, $this->pendingTool);
    }

    public function withLastSignal(array $lastSignal): self
    {
        return new self($this->status, $this->tokens, $lastSignal, $this->pendingTool);
    }

    public function withPendingTool(?array $pendingTool): self
    {
        return new self($this->status, $this->tokens, $this->lastSignal, $pendingTool);
    }
}
