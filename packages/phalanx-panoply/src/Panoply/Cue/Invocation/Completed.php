<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Invocation;

use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\StopReason;

final class Completed extends Cue
{
    public string $type { get => 'cue.invocation.completed'; }

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
    protected function payload(): array
    {
        return ['stop_reason' => $this->stopReason->value];
    }
}
