<?php

declare(strict_types=1);

namespace Phalanx\Agent\Persistence;

final class InvocationRecord
{
    public function __construct(
        private(set) string $id,
        private(set) string $activityId,
        private(set) string $promptHash,
        private(set) string $provider,
        private(set) string $model,
        private(set) \DateTimeImmutable $at,
        private(set) ?\DateTimeImmutable $completedAt = null,
    ) {
    }
}
