<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

final class StreamTraceEntry
{
    public function __construct(
        private(set) string $eventClass,
        private(set) float $timestamp,
        private(set) int $subscriberCount,
    ) {
    }
}
