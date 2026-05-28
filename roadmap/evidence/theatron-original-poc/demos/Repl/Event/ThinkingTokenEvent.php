<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Event;

readonly class ThinkingTokenEvent
{
    public function __construct(
        public string $delta,
    ) {
    }
}
