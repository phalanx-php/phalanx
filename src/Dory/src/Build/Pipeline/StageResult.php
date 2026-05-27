<?php

declare(strict_types=1);

namespace Phalanx\Dory\Build\Pipeline;

final class StageResult
{
    /**
     * @param array<string, string> $artifacts
     */
    public function __construct(
        private(set) string $stageName,
        private(set) bool $success,
        private(set) bool $skipped,
        private(set) float $durationMs,
        private(set) string $summary,
        private(set) array $artifacts = [],
    ) {
    }
}
