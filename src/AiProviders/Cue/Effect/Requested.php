<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Cue\Effect;

use Phalanx\AiProviders\Cue;
use Phalanx\AiProviders\Effect\Kind;

final class Requested extends Cue
{
    final public string $type { get => 'cue.effect.requested'; }

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) string $effectId,
        private(set) Kind $kind,
        private(set) string $summary,
        private(set) array $arguments = [],
        private(set) bool $requiresApproval = false,
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
            'kind' => $this->kind->value,
            'summary' => $this->summary,
            'arguments' => $this->arguments,
            'requires_approval' => $this->requiresApproval,
        ];
    }
}
