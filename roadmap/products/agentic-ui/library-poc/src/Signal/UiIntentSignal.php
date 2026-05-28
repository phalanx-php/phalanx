<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Signal;

use Phalanx\Eidolon\Signal\SignalPriority;

final class UiIntentSignal implements AgenticSignal
{
    public AgenticSignalType $type { get => AgenticSignalType::UiIntent; }
    public SignalPriority $priority { get => SignalPriority::Event; }

    public function __construct(
        public readonly string $sessionId,
        public readonly string $intent,
        public readonly array $payload = [],
    ) {}

    public function toArray(): array
    {
        return [
            'type'       => 'agent.ui_intent',
            'session_id' => $this->sessionId,
            'intent'     => $this->intent,
            'payload'    => $this->payload,
        ];
    }
}
