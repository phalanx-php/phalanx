<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Diagnostic;

use Phalanx\Supervisor\Supervisor;

final class DiagnosticCollector
{
    private int $baselineZend;

    private int $baselineReal;

    private int $peakZend;

    private int $peakReal;

    private float $startTime;

    private function __construct(
        private(set) string $policy,
        private(set) string $churnMode,
    ) {
        $this->baselineZend = memory_get_usage();
        $this->baselineReal = memory_get_usage(true);
        $this->peakZend = $this->baselineZend;
        $this->peakReal = $this->baselineReal;
        $this->startTime = microtime(true);
    }

    public static function baseline(string $policy, string $churnMode): self
    {
        return new self($policy, $churnMode);
    }

    public function snapshot(int $frames, ?Supervisor $supervisor = null): DiagnosticSnapshot
    {
        $zend = memory_get_usage();
        $real = memory_get_usage(true);

        if ($zend > $this->peakZend) {
            $this->peakZend = $zend;
        }

        if ($real > $this->peakReal) {
            $this->peakReal = $real;
        }

        $poolStats = $supervisor?->poolStats();
        $gcStatus = gc_status();

        return new DiagnosticSnapshot(
            frames: $frames,
            policy: $this->policy,
            churnMode: $this->churnMode,
            zendBytes: $zend,
            zendDelta: $zend - $this->baselineZend,
            zendPeak: $this->peakZend,
            realBytes: $real,
            realDelta: $real - $this->baselineReal,
            realPeak: $this->peakReal,
            borrowedTaskRuns: $poolStats['taskRun']['borrowed'] ?? 0,
            borrowedScopeFrames: $poolStats['scopeFrame']['borrowed'] ?? 0,
            borrowedTokens: $poolStats['token']['borrowed'] ?? 0,
            poolHitsTotal: self::sumPoolField($poolStats, 'hits'),
            poolMissesTotal: self::sumPoolField($poolStats, 'misses'),
            liveTasks: $supervisor?->liveCount() ?? 0,
            liveScopes: $supervisor?->liveScopeCount() ?? 0,
            elapsed: microtime(true) - $this->startTime,
            gcRoots: $gcStatus['roots'] ?? 0,
        );
    }

    /** @param array<string, array<string, int>>|null $poolStats */
    private static function sumPoolField(?array $poolStats, string $field): int
    {
        if ($poolStats === null) {
            return 0;
        }

        $sum = 0;

        foreach ($poolStats as $pool) {
            $sum += $pool[$field] ?? 0;
        }

        return $sum;
    }
}
