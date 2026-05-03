<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Phalanx\Runtime\Identity\RuntimeResourceId;
use Phalanx\Runtime\Memory\RuntimeMemory;
use PHPUnit\Framework\Assert as PHPUnitAssert;

class HandleExpectation
{
    public function __construct(
        private readonly RuntimeMemory $memory,
    ) {
    }

    public function closed(RuntimeResourceId|string|null $type = null): void
    {
        $live = $this->memory->resources->liveCount($type);
        $label = $type instanceof RuntimeResourceId ? $type->value() : ($type ?? 'runtime');

        PHPUnitAssert::assertSame(
            0,
            $live,
            "Expected every {$label} handle to be closed; {$live} still live.",
        );
    }
}
