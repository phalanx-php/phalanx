<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Event;

use Phalanx\Theatron\Stream\StreamEvent;

class AgentErrorEvent implements StreamEvent
{
    public function __construct(
        private(set) string $message,
        private(set) ?\Throwable $cause = null,
    ) {
    }
}
