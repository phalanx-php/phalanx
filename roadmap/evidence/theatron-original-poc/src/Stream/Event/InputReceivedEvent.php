<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Stream\Event;

use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Stream\StreamEvent;

final class InputReceivedEvent implements StreamEvent
{
    public function __construct(
        private(set) InputEvent $input,
    ) {
    }
}
