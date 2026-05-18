<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Http;

use Phalanx\Benchmarks\Kit\BenchmarkApp;
use Phalanx\Benchmarks\Kit\BenchmarkCase;

interface HttpBenchmarkCase extends BenchmarkCase
{
    public function run(BenchmarkApp $app): void;
}

abstract class AbstractHttpBenchmarkCase implements HttpBenchmarkCase
{
    abstract public function run(BenchmarkApp $app): void;

    public function __construct(
        private readonly string $caseName,
        private readonly int $caseIterations,
        private readonly int $caseWarmups = 5,
    ) {
    }

    public function name(): string
    {
        return $this->caseName;
    }

    public function iterations(): int
    {
        return $this->caseIterations;
    }

    public function warmups(): int
    {
        return $this->caseWarmups;
    }
}
