<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Signal;

use Phalanx\Eidolon\Signal\SignalPriority;

final class ToolResultSignal implements AgenticSignal
{
    public AgenticSignalType $type { get => AgenticSignalType::ToolResult; }
    public SignalPriority $priority { get => SignalPriority::Event; }

    public function __construct(
        public readonly string $sessionId,
        public readonly string $callId,
        public readonly mixed $result,
        public readonly bool $success = true,
    ) {}

    public function toArray(): array
    {
        return [
            'type'       => 'agent.tool_result',
            'session_id' => $this->sessionId,
            'call_id'    => $this->callId,
            'result'     => $this->result,
            'success'    => $this->success,
        ];
    }
}
