<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Cue\Invocation;

use Phalanx\AiProviders\Cue;
use Phalanx\AiProviders\Cue\StopReason;

final class Completed extends Cue
{
    final public string $type { get => 'cue.invocation.completed'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) StopReason $stopReason,
    ) {
        parent::__construct($id, $sequence, $activityId, $invocationId, $agentId, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return ['stop_reason' => $this->stopReason->value];
    }
}
