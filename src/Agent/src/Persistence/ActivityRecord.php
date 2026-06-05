<?php

declare(strict_types=1);

namespace Phalanx\Agent\Persistence;

use Phalanx\Agent\Activity\State;

final class ActivityRecord
{
    public function __construct(
        private(set) string $id,
        private(set) string $agentId,
        private(set) State $state,
        private(set) \DateTimeImmutable $startedAt,
        private(set) ?\DateTimeImmutable $completedAt = null,
        private(set) int $invocationCount = 0,
        private(set) ?string $serializedLog = null,
        private(set) ?string $pendingEffectId = null,
        private(set) ?string $pendingEffectPayload = null,
    ) {
    }
}
