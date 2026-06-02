<?php

declare(strict_types=1);

namespace Phalanx\Athena\Effect;

use Phalanx\Panoply\Grant;

final class Context
{
    public function __construct(
        private(set) string $activityId,
        private(set) ?string $invocationId,
        private(set) ?string $agentId,
        private(set) ?Grant $grant = null,
    ) {
        if ($this->activityId === '') {
            throw new \InvalidArgumentException('Effect activityId cannot be empty.');
        }
    }
}
