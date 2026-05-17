<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Effect;

use Phalanx\Panoply\Cue;

final class Executed extends Cue
{
    public string $type { get => 'cue.effect.executed'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) string $effectId,
        private(set) int $durationMs,
        private(set) ?string $resultDigest = null,
    ) {
        parent::__construct($id, $sequence, $activityId, $invocationId, $agentId, $at);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'effect_id'     => $this->effectId,
            'duration_ms'   => $this->durationMs,
            'result_digest' => $this->resultDigest,
        ];
    }
}
