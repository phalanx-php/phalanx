<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kit;

use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\StoaRunner;

/**
 * Specialized harness for HTTP benchmarks that provides Stoa-specific helpers.
 */
final class BenchmarkApp extends BenchmarkHarness
{
    /** @var array<string, StoaRunner> */
    private array $stoaRunners = [];

    public function stoaRunner(string $name, RouteGroup $routes): StoaRunner
    {
        return $this->stoaRunners[$name] ??= StoaRunner::from($this->application())
            ->withRoutes($routes);
    }

    public function shutdown(): void
    {
        foreach ($this->stoaRunners as $runner) {
            $runner->stop();
        }

        $this->stoaRunners = [];
        parent::shutdown();
    }
}
