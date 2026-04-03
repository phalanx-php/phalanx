<?php

declare(strict_types=1);

namespace Phalanx\Console;

/**
 * Observer protocol for concurrent scanners.
 *
 * The scanner calls these methods as it runs. The observer implementation
 * decides what to do with each event — print progress, accumulate metrics,
 * log to a file, or do nothing.
 *
 * All methods receive raw results as mixed so the interface stays
 * domain-agnostic. Concrete observers are typed at the command level.
 */
interface ScanObserver
{
    /** Called once before scanning begins, with the total item count. */
    public function onStart(int $total): void;

    /** Called immediately when an item resolves as a positive hit. */
    public function onHit(mixed $result): void;

    /** Called immediately when an item resolves as a miss. */
    public function onMiss(mixed $result): void;

    /** Called after all items have been processed, with elapsed seconds. */
    public function onDone(float $elapsed): void;
}
