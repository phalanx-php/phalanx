<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Provider;

use Phalanx\Panoply\Cue;

final class Retrying extends Cue
{
    final public string $type { get => 'cue.provider.retrying'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) string $provider,
        private(set) int $attempt,
        private(set) int $maxAttempts,
        private(set) ?int $backoffMs = null,
    ) {
        parent::__construct($id, $sequence, $activityId, $invocationId, $agentId, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'provider'     => $this->provider,
            'attempt'      => $this->attempt,
            'max_attempts' => $this->maxAttempts,
            'backoff_ms'   => $this->backoffMs,
        ];
    }
}
