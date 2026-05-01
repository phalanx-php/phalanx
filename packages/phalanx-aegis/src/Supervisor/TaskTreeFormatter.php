<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

/**
 * ASCII formatter for live task-tree snapshots produced by
 * Supervisor::tree(). Powers the future `phalanx ps` / `phalanx doctor`
 * surfaces and the leak / hang diagnostic reports.
 *
 * Output shape (single vertical bar per depth, no branches):
 *
 *   AppHandler                Running    12.4ms
 *   │ FetchUser(7)            Suspended   8.1ms  wait: postgres SELECT * FROM users WHERE id...
 *   │ AuditWrite(login)       Running     6.2ms
 *   │ │ FlushBuffer           Running     1.0ms  [holds: redis/cache#3/shared]
 *
 * The snapshot list is the flat `Supervisor::tree()` projection. The
 * formatter rebuilds the parent/child structure from `parentId` and the
 * declared root (or roots, when `$rootRunId` is null).
 */
final class TaskTreeFormatter
{
    /**
     * @param list<TaskRunSnapshot> $snapshots
     */
    public function format(array $snapshots, ?string $rootRunId = null): string
    {
        if ($snapshots === []) {
            return "(no live tasks)\n";
        }

        $byId = [];
        foreach ($snapshots as $snap) {
            $byId[$snap->id] = $snap;
        }

        $roots = $rootRunId !== null
            ? (isset($byId[$rootRunId]) ? [$byId[$rootRunId]] : [])
            : self::findRoots($byId);

        if ($roots === []) {
            return "(no matching tasks)\n";
        }

        $out = '';
        foreach ($roots as $root) {
            $out .= self::renderNode($root, $byId, 0);
        }
        return $out;
    }

    /**
     * @param array<string, TaskRunSnapshot> $byId
     * @return list<TaskRunSnapshot>
     */
    private static function findRoots(array $byId): array
    {
        $roots = [];
        foreach ($byId as $snap) {
            if ($snap->parentId === null || !isset($byId[$snap->parentId])) {
                $roots[] = $snap;
            }
        }
        return $roots;
    }

    /**
     * @param array<string, TaskRunSnapshot> $byId
     */
    private static function renderNode(
        TaskRunSnapshot $node,
        array $byId,
        int $depth,
    ): string {
        $line = str_repeat('│ ', $depth) . self::formatLine($node) . "\n";

        foreach ($node->childIds as $childId) {
            if (isset($byId[$childId])) {
                $line .= self::renderNode($byId[$childId], $byId, $depth + 1);
            }
        }

        return $line;
    }

    private static function formatLine(TaskRunSnapshot $snap): string
    {
        $name = self::truncate($snap->name, 40);
        $namePad = str_pad($name, 40);
        $state = str_pad($snap->state->value, 10);
        $elapsed = self::formatElapsed($snap->elapsed());

        $parts = [$namePad, $state, $elapsed];

        if ($snap->currentWait !== null) {
            $parts[] = self::formatWait($snap->currentWait);
        }

        if ($snap->leases !== []) {
            $parts[] = self::formatLeases($snap->leases);
        }

        return implode('  ', $parts);
    }

    private static function formatWait(WaitReason $wait): string
    {
        $kind = $wait->kind->value;
        return $wait->detail !== ''
            ? "wait: {$kind} {$wait->detail}"
            : "wait: {$kind}";
    }

    /**
     * @param list<array{domain: string, key: string, mode: string, acquiredAt: float}> $leases
     */
    private static function formatLeases(array $leases): string
    {
        $rendered = array_map(
            static fn(array $l): string => "{$l['domain']}#{$l['key']}/{$l['mode']}",
            $leases,
        );
        return '[holds: ' . implode(', ', $rendered) . ']';
    }

    private static function formatElapsed(float $seconds): string
    {
        if ($seconds < 1.0) {
            return sprintf('%6.1fms', $seconds * 1000);
        }
        if ($seconds < 60.0) {
            return sprintf('%7.2fs', $seconds);
        }
        return sprintf('%5.1fm', $seconds / 60.0);
    }

    private static function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 3) . '...';
    }
}
