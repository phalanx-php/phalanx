<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Provider;

use Phalanx\Panoply\Cue;

final class RateLimited extends Cue
{
    final public string $type { get => 'cue.provider.rate_limited'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) string $provider,
        private(set) string $model,
        private(set) ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($id, $sequence, $activityId, $invocationId, $agentId, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'retry_after_seconds' => $this->retryAfterSeconds,
        ];
    }
}
