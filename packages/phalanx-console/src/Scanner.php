<?php

declare(strict_types=1);

namespace Phalanx\Console;

use Phalanx\Task\Executable;

/**
 * An Executable that supports attaching a ScanObserver for live feedback.
 *
 * Implementations must be immutable: withObserver() returns a new instance
 * with the observer set. The original is unchanged.
 *
 * The scanner is responsible for calling the full observer lifecycle:
 *   onStart(total) → onHit/onMiss per item → onDone(elapsed)
 */
interface Scanner extends Executable
{
    public function withObserver(ScanObserver $observer): static;
}
