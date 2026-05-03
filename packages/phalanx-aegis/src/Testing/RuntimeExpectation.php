<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Phalanx\Runtime\Memory\RuntimeMemory;
use PHPUnit\Framework\Assert as PHPUnitAssert;

class RuntimeExpectation
{
    public function __construct(
        private readonly RuntimeMemory $memory,
    ) {
    }

    public function clean(): void
    {
        $live = $this->memory->resources->liveCount();

        PHPUnitAssert::assertSame(0, $live, "Expected no live runtime handles; {$live} still live.");
        PHPUnitAssert::assertSame(
            0,
            $this->memory->tables->resources->count(),
            'Expected no retained runtime handles.',
        );
        PHPUnitAssert::assertSame(
            0,
            $this->memory->tables->resourceEdges->count(),
            'Expected no retained runtime relationships.',
        );
        PHPUnitAssert::assertSame(
            0,
            $this->memory->tables->resourceLeases->count(),
            'Expected no retained runtime leases.',
        );
        PHPUnitAssert::assertSame(
            0,
            $this->memory->tables->resourceAnnotations->count(),
            'Expected no retained runtime annotations.',
        );
    }
}
