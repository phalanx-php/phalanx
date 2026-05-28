<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Stream\Event;

use Phalanx\Theatron\Stream\StreamEvent;

final class StoreUpdatedEvent implements StreamEvent
{
    /** @param class-string $sliceClass */
    public function __construct(
        private(set) string $sliceClass,
    ) {
    }
}
