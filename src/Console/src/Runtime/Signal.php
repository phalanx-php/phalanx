<?php

declare(strict_types=1);

namespace Phalanx\Console\Runtime;

/** @internal */
final readonly class Signal
{
    public function __construct(
        public int $number,
        public int $exitCode,
        public string $reason,
    ) {
    }
}
