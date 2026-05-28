<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Diagnostic;

final class DiagnosticSnapshot
{
    public function __construct(
        private(set) int $frames,
        private(set) string $policy,
        private(set) string $churnMode,
        private(set) int $zendBytes,
        private(set) int $zendDelta,
        private(set) int $zendPeak,
        private(set) int $realBytes,
        private(set) int $realDelta,
        private(set) int $realPeak,
        private(set) int $borrowedTaskRuns,
        private(set) int $borrowedScopeFrames,
        private(set) int $borrowedTokens,
        private(set) int $poolHitsTotal,
        private(set) int $poolMissesTotal,
        private(set) int $liveTasks,
        private(set) int $liveScopes,
        private(set) float $elapsed,
        private(set) int $gcRoots,
    ) {
    }
}
