<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Event;

use Phalanx\Theatron\Stream\StreamEvent;

class LlmRequestStartEvent implements StreamEvent
{
    public function __construct(
        private(set) string $requestId,
        private(set) string $method,
        private(set) string $path,
        private(set) string $requestBody,
        private(set) float $startTime,
    ) {
    }
}
