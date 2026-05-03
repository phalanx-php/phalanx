<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Phalanx\Runtime\Memory\RuntimeMemory;
use PHPUnit\Framework\Assert as PHPUnitAssert;

class LeaseExpectation
{
    public function __construct(
        private readonly RuntimeMemory $memory,
    ) {
    }

    public function released(): void
    {
        PHPUnitAssert::assertSame(
            0,
            $this->memory->tables->resourceLeases->count(),
            'Expected every runtime lease to be released.',
        );
    }
}
