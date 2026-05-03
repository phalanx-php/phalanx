<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Phalanx\Runtime\Identity\AegisResourceSid;
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
            $this->memory->resources->liveCount(AegisResourceSid::TaskRun),
            'Expected every Aegis task run to finish.',
        );
    }
}
