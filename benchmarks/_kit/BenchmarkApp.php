<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kit;

use Phalanx\Http\RouteGroup;
use Phalanx\Http\Runner;

/**
 * Specialized harness for HTTP benchmarks that provides Http-specific helpers.
 */
final class BenchmarkApp extends BenchmarkHarness
{
    /** @var array<string, \Phalanx\Http\Runner> */
    private array $httpRunners = [];

    public function httpRunner(string $name, RouteGroup $routes): \Phalanx\Http\Runner
    {
        return $this->httpRunners[$name] ??= \Phalanx\Http\Runner::from($this->application())
            ->withRoutes($routes);
    }

    #[\Override]
    public function shutdown(): void
    {
        foreach ($this->httpRunners as $runner) {
            $runner->stop();
        }

        $this->httpRunners = [];
        parent::shutdown();
    }
}
