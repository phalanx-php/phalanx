<?php

declare(strict_types=1);

namespace AegisSwoole\Task;

/**
 * Behavioral interface declaring task priority. Higher = more important.
 * Workers reading from a priority mailbox respect this; the in-process scheduler
 * is FIFO and ignores it.
 */
interface HasPriority
{
    public function priority(): int;
}
