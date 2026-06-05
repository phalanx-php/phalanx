<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Phalanx\Runtime\Memory\RuntimeMemory;

class PhalanxTestExpectations
{
    public function __construct(
        private readonly RuntimeMemory $memory,
    ) {
    }

    public function runtime(): RuntimeExpectation
    {
        return new RuntimeExpectation($this->memory);
    }

    public function scope(): ScopeExpectation
    {
        return new ScopeExpectation($this->memory);
    }

    public function work(): WorkExpectation
    {
        return new WorkExpectation($this->memory);
    }

    public function handles(): HandleExpectation
    {
        return new HandleExpectation($this->memory);
    }

    public function leases(): LeaseExpectation
    {
        return new LeaseExpectation($this->memory);
    }

    public function diagnostics(): DiagnosticsExpectation
    {
        return new DiagnosticsExpectation($this->memory);
    }
}
