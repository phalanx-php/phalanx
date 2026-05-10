<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kit;

/**
 * Base interface for all benchmark cases.
 */
interface BenchmarkCase
{
    public function name(): string;

    public function iterations(): int;

    public function warmups(): int;
}
