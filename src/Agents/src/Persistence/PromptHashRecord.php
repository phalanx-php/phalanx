<?php

declare(strict_types=1);

namespace Phalanx\Agents\Persistence;

final class PromptHashRecord
{
    public function __construct(
        private(set) string $hash,
        private(set) string $activityId,
        private(set) string $invocationId,
        private(set) \DateTimeImmutable $at,
    ) {
    }
}
