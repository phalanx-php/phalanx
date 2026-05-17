<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Effect;

use Phalanx\Panoply\Cue;

final class Paused extends Cue
{
    public string $type { get => 'cue.effect.paused'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) string $effectId,
        private(set) string $reason,
    ) {
        parent::__construct($id, $sequence, $activityId, $invocationId, $agentId, $at);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'effect_id' => $this->effectId,
            'reason'    => $this->reason,
        ];
    }
}
