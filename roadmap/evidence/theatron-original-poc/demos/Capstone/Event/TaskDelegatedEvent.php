<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Capstone\Event;

use Phalanx\Theatron\Stream\StreamEvent;

final class TaskDelegatedEvent implements StreamEvent
{
    public function __construct(
        private(set) string $taskId,
        private(set) string $title,
        private(set) string $assignedTo,
    ) {
    }
}
