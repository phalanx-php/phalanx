<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

/**
 * Formats a hierarchy of task snapshots into a human-readable CLI ledger.
 *
 * Implements "Elite" diagnostic features:
 * 1. Sibling logic (↳ only for first-born child at each level)
 * 2. High-depth panning (at level 10+, shifts left and adds '... ' prefix)
 * 3. Break arrow (⇗) for panned levels.
 */
final class TaskTreeFormatter
{
    private const int LABEL_COLUMN_WIDTH = 50;
    private const int MAX_DEPTH_LEVEL = 10;

    /** @var array<int, bool> Tracks if a depth has already seen a child (for sibling logic) */
    private array $depthHasChild = [];

    /**
     * @param list<TaskRunSnapshot> $snapshots
     */
    public function format(array $snapshots): string
    {
        if ($snapshots === []) {
            return '(no active tasks)';
        }

        $byId = [];
        foreach ($snapshots as $snap) {
            $byId[$snap->id] = $snap;
        }

        $roots = self::findRoots($byId);
        $this->depthHasChild = [];
        
        $out = '';
        foreach ($roots as $root) {
            $out .= $this->renderNode($root, $byId, 0);
        }

        return rtrim($out);
    }

    /**
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
    private function renderNode(
        TaskRunSnapshot $node,
        array $byId,
        int $depth,
    ): string {
        if ($depth > 50) {
            return str_repeat('  ', 10) . "... (max depth reached)\n";
        }

        $isFirstChildAtDepth = !($this->depthHasChild[$depth] ?? false);
        $this->depthHasChild[$depth] = true;

        $line = $this->formatLine($node, $depth, $isFirstChildAtDepth) . "\n";

        $childDepth = $depth + 1;
        $this->depthHasChild[$childDepth] = false;

        foreach ($node->childIds as $childId) {
            if (isset($byId[$childId])) {
                $line .= $this->renderNode($byId[$childId], $byId, $childDepth);
            }
        }

        return $line;
    }

    private function formatLine(TaskRunSnapshot $snap, int $depth, bool $isFirstChild): string
    {
        $panningOffset = max(0, $depth - self::MAX_DEPTH_LEVEL);
        $visualDepth = min($depth, self::MAX_DEPTH_LEVEL);
        
        $indent = str_repeat('  ', $visualDepth);
        $prefixChars = $panningOffset > 0 ? '... ' : '';

        $arrowChar = ($panningOffset > 0 && $visualDepth === self::MAX_DEPTH_LEVEL) ? '⇗ ' : '↳ ';
        $arrow = ($depth > 0 && $isFirstChild) ? $arrowChar : ($depth > 0 ? '  ' : '');

        $fullPrefix = $prefixChars . $indent . $arrow;
        $prefixWidth = mb_strlen($fullPrefix);
        
        $nameBudget = max(8, self::LABEL_COLUMN_WIDTH - $prefixWidth);
        $name = self::truncate($snap->name, $nameBudget);
        $label = $fullPrefix . str_pad($name, $nameBudget);

        $state = str_pad($snap->state->value, 10);
        $elapsed = self::formatElapsed($snap->elapsed());

        $parts = [$label, $state, $elapsed];

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

        return mb_substr($s, 0, $max - 1) . '…';
    }
}
