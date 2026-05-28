<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Diagnostic;

final class DiagnosticFormatter
{
    /**
     * @return array{identity: string, memory: string, runtime: string}
     */
    public static function statusBar(DiagnosticSnapshot $snap): array
    {
        $allocDelta = self::formatDelta($snap->zendDelta);
        $rssDelta = self::formatDelta($snap->realDelta);

        return [
            'identity' => sprintf(
                ' F:%-6d %s/%s',
                $snap->frames,
                $snap->policy,
                $snap->churnMode,
            ),
            'memory' => sprintf(
                ' Alloc: %8s  RSS: %8s',
                $allocDelta,
                $rssDelta,
            ),
            'runtime' => sprintf(
                ' Pool: %d/%d  Tasks: %d  %6.1fs ',
                $snap->borrowedTaskRuns + $snap->borrowedScopeFrames,
                self::totalCapacity($snap),
                $snap->liveTasks,
                $snap->elapsed,
            ),
        ];
    }

    public static function panelSummary(DiagnosticSnapshot $snap): string
    {
        $lines = [];
        $lines[] = sprintf(
            ' Alloc  baseline: %s   peak: %s   delta: %s',
            self::formatBytes($snap->zendBytes - $snap->zendDelta),
            self::formatBytes($snap->zendPeak),
            self::formatDelta($snap->zendDelta),
        );
        $lines[] = sprintf(
            ' RSS    baseline: %s   peak: %s   delta: %s',
            self::formatBytes($snap->realBytes - $snap->realDelta),
            self::formatBytes($snap->realPeak),
            self::formatDelta($snap->realDelta),
        );
        $lines[] = '';
        $lines[] = sprintf(
            ' Pool: taskRun %d  scopeFrame %d  token %d',
            $snap->borrowedTaskRuns,
            $snap->borrowedScopeFrames,
            $snap->borrowedTokens,
        );
        $lines[] = sprintf(
            ' Hits: %d   Misses: %d',
            $snap->poolHitsTotal,
            $snap->poolMissesTotal,
        );
        $lines[] = '';
        $lines[] = sprintf(
            ' GC roots: %d   Live tasks: %d   Live scopes: %d',
            $snap->gcRoots,
            $snap->liveTasks,
            $snap->liveScopes,
        );

        return implode("\n", $lines);
    }

    /**
     * @return array{identity: string, memory: string, time: string}
     */
    public static function simpleStatusBar(DiagnosticSnapshot $snap): array
    {
        return [
            'identity' => sprintf(' F:%-6d %s', $snap->frames, $snap->policy),
            'memory' => sprintf(
                ' Alloc: %s  RSS: %s',
                self::formatBytes($snap->zendBytes),
                self::formatBytes($snap->realBytes),
            ),
            'time' => sprintf(' %6.1fs ', $snap->elapsed),
        ];
    }

    private static function formatBytes(int $bytes): string
    {
        $abs = abs($bytes);

        if ($abs >= 1_073_741_824) {
            return sprintf('%.1fGB', $bytes / 1_073_741_824);
        }

        if ($abs >= 1_048_576) {
            return sprintf('%.1fMB', $bytes / 1_048_576);
        }

        if ($abs >= 1_024) {
            return sprintf('%.1fKB', $bytes / 1_024);
        }

        return sprintf('%dB', $bytes);
    }

    private static function formatDelta(int $delta): string
    {
        if ($delta === 0) {
            return '~0B';
        }

        $prefix = $delta > 0 ? '+' : '';

        return $prefix . self::formatBytes($delta);
    }

    private static function totalCapacity(DiagnosticSnapshot $snap): int
    {
        return max(1, $snap->borrowedTaskRuns + $snap->borrowedScopeFrames + $snap->borrowedTokens);
    }
}
