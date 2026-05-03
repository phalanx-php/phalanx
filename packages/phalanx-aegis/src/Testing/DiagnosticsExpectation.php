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
        $this->listenerFailures(0);
        $this->droppedEvents(0);
    }

    public function listenerFailures(int $expected): void
    {
        $actual = count($this->memory->events->listenerErrors());

        PHPUnitAssert::assertSame(
            $expected,
            $actual,
            "Expected {$expected} runtime listener failures; saw {$actual}.",
        );
    }

    public function droppedEvents(int $expected): void
    {
        $actual = $this->memory->counters->get(AegisCounterSid::RuntimeEventsDropped);

        PHPUnitAssert::assertSame(
            $expected,
            $actual,
            "Expected {$expected} dropped runtime diagnostic events; saw {$actual}.",
        );
    }
}
