<?php

declare(strict_types=1);

namespace Phalanx\Archon\Runtime\Identity;

/** @internal */
final readonly class ConsoleSignal
{
    public function __construct(
        public int $number,
        public int $exitCode,
        public string $reason,
    ) {
    }
}
