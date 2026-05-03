<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Phalanx\Runtime\Identity\AegisCounterSid;
use Phalanx\Runtime\Memory\RuntimeMemory;
use PHPUnit\Framework\Assert as PHPUnitAssert;

class DiagnosticsExpectation
{
    public function __construct(
        private readonly RuntimeMemory $memory,
    ) {
    }

    public function healthy(): void
    {
        PHPUnitAssert::assertSame(
            [],
            $this->memory->events->listenerErrors(),
            'Expected no runtime listener failures.',
        );
        PHPUnitAssert::assertSame(
            0,
            $this->memory->counters->get(AegisCounterSid::RuntimeEventsDropped),
            'Expected no dropped runtime diagnostic events.',
        );
    }
}
