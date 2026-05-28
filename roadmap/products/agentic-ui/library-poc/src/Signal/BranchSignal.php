<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Signal;

use Phalanx\Eidolon\Signal\SignalPriority;

final class BranchSignal implements AgenticSignal
{
    public AgenticSignalType $type { get => AgenticSignalType::Branch; }
    public SignalPriority $priority { get => SignalPriority::Event; }

    public function __construct(
        public readonly string $parentSessionId,
        public readonly string $newSessionId,
        public readonly string $reason,
    ) {}

    public function toArray(): array
    {
        return [
            'type'          => 'agent.branch',
            'parent_id'     => $this->parentSessionId,
            'new_session'   => $this->newSessionId,
            'reason'        => $this->reason,
        ];
    }
}
