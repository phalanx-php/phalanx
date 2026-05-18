<?php

declare(strict_types=1);

namespace Phalanx\Athena\Persistence;

use Phalanx\Athena\Activity\State;

final class ActivityRecord
{
    public function __construct(
        private(set) string $id,
        private(set) string $agentId,
        private(set) State $state,
        private(set) \DateTimeImmutable $startedAt,
        private(set) ?\DateTimeImmutable $completedAt = null,
        private(set) int $invocationCount = 0,
    ) {
    }
}
