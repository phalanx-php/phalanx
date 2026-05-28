<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Event;

use Phalanx\Theatron\Stream\StreamEvent;

class LlmRequestCompleteEvent implements StreamEvent
{
    public function __construct(
        private(set) string $requestId,
        private(set) int $status,
        private(set) float $elapsedMs,
        private(set) int $tokenCount,
        private(set) string $responseBody,
    ) {
    }
}
