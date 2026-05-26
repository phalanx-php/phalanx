<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Slices;

final class PendingEffect
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        private(set) string $kind,
        private(set) string $summary,
        private(set) array $arguments,
        private(set) int $hazardLevel,
        private(set) string $activityId = '',
        private(set) string $effectId = '',
        private(set) ?string $agentId = null,
        private(set) ?string $invocationId = null,
        private(set) string $hazard = 'none',
    ) {
    }
}
