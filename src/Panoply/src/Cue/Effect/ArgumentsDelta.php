<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Effect;

use Phalanx\Panoply\Cue;

final class ArgumentsDelta extends Cue
{
    final public string $type { get => 'cue.effect.arguments_delta'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) string $effectId,
        private(set) string $jsonDelta,
    ) {
        parent::__construct($id, $sequence, $activityId, $invocationId, $agentId, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'effect_id' => $this->effectId,
            'json_delta' => $this->jsonDelta,
        ];
    }
}
