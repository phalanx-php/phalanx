<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Phalanx\Runtime\Identity\RuntimeResourceSid;
use Phalanx\Runtime\Memory\RuntimeMemory;
use PHPUnit\Framework\Assert as PHPUnitAssert;

class WorkExpectation
{
    public function __construct(
        private readonly RuntimeMemory $memory,
    ) {
    }

    public function finished(): void
    {
        PHPUnitAssert::assertSame(
            0,
            $this->memory->resources->liveCount(RuntimeResourceSid::TaskRun),
            'Expected every Runtime task run to finish.',
        );
    }
}
