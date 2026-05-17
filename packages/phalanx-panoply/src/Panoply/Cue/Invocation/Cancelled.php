<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Invocation;

use Phalanx\Panoply\Cue;

final class Cancelled extends Cue
{
    final public string $type { get => 'cue.invocation.cancelled'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) string $reason,
    ) {
        parent::__construct($id, $sequence, $activityId, $invocationId, $agentId, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return ['reason' => $this->reason];
    }
}
