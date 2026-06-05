<?php

declare(strict_types=1);

namespace Phalanx\Agents\Turn;

use Phalanx\AiProviders\Context;

final class Config
{
    public function __construct(
        private(set) string $activityId,
        private(set) Context $context,
        private(set) int $maxInvocations = 3,
        private(set) int $invocation = 1,
    ) {
        if ($this->activityId === '') {
            throw new \InvalidArgumentException('Turn activityId cannot be empty.');
        }

        if ($this->maxInvocations < 1) {
            throw new \InvalidArgumentException('Turn maxInvocations must be >= 1.');
        }

        if ($this->invocation < 1) {
            throw new \InvalidArgumentException('Turn invocation must be >= 1.');
        }

        if ($this->invocation > $this->maxInvocations) {
            throw new \InvalidArgumentException('Turn invocation cannot exceed maxInvocations.');
        }
    }

    public function forInvocation(int $invocation): self
    {
        return new self(
            activityId: $this->activityId,
            context: $this->context,
            maxInvocations: $this->maxInvocations,
            invocation: $invocation,
        );
    }
}
