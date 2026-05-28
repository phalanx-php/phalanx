<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Event;

use Phalanx\Theatron\Stream\StreamEvent;

class UserSubmitEvent implements StreamEvent
{
    public function __construct(
        private(set) string $message,
    ) {
    }
}
