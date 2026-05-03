<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Runtime\Memory\RuntimeMemory;
use PHPUnit\Framework\Assert as PHPUnitAssert;

class ScopeExpectation
{
    public function __construct(
        private readonly RuntimeMemory $memory,
    ) {
    }

    public function disposed(): void
    {
        PHPUnitAssert::assertSame(
            0,
            $this->memory->resources->liveCount(AegisResourceSid::Scope),
            'Expected every Aegis scope to be disposed.',
        );
    }
}
