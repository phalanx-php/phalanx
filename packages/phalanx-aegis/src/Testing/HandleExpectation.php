<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Phalanx\Runtime\Memory\RuntimeMemory;
use PHPUnit\Framework\Assert as PHPUnitAssert;

class HandleExpectation
{
    public function __construct(
        private readonly RuntimeMemory $memory,
    ) {
    }

    public function closed(): void
    {
        PHPUnitAssert::assertSame(
            0,
            $this->memory->resources->liveCount(),
            'Expected every runtime handle to be closed.',
        );
    }
}
