<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Signal;

use Phalanx\Eidolon\Signal\SignalPriority;

final class FinalAnswerSignal implements AgenticSignal
{
    public AgenticSignalType $type { get => AgenticSignalType::FinalAnswer; }
    public SignalPriority $priority { get => SignalPriority::Event; }

    public function __construct(
        public readonly string $sessionId,
        public readonly string $answer,
        public readonly int $totalTokens,
    ) {}

    public function toArray(): array
    {
        return [
            'type'        => 'agent.final_answer',
            'session_id'  => $this->sessionId,
            'answer'      => $this->answer,
            'tokens'      => $this->totalTokens,
        ];
    }
}
