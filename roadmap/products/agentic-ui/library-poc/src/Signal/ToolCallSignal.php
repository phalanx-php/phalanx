<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Signal;

use Phalanx\Eidolon\Signal\SignalPriority;

final class ToolCallSignal implements AgenticSignal
{
    public AgenticSignalType $type { get => AgenticSignalType::ToolCall; }
    public SignalPriority $priority { get => SignalPriority::Event; }

    public function __construct(
        public readonly string $sessionId,
        public readonly string $toolName,
        public readonly array $args,
        public readonly string $callId,
    ) {}

    public function toArray(): array
    {
        return [
            'type'       => 'agent.tool_call',
            'session_id' => $this->sessionId,
            'tool'       => $this->toolName,
            'args'       => $this->args,
            'call_id'    => $this->callId,
        ];
    }
}
