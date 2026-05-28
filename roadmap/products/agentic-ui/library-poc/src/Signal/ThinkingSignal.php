<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Signal;

use Phalanx\Eidolon\Signal\SignalPriority;
use Phalanx\Eidolon\Signal\SignalType;

final class ThinkingSignal implements AgenticSignal
{
    public AgenticSignalType $type { get => AgenticSignalType::Thinking; }
    public SignalPriority $priority { get => SignalPriority::Event; }

    public function __construct(
        public readonly string $sessionId,
        public readonly string $text,
        public readonly int $step,
    ) {}

    public function toArray(): array
    {
        return [
            'type'       => 'agent.thinking',
            'session_id' => $this->sessionId,
            'text'       => $this->text,
            'step'       => $this->step,
        ];
    }
}
