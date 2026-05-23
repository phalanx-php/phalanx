<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Widget;

/**
 * Render-state of a single row in TaskList / ConcurrentTaskList. Drives
 * icon and styling selection; consumed only by the table renderer, not
 * by the supervised task itself (which has its own runtime state).
 */
enum TaskState
{
    case Pending;
    case Running;
    case Success;
    case Error;
    case Skipped;
}
