<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Cue\Effect;

use Phalanx\AiProviders\Cue;

final class Denied extends Cue
{
    final public string $type { get => 'cue.effect.denied'; }

    /**
     * @param list<string> $reasonCodes
     */
    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) string $effectId,
        private(set) array $reasonCodes,
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
            'reason_codes' => $this->reasonCodes,
        ];
    }
}
